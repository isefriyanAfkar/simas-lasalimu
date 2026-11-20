<?php

namespace Tests\Feature\Domains\People\Services\Import;

use App\Domains\Academic\Enums\Tingkat;
use App\Domains\Academic\Models\Rombel;
use App\Domains\Academic\Models\TahunAjaran;
use App\Domains\People\Services\Import\KolomImportSiswa;
use App\Domains\People\Services\Import\TemplateImportSiswa;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

/**
 * The import template, checked after it is written and read back: the
 * columns, text format for identity and phone columns, dropdowns, the
 * school year's rombel list and the instructions sheet.
 */
class TemplateImportSiswaTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Spreadsheet $template;

    private TahunAjaran $tahunAjaran;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tahunAjaran = TahunAjaran::factory()->create(['nama' => '2026/2027', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2027-06-26']);
        Rombel::factory()->for($this->tahunAjaran)->create(['tingkat' => Tingkat::XI, 'nomor' => 1]);
        Rombel::factory()->for($this->tahunAjaran)->create(['tingkat' => Tingkat::X, 'nomor' => 2]);
        Rombel::factory()->for($this->tahunAjaran)->create(['tingkat' => Tingkat::X, 'nomor' => 1]);
        Rombel::factory()->for(TahunAjaran::factory()->create(['nama' => '2025/2026']))->create(['tingkat' => Tingkat::XII, 'nomor' => 9]);

        $path = tempnam(sys_get_temp_dir(), 'template').'.xlsx';
        app(TemplateImportSiswa::class)->simpan($this->tahunAjaran, $path);
        $this->template = IOFactory::load($path);
    }

    /**
     * Release the workbook: its objects refer to each other, so PHP frees
     * the memory only after disconnectWorksheets().
     */
    protected function tearDown(): void
    {
        $this->template->disconnectWorksheets();

        parent::tearDown();
    }

    public function test_the_data_sheet_holds_every_column_in_order(): void
    {
        $sheet = $this->sheet(KolomImportSiswa::LEMBAR_DATA);
        $judul = $sheet->rangeToArray('A1:'.KolomImportSiswa::huruf('wali_utama').'1')[0];

        $this->assertSame(array_column(KolomImportSiswa::KOLOM, 0), $judul);
        $this->assertSame(0, $this->template->getActiveSheetIndex());
    }

    public function test_identity_and_phone_columns_are_text(): void
    {
        $sheet = $this->sheet(KolomImportSiswa::LEMBAR_DATA);

        foreach ([...KolomImportSiswa::berjenis('identitas'), ...KolomImportSiswa::berjenis('hp')] as $kolom) {
            $huruf = KolomImportSiswa::huruf($kolom);

            foreach ([2, KolomImportSiswa::MAKS_BARIS + 1] as $baris) {
                $this->assertSame('@', $sheet->getStyle("{$huruf}{$baris}")->getNumberFormat()->getFormatCode(), "{$kolom} baris {$baris}");
            }
        }

        $this->assertNotSame('@', $sheet->getStyle(KolomImportSiswa::huruf('nama').'2')->getNumberFormat()->getFormatCode());
    }

    public function test_choice_columns_have_a_dropdown_from_the_list_sheet(): void
    {
        $sheet = $this->sheet(KolomImportSiswa::LEMBAR_DATA);
        $daftar = $this->sheet(KolomImportSiswa::LEMBAR_DAFTAR);
        $pilihan = [
            'jenis_kelamin' => ['Laki-laki', 'Perempuan'],
            'agama' => ['Islam', 'Kristen', 'Katolik', 'Hindu', 'Buddha', 'Konghucu', 'Kepercayaan'],
            'rombel' => ['X-1', 'X-2', 'XI-1'],
            'ayah_meninggal' => ['ya', 'tidak'],
            'ibu_meninggal' => ['ya', 'tidak'],
            'wali_utama' => ['ayah', 'ibu', 'wali'],
        ];

        foreach ($pilihan as $kolom => $harapan) {
            $huruf = KolomImportSiswa::huruf($kolom);
            $validasi = $sheet->getDataValidation("{$huruf}2");

            $this->assertSame(DataValidation::TYPE_LIST, $validasi->getType(), $kolom);
            $this->assertTrue($sheet->dataValidationExists($huruf.(KolomImportSiswa::MAKS_BARIS + 1)), $kolom);
            $this->assertSame($harapan, $this->isiRentang($daftar, $validasi->getFormula1()), $kolom);
        }

        $this->assertFalse($sheet->dataValidationExists(KolomImportSiswa::huruf('nama').'2'));
        $this->assertSame(Worksheet::SHEETSTATE_HIDDEN, $daftar->getSheetState());
    }

    public function test_the_instructions_name_the_school_year_and_the_row_limit(): void
    {
        $petunjuk = implode("\n", array_map(fn (array $baris): string => (string) $baris[0], $this->sheet(KolomImportSiswa::LEMBAR_PETUNJUK)->toArray()));

        $this->assertStringContainsString('2026/2027', $petunjuk);
        $this->assertStringContainsString((string) KolomImportSiswa::MAKS_BARIS, $petunjuk);
        $this->assertStringContainsString('NIS adalah kunci', $petunjuk);
        $this->assertStringContainsString('Sel kosong berarti tidak diubah', $petunjuk);
    }

    private function sheet(string $nama): Worksheet
    {
        $sheet = $this->template->getSheetByName($nama);
        $this->assertNotNull($sheet, "Lembar {$nama} tidak ada.");

        return $sheet;
    }

    /**
     * The values a list formula such as 'Daftar'!$C$2:$C$4 points to.
     *
     * @return list<string>
     */
    private function isiRentang(Worksheet $daftar, string $rumus): array
    {
        $this->assertMatchesRegularExpression("/^'?Daftar'?!\\$([A-Z]+)\\$(\\d+):\\$([A-Z]+)\\$(\\d+)$/", $rumus);
        preg_match('/!\$([A-Z]+)\$(\\d+):\$([A-Z]+)\$(\\d+)$/', $rumus, $cocok);

        return array_map(fn (array $baris): string => (string) $baris[0], $daftar->rangeToArray("{$cocok[1]}{$cocok[2]}:{$cocok[3]}{$cocok[4]}"));
    }
}
