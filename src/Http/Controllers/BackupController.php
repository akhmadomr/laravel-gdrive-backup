<?php

namespace Akhmadomr\GdriveBackup\Http\Controllers;

use Akhmadomr\GdriveBackup\Models\RestoreHistory;
use Akhmadomr\GdriveBackup\Services\BackupRunner;
use Akhmadomr\GdriveBackup\Services\GoogleAccountManager;
use Akhmadomr\GdriveBackup\Services\ManifestService;
use Akhmadomr\GdriveBackup\Services\RestoreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class BackupController extends Controller
{
    public function __construct(
        protected GoogleAccountManager $google,
        protected BackupRunner $runner,
        protected ManifestService $manifest,
    ) {
    }

    public function status(): JsonResponse
    {
        $accounts = $this->google->publicAccounts();
        $systemId = $this->manifest->systemId();

        return response()->json([
            'success' => true,
            'data' => [
                'is_configured' => $this->google->isConfigured(),
                'is_connected' => count($accounts) > 0,
                'accounts' => $accounts,
                'schedule' => $this->runner->schedule(),
                'status' => $this->runner->status(),
                'last_backup' => $this->runner->lastRun(),
                'system' => [
                    'app_name' => config('app.name'),
                    'is_ready' => (bool) $systemId,
                    'system_id_masked' => $systemId ? substr($systemId, 0, 4).'••••'.substr($systemId, -4) : null,
                ],
            ],
        ]);
    }

    public function connect(Request $request): JsonResponse
    {
        try {
            $returnTo = $request->query('return_to');
            $url = $this->google->createAuthUrl((string) optional($request->user())->getAuthIdentifier(), $returnTo);

            return response()->json(['success' => true, 'auth_url' => $url]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function callback(Request $request): RedirectResponse
    {
        $stateReturnTo = \Illuminate\Support\Facades\Cache::pull('gdrive-backup:oauth:return_to:'.$request->query('state'));
        $targetPath = $stateReturnTo ?: config('gdrive-backup.frontend_path', '/settings/backup');
        $target = rtrim((string) config('gdrive-backup.frontend_url'), '/').'/'.ltrim($targetPath, '/');

        if ($request->query('error')) {
            return redirect($target.'?google_auth=error&message='.urlencode('Otorisasi dibatalkan.'));
        }

        try {
            $this->google->handleCallback($request->query('code'), $request->query('state'));

            return redirect($target.'?google_auth=success');
        } catch (\Throwable $e) {
            Log::warning('[gdrive-backup] OAuth callback gagal: '.$e->getMessage());

            return redirect($target.'?google_auth=error&message='.urlencode($e->getMessage()));
        }
    }

    public function disconnect(Request $request): JsonResponse
    {
        $request->validate(['id' => 'nullable|string']);
        $this->google->disconnect($request->input('id'));

        return response()->json(['success' => true, 'message' => 'Akun Google Drive berhasil diputuskan.']);
    }

    public function updateFolder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => 'required|string',
            'folder_id' => ['nullable', 'string', 'max:200', 'regex:/^[A-Za-z0-9_-]*$/'],
        ]);

        if (! $this->google->updateFolder($data['id'], trim((string) ($data['folder_id'] ?? '')))) {
            return response()->json(['success' => false, 'message' => 'Akun Google Drive tidak ditemukan.'], 404);
        }

        return response()->json(['success' => true, 'message' => 'Folder tujuan berhasil diperbarui.']);
    }

    public function updateSchedule(Request $request): JsonResponse
    {
        $data = $request->validate([
            'schedule' => 'required|array',
            'schedule.frequency' => 'required|in:daily,weekly,monthly',
            'schedule.day' => 'nullable|integer|between:0,6',
            'schedule.date' => 'nullable|integer|between:1,31',
            'schedule.time' => ['required', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
        ]);

        $this->runner->saveSchedule($data['schedule']);

        return response()->json(['success' => true, 'message' => 'Jadwal backup otomatis berhasil disimpan.']);
    }

    public function runBackup(): JsonResponse
    {
        if (! $this->manifest->systemId()) {
            return response()->json(['success' => false, 'message' => 'Modul backup belum selesai dipasang. Hubungi developer.'], 422);
        }

        if (empty($this->runner->destinations())) {
            return response()->json(['success' => false, 'message' => 'Belum ada tujuan backup. Hubungkan minimal satu akun Google Drive.'], 422);
        }

        // Jalankan setelah response terkirim agar UI tidak menunggu lama.
        app()->terminating(function () {
            ignore_user_abort(true);
            @set_time_limit(0);

            try {
                app(BackupRunner::class)->run();
            } catch (\Throwable) {
                // sudah dicatat di log oleh BackupRunner
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Backup sedang diproses di latar belakang. Muat ulang halaman ini dalam 1-2 menit.',
        ]);
    }

    public function restore(Request $request, RestoreService $restore): JsonResponse
    {
        $messages = config('gdrive-backup.messages');
        $file = $request->file('backup_file');

        if (! $file || ! $file->isValid()) {
            return response()->json(['success' => false, 'message' => $messages['upload_too_large']], 422);
        }

        $request->validate([
            'backup_file' => ['required', 'file', 'extensions:zip', 'max:'.(int) config('gdrive-backup.max_upload_kb')],
        ]);

        @ini_set('memory_limit', '512M');

        $result = $restore->restore($file->getRealPath(), $file->getClientOriginalName(), $request->user());

        return match ($result['status']) {
            'success' => response()->json(['success' => true, 'message' => $messages['restore_success']]),
            'rejected' => response()->json([
                'success' => false,
                'message' => str_replace(':ref', $result['reference'], $messages['restore_rejected']),
                'reference' => $result['reference'],
            ], 422),
            default => response()->json([
                'success' => false,
                'message' => str_replace(':ref', $result['reference'], $messages['restore_failed']),
                'reference' => $result['reference'],
            ], 500),
        };
    }

    public function restoreHistory(): JsonResponse
    {
        $data = Schema::hasTable('gdrive_backup_restore_histories')
            ? RestoreHistory::query()->latest('restored_at')->take(10)->get()
            : [];

        return response()->json(['success' => true, 'data' => $data]);
    }
}
