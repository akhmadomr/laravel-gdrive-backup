# Laravel Google Drive Backup & Secure Restore

[![Latest Version on Packagist](https://img.shields.io/badge/version-1.0.0-blue.svg)](https://github.com/akhmadomr/laravel-gdrive-backup)
[![Software License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![PHP Version](https://img.shields.io/badge/php-%5E8.2-8892BF.svg)](https://php.net)
[![Laravel Support](https://img.shields.io/badge/laravel-11.x%20%7C%2012.x-red.svg)](https://laravel.com)

Modul composable lengkap untuk **Laravel** yang menyediakan fitur pencadangan (*backup*) otomatis database MySQL ke Google Drive (mendukung banyak akun sekaligus tanpa batas), serta pemulihan (*restore*) aman langsung melalui antarmuka web (UI) tanpa memerlukan akses terminal server bagi klien/pengguna akhir.

---

## ✨ Fitur Utama

- **Pencadangan Murni Database (MySQL/MariaDB)**: Hanya data database yang dicadangkan. Kode project, vendor, dan `.env` tidak ikut masuk, menjaga kerahasiaan kredensial dan ukuran file tetap ringkas. Menggunakan library PHP murni (`ifsnop/mysqldump-php`), aman di shared hosting tanpa dependensi `mysqldump` CLI.
- **Dukungan Multi-Akun Google Drive**: Klien dapat menghubungkan 1, 2, atau lebih akun Google Drive sekaligus (1-Click Google OAuth). File cadangan otomatis diduplikasi ke seluruh akun yang terhubung untuk redundansi data maksimal.
- **Pemulihan Aman Berbasis System ID**: Setiap project memiliki System ID unik (`config/gdrive-backup.php`) yang diikutsertakan ke dalam `manifest.json` file ZIP backup beserta checksum SHA-256. Restore dari sistem lain atau file yang korup akan ditolak otomatis.
- **Pesan Penolakan Generik & Aman**: Jika file backup tidak cocok atau ditolak, user hanya melihat pesan generik:
  > *"File backup tidak sesuai dengan aplikasi ini. Hubungi developer jika menurut Anda ini sebuah kesalahan. (Kode: XXXXXX)"*
  Alasan detail teknis hanya dicatat di log server bersama kode referensi tersebut.
- **Otomatisasi Penuh**: Dilengkapi cron scheduler bawaan yang dapat diatur frekuensinya langsung dari UI (Harian, Mingguan, Bulanan).
- **Kompatibel Monorepo & Separate SPA**: Mendukung arsitektur Laravel Monorepo (Inertia.js dengan session auth) maupun Separate Repository (Laravel API Sanctum + Vue SPA).

---

## 📦 1. Instalasi Backend (Laravel)

### Tambahkan Repository ke `composer.json` Project Anda:
```json
"repositories": [
    {
        "type": "vcs",
        "url": "https://github.com/akhmadomr/laravel-gdrive-backup.git"
    }
]
```

### Install Package:
```bash
composer require akhmadomr/laravel-gdrive-backup
```

### Jalankan Artisan Install:

#### A. Untuk Project Backend API / Separate Repo (Frontend Vue Terpisah):
```bash
php artisan gdrive-backup:install --without-fe
```

#### B. Untuk Project Monorepo (Laravel + Inertia Vue di satu folder):
```bash
php artisan gdrive-backup:install
```

Perintah `gdrive-backup:install` akan secara otomatis:
1. Mem-publish file konfigurasi `config/gdrive-backup.php` dan men-generate `system_id` unik (UUID).
2. Mem-publish migration tabel `gdrive_backup_settings` dan `gdrive_backup_restore_histories`.
3. Mengoptimasi Google API Services dengan membersihkan service yang tidak terpakai dari `vendor`.
4. Menyediakan template variabel `GOOGLE_DRIVE_*` di file `.env`.

> ⚠️ **PENTING**: File `config/gdrive-backup.php` **WAJIB di-commit ke Git** agar seluruh server (lokal, staging, production) memiliki `system_id` yang identik!

---

## 🔑 2. Panduan Lengkap Setup Google Cloud Console (OAuth 2.0)

Untuk menghubungkan Google Drive, Anda memerlukan **Client ID** dan **Client Secret** resmi dari Google Cloud Console. Ikuti panduan langkah demi langkah berikut:

### Langkah 1: Buat / Pilih Project di Google Cloud Console
1. Buka [Google Cloud Console](https://console.cloud.google.com/).
2. Login menggunakan akun Google Anda.
3. Di bilah atas (*top bar*), klik dropdown project lalu klik **New Project**.
4. Beri nama project (misal: `App Backup Service`), lalu klik **Create**.

### Langkah 2: Aktifkan Google Drive API
1. Buka menu navigasi (garis tiga di kiri atas) > **APIs & Services** > **Library** (atau kunjungi [API Library](https://console.cloud.google.com/apis/library)).
2. Di kolom pencarian, ketik **Google Drive API**.
3. Klik **Google Drive API**, lalu klik tombol **Enable** (Aktifkan).

### Langkah 3: Konfigurasi OAuth Consent Screen
1. Masuk ke **APIs & Services** > **OAuth consent screen** ([Buka di sini](https://console.cloud.google.com/apis/credentials/consent)).
2. Pilih User Type: **External**, lalu klik **Create**.
3. **App Information**:
   - **App name**: Isi nama aplikasi Anda (misal: `Sistem Backup`).
   - **User support email**: Pilih email Google Anda.
   - **Developer contact information**: Isi email Google Anda.
   - Klik **Save and Continue**.
4. **Scopes**:
   - Klik tombol **Add or Remove Scopes**.
   - Cari dan centang scope berikut:
     - `.../auth/drive.file` (*Lihat, edit, buat, dan hapus file Google Drive yang dibuat oleh aplikasi ini*)
     - `.../auth/userinfo.email`
     - `.../auth/userinfo.profile`
   - Klik **Update**, lalu klik **Save and Continue**.
5. **Test Users** (*SANGAT PENTING jika status aplikasi masih Testing*):
   - Klik **Add Users**.
   - Masukkan alamat email Google yang akan Anda gunakan untuk login / backup (misal: `emailanda@gmail.com`).
   - Klik **Add**, lalu klik **Save and Continue**.
   - Klik **Back to Dashboard**.

### Langkah 4: Buat OAuth Client ID (Credentials)
1. Masuk ke **APIs & Services** > **Credentials** ([Buka di sini](https://console.cloud.google.com/apis/credentials)).
2. Di bagian atas, klik **+ CREATE CREDENTIALS** > pilih **OAuth client ID**.
3. **Application type**: Pilih **Web application**.
4. **Name**: Beri nama (misal: `Laravel Backup Client`).
5. **Authorized redirect URIs** (*Sangat Penting*):
   - Klik **+ ADD URI**.
   - Masukkan URL callback aplikasi Laravel Anda:
     - Untuk Lokal Dev (Port 8000): `http://localhost:8000/api/v1/backup/google/callback`
     - Untuk Lokal Dev (Tanpa Port): `http://localhost/api/v1/backup/google/callback`
     - Untuk Production Server: `https://api.domainanda.com/api/v1/backup/google/callback`
   *(Tips: Anda bisa menambahkan beberapa URI sekaligus untuk lokal dan production).*
6. Klik **CREATE**.
7. Modal pop-up akan menampilkan:
   - **Client ID** (contoh format: `xxxxxxxxxxxx-xxxxxxxxxxxxxxxx.apps.googleusercontent.com`)
   - **Client Secret** (contoh format: `GOCSPX-xxxxxxxxxxxxxxxxxxxx`)
8. Salin kedua nilai tersebut.

### Langkah 5: Masukkan Kredensial ke File `.env`
Buka file `.env` di backend Laravel Anda, dan isi kredensial yang baru saja disalin:
```env
# Google Drive OAuth (gdrive-backup)
GOOGLE_DRIVE_CLIENT_ID=masukkan-client-id-dari-google-console
GOOGLE_DRIVE_CLIENT_SECRET=masukkan-client-secret-dari-google-console
GOOGLE_DRIVE_REDIRECT_URI=http://localhost:8000/api/v1/backup/google/callback

# URL Frontend (untuk SPA terpisah, agar setelah login Google diarahkan ke halaman yang benar)
GDRIVE_BACKUP_FRONTEND_URL=http://localhost:5174
```

Setelah mengubah `.env`, jalankan pembersihan cache konfigurasi Laravel:
```bash
php artisan config:clear
```

---

## 🛠️ 3. Troubleshooting Masalah Umum

### ❌ Error 401: `invalid_client` ("The OAuth client was not found")
- **Penyebab**: Nilai `GOOGLE_DRIVE_CLIENT_ID` di file `.env` salah, tidak ada di Google Cloud Console, atau masih menggunakan nilai contoh default.
- **Solusi**:
  1. Pastikan Anda telah membuat *OAuth Client ID* baru dengan tipe **Web application** di project Google Cloud aktif Anda.
  2. Pastikan tidak ada spasi atau karakter terpotong saat menyalin Client ID ke `.env`.
  3. Jalankan `php artisan config:clear` dan restart `php artisan serve`.

### ❌ Error 403: `access_denied` ("Access blocked: app has not completed the Google verification process")
- **Penyebab**: Status aplikasi di OAuth Consent Screen masih **Testing**, dan email Google yang Anda gunakan belum dimasukkan ke daftar **Test Users**.
- **Solusi**:
  1. Buka **Google Cloud Console** > **APIs & Services** > **OAuth consent screen**.
  2. Gulir ke bagian **Test users** > klik **Add Users**.
  3. Masukkan email Google yang Anda gunakan untuk otorisasi > klik **Save**.

### ❌ Error `redirect_uri_mismatch`
- **Penyebab**: Port atau domain di browser saat menekan "Hubungkan" berbeda dengan yang didaftarkan di **Authorized redirect URIs**.
- **Solusi**:
  1. Periksa URL address bar saat server backend berjalan (apakah `http://localhost:8000` atau `http://localhost` atau virtualhost).
  2. Daftarkan URL persis `http://localhost:8000/api/v1/backup/google/callback` di Authorized redirect URIs pada Google Cloud Console.

---

## ⚙️ 4. Konfigurasi (`config/gdrive-backup.php`)

| Opsi | Default | Deskripsi |
|---|---|---|
| `system_id` | `UUID` | ID unik sistem untuk memvalidasi keamanan file restore. |
| `retention` | `3` | Jumlah file cadangan terbaru yang disimpan sebelum file terlama dihapus. |
| `local_disk` | `'local'` | Disk lokal server untuk menyimpan salinan cadangan (`null` untuk menonaktifkan). |
| `zip_password` | `null` | Password enkripsi ZIP (AES-256) untuk mengamankan file backup. |
| `route.prefix` | `'api/v1/backup'` | Prefix URL untuk seluruh endpoint modul backup. |
| `route.middleware` | `['api', 'auth:sanctum']` | Middleware autentikasi route backup. Otomatis disetel `['web', 'auth']` jika Monorepo Inertia. |
| `frontend_url` | `env('GDRIVE_BACKUP_FRONTEND_URL')` | URL host frontend tujuan redirect setelah proses OAuth Google selesai. |
| `frontend_path` | `'/settings/backup'` | Path rute frontend tujuan setelah proses OAuth selesai. |

---

## ⏰ 5. Scheduler Otomatis (Cron)

Pastikan scheduler Laravel sudah aktif di crontab server Anda:
```bash
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```
Jadwal backup otomatis yang diatur pengguna melalui UI akan dieksekusi secara otomatis oleh perintah `gdrive-backup:run`.

---

## 📡 6. Endpoint API

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
