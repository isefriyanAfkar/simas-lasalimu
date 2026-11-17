<?php

namespace App\Filament\Resources\Siswas\Pages;

use App\Domains\People\Models\Siswa;
use App\Filament\Resources\Siswas\Schemas\SiswaForm;
use App\Filament\Resources\Siswas\SiswaResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * Read-only page for a record the user may not update. The NIK is shown
 * masked only (placeholder); there is no Tampilkan NIK here.
 */
class ViewSiswa extends ViewRecord
{
    protected static string $resource = SiswaResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Siswa $record */
        $record = $this->getRecord();

        return SiswaForm::fillFromRecord($record, $data);
    }
}
