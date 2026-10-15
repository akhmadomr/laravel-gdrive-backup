<?php

namespace Akhmadomr\GdriveBackup\Services;

use Akhmadomr\GdriveBackup\Models\BackupSetting;
use Google\Client as GoogleClient;
use Google\Service\Drive;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Mengelola akun Google Drive yang terhubung (multi-akun).
 * Refresh token disimpan terenkripsi (APP_KEY) di tabel gdrive_backup_settings.
 */
class GoogleAccountManager
{
    public const SETTING_KEY = 'google_accounts';

    public function isConfigured(): bool
    {
        return filled(config('gdrive-backup.google.client_id'))
            && filled(config('gdrive-backup.google.client_secret'));
    }

    public function redirectUri(): string
    {
        return config('gdrive-backup.google.redirect_uri')
            ?: url(trim(config('gdrive-backup.route.prefix'), '/').'/google/callback');
    }

    /** @return array<int, array<string, mixed>> akun dengan refresh_token terdekripsi */
    public function accounts(): array
    {
        $accounts = BackupSetting::getJson(self::SETTING_KEY, []);
        if (! is_array($accounts)) {
            return [];
        }

        return array_values(array_filter(array_map(function ($acc) {
            try {
                $acc['refresh_token'] = Crypt::decryptString($acc['refresh_token'] ?? '');
            } catch (\Throwable) {
                return null; // APP_KEY berubah / data rusak → akun harus dihubungkan ulang
            }

            return $acc;
        }, $accounts)));
    }

    /** Data akun yang aman dikirim ke frontend. */
    public function publicAccounts(): array
    {
        return array_map(fn ($acc) => [
            'id' => $acc['id'],
            'email' => $acc['email'] ?? 'Akun Google',
            'name' => $acc['name'] ?? '',
            'avatar' => $acc['avatar'] ?? null,
            'folder_id' => $acc['folder_id'] ?? '',
            'connected_at' => $acc['connected_at'] ?? null,
        ], $this->accounts());
    }

    public function createAuthUrl(?string $userId): string
    {
        $this->ensureConfigured();

        $state = Str::random(40);
        Cache::put('gdrive-backup:oauth:'.$state, $userId ?? 'guest', now()->addMinutes(10));

        $client = $this->client();
        $client->setRedirectUri($this->redirectUri());
        $client->setAccessType('offline');
        $client->setPrompt('select_account consent');
        $client->setIncludeGrantedScopes(true);
        $client->setScopes([Drive::DRIVE_FILE, 'email', 'profile']);
        $client->setState($state);

        return $client->createAuthUrl();
    }

    /**
     * Tukar authorization code menjadi token & simpan akun.
     * State wajib valid agar orang lain tidak bisa menyusupkan akun Drive miliknya.
     */
    public function handleCallback(?string $code, ?string $state): array
    {
        $this->ensureConfigured();

        if (! $code || ! $state || ! Cache::pull('gdrive-backup:oauth:'.$state)) {
            throw new RuntimeException('Sesi otorisasi tidak valid atau kedaluwarsa. Silakan coba lagi.');
        }

        $client = $this->client();
        $client->setRedirectUri($this->redirectUri());
        $token = $client->fetchAccessTokenWithAuthCode($code);

        if (isset($token['error'])) {
            throw new RuntimeException('Gagal menukar token Google: '.($token['error_description'] ?? $token['error']));
        }

        $scope = $token['scope'] ?? '';
        if ($scope !== '' && ! str_contains($scope, 'drive')) {
            throw new RuntimeException('Izin akses Google Drive tidak dicentang. Harap centang izin Google Drive saat login.');
        }

        $client->setAccessToken($token);
        $profile = $this->fetchProfile($client);
        $refreshToken = $token['refresh_token'] ?? null;

        $accounts = $this->accounts();
        $found = false;

        foreach ($accounts as &$acc) {
            if (strcasecmp($acc['email'] ?? '', $profile['email']) === 0) {
                $acc['refresh_token'] = $refreshToken ?: $acc['refresh_token'];
                $acc['name'] = $profile['name'] ?: ($acc['name'] ?? '');
                $acc['avatar'] = $profile['avatar'] ?: ($acc['avatar'] ?? null);
                $acc['connected_at'] = now()->toDateTimeString();
                $found = true;
                break;
            }
        }
        unset($acc);

        if (! $found) {
            if (! $refreshToken) {
                throw new RuntimeException('Google tidak mengirimkan refresh token. Silakan coba hubungkan ulang.');
            }

            $accounts[] = [
                'id' => 'acc_'.Str::lower(Str::random(10)),
                'email' => $profile['email'],
                'name' => $profile['name'],
                'avatar' => $profile['avatar'],
                'refresh_token' => $refreshToken,
                'folder_id' => null,
                'connected_at' => now()->toDateTimeString(),
            ];
        }

        $this->save($accounts);

        return $profile;
    }

