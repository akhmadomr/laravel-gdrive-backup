<?php

namespace Akhmadomr\GdriveBackup\Services;

use Akhmadomr\GdriveBackup\Models\BackupSetting;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Alur backup: dump database → ZIP (database.sql + manifest.json) → upload ke
 * semua tujuan (lokal + setiap akun Google Drive) → hapus backup lama (retensi).
 */
class BackupRunner
{
    public const STATUS_CACHE_KEY = 'gdrive-backup:status';

    public const LAST_RUN_KEY = 'last_backup';

    public const SCHEDULE_KEY = 'schedule';

    public function __construct(
        protected DatabaseDumper $dumper,
        protected ManifestService $manifest,
        protected GoogleAccountManager $google,
    ) {
    }

    /**
     * @return array{success: bool, file: ?string, destinations: array}
     */
    public function run(): array
    {
        $lock = Cache::lock('gdrive-backup:running', 900);
        if (! $lock->get()) {
            throw new RuntimeException('Proses backup lain sedang berjalan.');
        }

        $workDir = storage_path('app/gdrive-backup-tmp/'.Str::random(12));

        try {
            if (! $this->manifest->systemId()) {
                throw new RuntimeException('system_id belum diatur. Jalankan: php artisan gdrive-backup:install');
            }

            @mkdir($workDir, 0755, true);
            $sqlPath = $workDir.'/'.ManifestService::SQL_FILE;
            $this->dumper->dump($sqlPath);

            $fileName = $this->getFilePrefix().'-'.now()->format('Y-m-d-H-i-s').'.zip';
            $zipPath = $workDir.'/'.$fileName;
            $this->makeZip($zipPath, $sqlPath, $this->manifest->build($sqlPath));

            $results = [];
            foreach ($this->destinations() as $dest) {
                $results[] = $this->uploadTo($dest, $zipPath, $fileName);
            }

            $success = collect($results)->contains('ok', true);
            $summary = [
                'at' => now()->toDateTimeString(),
                'success' => $success,
                'file' => $fileName,
                'destinations' => $results,
            ];

            BackupSetting::putJson(self::LAST_RUN_KEY, $summary);

            return $summary;
        } catch (\Throwable $e) {
            Log::error('[gdrive-backup] Backup gagal: '.$e->getMessage(), ['exception' => $e]);
            BackupSetting::putJson(self::LAST_RUN_KEY, [
                'at' => now()->toDateTimeString(),
                'success' => false,
                'file' => null,
                'destinations' => [],
            ]);

            throw $e;
        } finally {
            $this->deleteDirectory($workDir);
            Cache::forget(self::STATUS_CACHE_KEY);
            $lock->release();
        }
    }

    /** Status setiap tujuan backup (di-cache 5 menit karena listing Drive lambat). */
    public function status(): array
    {
        return Cache::remember(self::STATUS_CACHE_KEY, 300, function () {
            $maxAgeDays = match ($this->schedule()['frequency'] ?? 'daily') {
                'weekly' => 8,
                'monthly' => 32,
                default => 2,
            };

            $info = [];
            foreach ($this->destinations() as $dest) {
                $item = [
                    'key' => $dest['key'],
                    'type' => $dest['type'],
                    'label' => $dest['label'],
                    'email' => $dest['email'],
                    'folder_id' => $dest['folder_id'],
                    'amount' => 0,
                    'newest_backup_date' => null,
                    'used_storage' => '0 MB',
                    'is_healthy' => false,
                    'reachable' => true,
                ];

                try {
                    $disk = ($dest['disk'])();
                    $files = $this->zipFiles($disk);
                    $bytes = array_sum(array_map(fn ($f) => $disk->size($f), $files));
                    $newest = end($files) ?: null;
                    $newestTs = $newest ? $disk->lastModified($newest) : null;

                    $item['amount'] = count($files);
                    $item['used_storage'] = round($bytes / 1024 / 1024, 2).' MB';
                    $item['newest_backup_date'] = $newestTs
                        ? now()->setTimestamp($newestTs)->timezone(config('app.timezone'))->format('Y-m-d H:i:s')
                        : null;
                    $item['is_healthy'] = $newestTs && (time() - $newestTs) < $maxAgeDays * 86400;
                } catch (\Throwable $e) {
                    $item['reachable'] = false;
                    Log::warning('[gdrive-backup] Tidak bisa membaca tujuan '.$dest['key'].': '.$e->getMessage());
                }

                $info[] = $item;
            }

            return $info;
        });
    }

