<?php

namespace App\Filament\Resources\MataPelajarans\Pages;

use App\Domains\Academic\Services\MataPelajaranService;
use App\Filament\Resources\MataPelajarans\MataPelajaranResource;
use App\Filament\Support\GalatValidasi;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateMataPelajaran extends CreateRecord
{
    protected static string $resource = MataPelajaranResource::class;

    /**
     * @param  array{kode: string, nama: string, urutan?: int|string|null}  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(MataPelajaranService::class)->create($data);
        } catch (ValidationException $exception) {
            throw GalatValidasi::prefix($exception, 'data');
        }
    }
}
