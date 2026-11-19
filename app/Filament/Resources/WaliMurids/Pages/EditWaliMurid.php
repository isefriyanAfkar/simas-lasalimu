<?php

namespace App\Filament\Resources\WaliMurids\Pages;

use App\Domains\Foundation\Models\User;
use App\Domains\Foundation\Services\PelanggaranAturanPengguna;
use App\Domains\People\Models\WaliMurid;
use App\Domains\People\Services\WaliMuridService;
use App\Filament\Resources\WaliMurids\Schemas\WaliMuridForm;
use App\Filament\Resources\WaliMurids\WaliMuridResource;
use App\Filament\Support\GalatValidasi;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * No header delete action: a parent without children is deleted from the
 * list.
 */
class EditWaliMurid extends EditRecord
{
    protected static string $resource = WaliMuridResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var WaliMurid $record */
        $record = $this->getRecord();

        return WaliMuridForm::fillFromRecord($record, $data);
    }

    /**
     * Refill so the NIK field is empty again.
     */
    protected function afterSave(): void
    {
        $this->getRecord()->refresh();
        $this->fillForm();
    }

    /**
     * @param  WaliMurid  $record
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var User $pelaku */
        $pelaku = Filament::auth()->user();

        try {
            return app(WaliMuridService::class)->ubah($pelaku, $record, $data);
        } catch (ValidationException $exception) {
            throw GalatValidasi::prefix($exception, 'data');
        } catch (PelanggaranAturanPengguna $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            throw (new Halt)->rollBackDatabaseTransaction();
        }
    }
}
