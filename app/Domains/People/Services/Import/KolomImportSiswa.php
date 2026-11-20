<?php

namespace App\Domains\People\Services\Import;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/**
 * The columns of the student import file, in template order: one row is
 * one student with their rombel, father, mother and other guardian. The
 * template (TemplateImportSiswa) and the reader (BacaFileImportSiswa) both
 * follow this list, so they cannot drift apart.
 *
 * Kinds decide how a cell is read (BacaSelImport):
 * - identitas: NIS, NISN, NIK. Must be a text cell: a number cell may have
 *   lost leading zeros or turned into scientific notation, so it is refused
 * - hp: a phone number; a number cell without the leading 0 (8...) is read
 *   as 08...
 * - tanggal: an Excel date or text day-month-year
 * - pilihan kinds (jenis_kelamin, agama, rombel, ya_tidak, wali_utama):
 *   a dropdown in the template, read case-insensitively
 * - teks: anything else, trimmed
 */
final class KolomImportSiswa
{
    /**
     * The most data rows one file may hold.
     */
    public const int MAKS_BARIS = 1000;

    public const string LEMBAR_DATA = 'Siswa';

    public const string LEMBAR_DAFTAR = 'Daftar';

    public const string LEMBAR_PETUNJUK = 'Petunjuk';

    /**
     * key => [header, kind].
     *
     * @var array<string, array{string, string}>
     */
    public const array KOLOM = [
        'nis' => ['NIS', 'identitas'],
        'nisn' => ['NISN', 'identitas'],
        'nama' => ['Nama siswa', 'teks'],
        'jenis_kelamin' => ['Jenis kelamin', 'jenis_kelamin'],
        'agama' => ['Agama', 'agama'],
        'tempat_lahir' => ['Tempat lahir', 'teks'],
        'tanggal_lahir' => ['Tanggal lahir', 'tanggal'],
        'alamat' => ['Alamat', 'teks'],
        'no_hp' => ['Nomor HP siswa', 'hp'],
        'nik' => ['NIK siswa', 'identitas'],
        'tanggal_masuk' => ['Tanggal masuk', 'tanggal'],
        'asal_sekolah' => ['Asal sekolah', 'teks'],
        'rombel' => ['Rombel', 'rombel'],
        'ayah_nama' => ['Nama ayah', 'teks'],
        'ayah_no_hp' => ['Nomor HP ayah', 'hp'],
        'ayah_nik' => ['NIK ayah', 'identitas'],
        'ayah_pekerjaan' => ['Pekerjaan ayah', 'teks'],
        'ayah_meninggal' => ['Ayah meninggal', 'ya_tidak'],
        'ibu_nama' => ['Nama ibu', 'teks'],
        'ibu_no_hp' => ['Nomor HP ibu', 'hp'],
        'ibu_nik' => ['NIK ibu', 'identitas'],
        'ibu_pekerjaan' => ['Pekerjaan ibu', 'teks'],
        'ibu_meninggal' => ['Ibu meninggal', 'ya_tidak'],
        'wali_nama' => ['Nama wali', 'teks'],
        'wali_no_hp' => ['Nomor HP wali', 'hp'],
        'wali_nik' => ['NIK wali', 'identitas'],
        'wali_pekerjaan' => ['Pekerjaan wali', 'teks'],
        'wali_utama' => ['Wali utama', 'wali_utama'],
    ];

    public static function judul(string $kolom): string
    {
        return self::KOLOM[$kolom][0];
    }

    /**
     * Template column letter of $kolom (A for the first).
     */
    public static function huruf(string $kolom): string
    {
        $indeks = array_search($kolom, array_keys(self::KOLOM), true);

        return Coordinate::stringFromColumnIndex((int) $indeks + 1);
    }

    /**
     * @return list<string>
     */
    public static function berjenis(string $jenis): array
    {
        return array_keys(array_filter(self::KOLOM, fn (array $kolom): bool => $kolom[1] === $jenis));
    }
}
