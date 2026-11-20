<?php

namespace Tests\Feature\Domains\People\Services\Import;

use App\Domains\People\Services\Import\BacaSelImport;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Reading one cell of the import file. Each method returns [value, error];
 * an error never contains the cell's value, so a NIK never ends up in a
 * message.
 */
class BacaSelImportTest extends TestCase
{
    /**
     * @return array<string, array{mixed, string}>
     */
    public static function genders(): array
    {
        return [
            'L' => ['L', 'laki-laki'],
            'l' => ['l', 'laki-laki'],
            'Laki-laki' => ['Laki-laki', 'laki-laki'],
            'LAKI-LAKI' => [' LAKI-LAKI ', 'laki-laki'],
            'laki laki' => ['laki laki', 'laki-laki'],
            'P' => ['P', 'perempuan'],
            'p' => ['p', 'perempuan'],
            'Perempuan' => ['Perempuan', 'perempuan'],
            'PEREMPUAN' => ['PEREMPUAN', 'perempuan'],
        ];
    }

    #[DataProvider('genders')]
    public function test_gender_is_read_in_its_common_forms(mixed $sel, string $expected): void
    {
        $this->assertSame([$expected, null], BacaSelImport::jenisKelamin($sel));
    }

    public function test_an_unknown_gender_is_an_error(): void
    {
        $this->assertSame([null, 'Jenis kelamin tidak dikenali. Tulis L, P, Laki-laki, atau Perempuan.'], BacaSelImport::jenisKelamin('X'));
        $this->assertSame([null, null], BacaSelImport::jenisKelamin('  '));
        $this->assertSame([null, null], BacaSelImport::jenisKelamin(null));
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function dates(): array
    {
        return [
            'tanggal Excel' => [40299, '2010-05-01'],
            'tanggal Excel pecahan' => [40299.5, '2010-05-01'],
            'd-m-Y' => ['01-05-2010', '2010-05-01'],
            'd/m/Y' => ['1/5/2010', '2010-05-01'],
            'd.m.Y' => ['1.5.2010', '2010-05-01'],
            'Y-m-d' => ['2010-05-01', '2010-05-01'],
        ];
    }

    #[DataProvider('dates')]
    public function test_a_date_is_read_as_an_excel_date_or_day_month_year_text(mixed $sel, string $expected): void
    {
        $this->assertSame([$expected, null], BacaSelImport::tanggal($sel));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function badDates(): array
    {
        return [
            '31-02-2010' => ['31-02-2010'],
            'bulan saja' => ['Mei 2010'],
            'tahun dua angka' => ['1-5-10'],
            'angka negatif' => [-5],
        ];
    }

    #[DataProvider('badDates')]
    public function test_an_unreadable_date_is_an_error(mixed $sel): void
    {
        $this->assertSame([null, 'Tanggal tidak dikenali. Tulis hari-bulan-tahun, misalnya 31-12-2010.'], BacaSelImport::tanggal($sel));
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function phones(): array
    {
        return [
            'teks 08' => [' 0812-3456-7890 ', '0812-3456-7890'],
            'teks +62' => ['+6281234567890', '+6281234567890'],
            'angka tanpa 0' => [81234567890, '081234567890'],
            'angka 62' => [6281234567890, '6281234567890'],
            'angka pecahan bulat' => [81234567890.0, '081234567890'],
        ];
    }

    #[DataProvider('phones')]
    public function test_a_phone_number_is_read_with_or_without_the_leading_zero(mixed $sel, string $expected): void
    {
        $this->assertSame([$expected, null], BacaSelImport::noHp($sel));
    }

    public function test_an_identity_number_must_be_a_text_cell(): void
    {
        $pesan = 'Kolom NIK ayah harus berformat Teks: angka di Excel bisa kehilangan 0 di depan atau berubah menjadi notasi ilmiah.';

        $this->assertSame([null, $pesan], BacaSelImport::identitas(7404012345670001, 'NIK ayah'));
        $this->assertSame([null, $pesan], BacaSelImport::identitas(7.404012345670001E15, 'NIK ayah'));
        $this->assertSame([null, $pesan], BacaSelImport::identitas('7.40401E+15', 'NIK ayah'));
        $this->assertSame([null, $pesan], BacaSelImport::identitas('7,40401e15', 'NIK ayah'));
    }

    public function test_an_identity_number_in_a_text_cell_keeps_its_leading_zeros(): void
    {
        $this->assertSame(['00123', null], BacaSelImport::identitas(' 00123 ', 'NIS'));
        $this->assertSame(['0081234567', null], BacaSelImport::identitas('0081234567', 'NISN'));
        $this->assertSame([null, null], BacaSelImport::identitas('', 'NIS'));
    }

    public function test_an_error_never_contains_the_cell_value(): void
    {
        foreach ([BacaSelImport::identitas(7404012345670001, 'NIK siswa'), BacaSelImport::jenisKelamin('7404012345670001'), BacaSelImport::tanggal('7404012345670001')] as [, $pesan]) {
            $this->assertIsString($pesan);
            $this->assertStringNotContainsString('7404012345670001', $pesan);
            $this->assertStringNotContainsString('740401', $pesan);
        }
    }

    public function test_yes_and_no(): void
    {
        foreach (['ya', 'Ya', 'YA', 'y', 1, true, 'iya'] as $ya) {
            $this->assertSame([true, null], BacaSelImport::yaTidak($ya));
        }

        foreach (['tidak', 'Tidak', 't', 'n', 0, false] as $tidak) {
            $this->assertSame([false, null], BacaSelImport::yaTidak($tidak));
        }

        $this->assertSame([null, null], BacaSelImport::yaTidak(''));
        $this->assertSame([null, 'Tulis ya atau tidak.'], BacaSelImport::yaTidak('mungkin'));
    }

    public function test_a_choice_is_matched_case_insensitively_and_returns_the_stored_value(): void
    {
        $agama = ['Islam' => 'Islam', 'Kristen' => 'Kristen'];

        $this->assertSame(['Islam', null], BacaSelImport::pilihan(' islam ', $agama, 'Agama'));
        $this->assertSame([null, 'Agama tidak dikenali. Pilih dari daftar di template.'], BacaSelImport::pilihan('Lain', $agama, 'Agama'));
        $this->assertSame([7, null], BacaSelImport::pilihan('x-1', ['X-1' => 7], 'Rombel'));
    }

    public function test_text_is_trimmed_and_empty_is_null(): void
    {
        $this->assertSame(['Budi Santoso', null], BacaSelImport::teks('  Budi Santoso '));
        $this->assertSame(['2026', null], BacaSelImport::teks(2026));
        $this->assertSame([null, null], BacaSelImport::teks('   '));
    }
}
