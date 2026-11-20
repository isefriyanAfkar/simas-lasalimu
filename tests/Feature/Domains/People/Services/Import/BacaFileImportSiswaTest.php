<?php

namespace Tests\Feature\Domains\People\Services\Import;

use App\Domains\Academic\Enums\Tingkat;
use App\Domains\Academic\Models\Rombel;
use App\Domains\Academic\Models\TahunAjaran;
use App\Domains\People\Services\Import\BacaFileImportSiswa;
use App\Domains\People\Services\Import\KolomImportSiswa;
use App\Domains\People\Services\Import\TemplateImportSiswa;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

/**
 * Reading a whole import file: the header row decides the columns, every
 * cell is read by its kind, empty rows are skipped, and errors are kept per
 * row and column. The file itself is refused when it cannot be read, lacks
 * columns or holds too many rows.
 */
class BacaFileImportSiswaTest extends TestCase
{
    use LazilyRefreshDatabase;

    private TahunAjaran $tahunAjaran;

    private Rombel $x1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tahunAjaran = TahunAjaran::factory()->create(['nama' => '2026/2027', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2027-06-26']);
        $this->x1 = Rombel::factory()->for($this->tahunAjaran)->create(['tingkat' => Tingkat::X, 'nomor' => 1]);
    }

    public function test_a_filled_template_is_read_row_by_row(): void
    {
        $path = $this->file([
            ['nis' => '00123', 'nama' => ' Budi ', 'jenis_kelamin' => 'L', 'agama' => 'islam', 'tanggal_lahir' => 40299, 'no_hp' => 81234567890, 'rombel' => 'x-1',
                'ayah_nama' => 'Ahmad', 'ayah_meninggal' => 'tidak', 'ibu_nama' => 'Siti', 'ibu_meninggal' => 'Ya', 'wali_utama' => 'Ibu'],
            [],
            ['nis' => '0456', 'nama' => 'Ani', 'tanggal_masuk' => '13-07-2026'],
        ]);

        $hasil = app(BacaFileImportSiswa::class)->baca($path, $this->tahunAjaran);

        $this->assertSame([], $hasil->galatFile);
        $this->assertCount(2, $hasil->baris);

        [$budi, $ani] = $hasil->baris;
        $this->assertSame(2, $budi->nomor);
        $this->assertSame([], $budi->galat);
        $this->assertSame('00123', $budi->nilai['nis']);
        $this->assertSame('Budi', $budi->nilai['nama']);
        $this->assertSame('laki-laki', $budi->nilai['jenis_kelamin']);
        $this->assertSame('Islam', $budi->nilai['agama']);
        $this->assertSame('2010-05-01', $budi->nilai['tanggal_lahir']);
        $this->assertSame('081234567890', $budi->nilai['no_hp']);
        $this->assertSame($this->x1->id, $budi->nilai['rombel']);
        $this->assertFalse($budi->nilai['ayah_meninggal']);
        $this->assertTrue($budi->nilai['ibu_meninggal']);
        $this->assertSame('ibu', $budi->nilai['wali_utama']);
        $this->assertNull($budi->nilai['nisn']);

        $this->assertSame(4, $ani->nomor);
        $this->assertSame('0456', $ani->nilai['nis']);
        $this->assertSame('2026-07-13', $ani->nilai['tanggal_masuk']);
    }

    public function test_errors_are_kept_per_row_and_column_without_the_value(): void
    {
        $path = $this->file([
            ['nis' => 20261001, 'nama' => 'Budi', 'ayah_nik' => 7404012345670001, 'jenis_kelamin' => 'X', 'tanggal_lahir' => '31-02-2010', 'rombel' => 'X-9'],
        ]);

        $baris = app(BacaFileImportSiswa::class)->baca($path, $this->tahunAjaran)->baris[0];

        $this->assertSame(['nis', 'jenis_kelamin', 'tanggal_lahir', 'rombel', 'ayah_nik'], array_keys($baris->galat));
        $this->assertSame('Rombel X-9 tidak ada di tahun ajaran 2026/2027.', $baris->galat['rombel']);
        $this->assertStringStartsWith('Kolom NIK ayah harus berformat Teks', $baris->galat['ayah_nik']);
        $this->assertStringNotContainsString('7404012345670001', json_encode($baris->galat));
        $this->assertNull($baris->nilai['ayah_nik']);
    }

    public function test_a_file_that_is_not_xlsx_is_refused(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'import').'.xlsx';
        file_put_contents($path, 'bukan excel');

        $hasil = app(BacaFileImportSiswa::class)->baca($path, $this->tahunAjaran);

        $this->assertSame(['File tidak bisa dibaca. Unggah file .xlsx dari template.'], $hasil->galatFile);
        $this->assertSame([], $hasil->baris);
    }

