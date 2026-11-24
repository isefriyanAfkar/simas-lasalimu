<?php

namespace App\Console\Commands;

use App\Domains\Academic\Models\TahunAjaran;
use App\Domains\People\Services\Import\ContohImportSiswa;
use App\Domains\People\Services\Import\GalatContohImport;
use App\Domains\People\Services\ImportSiswaService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * A sample student import file with fake data that passes the check, for
 * trying the import outside production (ContohImportSiswa). Writes only the
 * file, never the database.
 */
#[Signature('simas:contoh-import {tahun_ajaran : Nama tahun ajaran, misalnya 2026/2027} {--baris=500 : Jumlah baris siswa (1 sampai 1000)}')]
#[Description('Buat file contoh import siswa berisi data palsu yang lolos pemeriksaan (bukan untuk production)')]
class BuatContohImportSiswa extends Command
{
    public function handle(ContohImportSiswa $contoh): int
    {
        try {
            $baris = (string) $this->option('baris');

            if (! ctype_digit($baris)) {
                throw GalatContohImport::jumlahBaris();
            }

            $nama = (string) $this->argument('tahun_ajaran');
            $tahunAjaran = TahunAjaran::query()->where('nama', $nama)->first() ?? throw GalatContohImport::tahunAjaranTidakAda($nama);
            $path = $contoh->buat($tahunAjaran, (int) $baris);
        } catch (GalatContohImport $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf('File contoh dibuat (%d baris): %s', (int) $baris, Storage::disk(ImportSiswaService::DISK)->path($path)));

        return self::SUCCESS;
    }
}
