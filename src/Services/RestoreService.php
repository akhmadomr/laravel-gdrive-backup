<?php

namespace Akhmadomr\GdriveBackup\Services;

use Akhmadomr\GdriveBackup\Exceptions\RestoreRejectedException;
use Akhmadomr\GdriveBackup\Models\RestoreHistory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ZipArchive;

class RestoreService
{
    public function __construct(
        protected ManifestService $manifest,
        protected SqlImporter $importer,
    ) {
    }

    /**
     * Jalankan restore dari file ZIP.
     *
     * @return array{status: string, reference: ?string}
     *                status: success | rejected | failed
     */
    public function restore(string $zipPath, string $originalName, ?object $user = null): array
    {
        $reference = strtoupper(Str::random(6));
        $workDir = storage_path('app/gdrive-backup-tmp/restore-'.Str::random(12));

        $lock = Cache::lock('gdrive-backup:running', 900);
        if (! $lock->get()) {
            return $this->finish('failed', $reference, 'Proses backup/restore lain sedang berjalan.', $originalName, $user);
        }

        try {
            @mkdir($workDir, 0755, true);
            [$manifest, $sqlPath] = $this->extract($zipPath, $workDir);

            $this->manifest->verify($manifest, $sqlPath);

            @set_time_limit(0);
            $this->importer->import($sqlPath);
            $this->afterRestore($manifest);

            return $this->finish('success', null, null, $originalName, $user);
        } catch (RestoreRejectedException $e) {
            return $this->finish('rejected', $reference, $e->getMessage(), $originalName, $user);
        } catch (\Throwable $e) {
            Log::error("[gdrive-backup] Restore error [{$reference}]", ['exception' => $e]);

            return $this->finish('failed', $reference, $e->getMessage(), $originalName, $user);
        } finally {
            if (is_dir($workDir)) {
                app('files')->deleteDirectory($workDir);
            }
            Cache::forget(BackupRunner::STATUS_CACHE_KEY);
            $lock->release();
        }
    }

    /**
     * Ambil hanya manifest.json & database.sql dari ZIP (tanpa extractTo,
     * sehingga aman dari serangan path traversal / zip slip).
     *
     * @return array{0: mixed, 1: string}
     */
    protected function extract(string $zipPath, string $workDir): array
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RestoreRejectedException('File bukan ZIP yang valid.');
        }

        try {
            if ($password = config('gdrive-backup.zip_password')) {
                $zip->setPassword($password);
            }

            $manifestRaw = $zip->getFromName(ManifestService::MANIFEST_FILE);
            if ($manifestRaw === false) {
                throw new RestoreRejectedException('manifest.json tidak ditemukan (bukan backup modul ini, atau password ZIP salah).');
            }

            $in = $zip->getStream(ManifestService::SQL_FILE);
            if (! $in) {
                throw new RestoreRejectedException('database.sql tidak ditemukan di dalam ZIP.');
            }

            $sqlPath = $workDir.'/'.ManifestService::SQL_FILE;
            $out = fopen($sqlPath, 'wb');
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);

            return [json_decode($manifestRaw, true), $sqlPath];
        } finally {
            $zip->close();
        }
    }

    protected function afterRestore(array $manifest): void
    {
        $connection = config('gdrive-backup.connection');

        if (config('gdrive-backup.migrate_after_restore', true)) {
            Artisan::call('migrate', ['--force' => true, '--database' => $connection]);
        }

        foreach (config('gdrive-backup.truncate_after_restore', []) as $table) {
            if (Schema::connection($connection)->hasTable($table)) {
                DB::connection($connection)->table($table)->truncate();
            }
        }

        foreach (config('gdrive-backup.after_restore', []) as $class) {
            app($class)($manifest);
        }

        try {
            Artisan::call('cache:clear');
        } catch (\Throwable) {
            // abaikan
        }
    }

    protected function finish(string $status, ?string $reference, ?string $reason, string $fileName, ?object $user): array
    {
        if ($status !== 'success') {
            Log::warning("[gdrive-backup] Restore {$status} [{$reference}] file={$fileName}: {$reason}");
        }

        try {
            RestoreHistory::create([
                'file_name' => mb_substr($fileName, 0, 255),
                'restored_by_name' => $user->name ?? 'Administrator',
                'restored_by_email' => $user->email ?? '-',
                'status' => $status,
                'reference' => $reference,
                'reason' => $reason,
                'restored_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('[gdrive-backup] Gagal mencatat riwayat restore: '.$e->getMessage());
        }

        return ['status' => $status, 'reference' => $reference];
    }
}
