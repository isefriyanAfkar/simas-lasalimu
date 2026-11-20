<?php

namespace App\Domains\People\Services\Import;

use App\Domains\Foundation\Models\User;
use App\Domains\People\Models\ImportSiswa;
use App\Domains\People\Services\ImportSiswaService;
use App\Domains\People\Services\PelanggaranAturanKesiswaan;
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

    /**
     * @param  int  $pelakuId  the operator who pressed Simpan; the save is made in their name
     */
    public function __construct(public int $importId, public int $pelakuId) {}

    public function handle(ImportSiswaService $service): void
    {
        $import = ImportSiswa::query()->find($this->importId);
        $pelaku = User::query()->find($this->pelakuId);

        if ($import === null || $pelaku === null) {
            return;
        }

        try {
            $service->simpan($import, $pelaku);
        } catch (PelanggaranAturanKesiswaan) {
            // Ended before this job ran (the cleanup): nothing to save, and
            // not a failed job.
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(ImportSiswaService::class)->tandaiGagal($this->importId, ImportSiswaService::PESAN_SIMPAN_GAGAL);
    }
}
