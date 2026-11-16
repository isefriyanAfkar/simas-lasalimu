<?php

namespace App\Filament\Resources\TahunAjarans\Pages;

use App\Domains\Academic\Models\TahunAjaran;
use App\Domains\Academic\Services\TahunAjaranService;
use App\Filament\Resources\TahunAjarans\TahunAjaranResource;
use App\Filament\Support\GalatValidasi;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * No header delete action: deleting is offered only from the list, where the
 * Service rule (not while active) and the Delete permission apply.
 */
class EditTahunAjaran extends EditRecord
{
    protected static string $resource = TahunAjaranResource::class;

    /**
     * @param  TahunAjaran  $record
     * @param  array{nama: string, tanggal_mulai: string, tanggal_selesai: string}  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return app(TahunAjaranService::class)->update($record, $data);
        } catch (ValidationException $exception) {
            throw GalatValidasi::prefix($exception, 'data');
        }
    }
}
