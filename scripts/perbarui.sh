#!/usr/bin/env bash
#
# Memperbarui SIMAS di server: down, git pull, composer install, migrate,
# seeder peran dan izin, cache ulang, up.
#
# Pemakaian (dari folder aplikasi, yang berisi file artisan):
#   PHP_BIN=<jalur php> COMPOSER_PHAR=<jalur composer> bash scripts/perbarui.sh
#   PHP_BIN=<jalur php> COMPOSER_PHAR=<jalur composer> bash scripts/perbarui.sh --dry-run
#
# - PHP_BIN kosong       -> memakai "php".
# - COMPOSER_PHAR kosong -> memakai perintah "composer".
# - COMPOSER_PHAR diisi  -> Composer dijalankan sebagai "$PHP_BIN $COMPOSER_PHAR".
# - --dry-run            -> hanya mencetak perintah, tidak menjalankan apa pun.
#
# Kalau satu langkah gagal, skrip berhenti dan situs dibiarkan dalam mode
# pemeliharaan, supaya pengunjung tidak melihat aplikasi yang setengah diperbarui.

set -uo pipefail

PHP_BIN="${PHP_BIN:-}"
PHP_BIN="${PHP_BIN:-php}"
COMPOSER_PHAR="${COMPOSER_PHAR:-}"
DRY_RUN=0

for argument in "$@"; do
    case "$argument" in
        --dry-run)
            DRY_RUN=1
            ;;
        *)
            echo "Pilihan tidak dikenal: $argument" >&2
            echo "Pemakaian: PHP_BIN=<jalur php> COMPOSER_PHAR=<jalur composer> bash scripts/perbarui.sh [--dry-run]" >&2
            exit 2
            ;;
    esac
done

if [ ! -f artisan ]; then
    echo "Berhenti: file artisan tidak ada di folder ini ($(pwd))." >&2
    echo "Jalankan skrip ini dari folder aplikasi SIMAS, misalnya: cd ~/domains/<alamat>/app" >&2
    exit 1
fi

if [ -n "$COMPOSER_PHAR" ]; then
    COMPOSER=("$PHP_BIN" "$COMPOSER_PHAR")
else
    COMPOSER=(composer)
fi

MAINTENANCE_ON=0

fail() {
    local step="$1"

    echo >&2
    echo "GAGAL pada langkah: $step" >&2

    if [ "$MAINTENANCE_ON" -eq 1 ]; then
        echo "Situs masih dalam mode pemeliharaan." >&2
        echo "Perbaiki penyebabnya, ulangi langkah yang gagal dan langkah sesudahnya," >&2
        echo "lalu nyalakan situs lagi dengan:" >&2
        echo "    $PHP_BIN artisan up" >&2
    else
        echo "Situs belum masuk mode pemeliharaan dan tetap berjalan seperti sebelumnya." >&2
    fi

    exit 1
}

run() {
    local step="$1"
    shift

    echo "==> $step"
    echo "    $*"

    if [ "$DRY_RUN" -eq 1 ]; then
        return 0
    fi

    "$@" || fail "$step"
}

if [ "$DRY_RUN" -eq 1 ]; then
    echo "(dry-run: perintah hanya dicetak, tidak dijalankan)"
fi

run "Mode pemeliharaan" "$PHP_BIN" artisan down
MAINTENANCE_ON=1

run "Ambil kode terbaru" git pull --ff-only
run "Pasang dependensi" "${COMPOSER[@]}" install --no-dev --optimize-autoloader --no-interaction
run "Migrasi database" "$PHP_BIN" artisan migrate --force
run "Seeder peran dan izin" "$PHP_BIN" artisan db:seed --class=PeranDanIzinSeeder --force
run "Buang cache lama" "$PHP_BIN" artisan optimize:clear
run "Buat cache baru" "$PHP_BIN" artisan optimize
run "Nyalakan situs" "$PHP_BIN" artisan up
MAINTENANCE_ON=0

if [ "$DRY_RUN" -eq 1 ]; then
    echo "Selesai (dry-run)."
else
    echo "Selesai: SIMAS sudah diperbarui dan situs menyala lagi."
fi
