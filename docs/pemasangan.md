# Pemasangan SIMAS

Perintah sisi aplikasi untuk memasang dan memperbarui SIMAS di server. Langkah
khusus penyedia hosting (panel, domain, PHP, akses SSH) tidak dibahas di sini.

Kebutuhan server: PHP 8.4, Composer, MariaDB 11.8. Node dan `npm run build`
**tidak diperlukan**: aset Filament disalin dari `vendor/` oleh Composer
(`filament:upgrade` di script `post-autoload-dump`).

Semua perintah dijalankan dari folder aplikasi.

**Catatan MariaDB 11.6.2 ke atas.** Sejak versi itu `innodb_snapshot_isolation`
bawaannya ON. Akibatnya, transaksi panjang yang mengubah baris yang baru saja
diubah sesi lain ditolak dengan galat 1020 "Record has changed since last
read". SIMAS menanganinya dua cara:

- import siswa mematikan variabel itu hanya untuk sesinya sendiri selama
  pemeriksaan dan simpan, lalu mengembalikan nilai semula. Dengan begitu,
  mengatur ulang kata sandi atau menonaktifkan akun siswa di tengah import
  tidak menggagalkan import, dan perubahan itu tidak tertimpa;
- penyimpanan lain dicoba ulang otomatis. Kalau tetap gagal, layar
  menampilkan "Sedang ada perubahan lain pada data ini. Coba lagi.".

Tidak ada yang perlu diatur di server. MariaDB sebelum 11.6.2 dan MySQL tidak
punya variabel itu dan memang selalu berperilaku seperti OFF. Untuk melihat
nilainya: `SHOW VARIABLES LIKE 'innodb_snapshot_isolation';`

## Pemasangan pertama

### 1. Unduh dependensi

```bash
composer install --no-dev --optimize-autoloader
```

### 2. Siapkan `.env`

```bash
cp -n .env.example .env
php artisan key:generate
```

Kedua perintah ini **hanya untuk server yang belum punya `.env`**. `cp -n` tidak
menimpa `.env` yang sudah ada.

> **Jangan menjalankan `key:generate` lagi pada server yang sudah dipakai.**
> Perintah itu mengganti `APP_KEY`. Akibatnya semua pengguna keluar dari
> sesi, dan semua data yang dienkripsi dengan kunci lama tidak bisa dibaca
> lagi, termasuk **seluruh NIK** pengguna. NIK yang tidak bisa dibuka tidak
> bisa dipulihkan, juga dari backup database.

Lalu buat kunci NIK, sekali saja per server:

```bash
php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
```

Salin hasilnya ke `NIK_HMAC_KEY` di `.env` (lihat di bawah). Kunci ini
dipakai untuk mencari NIK dan menolak NIK kembar, dan sengaja terpisah dari
`APP_KEY`. Tanpa kunci ini, NIK tidak bisa disimpan. Server yang sudah
berjalan sebelum ada fitur NIK cukup menambahkan kunci ini sekali; jangan
pernah menggantinya setelah ada NIK tersimpan.

Lalu ubah isian berikut di `.env`:

```dotenv
APP_NAME="SIMAS"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://alamat-sekolah.sch.id
APP_TIMEZONE=Asia/Makassar
APP_LOCALE=id

LOG_LEVEL=error

DB_CONNECTION=mariadb
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=nama_database
DB_USERNAME=nama_pengguna_database
DB_PASSWORD=kata_sandi_database

NIK_HMAC_KEY=base64:hasil_perintah_di_atas
```

- `APP_DEBUG` wajib `false` di produksi, karena kalau `true` pesan galat menampilkan isi konfigurasi.
- Kata sandi database hanya disimpan di `.env` server, jangan di repo.
- `NIK_HMAC_KEY` wajib diisi di produksi.

#### Cadangkan `APP_KEY` dan `NIK_HMAC_KEY`

Simpan salinan `APP_KEY` dan `NIK_HMAC_KEY` di tempat aman, **terpisah dari
backup database** (misalnya pengelola kata sandi milik sekolah), dan jangan
di repo.