    public function test_missing_columns_are_named(): void
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([['NIS', 'Nama siswa']]);
        $path = $this->simpan($spreadsheet);

        $hasil = app(BacaFileImportSiswa::class)->baca($path, $this->tahunAjaran);

        $this->assertCount(1, $hasil->galatFile);
        $this->assertStringStartsWith('Kolom tidak ditemukan: NISN, Jenis kelamin', $hasil->galatFile[0]);
    }

    public function test_a_file_with_too_many_rows_is_refused(): void
    {
        $baris = array_fill(0, KolomImportSiswa::MAKS_BARIS + 1, ['nama' => 'Budi']);

        $hasil = app(BacaFileImportSiswa::class)->baca($this->file($baris), $this->tahunAjaran);

        $this->assertSame([sprintf('File berisi %d baris data; paling banyak %d baris per file.', KolomImportSiswa::MAKS_BARIS + 1, KolomImportSiswa::MAKS_BARIS)], $hasil->galatFile);
        $this->assertSame([], $hasil->baris);
    }

    public function test_extra_columns_such_as_the_error_column_are_ignored(): void
    {
        $path = $this->file([['nis' => '1234', 'nama' => 'Budi']]);
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName(KolomImportSiswa::LEMBAR_DATA);
        $sheet->setCellValue('AC1', 'Keterangan galat');
        $sheet->setCellValue('AC2', 'NIS sudah dipakai');
        $path = $this->simpan($spreadsheet);

        $hasil = app(BacaFileImportSiswa::class)->baca($path, $this->tahunAjaran);

        $this->assertSame([], $hasil->galatFile);
        $this->assertSame([], $hasil->baris[0]->galat);
    }

    /**
     * A template for the school year, filled with $baris (column key =>
     * value); strings are written as text cells, numbers as number cells.
     *
     * @param  list<array<string, mixed>>  $baris
     */
    private function file(array $baris): string
    {
        $spreadsheet = app(TemplateImportSiswa::class)->buat($this->tahunAjaran);
        $sheet = $spreadsheet->getSheetByName(KolomImportSiswa::LEMBAR_DATA);

        foreach ($baris as $indeks => $nilai) {
            foreach ($nilai as $kolom => $isi) {
                $alamat = KolomImportSiswa::huruf($kolom).($indeks + 2);

                // Explicit types: PhpSpreadsheet would write a 16-digit
                // integer as text, while Excel stores it as a number.
                match (true) {
                    is_string($isi) => $sheet->setCellValueExplicit($alamat, $isi, DataType::TYPE_STRING),
                    is_int($isi), is_float($isi) => $sheet->setCellValueExplicit($alamat, $isi, DataType::TYPE_NUMERIC),
                    default => $sheet->setCellValue($alamat, $isi),
                };
            }
        }

        return $this->simpan($spreadsheet);
    }

    /**
     * Write $spreadsheet to a temporary file and release it (its objects
     * refer to each other, so the memory is freed only after
     * disconnectWorksheets()).
     */
    private function simpan(Spreadsheet $spreadsheet): string
    {
        $path = tempnam(sys_get_temp_dir(), 'import').'.xlsx';
        IOFactory::createWriter($spreadsheet, 'Xlsx')->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }
}
