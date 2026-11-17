<?php

namespace App\Filament\Resources\StatusKepegawaians\Pages;

use App\Domains\People\Models\StatusKepegawaian;
use App\Domains\People\Services\StatusKepegawaianService;
use App\Filament\Resources\StatusKepegawaians\StatusKepegawaianResource;
use App\Filament\Support\GalatValidasi;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * No header delete action: statuses are never deleted.
 */
class EditStatusKepegawaian extends EditRecord
{
    protected static string $resource = StatusKepegawaianResource::class;

    /**
     * @param  StatusKepegawaian  $record
     * @param  array{nama: string|null, urutan?: int|string|null}  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return app(StatusKepegawaianService::class)->update($record, $data);
        } catch (ValidationException $exception) {
            throw GalatValidasi::prefix($exception, 'data');
        }
    }
}
