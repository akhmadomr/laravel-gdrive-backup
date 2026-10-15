<?php

namespace Akhmadomr\GdriveBackup\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class InstallCommand extends Command
{
    protected $signature = 'gdrive-backup:install 
        {--force : Overwrite existing config file}
        {--with-fe : Paksa pasang komponen Frontend (Vue)}
        {--without-fe : Lewati pemasangan Frontend}';

    protected $description = 'Install and configure Laravel GDrive Backup module (supports Monorepo & Separate repo)';

    public function handle(): int
    {
        $this->info('🚀 Memasang modul GDrive Backup (akhmadomr/laravel-gdrive-backup)...');

        // 1. Publish config
        $configPath = config_path('gdrive-backup.php');
        $uuid = (string) Str::uuid();

        if (!file_exists($configPath) || $this->option('force')) {
            $this->call('vendor:publish', [
                '--tag' => 'gdrive-backup-config',
                '--force' => (bool) $this->option('force'),
            ]);

            // Inject generated UUID into config file
            if (file_exists($configPath)) {
                $content = file_get_contents($configPath);
                $content = preg_replace("/'system_id'\s*=>\s*null,/", "'system_id' => '{$uuid}',", $content);
                file_put_contents($configPath, $content);
                $this->info("✔ Config berhasil di-publish dengan System ID unik: {$uuid}");
            }
        } else {
            // Check if existing config already has system_id
            $existingConfig = file_get_contents($configPath);
            if (preg_match("/'system_id'\s*=>\s*null,/", $existingConfig)) {
                $existingConfig = preg_replace("/'system_id'\s*=>\s*null,/", "'system_id' => '{$uuid}',", $existingConfig);
                file_put_contents($configPath, $existingConfig);
                $this->info("✔ System ID unik ditambahkan ke config yang sudah ada: {$uuid}");
            } else {
                $this->line('ℹ Config sudah memiliki system_id. Melewati pembuatan ID baru.');
            }
        }

        // Pastikan middleware config sesuai tipe project (web/auth vs api/sanctum)
        $this->ensureConfigMiddleware($configPath);

        // 2. Publish migrations
        $this->call('vendor:publish', [
            '--tag' => 'gdrive-backup-migrations',
        ]);
        $this->info('✔ Migration berhasil di-publish.');

        // 3. Offer to run migrate
        if ($this->confirm('Jalankan migrasi database sekarang?', true)) {
            $this->call('migrate');
        }

        // 4. Optimasi Google API Client Services agar tidak berat/stuck
        $this->optimizeGoogleServices();

        // 5. Deteksi Monorepo dan tawarkan pasang Frontend (Vue) dalam 1 langkah
        $this->handleFrontendInstallation();

        // 6. Check and append Google Drive keys in .env
        $this->ensureEnvVariables();

        $this->newLine();
        $this->info('✨ Instalasi GDrive Backup selesai!');
        $this->comment('PENTING:');
        $this->line('1. Pastikan file <comment>config/gdrive-backup.php</comment> di-commit ke Git agar seluruh server menggunakan System ID yang sama.');
        $this->line('2. Isi <comment>GOOGLE_DRIVE_CLIENT_ID</comment> dan <comment>GOOGLE_DRIVE_CLIENT_SECRET</comment> di file <comment>.env</comment>.');
        $this->line('3. Pastikan scheduler cron aktif di server: <comment>* * * * * php artisan schedule:run >> /dev/null 2>&1</comment>');
        $this->newLine();

        return self::SUCCESS;
    }

