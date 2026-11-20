<?php

namespace App\Domains\People\Services\Import;

use App\Domains\Academic\Models\Rombel;
use App\Domains\Academic\Models\TahunAjaran;
use App\Domains\Foundation\Enums\Agama;
use App\Domains\Foundation\Enums\JenisKelamin;
use App\Domains\People\Services\ImportSiswaService;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The student import template for one target school year:
 * - sheet "Siswa": the columns of KolomImportSiswa in order, identity and
 *   phone columns formatted as text (so Excel keeps leading zeros), and a
 *   dropdown on every choice column for MAKS_BARIS rows;
 * - sheet "Petunjuk": how to fill it;
 * - hidden sheet "Daftar": the dropdown lists, among them the rombel of the
 *   school year in display order.
 * The dropdowns only help: a value typed by hand (L for laki-laki) is still
 * accepted and checked when the file is read.
 */
final class TemplateImportSiswa
{
    /**
     * Write the template to $path. The workbook is released afterwards: its
     * objects refer to each other, so PHP frees the memory only after
     * disconnectWorksheets().
     */
    public function simpan(TahunAjaran $tahunAjaran, string $path): void
    {
        $spreadsheet = $this->buat($tahunAjaran);

        try {
            IOFactory::createWriter($spreadsheet, 'Xlsx')->save($path);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    /**
     * The template written to a new temporary file, whose path is returned;
     * the caller deletes it (a download with deleteFileAfterSend()).
     */
    public function fileSementara(TahunAjaran $tahunAjaran): string
    {
        $path = ImportSiswaService::fileSementaraBaru('template-import');
        $this->simpan($tahunAjaran, $path);

        return $path;
    }

    public function buat(TahunAjaran $tahunAjaran): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $data = $spreadsheet->getActiveSheet()->setTitle(KolomImportSiswa::LEMBAR_DATA);
        $petunjuk = $spreadsheet->createSheet()->setTitle(KolomImportSiswa::LEMBAR_PETUNJUK);
        $daftar = $spreadsheet->createSheet()->setTitle(KolomImportSiswa::LEMBAR_DAFTAR);

        $this->isiData($data);
        $this->isiPetunjuk($petunjuk, $tahunAjaran);
        $rentang = $this->isiDaftar($daftar, $tahunAjaran);
        $this->pasangDropdown($data, $rentang);

        $daftar->setSheetState(Worksheet::SHEETSTATE_HIDDEN);
        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    private function isiData(Worksheet $sheet): void
    {
        $sheet->fromArray([array_column(KolomImportSiswa::KOLOM, 0)]);
        $terakhir = KolomImportSiswa::huruf((string) array_key_last(KolomImportSiswa::KOLOM));
        $sheet->getStyle("A1:{$terakhir}1")->getFont()->setBold(true);
        $sheet->freezePane('A2');

        foreach (array_keys(KolomImportSiswa::KOLOM) as $kolom) {
            $sheet->getColumnDimension(KolomImportSiswa::huruf($kolom))->setAutoSize(true);
        }

        foreach ([...KolomImportSiswa::berjenis('identitas'), ...KolomImportSiswa::berjenis('hp')] as $kolom) {
            $sheet->getStyle(self::rentangData($kolom))->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        }
    }

    private function isiPetunjuk(Worksheet $sheet, TahunAjaran $tahunAjaran): void
    {
        $baris = [
            "Template import siswa untuk tahun ajaran {$tahunAjaran->nama}.",
            '',
            'Isi lembar "Siswa": satu baris untuk satu siswa, beserta rombel, ayah, ibu, dan walinya.',
            'NIS adalah kunci: NIS yang sudah ada memperbarui siswa itu; NIS baru menambah siswa.',
            'Sel kosong berarti tidak diubah. Import tidak pernah menghapus isian dan tidak mengubah status siswa.',
            'Kolom NIS, NISN, NIK, dan nomor HP sudah berformat Teks. Jangan ubah formatnya: angka di Excel bisa kehilangan 0 di depan.',
            'Tanggal: tanggal Excel, atau teks hari-bulan-tahun, misalnya 31-12-2010.',
            'Jenis kelamin: L, P, Laki-laki, atau Perempuan.',
            "Rombel: pilih dari daftar rombel tahun ajaran {$tahunAjaran->nama}. Siswa yang sudah punya rombel lain di tahun ajaran itu akan dipindah, dan ini ditampilkan sebelum disimpan.",
            'Ayah, ibu, dan wali: wali yang sudah ada dikenali dari NIK dan nama, atau dari nama dan nomor HP yang sama. Kakak-adik boleh memakai wali yang sama. Nama wajib diisi, kecuali untuk ayah atau ibu yang sudah tercatat pada siswa itu.',
            'Ayah atau ibu yang sudah tercatat hanya diperbarui, tidak diganti orang lain. Mengganti ayah atau ibu dilakukan di layar Siswa.',
            'Ayah selalu laki-laki dan ibu selalu perempuan. Isi "Ayah meninggal" atau "Ibu meninggal" dengan ya atau tidak.',
            'Wali utama: ayah, ibu, atau wali. Kalau kosong, wali utama yang sekarang tetap; siswa yang belum punya mendapat ayah (kalau tidak meninggal), lalu ibu, lalu wali.',
            sprintf('Paling banyak %d baris per file.', KolomImportSiswa::MAKS_BARIS),
            'Seluruh file diperiksa dulu. Tidak ada yang tersimpan sebelum semua baris benar; baris yang salah ditandai di file yang bisa diunduh.',
        ];

        $sheet->fromArray(array_map(fn (string $teks): array => [$teks], $baris));
        $sheet->getStyle('A1')->getFont()->setBold(true);
        $sheet->getColumnDimension('A')->setWidth(120);
    }

    /**
     * Fill the list sheet; returns column key => list range for dropdowns.
     *
     * @return array<string, string>
     */
    private function isiDaftar(Worksheet $sheet, TahunAjaran $tahunAjaran): array
    {
        $rombel = Rombel::query()
            ->where('tahun_ajaran_id', $tahunAjaran->getKey())
            ->urutanTampilan()
            ->get()
            ->map(fn (Rombel $rombel): string => $rombel->nama)
            ->all();

        $daftar = [
            'jenis_kelamin' => ['Jenis kelamin', array_values(JenisKelamin::options())],
            'agama' => ['Agama', array_keys(Agama::options())],
            'rombel' => ['Rombel', $rombel],
            'ya_tidak' => ['Ya/tidak', ['ya', 'tidak']],
            'wali_utama' => ['Wali utama', ['ayah', 'ibu', 'wali']],
        ];

        $rentang = [];
        $indeks = 1;

        foreach ($daftar as $nama => [$judul, $isi]) {
            $huruf = Coordinate::stringFromColumnIndex($indeks);
            $sheet->setCellValue("{$huruf}1", $judul);

            foreach (array_values($isi) as $urutan => $nilai) {
                $sheet->setCellValueExplicit($huruf.($urutan + 2), $nilai, DataType::TYPE_STRING);
            }

            if ($isi !== []) {
                $rentang[$nama] = sprintf("'%s'!\$%s\$2:\$%s\$%d", KolomImportSiswa::LEMBAR_DAFTAR, $huruf, $huruf, count($isi) + 1);
            }

            $indeks++;
        }

        return $rentang;
    }

    /**
     * @param  array<string, string>  $rentang
     */
    private function pasangDropdown(Worksheet $sheet, array $rentang): void
    {
        $kolomDaftar = [
            'jenis_kelamin' => 'jenis_kelamin',
            'agama' => 'agama',
            'rombel' => 'rombel',
            'ayah_meninggal' => 'ya_tidak',
            'ibu_meninggal' => 'ya_tidak',
            'wali_utama' => 'wali_utama',
        ];

        foreach ($kolomDaftar as $kolom => $daftar) {
            if (! isset($rentang[$daftar])) {
                continue;
            }

            $validasi = (new DataValidation)
                ->setType(DataValidation::TYPE_LIST)
                ->setErrorStyle(DataValidation::STYLE_INFORMATION)
                ->setAllowBlank(true)
                ->setShowDropDown(true)
                ->setShowErrorMessage(true)
                ->setErrorTitle('Di luar daftar')
                ->setError('Nilai ini tidak ada di daftar. Pastikan penulisannya benar.')
                ->setFormula1($rentang[$daftar]);

            $sheet->setDataValidation(self::rentangData($kolom), $validasi);
        }
    }

    private static function rentangData(string $kolom): string
    {
        $huruf = KolomImportSiswa::huruf($kolom);

        return sprintf('%s2:%s%d', $huruf, $huruf, KolomImportSiswa::MAKS_BARIS + 1);
    }
}
