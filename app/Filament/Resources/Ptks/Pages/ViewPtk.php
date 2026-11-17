<?php

namespace App\Filament\Resources\Ptks\Pages;

use App\Domains\People\Models\Ptk;
use App\Filament\Resources\Ptks\PtkResource;
use App\Filament\Resources\Ptks\Schemas\PtkForm;
use Filament\Resources\Pages\ViewRecord;

/**
 * Read-only page for a record the user may not update, such as an operator
 * looking at the staff record of another operator. The NIK is shown masked
 * only (placeholder); there is no Tampilkan NIK here.
 */
class ViewPtk extends ViewRecord
{
    protected static string $resource = PtkResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Ptk $record */
        $record = $this->getRecord();

        return PtkForm::fillFromRecord($record, $data);
    }
}