    protected function optimizeGoogleServices(): void
    {
        $composerJsonPath = base_path('composer.json');
        if (!file_exists($composerJsonPath)) {
            return;
        }

        $composerData = json_decode(file_get_contents($composerJsonPath), true);
        if (!is_array($composerData)) {
            return;
        }

        $modified = false;

        // 1. Tambah script pre-autoload-dump
        if (!isset($composerData['scripts']['pre-autoload-dump'])) {
            $composerData['scripts']['pre-autoload-dump'] = 'Google\\Task\\Composer::cleanup';
            $modified = true;
        }

        // 2. Tambah extra google/apiclient-services filter
        if (!isset($composerData['extra']['google/apiclient-services'])) {
            $composerData['extra']['google/apiclient-services'] = ['Drive', 'Oauth2'];
            $modified = true;
        }

        if ($modified) {
            file_put_contents(
                $composerJsonPath,
                json_encode($composerData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
            );
            $this->info('✔ composer.json dioptimasi dengan Google Task Composer cleanup.');
        }

        // 3. Bersihkan file service yang tidak terpakai dari vendor jika ada
        $servicesDir = base_path('vendor/google/apiclient-services/src');
        if (is_dir($servicesDir)) {
            $items = scandir($servicesDir);
            $allowed = ['Drive', 'Oauth2', 'Drive.php', 'Oauth2.php', '.', '..'];
            $removedCount = 0;
            foreach ($items as $item) {
                if (!in_array($item, $allowed, true)) {
                    $itemPath = $servicesDir . DIRECTORY_SEPARATOR . $item;
                    $this->deleteDirectoryRecursive($itemPath);
                    $removedCount++;
                }
            }
            if ($removedCount > 0) {
                $this->info("✔ {$removedCount} folder service Google API yang tidak terpakai berhasil dibersihkan dari vendor.");
            }
        }
    }

    protected function handleFrontendInstallation(): void
    {
        if ($this->option('without-fe')) {
            return;
        }

        $packageJson = file_exists(base_path('package.json')) ? (string) file_get_contents(base_path('package.json')) : '';
        $composerJson = file_exists(base_path('composer.json')) ? (string) file_get_contents(base_path('composer.json')) : '';
        $isInertia = str_contains($packageJson, '@inertiajs') || str_contains($composerJson, 'inertiajs/inertia-laravel');

        $packageJsonExists = file_exists(base_path('package.json'));
        $resourcesJsExists = is_dir(resource_path('js'));
        $srcExists = is_dir(base_path('src'));

        $isMonorepo = $packageJsonExists && ($resourcesJsExists || $srcExists) && $isInertia;

        if (!$isMonorepo && !$this->option('with-fe')) {
            return;
        }

        $this->newLine();
        $this->comment('🔍 Terdeteksi project Monorepo (Laravel + Inertia Vue).');
        
        $shouldInstallFe = $this->option('with-fe') || $this->confirm('Apakah Anda ingin langsung memasang antarmuka Frontend (Vue) lengkap ke project ini?', true);

        if (!$shouldInstallFe) {
            return;
        }

        $feBase = $resourcesJsExists ? resource_path('js') : ($srcExists ? base_path('src') : resource_path('js'));
        $bundleDir = __DIR__ . '/../../resources/frontend';

        // Deteksi apakah Inertia (ada folder Pages dengan P kapital atau pages)
        $isCapitalPages = is_dir("{$feBase}/Pages");
        $pagesFolder = $isCapitalPages ? 'Pages/Settings' : 'pages/settings';
        $renderPath = $isCapitalPages ? 'Settings/BackupPage' : 'settings/BackupPage';

        $files = [
            [
                'from' => "{$bundleDir}/services/gdriveBackupApi.js",
                'to' => "{$feBase}/services/gdriveBackupApi.js",
            ],
            [
                'from' => "{$bundleDir}/composables/useGdriveBackup.js",
                'to' => "{$feBase}/composables/useGdriveBackup.js",
            ],
            [
                'from' => "{$bundleDir}/pages/settings/BackupPage.vue",
                'to' => "{$feBase}/{$pagesFolder}/BackupPage.vue",
            ],
        ];

        foreach ($files as $file) {
            $destDir = dirname($file['to']);
            if (!is_dir($destDir)) {
                mkdir($destDir, 0755, true);
            }
            copy($file['from'], $file['to']);
            $rel = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file['to']);
            $this->line("  <info>✔</info> Disalin: <comment>{$rel}</comment>");
        }

        // Sesuaikan Layout BackupPage.vue dengan layout project
        $this->adaptPageLayout("{$feBase}/{$pagesFolder}/BackupPage.vue", $feBase);

        $this->info('✔ File antarmuka Frontend (Vue) berhasil dipasang!');

        // Cek Inertia
        $packageJson = file_exists(base_path('package.json')) ? file_get_contents(base_path('package.json')) : '';
        $isInertia = str_contains($packageJson, '@inertiajs/vue3') || str_contains($packageJson, '@inertiajs/inertia-vue3');

        $this->newLine();
        if ($isInertia) {
            // Otomatis inject route ke routes/web.php
            $this->autoInjectInertiaRoute($renderPath);

            // Otomatis inject menu sidebar jika ada AppLayout.vue
            $this->autoInjectSidebarMenu($feBase);
        } else {
            $this->comment('💡 Petunjuk Route (Vue Router / SPA):');
            $this->line('Tambahkan route di router frontend Anda:');
            $this->line("  { path: '/settings/backup', component: () => import('@/{$pagesFolder}/BackupPage.vue') }");
        }
    }

