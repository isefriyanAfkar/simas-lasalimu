<?php

namespace App\Filament\Resources\StatusKepegawaians\Pages;

use App\Domains\People\Services\StatusKepegawaianService;
use App\Filament\Resources\StatusKepegawaians\StatusKepegawaianResource;
use App\Filament\Support\GalatValidasi;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateStatusKepegawaian extends CreateRecord
{
    protected static string $resource = StatusKepegawaianResource::class;

    /**
     * @param  array{nama: string|null, urutan?: int|string|null}  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(StatusKepegawaianService::class)->create($data);
        } catch (ValidationException $exception) {
            throw GalatValidasi::prefix($exception, 'data');
        }
    }
}
