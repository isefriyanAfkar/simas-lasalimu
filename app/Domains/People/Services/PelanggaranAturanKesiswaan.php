<?php

namespace App\Domains\People\Services;

use RuntimeException;

/**
 * Thrown when a student operation breaks a rule that is not tied to a form
 * field. The message is safe to show to the user.
 */
class PelanggaranAturanKesiswaan extends RuntimeException
{
    public static function sudahTidakAktif(): self
    {
        return new self('Siswa ini sudah tidak aktif. Aktifkan kembali dulu bila statusnya perlu diubah.');
    }

    public static function sudahAktif(): self
    {
        return new self('Siswa ini masih aktif.');
    }

    public static function waliUtamaTidakBolehDilepas(string $namaWali, string $namaSiswa): self
    {
        return new self("{$namaWali} adalah wali utama {$namaSiswa}. Pilih wali utama lain dulu.");
    }

    /**
     * @param  list<string>  $namaSiswa
     */
    public static function waliUtamaTidakBolehMeninggal(string $namaWali, array $namaSiswa): self
    {
        return new self("{$namaWali} masih wali utama ".implode(', ', $namaSiswa).'. Pilih wali utama lain dulu.');
    }

    public static function waliMeninggalTidakBolehUtama(string $namaWali): self
    {
        return new self("{$namaWali} ditandai meninggal dan tidak bisa dijadikan wali utama.");
    }

    public static function sudahWaliUtama(string $namaWali): self
    {
        return new self("{$namaWali} sudah menjadi wali utama.");
    }

    public static function hubunganSudahTidakAda(): self
    {
        return new self('Data wali ini sudah diubah atau dilepas pengguna lain. Muat ulang halaman.');
    }

    public static function waliMasihTerhubung(string $namaWali, int $jumlahSiswa): self
    {
        return new self("{$namaWali} masih terhubung ke {$jumlahSiswa} siswa. Lepas dulu dari semua siswanya.");
    }
}
