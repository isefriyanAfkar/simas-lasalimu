<?php

namespace App\Domains\People\Services\Import;

use App\Domains\People\Models\ImportSiswa;
use App\Domains\People\Services\ImportSiswaService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Queued check of an import (ImportSiswaService::pratinjau()). Runs once:
 * a check that stops (an exception, the timeout, or a worker killed
 * mid-way and found again after retry_after) is never retried, and the
 * import becomes gagal so the operator never waits on "sedang diperiksa".
 */
final class PeriksaImportSiswa implements ShouldQueue
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
            $service->pratinjau($import);
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(ImportSiswaService::class)->tandaiGagal($this->importId, ImportSiswaService::PESAN_PERIKSA_GAGAL);
    }
}
