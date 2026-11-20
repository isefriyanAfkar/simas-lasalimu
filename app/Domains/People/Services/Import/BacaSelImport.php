<?php

namespace App\Domains\People\Services\Import;

use PhpOffice\PhpSpreadsheet\Shared\Date;
use Throwable;

/**
 * Reads one cell of the student import file into the value the services
 * expect. Every method returns [value, error]: an empty cell is [null,
 * null] (an empty cell never changes stored data), an unreadable one is
 * [null, message]. A message never contains the cell's value, so a NIK can
 * never end up in a message, a preview or a log.
 *
 * Only the form is checked here; the services check the rules (NIS length,
 * phone number format, unique NIK, ...).
 */
final class BacaSelImport
{
    public const string PESAN_TANGGAL = 'Tanggal tidak dikenali. Tulis hari-bulan-tahun, misalnya 31-12-2010.';

    public const string PESAN_JENIS_KELAMIN = 'Jenis kelamin tidak dikenali. Tulis L, P, Laki-laki, atau Perempuan.';

    /**
     * @return array{string|null, string|null}
     */
    public static function teks(mixed $sel): array
    {
        if (is_bool($sel)) {
            return [$sel ? 'ya' : 'tidak', null];
        }

        $teks = is_scalar($sel) ? trim((string) $sel) : '';

        return [$teks === '' ? null : $teks, null];
    }

    /**
     * NIS, NISN or NIK. A number cell is refused, never guessed: Excel drops
     * leading zeros and writes long numbers in scientific notation, losing
     * digits.
     *
     * @return array{string|null, string|null}
     */
    public static function identitas(mixed $sel, string $label): array
    {
        $pesan = "Kolom {$label} harus berformat Teks: angka di Excel bisa kehilangan 0 di depan atau berubah menjadi notasi ilmiah.";

        if (is_int($sel) || is_float($sel)) {
            return [null, $pesan];
        }

        [$teks] = self::teks($sel);

        if ($teks !== null && preg_match('/^\d+(?:[.,]\d+)?e[+-]?\d+$/i', $teks) === 1) {
            return [null, $pesan];
        }

        return [$teks, null];
    }

    /**
     * A phone number as text, or a number cell, which has lost the leading 0
     * of a mobile number: 81234567890 is read as 081234567890. The service
     * normalizes and checks the format.
     *
     * @return array{string|null, string|null}
     */
    public static function noHp(mixed $sel): array
    {
        if (is_int($sel) || (is_float($sel) && floor($sel) === $sel)) {
            $angka = sprintf('%.0f', $sel);

            return [str_starts_with($angka, '8') ? "0{$angka}" : $angka, null];
        }

        return self::teks($sel);
    }

    /**
     * An Excel date (a number), or text day-month-year with -, / or . (and
     * year-month-day). Returned as Y-m-d.
     *
     * @return array{string|null, string|null}
     */
    public static function tanggal(mixed $sel): array
    {
        if (is_int($sel) || is_float($sel)) {
            if ($sel < 1) {
                return [null, self::PESAN_TANGGAL];
            }

            try {
                return [Date::excelToDateTimeObject(floor($sel))->format('Y-m-d'), null];
            } catch (Throwable) {
                return [null, self::PESAN_TANGGAL];
            }
        }

        [$teks] = self::teks($sel);

        if ($teks === null) {
            return [null, null];
        }

        if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})$/', $teks, $cocok) === 1) {
            [, $hari, $bulan, $tahun] = $cocok;
        } elseif (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $teks, $cocok) === 1) {
            [, $tahun, $bulan, $hari] = $cocok;
        } else {
            return [null, self::PESAN_TANGGAL];
        }

        if (! checkdate((int) $bulan, (int) $hari, (int) $tahun)) {
            return [null, self::PESAN_TANGGAL];
        }

        return [sprintf('%04d-%02d-%02d', $tahun, $bulan, $hari), null];
    }

    /**
     * L, P, Laki-laki or Perempuan, in any case.
     *
     * @return array{string|null, string|null}
     */
    public static function jenisKelamin(mixed $sel): array
    {
        [$teks] = self::teks($sel);

        if ($teks === null) {
            return [null, null];
        }

        return match (str_replace([' ', '-'], '', mb_strtolower($teks))) {
            'l', 'lk', 'lakilaki', 'pria' => ['laki-laki', null],
            'p', 'pr', 'perempuan', 'wanita' => ['perempuan', null],
            default => [null, self::PESAN_JENIS_KELAMIN],
        };
    }

    /**
     * @return array{bool|null, string|null}
     */
    public static function yaTidak(mixed $sel): array
    {
        if (is_bool($sel)) {
            return [$sel, null];
        }

        [$teks] = self::teks($sel);

        if ($teks === null) {
            return [null, null];
        }

        return match (mb_strtolower($teks)) {
            'ya', 'y', 'iya', '1', 'true' => [true, null],
            'tidak', 't', 'n', 'no', '0', 'false' => [false, null],
            default => [null, 'Tulis ya atau tidak.'],
        };
    }

    /**
     * One of $pilihan's keys, matched without regard to case or surrounding
     * spaces; returns its value.
     *
     * @template T
     *
     * @param  array<string, T>  $pilihan
     * @return array{T|null, string|null}
     */
    public static function pilihan(mixed $sel, array $pilihan, string $label): array
    {
        [$teks] = self::teks($sel);

        if ($teks === null) {
            return [null, null];
        }

        foreach ($pilihan as $kunci => $nilai) {
            if (mb_strtolower((string) $kunci) === mb_strtolower($teks)) {
                return [$nilai, null];
            }
        }

        return [null, "{$label} tidak dikenali. Pilih dari daftar di template."];
    }
}
