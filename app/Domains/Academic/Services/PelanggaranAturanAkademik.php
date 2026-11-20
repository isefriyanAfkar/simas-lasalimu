<?php

namespace App\Domains\Academic\Services;

use RuntimeException;

/**
 * Thrown when an academic operation breaks a rule that is not tied to a form
 * field (activation, deletion, missing active period). The message is safe
 * to show to the user.
 */
class PelanggaranAturanAkademik extends RuntimeException
{
    public static function tahunAjaranAktifTidakBolehDihapus(): self
    {
        return new self('Tahun ajaran yang sedang aktif tidak boleh dihapus. Aktifkan tahun ajaran lain dulu.');
    }

    public static function tahunAjaranDipakaiRombel(string $nama, int $jumlahRombel): self
    {
        return new self("Tahun ajaran {$nama} sudah dipakai oleh {$jumlahRombel} rombel. Hapus rombelnya dulu.");
    }

    public static function tahunAjaranDipakaiImport(string $nama, int $jumlahImport): self
    {
        return new self("Tahun ajaran {$nama} sudah dipakai oleh {$jumlahImport} import siswa, jadi tidak bisa dihapus.");
    }

    public static function semesterBukanMilikTahunAjaranAktif(): self
    {
        return new self('Semester hanya bisa diaktifkan kalau tahun ajarannya sedang aktif.');
    }

    public static function belumAdaTahunAjaranAktif(): self
    {
        return new self('Belum ada tahun ajaran aktif. Aktifkan tahun ajaran di menu Tahun Ajaran.');
    }

    public static function belumAdaSemesterAktif(): self
    {
        return new self('Belum ada semester aktif. Aktifkan tahun ajaran di menu Tahun Ajaran.');
    }

    public static function rombelDipakaiSiswa(string $namaRombel, int $jumlahSiswa): self
    {
        return new self("Rombel {$namaRombel} sudah dipakai oleh {$jumlahSiswa} siswa dan tidak bisa dihapus.");
    }

    public static function rombelPenuh(string $namaRombel, int $kapasitas): self
    {
        return new self("Rombel {$namaRombel} sudah penuh ({$kapasitas} siswa aktif). Naikkan kapasitasnya dulu.");
    }

    public static function siswaTidakAktif(string $namaSiswa): self
    {
        return new self("{$namaSiswa} tidak aktif dan tidak bisa ditempatkan.");
    }

    public static function siswaSudahDiRombel(string $namaSiswa, string $namaRombel): self
    {
        return new self("{$namaSiswa} sudah di {$namaRombel}.");
    }

    public static function siswaBelumPunyaRombel(string $namaSiswa, string $tahunAjaran): self
    {
        return new self("{$namaSiswa} belum punya rombel di tahun ajaran {$tahunAjaran}. Tempatkan dulu, baru bisa dipindah.");
    }

    public static function tahunAjaranSudahLewat(string $tahunAjaran): self
    {
        return new self("Tahun ajaran {$tahunAjaran} sudah lewat. Pilih rombel di tahun ajaran aktif atau sesudahnya.");
    }

    public static function penempatanAdalahRiwayat(string $namaSiswa): self
    {
        return new self("{$namaSiswa} sudah tidak aktif. Penempatannya menjadi riwayat dan tidak bisa dihapus.");
    }
}