- Backup database saja tidak cukup. NIK di database terenkripsi dengan
  `APP_KEY` dan dicari lewat `NIK_HMAC_KEY`.
- Kalau `APP_KEY` hilang, **semua NIK tidak bisa dibuka lagi**.
- Kalau `NIK_HMAC_KEY` hilang, **semua NIK tidak bisa dicari lagi**, dan NIK
  kembar tidak bisa dicegah.
- Kunci tidak disimpan bersama backup database, supaya orang yang memegang
  file backup tidak otomatis bisa membaca NIK.

### 3. Buat tabel dan data awal

```bash
php artisan migrate --force
php artisan db:seed --class=PeranDanIzinSeeder --force
```

- `migrate --force` membuat semua tabel, termasuk tabel pengaturan dan nilai awalnya.
- `PeranDanIzinSeeder` membuat role `super_admin` dan `operator` serta izin
  pengguna. Seeder ini aman dijalankan berulang.
- **Jangan** menjalankan `php artisan db:seed` tanpa `--class`. `DatabaseSeeder`
  memakai factory untuk data contoh, dan factory butuh paket pengembangan yang
  tidak terpasang dengan `--no-dev`.

### 4. Tautkan folder unggahan dan periksa izin tulis

```bash
php artisan storage:link
```

Logo sekolah disimpan di `storage/app/public` dan disajikan lewat `public/storage`.

Foto pengguna disimpan di `storage/app/private/foto-pengguna`. Folder itu
tidak bisa dibuka lewat URL publik. Foto hanya tampil lewat aplikasi kepada
pengguna yang sudah login dan berhak melihatnya. Jangan memindahkan atau
menautkan folder ini ke `public/`. Folder ini tidak ada di backup database,
jadi cadangkan bersama berkas `storage/`.

Folder `storage/` dan `bootstrap/cache/` harus bisa ditulis oleh pengguna yang
menjalankan PHP di server web. Kalau tidak, halaman gagal dimuat dan unggahan
logo gagal disimpan.

### 5. Cache untuk produksi

```bash
php artisan optimize
```

`optimize` sudah mencakup cache config, route, view, dan event, cache komponen
dan ikon Filament (`filament:optimize`), serta cache kelas pengaturan.

### 6. Buat super admin pertama

```bash
php artisan simas:buat-super-admin
```

Perintah ini menanyakan nama, username, kata sandi, dan konfirmasi kata sandi.
Isiannya diperiksa dengan aturan yang sama dengan aplikasi: username unik dan
kata sandi minimal 8 karakter. Perintah ini juga bisa dijalankan lagi kapan pun
untuk menambah super admin. Pembuatan tercatat di Log Aktivitas dengan pelaku
"Sistem".

Log Aktivitas juga dipakai aplikasi: "Aktifkan kembali" di Guru dan Staf hanya
mengaktifkan lagi akun guru kalau entri log penonaktifan akunnya masih ada,
jadi jangan memangkas log aktivitas (misalnya dengan `activitylog:clean`)
sembarangan.

Setelah itu buka `APP_URL`. Halaman awal langsung mengalihkan ke halaman login.

## Pembaruan

```bash
php artisan down

git pull
composer install --no-dev --optimize-autoloader

php artisan migrate --force
php artisan db:seed --class=PeranDanIzinSeeder --force

php artisan optimize:clear
php artisan optimize

php artisan up
```

- `optimize:clear` membuang cache lama, termasuk cache Filament, sebelum cache
  baru dibuat, supaya perubahan config, route, dan view ikut terbaca.
- Kalau **salah satu** langkah gagal (`git pull`, `composer install`, `migrate`,
  atau yang lain), aplikasi tetap dalam mode pemeliharaan dan pengunjung melihat
  halaman "sedang diperbarui". Perbaiki penyebabnya, ulangi langkah yang gagal
  dan langkah sesudahnya, lalu jalankan `php artisan up`.
