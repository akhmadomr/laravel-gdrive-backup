<?php

namespace Akhmadomr\GdriveBackup\Services;

use Ifsnop\Mysqldump\Mysqldump;
use RuntimeException;

/**
 * Dump database MySQL memakai ifsnop/mysqldump-php (murni PHP, tidak butuh
 * binary mysqldump / proc_open, sehingga aman di shared hosting).
 */
class DatabaseDumper
{
    /** Tabel milik modul yang tidak pernah ikut di-backup. */
    public const INTERNAL_TABLES = [
        'gdrive_backup_settings',
        'gdrive_backup_restore_histories',
    ];

    public function dump(string $targetPath): void
    {
        $connection = config('gdrive-backup.connection');
        $db = config("database.connections.{$connection}");

        if (! $db || ! in_array($db['driver'] ?? null, ['mysql', 'mariadb'], true)) {
            throw new RuntimeException("Koneksi [{$connection}] bukan MySQL/MariaDB.");
        }

        $dsn = ! empty($db['unix_socket'])
            ? "mysql:unix_socket={$db['unix_socket']};dbname={$db['database']}"
            : sprintf('mysql:host=%s;port=%s;dbname=%s', $db['host'] ?? '127.0.0.1', $db['port'] ?? 3306, $db['database']);

        $exclude = array_values(array_unique(array_merge(
            config('gdrive-backup.exclude_tables', []),
            self::INTERNAL_TABLES
        )));

        $dump = new Mysqldump($dsn, $db['username'] ?? '', $db['password'] ?? '', [
            'add-drop-table' => true,
            'single-transaction' => true,
            'skip-definer' => true,
            'skip-tz-utc' => false,
            'default-character-set' => Mysqldump::UTF8MB4,
            'exclude-tables' => $exclude,
            'no-data' => array_values(config('gdrive-backup.exclude_table_data', [])),
        ]);

        $dump->start($targetPath);
    }
}
