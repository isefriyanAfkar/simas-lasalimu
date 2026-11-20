<?php

namespace Tests\Feature\Domains\People\Services\Import;

use App\Domains\Academic\Models\TahunAjaran;
use App\Domains\People\Services\Import\KolomImportSiswa;
use App\Domains\People\Services\Import\TemplateImportSiswa;
use App\Domains\People\Services\ImportSiswaService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * A filled import template for tests: strings as text cells, numbers as
 * number cells, null cells left empty.
 */
trait MembuatFileImport
{
    /**
     * The file's content.
     *
     * @param  list<array<string, mixed>>  $baris
     */
    protected function isiFileImport(TahunAjaran $tahunAjaran, array $baris): string
    {
        $spreadsheet = app(TemplateImportSiswa::class)->buat($tahunAjaran);
        $sheet = $spreadsheet->getSheetByName(KolomImportSiswa::LEMBAR_DATA);
        $this->assertNotNull($sheet);

        foreach ($baris as $indeks => $nilai) {
            foreach ($nilai as $kolom => $isi) {
                $alamat = KolomImportSiswa::huruf($kolom).($indeks + 2);

                match (true) {
                    $isi === null => null,
                    is_string($isi) => $sheet->setCellValueExplicit($alamat, $isi, DataType::TYPE_STRING),
                    default => $sheet->setCellValueExplicit($alamat, $isi, DataType::TYPE_NUMERIC),
                };
            }
        }

        $sementara = tempnam(sys_get_temp_dir(), 'import').'.xlsx';
        IOFactory::createWriter($spreadsheet, 'Xlsx')->save($sementara);
        $spreadsheet->disconnectWorksheets();
        $isi = (string) file_get_contents($sementara);
        unlink($sementara);

        return $isi;
    }

    /**
     * The file put in the import folder, as an upload would; returns its path.
     *
     * @param  list<array<string, mixed>>  $baris
     */
    protected function fileImport(TahunAjaran $tahunAjaran, array $baris): string
    {
        $path = ImportSiswaService::FOLDER.'/'.Str::uuid().'.xlsx';
        Storage::disk(ImportSiswaService::DISK)->put($path, $this->isiFileImport($tahunAjaran, $baris));

        return $path;
    }
}
