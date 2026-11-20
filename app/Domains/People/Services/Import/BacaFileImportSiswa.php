<?php

namespace App\Domains\People\Services\Import;

use App\Domains\Academic\Models\Rombel;
use App\Domains\Academic\Models\TahunAjaran;
use App\Domains\Foundation\Enums\Agama;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Reads a filled student import file (.xlsx, see TemplateImportSiswa).
 *
 * - The data sheet is the one named "Siswa", else the first sheet.
 * - Row 1 holds the headers; columns are found by header text, without
 *   regard to case or order. Every template column must be there; other
 *   columns (such as the error column of a returned file) are ignored.
 * - Empty rows are skipped; at most KolomImportSiswa::MAKS_BARIS data rows.
 * - Every cell is read by its kind (BacaSelImport); the rombel by its name
 *   among the rombel of the target school year.
 *
 * Only reading: no rule of the services is checked here, and nothing is
 * written. Messages never contain a cell's value.
 */
final class BacaFileImportSiswa
{
    public function baca(string $path, TahunAjaran $tahunAjaran): HasilBacaFileImport
    {
        try {
            $reader = IOFactory::createReader('Xlsx');
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($path);
        } catch (Throwable) {
            return new HasilBacaFileImport(['File tidak bisa dibaca. Unggah file .xlsx dari template.'], []);
        }

        try {
            return $this->bacaWorkbook($spreadsheet->getSheetByName(KolomImportSiswa::LEMBAR_DATA) ?? $spreadsheet->getSheet(0), $tahunAjaran);
        } finally {
            // The workbook's objects refer to each other: release them, or
            // PHP keeps the memory until the request ends.
            $spreadsheet->disconnectWorksheets();
        }
    }

    private function bacaWorkbook(Worksheet $sheet, TahunAjaran $tahunAjaran): HasilBacaFileImport
    {
        $kolomKe = $this->kolomDariJudul($sheet);
        $hilang = array_diff(array_keys(KolomImportSiswa::KOLOM), array_keys($kolomKe));

        if ($hilang !== []) {
            return new HasilBacaFileImport([
                'Kolom tidak ditemukan: '.implode(', ', array_map(KolomImportSiswa::judul(...), $hilang)).'. Pakai template terbaru.',
            ], []);
        }

        $mentah = $this->barisMentah($sheet, $kolomKe);

        if (count($mentah) > KolomImportSiswa::MAKS_BARIS) {
            return new HasilBacaFileImport([
                sprintf('File berisi %d baris data; paling banyak %d baris per file.', count($mentah), KolomImportSiswa::MAKS_BARIS),
            ], []);
        }

        $rombel = Rombel::query()
            ->where('tahun_ajaran_id', $tahunAjaran->getKey())
            ->get()
            ->mapWithKeys(fn (Rombel $rombel): array => [$rombel->nama => (int) $rombel->getKey()])
            ->all();

        $baris = [];

        foreach ($mentah as $nomor => $sel) {
            $baris[] = $this->bacaBaris($nomor, $sel, $rombel, $tahunAjaran);
        }

        return new HasilBacaFileImport([], $baris);
    }

    /**
     * @param  array<string, mixed>  $sel
     * @param  array<string, int>  $rombel  name => id
     */
    private function bacaBaris(int $nomor, array $sel, array $rombel, TahunAjaran $tahunAjaran): BarisImportSiswa
    {
        $nilai = [];
        $galat = [];

        foreach (KolomImportSiswa::KOLOM as $kolom => [$judul, $jenis]) {
            $isi = $sel[$kolom] ?? null;

            [$nilai[$kolom], $pesan] = match ($jenis) {
                'identitas' => BacaSelImport::identitas($isi, $judul),
                'hp' => BacaSelImport::noHp($isi),
                'tanggal' => BacaSelImport::tanggal($isi),
                'jenis_kelamin' => BacaSelImport::jenisKelamin($isi),
                'agama' => BacaSelImport::pilihan($isi, array_combine(array_keys(Agama::options()), array_keys(Agama::options())), $judul),
                'rombel' => $this->rombel($isi, $rombel, $tahunAjaran),
                'ya_tidak' => BacaSelImport::yaTidak($isi),
                'wali_utama' => BacaSelImport::pilihan($isi, ['ayah' => 'ayah', 'ibu' => 'ibu', 'wali' => 'wali'], $judul),
                default => BacaSelImport::teks($isi),
            };

            if ($pesan !== null) {
                $galat[$kolom] = $pesan;
            }
        }

        return new BarisImportSiswa($nomor, $nilai, $galat);
    }

    /**
     * @param  array<string, int>  $rombel
     * @return array{int|null, string|null}
     */
    private function rombel(mixed $isi, array $rombel, TahunAjaran $tahunAjaran): array
    {
        [$id, $pesan] = BacaSelImport::pilihan($isi, $rombel, 'Rombel');

        if ($pesan === null) {
            return [$id, null];
        }

        [$teks] = BacaSelImport::teks($isi);

        return [null, 'Rombel '.mb_strtoupper((string) $teks)." tidak ada di tahun ajaran {$tahunAjaran->nama}."];
    }

    /**
     * Column key => column index, from the header row.
     *
     * @return array<string, int>
     */
    private function kolomDariJudul(Worksheet $sheet): array
    {
        $judulKe = [];

        foreach (KolomImportSiswa::KOLOM as $kolom => [$judul]) {
            $judulKe[mb_strtolower($judul)] = $kolom;
        }

        $kolomKe = [];
        $terakhir = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

        for ($indeks = 1; $indeks <= $terakhir; $indeks++) {
            $judul = mb_strtolower(trim((string) $this->nilaiSel($sheet, $indeks, 1)));

            if (isset($judulKe[$judul]) && ! isset($kolomKe[$judulKe[$judul]])) {
                $kolomKe[$judulKe[$judul]] = $indeks;
            }
        }

        return $kolomKe;
    }

    /**
     * The non-empty data rows: Excel row number => [column key => raw value].
     *
     * @param  array<string, int>  $kolomKe
     * @return array<int, array<string, mixed>>
     */
    private function barisMentah(Worksheet $sheet, array $kolomKe): array
    {
        $baris = [];
        $terakhir = $sheet->getHighestDataRow();

        for ($nomor = 2; $nomor <= $terakhir; $nomor++) {
            $sel = [];

            foreach ($kolomKe as $kolom => $indeks) {
                $sel[$kolom] = $this->nilaiSel($sheet, $indeks, $nomor);
            }

            $berisi = array_filter($sel, fn (mixed $isi): bool => $isi !== null && ! (is_string($isi) && trim($isi) === ''));

            if ($berisi !== []) {
                $baris[$nomor] = $sel;
            }
        }

        return $baris;
    }

    /**
     * The cell's value: a number cell as int or float, text as a string, a
     * formula as its result. An unreadable formula is read as empty.
     */
    private function nilaiSel(Worksheet $sheet, int $kolom, int $baris): mixed
    {
        $cell = $sheet->getCell([$kolom, $baris]);

        try {
            $nilai = $cell->getCalculatedValue();
        } catch (Throwable) {
            return null;
        }

        return $nilai instanceof RichText ? $nilai->getPlainText() : $nilai;
    }
}