    protected function adaptPageLayout(string $backupPagePath, string $feBase): void
    {
        if (!file_exists($backupPagePath)) {
            return;
        }

        $appLayoutPath = "{$feBase}/Layouts/AppLayout.vue";
        $authLayoutPath = "{$feBase}/Layouts/AuthenticatedLayout.vue";
        $breadcrumbPath = "{$feBase}/Components/Breadcrumb.vue";

        $content = file_get_contents($backupPagePath);

        // Jika sudah memiliki AppLayout / AuthenticatedLayout, lewati
        if (str_contains($content, '<AppLayout') || str_contains($content, '<AuthenticatedLayout')) {
            return;
        }

        $layoutComponent = null;
        $layoutImport = null;

        if (file_exists($appLayoutPath)) {
            $layoutComponent = 'AppLayout';
            $layoutImport = "import AppLayout from '@/Layouts/AppLayout.vue'";
        } elseif (file_exists($authLayoutPath)) {
            $layoutComponent = 'AuthenticatedLayout';
            $layoutImport = "import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'";
        }

        if (!$layoutComponent) {
            return;
        }

        $hasBreadcrumb = file_exists($breadcrumbPath);
        $breadcrumbSnippet = $hasBreadcrumb ? "
      <!-- Breadcrumb -->
      <Breadcrumb
        :items=\"[
          { label: 'Dashboard', route: 'dashboard' },
          { label: 'Backup Data', route: 'settings.backup' }
        ]\"
      />\n" : '';

        $breadcrumbImport = $hasBreadcrumb ? "import Breadcrumb from '@/Components/Breadcrumb.vue'\n" : '';

        // Bungkus template
        $content = preg_replace(
            '/<template>\s*<div class="space-y-6 pb-20/',
            "<template>\n  <{$layoutComponent} title=\"Backup Data\">\n    <div class=\"p-3 sm:p-6 space-y-6 pb-20{$breadcrumbSnippet}",
            $content
        );

        $content = preg_replace(
            '/<\/div>\s*<\/template>/',
            "    </div>\n  </{$layoutComponent}>\n</template>",
            $content
        );

        // Tambah import layout
        $content = preg_replace(
            '/<script setup>\s*import { ref, onMounted } from \'vue\'/',
            "<script setup>\nimport { ref, onMounted } from 'vue'\n{$layoutImport}\n{$breadcrumbImport}",
            $content
        );

