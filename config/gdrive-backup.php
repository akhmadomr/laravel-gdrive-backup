<?php

return [

    /*
    |--------------------------------------------------------------------------
    | System ID
    |--------------------------------------------------------------------------
    |
    | Identitas unik sistem ini. Dibuat otomatis oleh `php artisan gdrive-backup:install`.
    | File config ini WAJIB di-commit ke git, supaya semua server dari project yang
    | sama memiliki ID yang sama. Restore akan DITOLAK jika ID di file backup berbeda.
    |
    | JANGAN diubah setelah ada file backup, karena backup lama tidak akan bisa direstore.
    |
    */

    'system_id' => null,

    /*
    |--------------------------------------------------------------------------
    | Database
    |--------------------------------------------------------------------------
    */

    // Nama koneksi di config/database.php (saat ini hanya MySQL/MariaDB).
    'connection' => env('DB_CONNECTION', 'mysql'),

    // Tabel yang TIDAK ikut di-backup sama sekali (struktur & isi).
    // Tabel milik modul ini selalu dikecualikan otomatis agar koneksi Google
    // Drive & riwayat restore tidak tertimpa saat restore.
    'exclude_tables' => [
        'sessions',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
    ],

    // Tabel yang hanya di-backup strukturnya (isinya tidak).
    'exclude_table_data' => [
        'personal_access_tokens',
    ],

    // Tabel yang dikosongkan setelah restore berhasil (mis. memaksa semua user login ulang).
    'truncate_after_restore' => [
        'personal_access_tokens',
    ],

    // Class invokable tambahan yang dijalankan setelah restore berhasil.
    // Contoh: \App\Backup\AfterRestore::class  (method __invoke(array $manifest))
    'after_restore' => [],

    // Setelah restore backup dari versi aplikasi yang lebih lama,
    // jalankan `migrate --force` otomatis agar struktur database ikut diperbarui.
    'migrate_after_restore' => true,

    // Verifikasi kesesuaian migration saat restore. Mencegah restore database dari
    // versi aplikasi yang lebih baru ke aplikasi yang lebih lama.
    'verify_migrations' => true,

    /*
    |--------------------------------------------------------------------------
    | Penyimpanan
    |--------------------------------------------------------------------------
    */

    // Custom prefix nama file ZIP backup. Kosongkan untuk otomatis menggunakan slug APP_NAME (atau nama folder project).
    'file_prefix' => env('GDRIVE_BACKUP_FILE_PREFIX'),

    // Nama folder backup di disk lokal & Google Drive. Kosongkan untuk otomatis {file_prefix}-backup.
    'folder_name' => env('GDRIVE_BACKUP_FOLDER_NAME'),

    // Disk lokal untuk salinan cadangan di server. Isi null untuk menonaktifkan.
    'local_disk' => 'local',

    // Jumlah file backup terbaru yang disimpan di setiap tujuan.
    'retention' => 3,

    // Password enkripsi ZIP (AES-256). Kosongkan untuk tanpa enkripsi.
    // PERINGATAN: jika password hilang/berubah, backup lama tidak bisa direstore.
    'zip_password' => env('GDRIVE_BACKUP_ZIP_PASSWORD'),

    // Batas ukuran file upload restore (KB). Pastikan juga upload_max_filesize & post_max_size PHP.
    'max_upload_kb' => 512000,

    /*
    |--------------------------------------------------------------------------
    | Google Drive OAuth
    |--------------------------------------------------------------------------
    */

    'google' => [
        'client_id' => env('GOOGLE_DRIVE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_DRIVE_CLIENT_SECRET'),
        // Kosongkan untuk otomatis: {APP_URL}/{route.prefix}/google/callback
        'redirect_uri' => env('GOOGLE_DRIVE_REDIRECT_URI'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    */

    'route' => [
        'enabled' => true,
        'prefix' => 'api/v1/backup',
        // Untuk SPA terpisah (Sanctum token), gunakan ['api', 'auth:sanctum'].
        // Untuk Monorepo (Inertia/Session web), otomatis disetel ke ['web', 'auth'] saat install.
        'middleware' => ['api', 'auth:sanctum'],
        // Callback OAuth dibuka oleh browser (redirect dari Google), tanpa auth.
        'callback_middleware' => ['web'],
    ],

    // Halaman frontend tujuan setelah proses OAuth Google selesai.
    'frontend_url' => env('GDRIVE_BACKUP_FRONTEND_URL', env('FRONTEND_URL', env('APP_URL'))),
    'frontend_path' => env('GDRIVE_BACKUP_FRONTEND_PATH', '/settings/backup'),

    /*
    |--------------------------------------------------------------------------
    | Jadwal otomatis
    |--------------------------------------------------------------------------
    |
    | Jadwal diatur user lewat UI. Pastikan cron Laravel scheduler aktif di server:
    | * * * * * php /path/to/artisan schedule:run >> /dev/null 2>&1
    |
    */

    'schedule' => [
        'enabled' => true,
        'timezone' => env('APP_TIMEZONE', 'Asia/Jakarta'),
        'default' => [
            'frequency' => 'daily', // daily | weekly | monthly
            'day' => 1,             // 0 (Minggu) - 6 (Sabtu), untuk weekly
            'date' => 1,            // 1 - 31, untuk monthly
            'time' => '01:30',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Pesan untuk user
    |--------------------------------------------------------------------------
    |
    | Sengaja dibuat generik. Detail alasan hanya dicatat di log server
    | dan dapat ditelusuri menggunakan kode referensi (:ref).
    |
    */

    'messages' => [
        'restore_rejected' => 'File backup tidak sesuai dengan aplikasi ini. Hubungi developer jika menurut Anda ini sebuah kesalahan. (Kode: :ref)',
        'restore_failed' => 'Restore gagal diproses. Silakan hubungi developer. (Kode: :ref)',
        'restore_success' => 'Restore berhasil. Silakan login kembali.',
        'upload_too_large' => 'Ukuran file melebihi batas upload server. Hubungi developer.',
    ],

];
