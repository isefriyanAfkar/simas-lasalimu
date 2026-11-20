<?php

namespace App\Domains\People\Services\Import;

use App\Domains\People\Models\ImportSiswa;
use App\Domains\People\Services\ImportSiswaService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Queued save of an import (ImportSiswaService::simpan()). Runs once; a
 * save that stops rolls its transaction back (nothing is kept) and the
 * import becomes gagal.
 */
final class SimpanImportSiswa implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = ImportSiswaService::BATAS_WAKTU_JOB;

    public bool $failOnTimeout = true;

    public function __construct(public int $importId) {}

    public function handle(ImportSiswaService $service): void
    {
        $import = ImportSiswa::query()->find($this->importId);

        if ($import !== null) {
            $service->simpan($import);
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(ImportSiswaService::class)->tandaiGagal($this->importId, ImportSiswaService::PESAN_SIMPAN_GAGAL);
    }
}