    public function schedule(): array
    {
        $saved = BackupSetting::getJson(self::SCHEDULE_KEY);

        return array_merge(config('gdrive-backup.schedule.default'), is_array($saved) ? $saved : []);
    }

    public function saveSchedule(array $schedule): void
    {
        BackupSetting::putJson(self::SCHEDULE_KEY, $schedule);
        Cache::forget(self::STATUS_CACHE_KEY);
    }

    public function lastRun(): ?array
    {
        return BackupSetting::getJson(self::LAST_RUN_KEY);
    }

    /**
     * Semua tujuan backup: disk lokal (opsional) + tiap akun Google Drive.
     * Disk dibuat lazy (closure) supaya kegagalan satu akun tidak menggagalkan yang lain.
     */
    public function destinations(): array
    {
        $list = [];

        if ($local = config('gdrive-backup.local_disk')) {
            $list[] = [
                'key' => 'local',
                'type' => 'local',
                'label' => 'Server Lokal',
                'email' => null,
                'folder_id' => null,
                'disk' => fn () => Storage::disk($local),
            ];
        }

        foreach ($this->google->accounts() as $acc) {
            $list[] = [
                'key' => 'google:'.$acc['id'],
                'type' => 'google',
                'label' => 'Google Drive',
                'email' => $acc['email'] ?? null,
                'folder_id' => $acc['folder_id'] ?? null,
                'disk' => fn () => $this->google->disk($acc),
            ];
        }

        return $list;
    }

    protected function uploadTo(array $dest, string $zipPath, string $fileName): array
    {
        $folder = $this->getBackupFolderName();

        try {
            /** @var Filesystem $disk */
            $disk = ($dest['disk'])();

            try {
                if (! $disk->directoryExists($folder)) {
                    $disk->makeDirectory($folder);
                }
            } catch (\Throwable) {
                // sebagian adapter melempar error walau folder sudah ada
            }

            $stream = fopen($zipPath, 'rb');
            try {
                $disk->writeStream($folder.'/'.$fileName, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            $this->applyRetention($disk);

            return ['key' => $dest['key'], 'label' => $dest['label'], 'email' => $dest['email'], 'ok' => true];
        } catch (\Throwable $e) {
            Log::error("[gdrive-backup] Upload ke {$dest['key']} gagal: ".$e->getMessage());

            return ['key' => $dest['key'], 'label' => $dest['label'], 'email' => $dest['email'], 'ok' => false];
        }
    }

    protected function applyRetention(Filesystem $disk): void
    {
        $keep = max(1, (int) config('gdrive-backup.retention', 3));
        $files = $this->zipFiles($disk);

        foreach (array_slice($files, 0, max(0, count($files) - $keep)) as $old) {
            $disk->delete($old);
        }
    }

    /** File ZIP di folder backup, diurutkan dari terlama ke terbaru (nama berformat tanggal). */
    protected function zipFiles(Filesystem $disk): array
    {
        $folder = $this->getBackupFolderName();
        $files = array_values(array_filter(
            $disk->files($folder),
            fn ($f) => str_ends_with(strtolower($f), '.zip')
        ));
        sort($files);

        return $files;
    }

    public function getFilePrefix(): string
    {
        if ($prefix = config('gdrive-backup.file_prefix')) {
            return Str::slug($prefix);
        }

        $appName = config('app.name');
        if (!empty($appName) && strtolower($appName) !== 'laravel') {
            return Str::slug($appName);
        }

        return Str::slug(basename(base_path()));
    }

    public function getBackupFolderName(): string
    {
        if ($folder = config('gdrive-backup.folder_name')) {
            return $folder;
        }

        return $this->getFilePrefix() . '-backup';
    }

    protected function makeZip(string $zipPath, string $sqlPath, array $manifest): void
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Gagal membuat file ZIP.');
        }

        $zip->addFile($sqlPath, ManifestService::SQL_FILE);
        $zip->addFromString(ManifestService::MANIFEST_FILE, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if ($password = config('gdrive-backup.zip_password')) {
            $zip->setPassword($password);
            $zip->setEncryptionName(ManifestService::SQL_FILE, ZipArchive::EM_AES_256);
            $zip->setEncryptionName(ManifestService::MANIFEST_FILE, ZipArchive::EM_AES_256);
        }

        $zip->close();
    }

    protected function deleteDirectory(string $dir): void
    {
        if (is_dir($dir)) {
            app('files')->deleteDirectory($dir);
        }
    }
}
