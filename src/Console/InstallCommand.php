<?php

namespace Akhmadomr\GdriveBackup\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class InstallCommand extends Command
{
    protected $signature = 'gdrive-backup:install 
        {--force : Overwrite existing config file}
        {--with-fe : Paksa pasang komponen Frontend (Vue)}
        {--without-fe : Lewati pemasangan Frontend}
        {--frontend-path= : Path spesifik folder project frontend Vue}';

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

        // 5. Siapkan variabel Google OAuth di .env (nilai diisi user / developer_credentials.json lokal)
        $this->ensureEnvVariables();

        // 6. Deteksi Monorepo atau Separate Frontend dan pasang UI otomatis
        $this->handleFrontendInstallation();

        $this->newLine();
        $this->info('✨ Instalasi GDrive Backup selesai!');
        $this->comment('INFORMASI PENTING:');
        $this->line('1. Pastikan file <comment>config/gdrive-backup.php</comment> di-commit ke Git agar seluruh server menggunakan System ID yang sama.');
        $this->line('2. Pastikan <comment>GOOGLE_DRIVE_CLIENT_ID</comment> & <comment>GOOGLE_DRIVE_CLIENT_SECRET</comment> di .env sudah diisi dari Google Cloud Console.');
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
            $this->line('ℹ Opsi --without-fe aktif. Melewati pemasangan antarmuka Frontend.');
            return;
        }

        $packageJson = file_exists(base_path('package.json')) ? (string) file_get_contents(base_path('package.json')) : '';
        $composerJson = file_exists(base_path('composer.json')) ? (string) file_get_contents(base_path('composer.json')) : '';
        $isInertia = str_contains($packageJson, '@inertiajs') || str_contains($composerJson, 'inertiajs/inertia-laravel');

        $packageJsonExists = file_exists(base_path('package.json'));
        $resourcesJsExists = is_dir(resource_path('js'));
        $srcExists = is_dir(base_path('src'));

        $isMonorepo = $packageJsonExists && ($resourcesJsExists || $srcExists) && $isInertia;

        if ($isMonorepo) {
            $this->newLine();
            $this->comment('🔍 Terdeteksi project Monorepo (Laravel + Inertia Vue).');
            
            $shouldInstallFe = $this->option('with-fe') || $this->confirm('Apakah Anda ingin langsung memasang antarmuka Frontend (Vue) lengkap ke project ini?', true);

            if (!$shouldInstallFe) {
                return;
            }

            $this->installToMonorepo();
            return;
        }

        // Jika bukan Monorepo, cari project Frontend Vue terpisah di folder sekitar (Separate Repository)
        $siblingFe = $this->findSiblingFrontendProject();

        if ($siblingFe) {
            $this->newLine();
            $feName = basename($siblingFe);
            $this->comment("🔍 Terdeteksi project Frontend Vue terpisah: <info>{$siblingFe}</info>");
            
            $shouldInstall = $this->option('with-fe') || $this->confirm("Apakah Anda ingin langsung memasang komponen UI Backup ke frontend [{$feName}]?", true);

            if ($shouldInstall) {
                $this->installToVueSpa($siblingFe);
            }
            return;
        }

        if ($this->option('with-fe')) {
            $manualPath = $this->ask('Masukkan path folder project frontend Vue Anda:');
            if ($manualPath && is_dir($manualPath) && file_exists($manualPath . '/package.json')) {
                $this->installToVueSpa($manualPath);
            } else {
                $this->warn('Folder frontend tidak valid. Melewati pemasangan frontend.');
            }
        }
    }

    protected function findSiblingFrontendProject(): ?string
    {
        if ($this->option('frontend-path')) {
            $customPath = base_path($this->option('frontend-path'));
            if (!is_dir($customPath)) {
                $customPath = $this->option('frontend-path');
            }
            if (is_dir($customPath) && file_exists($customPath . DIRECTORY_SEPARATOR . 'package.json')) {
                return realpath($customPath) ?: $customPath;
            }
        }

        $base = base_path();
        $parent = dirname($base);
        $grandparent = dirname($parent);

        $searchCandidates = [
            $parent . DIRECTORY_SEPARATOR . 'frontend',
            $grandparent . DIRECTORY_SEPARATOR . 'frontend',
            $parent . DIRECTORY_SEPARATOR . 'web',
            $grandparent . DIRECTORY_SEPARATOR . 'web',
            $parent . DIRECTORY_SEPARATOR . 'client',
            $grandparent . DIRECTORY_SEPARATOR . 'client',
        ];

        foreach ($searchCandidates as $candidate) {
            if (!is_dir($candidate)) {
                continue;
            }

            if (file_exists($candidate . DIRECTORY_SEPARATOR . 'package.json')) {
                $pkg = file_get_contents($candidate . DIRECTORY_SEPARATOR . 'package.json');
                if (str_contains($pkg, 'vue')) {
                    return realpath($candidate) ?: $candidate;
                }
            }

            $subdirs = glob($candidate . '/*', GLOB_ONLYDIR);
            if ($subdirs) {
                foreach ($subdirs as $subdir) {
                    if (file_exists($subdir . DIRECTORY_SEPARATOR . 'package.json')) {
                        $pkg = file_get_contents($subdir . DIRECTORY_SEPARATOR . 'package.json');
                        if (str_contains($pkg, 'vue')) {
                            return realpath($subdir) ?: $subdir;
                        }
                    }
                }
            }
        }

        return null;
    }

    protected function installToMonorepo(): void
    {
        $resourcesJsExists = is_dir(resource_path('js'));
        $srcExists = is_dir(base_path('src'));
        $feBase = $resourcesJsExists ? resource_path('js') : ($srcExists ? base_path('src') : resource_path('js'));
        $bundleDir = __DIR__ . '/../../resources/frontend';

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

        $this->adaptPageLayout("{$feBase}/{$pagesFolder}/BackupPage.vue", $feBase);
        $this->info('✔ File antarmuka Frontend (Vue Monorepo) berhasil dipasang!');

        $this->autoInjectInertiaRoute($renderPath);
        $this->autoInjectSidebarMenu($feBase);
    }

    protected function installToVueSpa(string $feDir): void
    {
        $bundleDir = __DIR__ . '/../../resources/frontend';
        $targetBase = is_dir("{$feDir}/src") ? "{$feDir}/src" : "{$feDir}/resources/js";

        // Tentukan lokasi page yang rapi sesuai konvensi project
        $pageSubdir = 'pages/settings';
        $pageImportPath = '@/pages/settings/BackupPage.vue';

        if (is_dir("{$targetBase}/views/admin")) {
            $pageSubdir = 'views/admin/settings';
            $pageImportPath = '@/views/admin/settings/BackupPage.vue';
        } elseif (is_dir("{$targetBase}/views")) {
            $pageSubdir = 'views/settings';
            $pageImportPath = '@/views/settings/BackupPage.vue';
        }

        $destPageFile = "{$targetBase}/{$pageSubdir}/BackupPage.vue";

        $files = [
            [
                'from' => "{$bundleDir}/services/gdriveBackupApi.js",
                'to' => "{$targetBase}/services/gdriveBackupApi.js",
            ],
            [
                'from' => "{$bundleDir}/composables/useGdriveBackup.js",
                'to' => "{$targetBase}/composables/useGdriveBackup.js",
            ],
            [
                'from' => "{$bundleDir}/pages/settings/BackupPage.vue",
                'to' => $destPageFile,
            ],
        ];

        foreach ($files as $file) {
            $destDir = dirname($file['to']);
            if (!is_dir($destDir)) {
                mkdir($destDir, 0755, true);
            }
            copy($file['from'], $file['to']);
            $rel = str_replace($feDir . DIRECTORY_SEPARATOR, '', $file['to']);
            $this->line("  <info>✔</info> Disalin: <comment>{$rel}</comment>");
        }

        // Perbaiki import useGdriveBackup di BackupPage.vue
        if (file_exists($destPageFile)) {
            $pageContent = file_get_contents($destPageFile);
            $pageContent = str_replace(
                "import { useGdriveBackup } from '../../composables/useGdriveBackup'",
                "import { useGdriveBackup } from '@/composables/useGdriveBackup'",
                $pageContent
            );
            file_put_contents($destPageFile, $pageContent);
        }

        $this->info("✔ Komponen Frontend berhasil dipasang ke project Vue SPA ({$pageSubdir}/BackupPage.vue).");

        // 1. Otomatis sesuaikan layout BackupPage.vue dengan Layout yang ada di project (AdminLayout / AppLayout / dll)
        $this->adaptPageLayout($destPageFile, $targetBase, true);

        // 2. Auto-inject route ke Vue Router
        $this->autoInjectVueSpaRoute($targetBase, $pageImportPath);

        // 3. Auto-inject menu ke Sidebar Layout
        $this->autoInjectVueSpaSidebar($targetBase);

        // 4. Set GDRIVE_BACKUP_FRONTEND_URL di .env backend
        $this->updateEnvFrontendUrl();
    }

    protected function autoInjectVueSpaRoute(string $targetBase, string $pageImportPath): void
    {
        $routerPaths = [
            "{$targetBase}/router/index.js",
            "{$targetBase}/router.js",
        ];

        $routerFile = null;
        foreach ($routerPaths as $path) {
            if (file_exists($path)) {
                $routerFile = $path;
                break;
            }
        }

        if (!$routerFile) {
            $this->line("💡 Daftarkan route berikut di router Anda: { path: '/settings/backup', component: () => import('{$pageImportPath}') }");
            return;
        }

        $content = file_get_contents($routerFile);
        $injected = false;

        // Cek lazy import
        $componentConst = "const AdminBackup = () => import('{$pageImportPath}')\n";
        if (!str_contains($content, 'AdminBackup')) {
            if (str_contains($content, 'const AdminProfile')) {
                $content = preg_replace('/(const AdminProfile.*?\n)/', "$1{$componentConst}", $content, 1);
            } else {
                $content = $componentConst . $content;
            }
            $injected = true;
        }

        // Sisipkan ke grup superadmin jika ada
        if (str_contains($content, "path: '/superadmin'") && !preg_match('/[\'"]superadmin-backup[\'"]/', $content)) {
            $routeSnippet = "\n      { path: 'settings/backup', name: 'superadmin-backup', component: AdminBackup },";
            if (preg_match('/(path:\s*[\'"]\/superadmin[\'"].*?children:\s*\[)/s', $content)) {
                $content = preg_replace('/(path:\s*[\'"]\/superadmin[\'"].*?children:\s*\[)/s', "$1{$routeSnippet}", $content, 1);
                $injected = true;
            }
        }

        // Sisipkan ke grup admin jika ada
        if (str_contains($content, "path: '/admin'") && !preg_match('/[\'"]admin-backup[\'"]/', $content)) {
            $routeSnippet = "\n      { path: 'settings/backup', name: 'admin-backup', component: AdminBackup },";
            if (preg_match('/(path:\s*[\'"]\/admin[\'"].*?children:\s*\[)/s', $content)) {
                $content = preg_replace('/(path:\s*[\'"]\/admin[\'"].*?children:\s*\[)/s', "$1{$routeSnippet}", $content, 1);
                $injected = true;
            }
        }

        // Tambahkan redirect route /settings/backup jika belum ada (agar callback Google OAuth / direct link tidak 404)
        if (!preg_match('/path:\s*[\'"]\/settings\/backup[\'"]/', $content) && str_contains($content, 'routes = [')) {
            $redirectSnippet = "  // Backup route redirect (OAuth callback & direct access)\n  {\n    path: '/settings/backup',\n    redirect: (to) => {\n      try {\n        const stored = localStorage.getItem('user')\n        const u = stored ? JSON.parse(stored) : null\n        const role = u?.role?.name || u?.role\n        const prefix = role === 'superadmin' ? '/superadmin' : '/admin'\n        return { path: `\${prefix}/settings/backup`, query: to.query }\n      } catch {\n        return { path: '/superadmin/settings/backup', query: to.query }\n      }\n    },\n  },\n";
            $content = str_replace('routes = [', "routes = [\n{$redirectSnippet}", $content);
            $injected = true;
        }

        // Pola generic routes = [ ... ] (hanya jika bukan grup superadmin/admin)
        if (!str_contains($content, "path: '/superadmin'") && !str_contains($content, "path: '/admin'")) {
            if (!str_contains($content, 'settings/backup') && str_contains($content, 'routes = [')) {
                $routeCode = "  {\n    path: '/settings/backup',\n    name: 'settings-backup',\n    component: AdminBackup,\n    meta: { requiresAuth: true }\n  },\n";
                $content = str_replace('routes = [', "routes = [\n{$routeCode}", $content);
                $injected = true;
            }
        }

        if ($injected) {
            file_put_contents($routerFile, $content);
            $this->info('✔ Route settings/backup otomatis didaftarkan ke: ' . basename($routerFile));
        } else {
            $this->line('ℹ Route settings/backup sudah terdaftar di router frontend.');
        }
    }

    protected function autoInjectVueSpaSidebar(string $targetBase): void
    {
        $sidebarCandidates = [
            "{$targetBase}/components/layout/AdminLayout.vue",
            "{$targetBase}/layouts/AdminLayout.vue",
            "{$targetBase}/components/layout/Sidebar.vue",
        ];

        $sidebarFile = null;
        foreach ($sidebarCandidates as $file) {
            if (file_exists($file)) {
                $sidebarFile = $file;
                break;
            }
        }

        if (!$sidebarFile) {
            return;
        }

        $content = file_get_contents($sidebarFile);

        if (str_contains($content, 'settings/backup') || str_contains($content, 'Backup Data')) {
            $this->line('ℹ Menu "Backup Data" sudah ada di sidebar layout.');
            return;
        }

        $injected = false;

        // Cek pola if role == superadmin -> items.push({ label: 'User Manajemen' })
        if (str_contains($content, 'User Manajemen')) {
            $menuSnippet = "\n  if (['admin', 'superadmin'].includes(role.value)) {\n    items.push({ label: 'Backup Data', icon: 'pi-database', path: `\${p}/settings/backup` })\n  }\n";
            
            $content = preg_replace(
                '/(items\.push\(\{\s*label:\s*[\'"]User Manajemen[\'"].*?\}\)\s*\n\s*\})/',
                "$1{$menuSnippet}",
                $content,
                1,
                $replaced
            );

            if ($replaced > 0) {
                $injected = true;
            }
        }

        // Fallback: Jika tidak ada User Manajemen, cari return items di dalam menu
        if (!$injected && preg_match('/return\s+items\b/i', $content)) {
            $menuSnippet = "  if (['admin', 'superadmin'].includes(role.value)) {\n    items.push({ label: 'Backup Data', icon: 'pi-database', path: `\${p}/settings/backup` })\n  }\n\n  ";
            $content = preg_replace('/(\s*)(return\s+items\b)/i', "$1{$menuSnippet}$2", $content, 1, $replaced);
            if ($replaced > 0) {
                $injected = true;
            }
        }

        if ($injected) {
            file_put_contents($sidebarFile, $content);
            $this->info('✔ Menu "Backup Data" otomatis ditambahkan ke sidebar: ' . basename($sidebarFile));
        }
    }

    protected function updateEnvFrontendUrl(): void
    {
        $envPath = base_path('.env');
        if (!file_exists($envPath)) {
            return;
        }

        $content = file_get_contents($envPath);
        $frontendUrl = 'http://localhost:5173';

        if (str_contains($content, 'GDRIVE_BACKUP_FRONTEND_URL=')) {
            $content = preg_replace('/#?\s*GDRIVE_BACKUP_FRONTEND_URL=.*/', "GDRIVE_BACKUP_FRONTEND_URL={$frontendUrl}", $content);
        } else {
            $content .= "\nGDRIVE_BACKUP_FRONTEND_URL={$frontendUrl}\n";
        }

        file_put_contents($envPath, $content);
        $this->info("✔ GDRIVE_BACKUP_FRONTEND_URL disetel ke {$frontendUrl} di .env backend.");
    }

    protected function adaptPageLayout(string $backupPagePath, string $feBase, bool $isVueSpa = false): void
    {
        if (!file_exists($backupPagePath)) {
            return;
        }

        $content = file_get_contents($backupPagePath);

        // Jika sudah memiliki layout pembungkus apapun, lewati
        if (preg_match('/<template>\s*<([A-Z][a-zA-Z0-9]+Layout)/', $content)) {
            return;
        }

        $layoutCandidates = [
            ['name' => 'AdminLayout', 'file' => "{$feBase}/components/layout/AdminLayout.vue", 'import' => "import AdminLayout from '@/components/layout/AdminLayout.vue'"],
            ['name' => 'AdminLayout', 'file' => "{$feBase}/layouts/AdminLayout.vue", 'import' => "import AdminLayout from '@/layouts/AdminLayout.vue'"],
            ['name' => 'AdminLayout', 'file' => "{$feBase}/Layouts/AdminLayout.vue", 'import' => "import AdminLayout from '@/Layouts/AdminLayout.vue'"],
            ['name' => 'AppLayout', 'file' => "{$feBase}/Layouts/AppLayout.vue", 'import' => "import AppLayout from '@/Layouts/AppLayout.vue'"],
            ['name' => 'AppLayout', 'file' => "{$feBase}/layouts/AppLayout.vue", 'import' => "import AppLayout from '@/layouts/AppLayout.vue'"],
            ['name' => 'AppLayout', 'file' => "{$feBase}/components/layout/AppLayout.vue", 'import' => "import AppLayout from '@/components/layout/AppLayout.vue'"],
            ['name' => 'AuthenticatedLayout', 'file' => "{$feBase}/Layouts/AuthenticatedLayout.vue", 'import' => "import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'"],
            ['name' => 'AuthenticatedLayout', 'file' => "{$feBase}/layouts/AuthenticatedLayout.vue", 'import' => "import AuthenticatedLayout from '@/layouts/AuthenticatedLayout.vue'"],
            ['name' => 'MainLayout', 'file' => "{$feBase}/layouts/MainLayout.vue", 'import' => "import MainLayout from '@/layouts/MainLayout.vue'"],
            ['name' => 'MainLayout', 'file' => "{$feBase}/components/layout/MainLayout.vue", 'import' => "import MainLayout from '@/components/layout/MainLayout.vue'"],
        ];

        $matchedLayout = null;
        foreach ($layoutCandidates as $cand) {
            if (file_exists($cand['file'])) {
                $matchedLayout = $cand;
                break;
            }
        }

        if (!$matchedLayout) {
            return;
        }

        $layoutComponent = $matchedLayout['name'];
        $layoutImport = $matchedLayout['import'];

        // Cek breadcrumb component
        $breadcrumbCandidates = [
            ['file' => "{$feBase}/components/common/Breadcrumb.vue", 'import' => "import Breadcrumb from '@/components/common/Breadcrumb.vue'"],
            ['file' => "{$feBase}/components/Breadcrumb.vue", 'import' => "import Breadcrumb from '@/components/Breadcrumb.vue'"],
            ['file' => "{$feBase}/Components/Breadcrumb.vue", 'import' => "import Breadcrumb from '@/Components/Breadcrumb.vue'"],
        ];

        $matchedBreadcrumb = null;
        foreach ($breadcrumbCandidates as $bc) {
            if (file_exists($bc['file'])) {
                $matchedBreadcrumb = $bc;
                break;
            }
        }

        $breadcrumbSnippet = '';
        $breadcrumbImport = '';
        $extraScript = '';

        if ($matchedBreadcrumb) {
            $breadcrumbImport = $matchedBreadcrumb['import'] . "\n";
            if ($isVueSpa) {
                $breadcrumbSnippet = "\n      <!-- Top Bar: Breadcrumb -->\n      <div class=\"flex items-center justify-between mb-2\">\n        <Breadcrumb\n          :items=\"[\n            { label: 'Dashboard', href: pathPrefix + '/dashboard' },\n            { label: 'Backup Data', href: pathPrefix + '/settings/backup' },\n          ]\"\n        />\n      </div>\n";
                $extraScript = "\nimport { useRoute } from 'vue-router'\nconst route = useRoute()\nconst pathPrefix = computed(() => {\n  const m = route.path.match(/^\\/(admin|superadmin)/)\n  return m ? `/\${m[1]}` : '/admin'\n})\n";
            } else {
                $breadcrumbSnippet = "\n      <!-- Breadcrumb -->\n      <div class=\"flex items-center justify-between mb-2\">\n        <Breadcrumb\n          :items=\"[\n            { label: 'Dashboard', route: 'dashboard' },\n            { label: 'Backup Data', route: 'settings.backup' }\n          ]\"\n        />\n      </div>\n";
            }
        }

        // Bungkus template secara presisi & aman (tanpa merusak tag atau atribut class)
        $content = preg_replace_callback(
            '/<template>\s*(<div\b[^>]*>)/',
            function ($matches) use ($layoutComponent, $breadcrumbSnippet) {
                $divTag = $matches[1];
                // Pastikan class memiliki responsive container padding p-3 sm:p-6 lg:p-8 agar tidak menempel di tepi sidebar/layar
                if (!preg_match('/\bp-[0-9]|\bpx-[0-9]/', $divTag)) {
                    $divTag = preg_replace('/class="([^"]*)"/', 'class="p-3 sm:p-6 lg:p-8 $1"', $divTag);
                }
                return "<template>\n  <{$layoutComponent} title=\"Backup Data\">\n    {$divTag}{$breadcrumbSnippet}";
            },
            $content
        );

        $content = preg_replace(
            '/<\/div>\s*<\/template>/',
            "    </div>\n  </{$layoutComponent}>\n</template>",
            $content
        );

        // Tambah import layout & script
        $content = preg_replace(
            '/<script setup>\s*import\s*\{\s*ref,\s*onMounted\s*\}\s*from\s*[\'"]vue[\'"]/',
            "<script setup>\nimport { ref, computed, onMounted } from 'vue'\n{$layoutImport}\n{$breadcrumbImport}{$extraScript}",
            $content
        );

        file_put_contents($backupPagePath, $content);
        $this->info("✔ BackupPage.vue otomatis dibungkus dengan layout <{$layoutComponent}> project Anda.");
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

        // Kredensial TIDAK di-hardcode (repo publik).
        // Opsional: baca dari developer_credentials.json lokal (di-gitignore, tidak ikut ter-push).
        $candidatePaths = [
            __DIR__ . '/../../developer_credentials.json',
            dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'developer_credentials.json',
            dirname(base_path()) . DIRECTORY_SEPARATOR . 'developer_credentials.json',
        ];

        $devClientId = '';
        $devClientSecret = '';

        foreach ($candidatePaths as $path) {
            if (file_exists($path)) {
                $devCreds = json_decode(file_get_contents($path), true);
                if (!empty($devCreds['client_id'])) $devClientId = $devCreds['client_id'];
                if (!empty($devCreds['client_secret'])) $devClientSecret = $devCreds['client_secret'];
                break;
            }
        }

        $appUrl = env('APP_URL', 'http://localhost:8000');
        $defaultRedirectUri = rtrim($appUrl, '/') . '/api/v1/backup/google/callback';

        $variablesToAdd = [];

        // GOOGLE_DRIVE_CLIENT_ID (hanya diisi jika belum ada / masih kosong)
        if (!str_contains($envContent, 'GOOGLE_DRIVE_CLIENT_ID=')) {
            $variablesToAdd[] = "GOOGLE_DRIVE_CLIENT_ID={$devClientId}";
        } elseif ($devClientId !== '') {
            $envContent = preg_replace('/GOOGLE_DRIVE_CLIENT_ID=[ \t]*(\r?\n|$)/', "GOOGLE_DRIVE_CLIENT_ID={$devClientId}$1", $envContent);
        }

        // GOOGLE_DRIVE_CLIENT_SECRET (hanya diisi jika belum ada / masih kosong)
        if (!str_contains($envContent, 'GOOGLE_DRIVE_CLIENT_SECRET=')) {
            $variablesToAdd[] = "GOOGLE_DRIVE_CLIENT_SECRET={$devClientSecret}";
        } elseif ($devClientSecret !== '') {
            $envContent = preg_replace('/GOOGLE_DRIVE_CLIENT_SECRET=[ \t]*(\r?\n|$)/', "GOOGLE_DRIVE_CLIENT_SECRET={$devClientSecret}$1", $envContent);
        }

        // GOOGLE_DRIVE_REDIRECT_URI
        if (!str_contains($envContent, 'GOOGLE_DRIVE_REDIRECT_URI=')) {
            $variablesToAdd[] = "GOOGLE_DRIVE_REDIRECT_URI={$defaultRedirectUri}";
        } else {
            $envContent = preg_replace('/GOOGLE_DRIVE_REDIRECT_URI=\s*(\r?\n|$)/', "GOOGLE_DRIVE_REDIRECT_URI={$defaultRedirectUri}$1", $envContent);
        }

        if (!empty($variablesToAdd)) {
            $envContent .= "\n# Google Drive OAuth (gdrive-backup)\n" . implode("\n", $variablesToAdd) . "\n";
        }
        file_put_contents($envPath, $envContent);

        $hasId = preg_match('/^GOOGLE_DRIVE_CLIENT_ID=\S+/m', $envContent);
        $hasSecret = preg_match('/^GOOGLE_DRIVE_CLIENT_SECRET=\S+/m', $envContent);

        if ($hasId && $hasSecret) {
            $this->info('✔ Kredensial Google OAuth terdeteksi di .env.');
        } else {
            $this->warn('⚠ GOOGLE_DRIVE_CLIENT_ID / GOOGLE_DRIVE_CLIENT_SECRET di .env masih kosong.');
            $this->line('  Buat OAuth Client di Google Cloud Console lalu isi di .env (lihat README bagian "Kredensial Google Cloud").');
            $this->line("  Authorized redirect URI: <comment>{$defaultRedirectUri}</comment>");
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
            if (str_contains($content, "'api', 'auth:sanctum'") || str_contains($content, '"api", "auth:sanctum"')) {
                $content = str_replace(["'api', 'auth:sanctum'", '"api", "auth:sanctum"'], "'web', 'auth'", $content);
                $content = preg_replace("/'callback_middleware'\s*=>\s*\['api'\]/", "'callback_middleware' => ['web']", $content);
                file_put_contents($configPath, $content);
                $this->info("✔ Middleware route gdrive-backup otomatis disetel ke ['web', 'auth'] untuk Inertia Monorepo.");
            }
        } else {
            if (str_contains($content, "'web', 'auth'") || str_contains($content, '"web", "auth"')) {
                $content = str_replace(["'web', 'auth'", '"web", "auth"'], "'api', 'auth:sanctum'", $content);
                file_put_contents($configPath, $content);
                $this->info("✔ Middleware route gdrive-backup otomatis disetel ke ['api', 'auth:sanctum'] untuk API backend.");
            }
        }
    }
}
