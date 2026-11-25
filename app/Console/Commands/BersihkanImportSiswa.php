<?php

namespace App\Console\Commands;

use App\Domains\People\Services\ImportSiswaService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Scheduled every five minutes (routes/console.php). Ends imports whose job
 * died, gives up imports left alone for a day, and deletes leftover upload
 * and temporary files; see ImportSiswaService::bersihkan().
 */
#[Signature('simas:bersihkan-import')]
#[Description('Akhiri import siswa yang macet atau terlantar, dan hapus file unggahan sisa')]
class BersihkanImportSiswa extends Command
{
    public function handle(ImportSiswaService $service): int
    {
        $hasil = $service->bersihkan();

        $this->components->info(sprintf(
            'Import macet ditandai gagal: %d. Import terlantar dibatalkan: %d. File sisa dihapus: %d. File sementara dihapus: %d.',
            $hasil['macet'],
            $hasil['terlantar'],
            $hasil['file'],
            $hasil['sementara'],
        ));

        return self::SUCCESS;
    }
}
