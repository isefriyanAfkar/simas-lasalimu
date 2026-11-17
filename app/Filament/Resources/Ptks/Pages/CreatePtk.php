<?php

namespace App\Filament\Resources\Ptks\Pages;

use App\Domains\Foundation\Models\User;
use App\Domains\Foundation\Services\PelanggaranAturanPengguna;
use App\Domains\People\Services\PtkService;
use App\Filament\Resources\Ptks\PtkResource;
use App\Filament\Support\GalatValidasi;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreatePtk extends CreateRecord
{
    protected static string $resource = PtkResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $pelaku */
        $pelaku = Filament::auth()->user();

        try {
            return app(PtkService::class)->create($pelaku, $data);
        } catch (ValidationException $exception) {
            throw GalatValidasi::prefix($exception, 'data');
        } catch (PelanggaranAturanPengguna $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            throw (new Halt)->rollBackDatabaseTransaction();
        }
    }
}
