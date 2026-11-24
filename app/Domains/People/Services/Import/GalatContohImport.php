<?php

namespace App\Domains\People\Services\Import;

use RuntimeException;

/**
 * Why ContohImportSiswa makes no file. The message is meant for the
 * terminal.
 */
final class GalatContohImport extends RuntimeException
{
    public static function production(): self
    {
        return new self('Perintah ini tidak boleh dijalankan di production.');
    }

    public static function jumlahBaris(): self
    {
        return new self(sprintf('Jumlah baris harus 1 sampai %d.', KolomImportSiswa::MAKS_BARIS));
    }

    public static function tahunAjaranTidakAda(string $nama): self
    {
        return new self("Tahun ajaran {$nama} tidak ditemukan.");
    }

    public static function tahunAjaranLewat(string $nama): self
    {
        return new self("Tahun ajaran {$nama} sudah lewat; import hanya menerima tahun ajaran aktif atau sesudahnya.");
    }

    public static function kursiKurang(string $tahunAjaran, int $kursi, int $jumlahBaris): self
    {
        return new self("Kursi kosong di rombel {$tahunAjaran} hanya {$kursi}, kurang untuk {$jumlahBaris} baris.");
    }
}
