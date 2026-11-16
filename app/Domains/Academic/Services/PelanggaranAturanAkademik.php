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
}
