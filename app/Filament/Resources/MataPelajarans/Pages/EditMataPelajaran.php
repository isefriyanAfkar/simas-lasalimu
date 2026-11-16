<?php

namespace App\Filament\Resources\MataPelajarans\Pages;

use App\Domains\Academic\Models\MataPelajaran;
use App\Domains\Academic\Services\MataPelajaranService;
use App\Filament\Resources\MataPelajarans\MataPelajaranResource;
use App\Filament\Support\GalatValidasi;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * No header delete action: subjects are never deleted.
 */
class EditMataPelajaran extends EditRecord
{
    protected static string $resource = MataPelajaranResource::class;

    /**
     * @param  MataPelajaran  $record
     * @param  array{kode: string, nama: string, urutan?: int|string|null}  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return app(MataPelajaranService::class)->update($record, $data);
        } catch (ValidationException $exception) {
            throw GalatValidasi::prefix($exception, 'data');
        }
    }
}