        file_put_contents($backupPagePath, $content);
        $this->info("✔ BackupPage.vue otomatis dibungkus dengan <{$layoutComponent}> project Anda.");
    }

    protected function autoInjectInertiaRoute(string $renderPath): void
    {
        $webRoutesPath = base_path('routes/web.php');
        if (!file_exists($webRoutesPath)) {
            return;
        }

        $webContent = file_get_contents($webRoutesPath);

        if (str_contains($webContent, 'settings.backup') || str_contains($webContent, '/settings/backup')) {
            $this->line('ℹ Route /settings/backup sudah ada di routes/web.php.');
            return;
        }

        $routeSnippet = "\n// Ditambahkan otomatis oleh gdrive-backup:install
Route::middleware(['auth'])->group(function () {
    Route::get('/settings/backup', function () {
        return \\Inertia\\Inertia::render('{$renderPath}');
    })->name('settings.backup');
});\n";

        // Sisipkan sebelum fallback route jika ada, atau di akhir file
        if (str_contains($webContent, 'Route::fallback(')) {
            $webContent = preg_replace('/(Route::fallback\()/', "{$routeSnippet}\n$1", $webContent, 1);
        } else {
            $webContent .= $routeSnippet;
        }

        file_put_contents($webRoutesPath, $webContent);
        $this->info('✔ Route /settings/backup otomatis ditambahkan ke routes/web.php.');
    }

    protected function autoInjectSidebarMenu(string $feBase): void
    {
        $appLayoutPath = "{$feBase}/Layouts/AppLayout.vue";
        if (!file_exists($appLayoutPath)) {
            return;
        }

        $appContent = file_get_contents($appLayoutPath);

        if (str_contains($appContent, 'settings.backup')) {
            $this->line('ℹ Menu navigasi Backup Data sudah ada di AppLayout.vue.');
            return;
        }

        // Cek pola array mainMenu = [ ... ]
        if (str_contains($appContent, 'mainMenu') && str_contains($appContent, 'User Manajemen')) {
            $menuItem = "  {
    label: 'Backup Data',
    icon: 'pi-database',
    route: 'settings.backup',
    roles: ['admin'],
  },\n";

            $pattern = "/(label:\s*['\"]User Manajemen['\"].*?roles:\s*\[.*?\]\s*\},?\s*)/s";
            if (preg_match($pattern, $appContent)) {
                $appContent = preg_replace($pattern, "$1\n{$menuItem}", $appContent, 1);
                file_put_contents($appLayoutPath, $appContent);
                $this->info('✔ Menu "Backup Data" otomatis ditambahkan ke sidebar AppLayout.vue.');
            }
        }
    }

    protected function deleteDirectoryRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($path) ? $this->deleteDirectoryRecursive($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    protected function ensureEnvVariables(): void
    {
        $envPath = base_path('.env');
        if (!file_exists($envPath)) {
            return;
        }

        $envContent = file_get_contents($envPath);
        $variablesToAdd = [];

        // Cek file developer_credentials.json di folder Composable
        $devCredsPath = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'developer_credentials.json';
        $devClientId = '';
        $devClientSecret = '';
        if (file_exists($devCredsPath)) {
            $devCreds = json_decode(file_get_contents($devCredsPath), true);
            $devClientId = $devCreds['client_id'] ?? '';
            $devClientSecret = $devCreds['client_secret'] ?? '';
        }

        $appUrl = env('APP_URL', 'http://localhost:8000');
        $defaultRedirectUri = rtrim($appUrl, '/') . '/api/v1/backup/google/callback';

        if (!str_contains($envContent, 'GOOGLE_DRIVE_CLIENT_ID')) {
            $variablesToAdd[] = "GOOGLE_DRIVE_CLIENT_ID={$devClientId}";
        } elseif (!empty($devClientId) && preg_match('/GOOGLE_DRIVE_CLIENT_ID=\s*(\r?\n|$)/', $envContent)) {
            $envContent = preg_replace('/GOOGLE_DRIVE_CLIENT_ID=\s*(\r?\n|$)/', "GOOGLE_DRIVE_CLIENT_ID={$devClientId}$1", $envContent);
        }

        if (!str_contains($envContent, 'GOOGLE_DRIVE_CLIENT_SECRET')) {
            $variablesToAdd[] = "GOOGLE_DRIVE_CLIENT_SECRET={$devClientSecret}";
        } elseif (!empty($devClientSecret) && preg_match('/GOOGLE_DRIVE_CLIENT_SECRET=\s*(\r?\n|$)/', $envContent)) {
            $envContent = preg_replace('/GOOGLE_DRIVE_CLIENT_SECRET=\s*(\r?\n|$)/', "GOOGLE_DRIVE_CLIENT_SECRET={$devClientSecret}$1", $envContent);
        }

        if (!str_contains($envContent, 'GOOGLE_DRIVE_REDIRECT_URI')) {
            $variablesToAdd[] = "GOOGLE_DRIVE_REDIRECT_URI={$defaultRedirectUri}";
        } elseif (preg_match('/GOOGLE_DRIVE_REDIRECT_URI=\s*(\r?\n|$)/', $envContent)) {
            $envContent = preg_replace('/GOOGLE_DRIVE_REDIRECT_URI=\s*(\r?\n|$)/', "GOOGLE_DRIVE_REDIRECT_URI={$defaultRedirectUri}$1", $envContent);
        }

        if ($this->option('without-fe') && !str_contains($envContent, 'GDRIVE_BACKUP_FRONTEND_URL')) {
            $variablesToAdd[] = "# URL frontend SPA (jika berbeda dari APP_URL/FRONTEND_URL)\n# GDRIVE_BACKUP_FRONTEND_URL=http://localhost:5174";
        }

        if (!empty($variablesToAdd)) {
            $appendContent = "\n# Google Drive OAuth (gdrive-backup)\n" . implode("\n", $variablesToAdd) . "\n";
            file_put_contents($envPath, $envContent . $appendContent);
            $this->info('✔ Variabel GOOGLE_DRIVE_* ditambahkan ke .env.');
        } else {
            file_put_contents($envPath, $envContent);
        }

        if (!empty($devClientId)) {
            $this->info('✔ Kredensial developer Google Drive otomatis disinkronkan dari Composable ke .env!');
        }
    }

    protected function ensureConfigMiddleware(string $configPath): void
    {
        if (!file_exists($configPath)) {
            return;
        }

        $packageJson = file_exists(base_path('package.json')) ? (string) file_get_contents(base_path('package.json')) : '';
        $composerJson = file_exists(base_path('composer.json')) ? (string) file_get_contents(base_path('composer.json')) : '';
        $isInertia = str_contains($packageJson, '@inertiajs') || str_contains($composerJson, 'inertiajs/inertia-laravel');

        $isMonorepoInertia = !$this->option('without-fe') && $isInertia;

        $content = file_get_contents($configPath);

        if ($isMonorepoInertia) {
            // Untuk monorepo (Inertia/Session), gunakan ['web', 'auth']
            if (str_contains($content, "'api', 'auth:sanctum'") || str_contains($content, '"api", "auth:sanctum"')) {
                $content = str_replace(["'api', 'auth:sanctum'", '"api", "auth:sanctum"'], "'web', 'auth'", $content);
                $content = preg_replace("/'callback_middleware'\s*=>\s*\['api'\]/", "'callback_middleware' => ['web']", $content);
                file_put_contents($configPath, $content);
                $this->info("✔ Middleware route gdrive-backup otomatis disetel ke ['web', 'auth'] untuk Inertia Monorepo.");
            }
        } else {
            // Untuk SPA terpisah / API Backend: pastikan gunakan ['api', 'auth:sanctum']
            if (str_contains($content, "'web', 'auth'") || str_contains($content, '"web", "auth"')) {
                $content = str_replace(["'web', 'auth'", '"web", "auth"'], "'api', 'auth:sanctum'", $content);
                file_put_contents($configPath, $content);
                $this->info("✔ Middleware route gdrive-backup otomatis disetel ke ['api', 'auth:sanctum'] untuk API backend.");
            }
        }
    }
}
