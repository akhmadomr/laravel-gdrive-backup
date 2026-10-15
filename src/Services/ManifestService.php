<?php

namespace Akhmadomr\GdriveBackup\Services;

use Akhmadomr\GdriveBackup\Exceptions\RestoreRejectedException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ManifestService
{
    public const FORMAT_VERSION = 1;

    public const PACKAGE_VERSION = '1.0.0';

    public const SQL_FILE = 'database.sql';

    public const MANIFEST_FILE = 'manifest.json';

    public function systemId(): ?string
    {
        $id = config('gdrive-backup.system_id');

        return is_string($id) && $id !== '' ? $id : null;
    }

    public function build(string $sqlPath): array
    {
        $connection = config('gdrive-backup.connection');

        return [
            'format_version' => self::FORMAT_VERSION,
            'system_id' => $this->systemId(),
            'app_name' => config('app.name'),
            'created_at' => now()->toIso8601String(),
            'database' => [
                'driver' => config("database.connections.{$connection}.driver"),
                'name' => config("database.connections.{$connection}.database"),
            ],
            'sql_file' => self::SQL_FILE,
            'sql_sha256' => hash_file('sha256', $sqlPath),
            'migrations' => $this->appliedMigrations(),
            'package_version' => self::PACKAGE_VERSION,
        ];
    }

    /**
     * Validasi manifest sebelum restore. Setiap kegagalan melempar
     * RestoreRejectedException dengan alasan internal (untuk log).
     */
    public function verify(mixed $manifest, string $sqlPath): void
    {
        if (! is_array($manifest) || ($manifest['format_version'] ?? null) !== self::FORMAT_VERSION) {
            throw new RestoreRejectedException('Manifest tidak valid atau format_version tidak didukung.');
        }

        $localId = $this->systemId();
        if (! $localId) {
            throw new RestoreRejectedException('system_id belum diatur di config/gdrive-backup.php (jalankan gdrive-backup:install).');
        }

        if (! is_string($manifest['system_id'] ?? null) || ! hash_equals($localId, $manifest['system_id'])) {
            throw new RestoreRejectedException(sprintf(
                'system_id berbeda. backup=%s app=%s (app_name backup: %s)',
                $manifest['system_id'] ?? 'null',
                $localId,
                $manifest['app_name'] ?? '-'
            ));
        }

        $expected = $manifest['sql_sha256'] ?? '';
        if (! is_string($expected) || ! hash_equals($expected, (string) hash_file('sha256', $sqlPath))) {
            throw new RestoreRejectedException('Checksum SHA-256 database.sql tidak cocok (file rusak/diubah).');
        }

        if (config('gdrive-backup.verify_migrations', true)) {
            $backupMigrations = is_array($manifest['migrations'] ?? null) ? $manifest['migrations'] : [];
            $knownMigrations = array_unique(array_merge(
                $this->availableMigrations(),
                $this->appliedMigrations()
            ));

            $knownBaseNames = array_map(
                fn (string $name) => preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', $name),
                $knownMigrations
            );

            $unknown = [];
            foreach ($backupMigrations as $migration) {
                if (in_array($migration, $knownMigrations, true)) {
                    continue;
                }

                $baseName = preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', $migration);
                if (in_array($baseName, $knownBaseNames, true)) {
                    continue;
                }

                $unknown[] = $migration;
            }

            if (! empty($unknown)) {
                throw new RestoreRejectedException(
                    'Backup berasal dari versi aplikasi yang lebih baru. Migration tidak dikenal: '.implode(', ', array_slice($unknown, 0, 10))
                );
            }
        }
    }

    /** Daftar migration yang sudah dijalankan di database saat ini. */
    public function appliedMigrations(): array
    {
        $table = $this->migrationsTable();
        $connection = config('gdrive-backup.connection');

        if (! Schema::connection($connection)->hasTable($table)) {
            return [];
        }

        return DB::connection($connection)->table($table)->orderBy('id')->pluck('migration')->all();
    }

    /** Daftar file migration yang dimiliki aplikasi (kode) saat ini. */
    public function availableMigrations(): array
    {
        $migrator = app('migrator');
        $paths = array_merge([database_path('migrations')], $migrator->paths());

        return array_keys($migrator->getMigrationFiles($paths));
    }

    protected function migrationsTable(): string
    {
        $config = config('database.migrations');

        return is_array($config) ? ($config['table'] ?? 'migrations') : ($config ?: 'migrations');
    }
}
