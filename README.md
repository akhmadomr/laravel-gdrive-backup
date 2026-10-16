# Laravel Google Drive Backup & Secure Restore

[![Latest Version on Packagist](https://img.shields.io/badge/version-1.0.0-blue.svg)](https://github.com/akhmadomr/laravel-gdrive-backup)
[![Software License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![PHP Version](https://img.shields.io/badge/php-%5E8.2-8892BF.svg)](https://php.net)
[![Laravel Support](https://img.shields.io/badge/laravel-11.x%20%7C%2012.x-red.svg)](https://laravel.com)

Modul composable lengkap untuk **Laravel** dan **Vue 3** yang menyediakan fitur pencadangan (*backup*) otomatis database MySQL ke Google Drive (mendukung banyak akun sekaligus tanpa batas), serta pemulihan (*restore*) aman langsung melalui antarmuka web (UI) tanpa memerlukan akses terminal server bagi klien/pengguna akhir.

---

## ✨ Fitur Utama

- **Zero-Config 1-Command Installation**: Cukup jalankan satu perintah installer, seluruh backend, database, kredensial Google OAuth siap pakai, hingga komponen UI frontend (route & menu sidebar) otomatis terpasang tanpa setup rumit!
- **Pencadangan Murni Database (MySQL/MariaDB)**: Hanya data database yang dicadangkan. Kredensial `.env` dan kode aplikasi tidak ikut masuk. Menggunakan library PHP murni (`ifsnop/mysqldump-php`), aman di shared hosting tanpa dependensi CLI `mysqldump`.
- **Dukungan Multi-Akun Google Drive**: Pengguna dapat menghubungkan banyak akun Google Drive sekaligus (1-Click Google OAuth). File cadangan otomatis diduplikasi ke seluruh akun yang terhubung untuk redundansi data maksimal.
- **Pemulihan Aman Berbasis System ID**: Setiap project memiliki System ID unik (`config/gdrive-backup.php`) yang diikutsertakan ke dalam `manifest.json` file ZIP backup beserta checksum SHA-256. Restore dari sistem lain atau file yang korup akan ditolak otomatis.
- **Pesan Penolakan Generik & Aman**: Jika file backup tidak cocok atau ditolak, user hanya melihat pesan generik:
  > *"File backup tidak sesuai dengan aplikasi ini. Hubungi developer jika menurut Anda ini sebuah kesalahan. (Kode: XXXXXX)"*
  Alasan teknis detail hanya dicatat di log server bersama kode referensi tersebut.
- **Otomatisasi Penuh**: Dilengkapi cron scheduler bawaan yang dapat diatur frekuensinya langsung dari UI (Harian, Mingguan, Bulanan).
- **Dukungan Penuh Monorepo & Separate SPA**: Otomatis mendeteksi arsitektur Laravel Monorepo (Inertia.js) maupun Separate Repository (Laravel API Sanctum + Vue 3 SPA).

---

## 🚀 1. Instalasi Cepat (1 Langkah Selesai)

### Langkah 1: Install Paket Modul di Backend Laravel
Jika menggunakan repositori privat/lokal, pastikan repository terdaftar di `composer.json`, lalu jalankan:

```bash
composer require akhmadomr/laravel-gdrive-backup
```

### Langkah 2: Jalankan Artisan Install
```bash
php artisan gdrive-backup:install
```

Perintah pintar ini akan secara otomatis:
1. **Membuat & Mengisi System ID Unik**: Men-generate UUID keamanan unik di `config/gdrive-backup.php`.
2. **Migrasi Database**: Menjalankan migrasi tabel `gdrive_backup_settings` dan `gdrive_backup_restore_histories`.
3. **Konfigurasi Kredensial Siap Pakai (Zero-Config)**: Otomatis mengisi kredensial Google OAuth bawaan yang sudah aktif ke file `.env`, sehingga Anda **tidak perlu repot membuka Google Cloud Console**!
4. **Deteksi & Pasang Komponen Frontend Otomatis**:
   - Jika project Anda **Monorepo (Inertia Vue)**: File halaman disalin ke `resources/js`, route ditambahkan ke `routes/web.php`, dan menu disisipkan ke sidebar `AppLayout.vue`.
   - Jika project Anda **Separate Repo (Vue 3 SPA)** (misal folder frontend berada di `../frontend/web-acspeduli`): File `gdriveBackupApi.js`, `useGdriveBackup.js`, dan `BackupPage.vue` otomatis disalin ke project frontend Anda, route didaftarkan ke `router/index.js`, dan tombol menu "Backup Data" otomatis ditambahkan ke sidebar layout (`AdminLayout.vue`).
5. **Optimasi Composer**: Membersihkan service Google API yang tidak terpakai agar performa Composer tetap gesit.

> ⚠️ **PENTING**: File `config/gdrive-backup.php` **WAJIB di-commit ke Git** agar seluruh server (lokal, staging, production) memiliki `system_id` yang identik!

---

## 🛠️ Opsi Flag Perintah Installer

| Flag | Deskripsi |
|---|---|
| *(tanpa flag)* | Mode interaktif pintar: mendeteksi frontend otomatis dan meminta konfirmasi pemasangan. |
| `--with-fe` | Memaksa pemasangan komponen Frontend tanpa dialog konfirmasi. |
| `--without-fe` | Melewati pemasangan Frontend (khusus untuk project backend API headless murni). |
| `--frontend-path=...` | Menentukan path lokasi folder project frontend Vue jika berada di direktori custom. |
| `--force` | Timpa ulang file konfigurasi `config/gdrive-backup.php`. |

---

## 🔑 2. Menggunakan Kredensial Google Cloud Sendiri (Opsional)

> 💡 **Catatan**: Modul ini **sudah dilengkapi kredensial bawaan yang langsung aktif**. Anda HANYA perlu mengikuti langkah di bawah jika ingin menggunakan project Google Cloud Console milik perusahaan / organisasi Anda sendiri.

Jika ingin menggunakan kredensial sendiri:

1. Buka [Google Cloud Console](https://console.cloud.google.com/) dan buat project baru.
2. Masuk ke **APIs & Services** > **Library**, cari **Google Drive API** dan klik **Enable**.
3. Di **OAuth consent screen**, pilih tipe **External**, masukkan data aplikasi, dan tambahkan scope `.../auth/drive.file`.
4. Tambahkan email Google Anda ke daftar **Test users** jika status masih pengujian (*Testing*).
5. Masuk ke **Credentials** > **Create Credentials** > **OAuth client ID** (Pilih tipe *Web application*).
6. Tambahkan **Authorized redirect URIs**:
   - Lokal dev: `http://localhost:8000/api/v1/backup/google/callback`
   - Production: `https://api.domainanda.com/api/v1/backup/google/callback`
7. Perbarui kredensial di file `.env` backend Anda:
   ```env
   GOOGLE_DRIVE_CLIENT_ID=client-id-milik-anda.apps.googleusercontent.com
   GOOGLE_DRIVE_CLIENT_SECRET=client-secret-milik-anda
   GOOGLE_DRIVE_REDIRECT_URI=http://localhost:8000/api/v1/backup/google/callback
   ```
8. Jalankan pembersihan cache konfigurasi:
   ```bash
   php artisan config:clear
   ```

---

## ⚙️ 3. Konfigurasi (`config/gdrive-backup.php`)

| Opsi | Default | Deskripsi |
|---|---|---|
| `system_id` | `UUID` | ID unik sistem untuk memvalidasi keamanan file restore. |
| `retention` | `3` | Jumlah file cadangan terbaru yang disimpan sebelum file terlama dihapus. |
| `local_disk` | `'local'` | Disk lokal server untuk menyimpan salinan cadangan (`null` untuk menonaktifkan). |
| `zip_password` | `null` | Password enkripsi ZIP (AES-256) untuk mengamankan file backup. |
| `route.prefix` | `'api/v1/backup'` | Prefix URL untuk seluruh endpoint modul backup. |
| `route.middleware` | `['api', 'auth:sanctum']` | Middleware autentikasi route backup (otomatis `['web', 'auth']` jika Monorepo Inertia). |
| `frontend_url` | `env('GDRIVE_BACKUP_FRONTEND_URL')` | URL host frontend tujuan redirect setelah proses OAuth Google selesai. |
| `frontend_path` | `'/settings/backup'` | Path rute frontend tujuan setelah proses OAuth selesai. |

---

## ⏰ 4. Scheduler Otomatis (Cron)

Pastikan scheduler cron Laravel sudah aktif di crontab server Anda:
```bash
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```
Jadwal backup otomatis yang diatur pengguna melalui antarmuka web (UI) akan dieksekusi secara otomatis oleh modul ini sesuai frekuensi yang dipilih.

---

## 📡 5. Endpoint API

| Method | Endpoint | Deskripsi | Auth |
|---|---|---|---|
| `GET` | `/api/v1/backup/status` | Mengambil status disk, kapasitas, dan akun terhubung | Yes (Sanctum/Web) |
| `GET` | `/api/v1/backup/google/connect` | Menghasilkan URL otorisasi Google OAuth | Yes (Sanctum/Web) |
| `GET` | `/api/v1/backup/google/callback` | Callback redirect browser dari Google | Public (State Verified) |
| `POST` | `/api/v1/backup/google/disconnect` | Memutuskan akun Google Drive | Yes (Sanctum/Web) |
| `POST` | `/api/v1/backup/google/folder` | Mengatur Folder ID tujuan spesifik | Yes (Sanctum/Web) |
| `POST` | `/api/v1/backup/schedule` | Menyimpan preferensi jadwal backup otomatis | Yes (Sanctum/Web) |
| `POST` | `/api/v1/backup/run` | Menjalankan pencadangan database manual | Yes (Sanctum/Web) |
| `POST` | `/api/v1/backup/restore` | Mengunggah file `.zip` dan mengeksekusi restore | Yes (Sanctum/Web) |
| `GET` | `/api/v1/backup/restore-history` | Mengambil riwayat restore database | Yes (Sanctum/Web) |

---

## 📄 Lisensi
Modul ini dilisensikan di bawah [MIT License](LICENSE).
