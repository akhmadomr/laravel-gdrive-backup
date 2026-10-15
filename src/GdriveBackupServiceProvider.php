<?php

namespace Akhmadomr\GdriveBackup;

use Akhmadomr\GdriveBackup\Console\InstallCommand;
use Akhmadomr\GdriveBackup\Console\RunBackupCommand;
use Akhmadomr\GdriveBackup\Models\BackupSetting;
use Akhmadomr\GdriveBackup\Services\BackupRunner;
use Akhmadomr\GdriveBackup\Services\DatabaseDumper;
use Akhmadomr\GdriveBackup\Services\GoogleAccountManager;
use Akhmadomr\GdriveBackup\Services\ManifestService;
use Akhmadomr\GdriveBackup\Services\RestoreService;
use Akhmadomr\GdriveBackup\Services\SqlImporter;
use Google\Client as GoogleClient;
use Google\Service\Drive as GoogleDriveService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem as Flysystem;
use Masbug\Flysystem\GoogleDriveAdapter;

class GdriveBackupServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/gdrive-backup.php', 'gdrive-backup');

        $this->app->singleton(DatabaseDumper::class);
        $this->app->singleton(SqlImporter::class);
        $this->app->singleton(ManifestService::class);
        $this->app->singleton(GoogleAccountManager::class);
        $this->app->singleton(BackupRunner::class);
        $this->app->singleton(RestoreService::class);
    }

    public function boot(): void
    {
        // 1. Register Flysystem Google Drive Driver
        $this->registerFlysystemDriver();

        // 2. Publish Config & Migrations
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/gdrive-backup.php' => config_path('gdrive-backup.php'),
            ], 'gdrive-backup-config');

            $existingMigrations = glob(database_path('migrations/*_create_gdrive_backup_tables.php'));
            $migrationTarget = ! empty($existingMigrations)
                ? $existingMigrations[0]
                : database_path('migrations/' . date('Y_m_d_His') . '_create_gdrive_backup_tables.php');

            $this->publishes([
                __DIR__ . '/../database/migrations/create_gdrive_backup_tables.php' => $migrationTarget,
            ], 'gdrive-backup-migrations');

            $feTarget = is_dir(resource_path('js')) ? resource_path('js') : base_path('src');
            $this->publishes([
                __DIR__ . '/../resources/frontend' => $feTarget,
            ], 'gdrive-backup-frontend');

            $this->commands([
                InstallCommand::class,
                RunBackupCommand::class,
            ]);
        }

        // Auto load migrations only if not published to database/migrations
        $hasPublished = !empty(glob(database_path('migrations/*_create_gdrive_backup_tables.php')));
        if (!$hasPublished) {
            $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        }

        // 3. Register Routes
        if (config('gdrive-backup.route.enabled', true)) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');
        }

        // 4. Register Automated Schedule
        $this->registerSchedule();
    }

    protected function registerFlysystemDriver(): void
    {
        Storage::extend('gdrive-backup', function ($app, $config) {
            if (empty($config['clientId']) || empty($config['clientSecret']) || empty($config['refreshToken'])) {
                throw new \RuntimeException('Kredensial Google Drive tidak lengkap (clientId, clientSecret, atau refreshToken).');
            }

            $client = new GoogleClient();
            $client->setClientId($config['clientId']);
            $client->setClientSecret($config['clientSecret']);

            $accessToken = $client->fetchAccessTokenWithRefreshToken($config['refreshToken']);
            if (isset($accessToken['error'])) {
                throw new \RuntimeException('Google OAuth error: ' . ($accessToken['error_description'] ?? $accessToken['error']));
            }
            $client->setAccessToken($accessToken);

            $service = new GoogleDriveService($client);
            $options = [];
            if (!empty($config['teamDriveId'])) {
                $options['teamDriveId'] = $config['teamDriveId'];
            }

            $adapter = new GoogleDriveAdapter($service, $config['folderId'] ?? '/', $options);
            $driver = new Flysystem($adapter);

            return new FilesystemAdapter($driver, $adapter, $config);
        });
    }

    protected function registerSchedule(): void
    {
        $this->app->booted(function () {
            if (!config('gdrive-backup.schedule.enabled', true)) {
                return;
            }

            try {
                if (!Schema::hasTable('gdrive_backup_settings')) {
                    return;
                }

                $accounts = BackupSetting::getJson(GoogleAccountManager::SETTING_KEY, []);
                if (empty($accounts)) {
                    return; // Tidak menjadwalkan jika belum ada akun Google Drive terhubung
                }

                $scheduleData = BackupSetting::getJson(BackupRunner::SCHEDULE_KEY, config('gdrive-backup.schedule.default', []));
                $frequency = $scheduleData['frequency'] ?? 'daily';
                $time = $scheduleData['time'] ?? '01:30';
                $timezone = config('gdrive-backup.schedule.timezone', config('app.timezone', 'Asia/Jakarta'));

                $schedule = $this->app->make(Schedule::class);
                $event = $schedule->command('gdrive-backup:run')->timezone($timezone);

                if ($frequency === 'daily') {
                    $event->dailyAt($time);
                } elseif ($frequency === 'weekly') {
                    $day = $scheduleData['day'] ?? 1;
                    $event->weeklyOn($day, $time);
                } elseif ($frequency === 'monthly') {
                    $date = $scheduleData['date'] ?? 1;
                    $event->monthlyOn($date, $time);
                } else {
                    $event->dailyAt('01:30');
                }
            } catch (\Throwable $e) {
                // Ignore during early bootstrap/migrations
            }
        });
    }
}
