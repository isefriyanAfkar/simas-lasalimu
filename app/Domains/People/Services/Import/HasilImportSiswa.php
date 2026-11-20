<?php

namespace App\Domains\People\Services\Import;

/**
 * The outcome of one run of the import (ProsesImportSiswa), as stored on
 * ImportSiswa: the counts, the errors and the rombel moves.
 *
 * - galat: {baris, kolom, pesan}; baris null and kolom null for the file
 *   as a whole. Ordered by row, then by template column.
 * - pindah: students that change rombel, {baris, nis, nama, dari, ke}.
 */
final class HasilImportSiswa
{
    /**
     * The counts, in the order the screen shows them. A row without errors
     * is counted in exactly one of the four siswa_ keys: baru, else
     * dipindah, else diperbarui, else tanpa_perubahan.
     *
     * @var list<string>
     */
    public const array RINGKASAN = [
        'siswa_baru',
        'siswa_diperbarui',
        'siswa_dipindah',
        'siswa_tanpa_perubahan',
        'wali_baru',
        'wali_dipakai_bersama',
        'baris_bergalat',
    ];

    /**
     * @param  array<string, int>  $ringkasan
     * @param  list<array{baris: int|null, kolom: string|null, pesan: string}>  $galat
     * @param  list<array{baris: int, nis: string, nama: string, dari: string, ke: string}>  $pindah
     */
    public function __construct(
        public readonly array $ringkasan,
        public readonly array $galat,
        public readonly array $pindah,
    ) {}

    /**
     * A file that could not be read as a whole: its errors, nothing else.
     *
     * @param  list<string>  $pesan
     */
    public static function galatFile(array $pesan): self
    {
        return new self(
            array_fill_keys(self::RINGKASAN, 0),
            array_map(fn (string $isi): array => ['baris' => null, 'kolom' => null, 'pesan' => $isi], $pesan),
            [],
        );
    }

    public function bersih(): bool
    {
        return $this->galat === [];
    }
}
