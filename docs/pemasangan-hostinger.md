# Pemasangan SIMAS di Hostinger

Catatan khusus Hostinger. Isinya hanya hal yang sudah terbukti di server.
Urutan perintah aplikasi (migrate, seeder, cache, super admin) tetap mengikuti
[pemasangan.md](pemasangan.md). Dokumen ini menjelaskan cara menjalankannya di
Hostinger.

## Keadaan server

- **Paket:** Business Web Hosting, dipakai bersama tujuh situs.
- **Versi PHP paket:** 8.2, dan **tidak boleh diubah** karena berlaku untuk
  situs lain. SIMAS memakai PHP 8.4 lewat jalur khusus di bawah.
- **PHP 8.4 di terminal:**

  ```bash
  /opt/alt/php84/usr/bin/php
  ```

- **Composer:**

  ```bash
  /opt/alt/php84/usr/bin/php /usr/local/bin/composer2
  ```

Di semua perintah `php artisan ...` dari `pemasangan.md`, ganti `php` dengan
`/opt/alt/php84/usr/bin/php`. Ganti juga `composer` dengan perintah Composer di atas.

## Pemasangan pertama (staging)

### 1. Tambahkan website

Staging ditambahkan sebagai **website tersendiri** di Hostinger. Folder situsnya
adalah `~/domains/<alamat>/`.

### 2. Akses repo private lewat deploy key

Repo diambil dengan deploy key **baca-saja** di `~/.ssh/simas_deploy`, lewat
alias host `github-simas` di `~/.ssh/config`:

```
Host github-simas
    HostName github.com
    User git
    IdentityFile ~/.ssh/simas_deploy
    IdentitiesOnly yes
```

Alamat clone memakai alias itu, bukan `github.com`:

```bash
git clone git@github-simas:<pemilik>/<repo>.git app
```

### 3. Susun folder situs

Isi folder situs `~/domains/<alamat>/`:

- `app/`: hasil clone;
- `public_html`: symlink ke `app/public`;
- `public_html_asli`: folder `public_html` bawaan Hostinger, yang diganti nama.

Perintah `mv` dan `ln` dijalankan **di folder situs**, bukan di dalam `app/`:

```bash
cd ~/domains/<alamat>
git clone git@github-simas:<pemilik>/<repo>.git app
mv public_html public_html_asli
ln -s app/public public_html
```

### 4. PHP 8.4 untuk web

Tambahkan blok berikut **di awal** `public/.htaccess`, persis seperti di
dokumentasi Hostinger:

```apache
<FilesMatch ".(php4|php5|php3|php2|php|phtml)$">
SetHandler application/x-lsphp84
</FilesMatch>
```

Lalu tandai file itu supaya perubahan lokal ini tidak mengganggu `git pull`:

```bash
cd ~/domains/<alamat>/app
git update-index --skip-worktree public/.htaccess
```

Kalau suatu saat `public/.htaccess` di repo ikut berubah, `git pull` akan
menolak menimpa file ini. Di saat seperti itu, cabut tandanya dulu dengan
`git update-index --no-skip-worktree public/.htaccess`. Setelah itu gabungkan
perubahannya, pastikan blok `FilesMatch` tetap di awal, lalu pasang tandanya lagi.

### 5. Dependensi dan `.env`

```bash
cd ~/domains/<alamat>/app
/opt/alt/php84/usr/bin/php /usr/local/bin/composer2 install --no-dev --optimize-autoloader
cp -n .env.example .env
/opt/alt/php84/usr/bin/php artisan key:generate
/opt/alt/php84/usr/bin/php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
```

`key:generate` dan perintah kunci NIK di atas **hanya untuk pemasangan
pertama**. Jangan menjalankan `key:generate` lagi di server yang sudah
dipakai: semua pengguna keluar dari sesi, dan seluruh NIK tidak bisa dibuka
lagi, juga dari backup database. Penjelasan lengkap ada di langkah 2
[pemasangan.md](pemasangan.md).

