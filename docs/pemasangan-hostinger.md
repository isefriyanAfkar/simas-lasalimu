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
```

Isian `.env` yang wajib untuk Hostinger:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://<alamat>
DB_CONNECTION=mariadb
```

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

### 7. Periksa sebelum situs dibuka

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
