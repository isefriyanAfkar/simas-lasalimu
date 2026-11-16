<?php

namespace App\Filament\Resources\Rombels\Pages;

use App\Domains\Academic\Models\Rombel;
use App\Domains\Academic\Services\RombelService;
use App\Filament\Resources\Rombels\RombelResource;
use App\Filament\Support\GalatValidasi;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * No header delete action: deleting is offered only from the list.
 */
class EditRombel extends EditRecord
{
    protected static string $resource = RombelResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Rombel $record */
        $record = $this->getRecord();

        $data['tingkat'] = $record->tingkat->value;

        return $data;
    }

    /**
     * @param  Rombel  $record
     * @param  array{tahun_ajaran_id: int|string, tingkat: int|string, nomor: int|string, kapasitas?: int|string|null}  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return app(RombelService::class)->update($record, $data);
        } catch (ValidationException $exception) {
            throw GalatValidasi::prefix($exception, 'data');
        }
    }
}
