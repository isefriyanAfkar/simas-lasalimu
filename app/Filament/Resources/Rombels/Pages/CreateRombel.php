<?php

namespace App\Filament\Resources\Rombels\Pages;

use App\Domains\Academic\Services\RombelService;
use App\Filament\Resources\Rombels\RombelResource;
use App\Filament\Support\GalatValidasi;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateRombel extends CreateRecord
{
    protected static string $resource = RombelResource::class;

    /**
     * @param  array{tahun_ajaran_id: int|string, tingkat: int|string, nomor: int|string, kapasitas?: int|string|null}  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(RombelService::class)->create($data);
        } catch (ValidationException $exception) {
            throw GalatValidasi::prefix($exception, 'data');
        }
    }
}
