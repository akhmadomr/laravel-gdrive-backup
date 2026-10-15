<?php

namespace Akhmadomr\GdriveBackup\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Import file .sql secara streaming (per statement), sehingga file besar
 * tidak perlu dimuat seluruhnya ke memori.
 */
class SqlImporter
{
    public function import(string $sqlPath): void
    {
        $handle = fopen($sqlPath, 'rb');
        if (! $handle) {
            throw new RuntimeException('Tidak dapat membuka file SQL.');
        }

        $db = DB::connection(config('gdrive-backup.connection'));
        $delimiter = ';';
        $buffer = '';

        $db->unprepared('SET FOREIGN_KEY_CHECKS=0');

        try {
            while (($line = fgets($handle)) !== false) {
                $trimmed = trim($line);

                if ($buffer === '' && ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#'))) {
                    continue;
                }

                if ($buffer === '' && preg_match('/^DELIMITER\s+(\S+)/i', $trimmed, $m)) {
                    $delimiter = $m[1];
                    continue;
                }

                $buffer .= $line;

                if (str_ends_with(rtrim($line), $delimiter)) {
                    $statement = substr(rtrim($buffer), 0, -strlen($delimiter));
                    if (trim($statement) !== '') {
                        $db->unprepared($statement);
                    }
                    $buffer = '';
                }
            }

            if (trim($buffer) !== '') {
                $db->unprepared($buffer);
            }
        } finally {
            fclose($handle);
            $db->unprepared('SET FOREIGN_KEY_CHECKS=1');
        }
    }
}