Isian `.env` yang wajib untuk Hostinger:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://<alamat>
DB_CONNECTION=mariadb
NIK_HMAC_KEY=base64:<hasil perintah kunci NIK>
```

`NIK_HMAC_KEY` wajib diisi. Tanpa kunci ini, NIK tidak bisa disimpan.

**Cadangkan `APP_KEY` dan `NIK_HMAC_KEY` terpisah dari backup database**,
misalnya di pengelola kata sandi milik sekolah. Backup database Hostinger
tidak memuat `.env`. Kalau `APP_KEY` hilang, semua NIK tidak bisa dibuka
lagi. Kalau `NIK_HMAC_KEY` hilang, semua NIK tidak bisa dicari lagi.

Isian `DB_*` lainnya mengikuti database yang dibuat di Hostinger. Setelah
`.env` terisi, batasi aksesnya:

```bash
chmod 600 .env
```

**Setiap kali `.env` diubah, jalankan ulang:**

```bash
/opt/alt/php84/usr/bin/php artisan optimize
```

Tanpa langkah ini, aplikasi tetap memakai nilai lama dari cache config.

### 6. Database, unggahan, cache, super admin

Ikuti langkah 3 sampai 6 di [pemasangan.md](pemasangan.md), dengan
`/opt/alt/php84/usr/bin/php` sebagai pengganti `php`:

```bash
/opt/alt/php84/usr/bin/php artisan migrate --force
/opt/alt/php84/usr/bin/php artisan db:seed --class=PeranDanIzinSeeder --force
/opt/alt/php84/usr/bin/php artisan storage:link
/opt/alt/php84/usr/bin/php artisan optimize
/opt/alt/php84/usr/bin/php artisan simas:buat-super-admin
```

### 7. Cron untuk antrean dan pembersihan

Import siswa diperiksa dan disimpan di antrean, bukan di permintaan web.
Antrean dijalankan oleh **satu** cron yang memanggil penjadwal Laravel setiap
menit. Penjadwal itu menjalankan:

- `queue:work --stop-when-empty`: memproses job import, lalu berhenti saat
  antrean kosong. Selama satu worker masih berjalan, menit berikutnya tidak
  memulai worker kedua.
- `simas:bersihkan-import`, setiap 5 menit: import yang macet ditandai gagal,
  import yang 24 jam tidak dilanjutkan dibatalkan, dan file unggahan sisa
  serta file sementara yang lebih tua dari 1 hari dihapus.

**File sementara** ada di `storage/app/private/tmp`, bukan di folder temp
sistem. Yang disimpan di sana: file galat import yang diunduh operator
(bisa berisi NIK), template import, dan file contoh import. Folder temp
sistem dipakai bersama situs lain di hosting ini, jadi tidak dipakai.
Folder `tmp` dibuat otomatis saat pertama dibutuhkan dan tidak masuk git.

- File galat dan template dihapus begitu dikirim ke browser, juga kalau
  unduhan terputus di tengah jalan.
- File contoh import dihapus begitu disalin ke folder import.
- File yang gagal ditulis langsung dihapus.
- Sebuah file hanya tertinggal kalau proses PHP mati di antara pembuatan
  dan pengirimannya. File seperti itu dihapus oleh `simas:bersihkan-import`
  setelah 1 hari.

Folder `tmp` dibuat dengan izin `0700`, yaitu hanya bisa dibaca dan ditulis
oleh pengguna yang membuatnya. Di Hostinger, PHP untuk web dan cron
berjalan sebagai pengguna akun yang sama, jadi ini tidak masalah. Di server
yang pengguna cron atau SSH-nya berbeda dari pengguna web (misalnya VPS),
pastikan folder `storage/app/private/tmp` dimiliki pengguna web. Kalau
tidak, unduhan file galat dan template gagal dengan pesan "Tidak bisa
membuat file sementara".

**a. Isi `.env`.** Tambahkan, lalu jalankan `optimize`:

```dotenv
QUEUE_CONNECTION=database
```

Jangan isi `DB_QUEUE_RETRY_AFTER`. Bawaannya 1200 detik, dan nilai itu harus
tetap di atas batas waktu job import (900 detik).

**b. Cari path absolut folder aplikasi:**

```bash
cd ~/domains/<alamat>/app
pwd
```

Hasilnya misalnya `/home/u123456789/domains/<alamat>/app`. Path itu dipakai
utuh di baris cron; jangan pakai `~`.

**c. Buat cron di hPanel** → Advanced → Cron Jobs. Isi formnya begini:

- **Jenis:** pilih **Kustom**.
- **Perintah:**

  ```bash
  /opt/alt/php84/usr/bin/php /home/u123456789/domains/<alamat>/app/artisan schedule:run
  ```

- **Pilihan Umum:** biarkan kosong.
- **Kelima kolom jadwal** (menit, jam, hari, bulan, hari dalam minggu): di
  setiap kolom pilih opsi bertanda `(*)`, artinya "setiap". Jadwal yang
  tersimpan harus `* * * * *`.

Ganti `/home/u123456789/domains/<alamat>/app` dengan hasil `pwd` di atas.
Jangan pakai `php` saja: di cron, `php` adalah PHP 8.2 bawaan paket.

Perintahnya **tanpa pengalihan keluaran**. Hostinger menangkap sendiri
keluaran setiap cron, yang bisa dilihat lewat tombol **Lihat Output** di
daftar Cron Jobs. Pengalihan ke `storage/logs/cron.log` tidak menghasilkan
apa-apa di Hostinger.

Untuk hosting lain yang tidak menangkap keluaran cron, pakai versi dengan
pengalihan ke file:

```bash
/opt/alt/php84/usr/bin/php /home/u123456789/domains/<alamat>/app/artisan schedule:run >> /home/u123456789/domains/<alamat>/app/storage/logs/cron.log 2>&1
```

**d. Pastikan cron benar-benar berjalan.**

1. Lihat jadwalnya dikenali:

   ```bash
   /opt/alt/php84/usr/bin/php artisan schedule:list
   ```

   Harus ada `queue:work` (setiap menit) dan `simas:bersihkan-import`
   (setiap 5 menit).
2. Tunggu dua sampai tiga menit, lalu tekan **Lihat Output** pada cron itu
   di daftar Cron Jobs. Harus ada baris `Running [...] queue:work`. Kalau
   kosong atau berisi galat, periksa path PHP dan artisan di perintah cron.
3. Uji dari aplikasi: unggah file import. Dalam satu sampai dua menit status
   harus berubah dari "Menunggu diperiksa". Halaman import memberi tahu kalau
   setelah 3 menit belum diproses.

**Arti "Has Mutex" di `schedule:list`.** Tanda ini muncul di baris
`queue:work` selama sebuah worker sedang berjalan. Selama tanda itu ada,
penjadwal tidak memulai worker kedua. Ini wajar dan tidak perlu diapa-apakan.

Kalau worker dihentikan paksa oleh hosting, tanda itu tertinggal, tetapi
kedaluwarsa sendiri setelah 30 menit, sama dengan batas import macet.
Sesudah itu, menit berikutnya memulai worker baru. Untuk menghapusnya lebih
cepat:

```bash
/opt/alt/php84/usr/bin/php artisan schedule:clear-cache
```

Jalankan perintah ini hanya kalau tidak ada import yang sedang diproses,
yaitu peringatan "Import siswa sedang diproses" tidak tampil di panel.
Perintah ini menghapus tanda semua jadwal, juga milik worker yang masih
berjalan, sehingga menit berikutnya bisa memulai worker kedua.

**e. Batas waktu job.** Batas 900 detik per job hanya dipaksakan kalau
ekstensi `pcntl` ada (`/opt/alt/php84/usr/bin/php -m | grep pcntl`). Tanpa
`pcntl`, job yang tidak selesai ditandai gagal oleh `simas:bersihkan-import`
setelah 30 menit. Kalau job itu ternyata masih berjalan, hasilnya dibuang:
pemeriksaan tidak menimpa status gagal, dan simpan memeriksa ulang statusnya
tepat sebelum commit, lalu membatalkan seluruh transaksinya. Jadi tidak ada
data siswa yang setengah tersimpan, dan import yang ditandai gagal memang
tidak menyimpan apa pun.

**f. Jalankan import di luar jam sibuk.** Selama satu import diperiksa atau
disimpan, yang menunggu sampai import selesai adalah:

- penyimpanan data siswa, wali murid, hubungan wali, penempatan rombel, dan
  perubahan rombel;
- setiap penyimpanan di layar Pengguna dan layar Guru/Staf, serta perubahan
  profil sendiri (nama, nomor HP, alamat, foto);
- masuk dan keluar untuk akun siswa atau wali yang **diubah** oleh import
  itu (barisnya di file berbeda dari data di sistem).

Masuk dan keluar akun lain, termasuk dengan "Ingat saya", serta
mengaktifkan, menonaktifkan, dan mengatur ulang kata sandi, tidak menunggu.
Di laptop pengembang, 500 baris butuh sekitar 25 detik untuk pemeriksaan
dan 25 detik untuk simpan; di server bersama bisa lebih lama. Selama itu
setiap halaman panel menampilkan peringatan "Import siswa sedang
diproses". Karena itu:

- unggah dan simpan import di luar jam kerja tata usaha, misalnya sore atau
  malam;
- bagi data besar menjadi beberapa file, misalnya per tingkat, dan jalankan
  satu per satu;
- kalau ada yang melaporkan "Coba lagi" saat menyimpan data siswa atau akun,
  tunggu sampai peringatan di atas halaman hilang.

### 8. Periksa sebelum situs dibuka

```bash
/opt/alt/php84/usr/bin/php artisan about
```

Pastikan di bagian Environment tertulis **Debug Mode OFF**. Kalau masih ON,
periksa `APP_DEBUG=false` di `.env`, jalankan `optimize` lagi, lalu ulangi
pemeriksaan ini.

## Pembaruan

Pembaruan dijalankan dengan `scripts/perbarui.sh` dari folder `app/`:

```bash
cd ~/domains/<alamat>/app
PHP_BIN=/opt/alt/php84/usr/bin/php COMPOSER_PHAR=/usr/local/bin/composer2 bash scripts/perbarui.sh
```

Untuk melihat perintah yang akan dijalankan tanpa menjalankannya:

```bash
PHP_BIN=/opt/alt/php84/usr/bin/php COMPOSER_PHAR=/usr/local/bin/composer2 bash scripts/perbarui.sh --dry-run
```

Skrip ini menjalankan berurutan: `down`, `git pull --ff-only`,
`composer install --no-dev --optimize-autoloader`, `migrate --force`,
`PeranDanIzinSeeder`, `optimize:clear`, `optimize`, `up`.

- Skrip menolak jalan kalau folder saat ini tidak berisi file `artisan`.
- **Kalau satu langkah gagal**, skrip berhenti dan situs dibiarkan dalam mode
  pemeliharaan. Skrip mencetak nama langkah yang gagal dan perintah untuk
  menyalakan situs lagi:

  ```bash
  /opt/alt/php84/usr/bin/php artisan up
  ```

  Perbaiki penyebabnya dulu, ulangi langkah yang gagal dan langkah sesudahnya,
  baru nyalakan situs.
