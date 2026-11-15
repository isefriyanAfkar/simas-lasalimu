<?php

namespace App\Filament\Resources\TahunAjarans\Pages;

use App\Domains\Academic\Services\TahunAjaranService;
use App\Filament\Resources\TahunAjarans\TahunAjaranResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateTahunAjaran extends CreateRecord
{
    protected static string $resource = TahunAjaranResource::class;

    /**
     * @param  array{nama: string, tanggal_mulai: string, tanggal_selesai: string}  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(TahunAjaranService::class)->create($data);
        } catch (ValidationException $exception) {
            throw TahunAjaranResource::prefixValidationErrors($exception, 'data');
        }
    }
}