    public function disconnect(?string $id): void
    {
        $accounts = $id
            ? array_values(array_filter($this->accounts(), fn ($a) => $a['id'] !== $id))
            : [];

        $this->save($accounts);
    }

    public function updateFolder(string $id, ?string $folderId): bool
    {
        $accounts = $this->accounts();
        $updated = false;

        foreach ($accounts as &$acc) {
            if ($acc['id'] === $id) {
                $acc['folder_id'] = $folderId ?: null;
                $updated = true;
            }
        }
        unset($acc);

        if ($updated) {
            $this->save($accounts);
        }

        return $updated;
    }

    /** Buat disk Flysystem Google Drive untuk satu akun. */
    public function disk(array $account): Filesystem
    {
        return Storage::build([
            'driver' => 'gdrive-backup',
            'clientId' => config('gdrive-backup.google.client_id'),
            'clientSecret' => config('gdrive-backup.google.client_secret'),
            'refreshToken' => $account['refresh_token'],
            'folderId' => $account['folder_id'] ?: '/',
        ]);
    }

    protected function save(array $accounts): void
    {
        $stored = array_map(function ($acc) {
            $acc['refresh_token'] = Crypt::encryptString($acc['refresh_token']);

            return $acc;
        }, array_values($accounts));

        BackupSetting::putJson(self::SETTING_KEY, $stored);
        Cache::forget(BackupRunner::STATUS_CACHE_KEY);
    }

    protected function client(): GoogleClient
    {
        $client = new GoogleClient();
        $client->setClientId(config('gdrive-backup.google.client_id'));
        $client->setClientSecret(config('gdrive-backup.google.client_secret'));

        return $client;
    }

    protected function fetchProfile(GoogleClient $client): array
    {
        $profile = ['email' => 'Akun Google', 'name' => '', 'avatar' => null];

        try {
            $user = (new \Google\Service\Oauth2($client))->userinfo->get();
            $profile['email'] = $user->email ?: $profile['email'];
            $profile['name'] = $user->name ?: '';
            $profile['avatar'] = $user->picture ?: null;

            return $profile;
        } catch (\Throwable) {
            // lanjut ke fallback Drive API
        }

        try {
            $about = (new Drive($client))->about->get(['fields' => 'user(displayName,emailAddress,photoLink)']);
            if ($user = $about->getUser()) {
                $profile['email'] = $user->getEmailAddress() ?: $profile['email'];
                $profile['name'] = $user->getDisplayName() ?: '';
                $profile['avatar'] = $user->getPhotoLink() ?: null;
            }
        } catch (\Throwable) {
            // biarkan default
        }

        return $profile;
    }

    protected function ensureConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('GOOGLE_DRIVE_CLIENT_ID / GOOGLE_DRIVE_CLIENT_SECRET belum diatur di .env server.');
        }
    }
}
