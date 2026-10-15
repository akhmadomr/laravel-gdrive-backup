<?php

namespace Akhmadomr\GdriveBackup\Console;

use Akhmadomr\GdriveBackup\Services\BackupRunner;
use Illuminate\Console\Command;

class RunBackupCommand extends Command
{
    protected $signature = 'gdrive-backup:run';

    protected $description = 'Backup database ke disk lokal & semua akun Google Drive yang terhubung';

    public function handle(BackupRunner $runner): int
    {
        $this->info('Memulai backup database...');

        try {
            $result = $runner->run();
        } catch (\Throwable $e) {
            $this->error('Backup gagal: '.$e->getMessage());

            return self::FAILURE;
        }

        foreach ($result['destinations'] as $dest) {
            $label = $dest['label'].($dest['email'] ? " ({$dest['email']})" : '');
            $dest['ok'] ? $this->line("  <info>✔</info> {$label}") : $this->line("  <error>✘</error> {$label} (lihat log)");
        }

        $this->info('Selesai: '.$result['file']);

        return $result['success'] ? self::SUCCESS : self::FAILURE;
    }
}
