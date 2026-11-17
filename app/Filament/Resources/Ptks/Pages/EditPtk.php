<?php

namespace App\Filament\Resources\Ptks\Pages;

use App\Domains\Foundation\Models\User;
use App\Domains\Foundation\Services\PelanggaranAturanPengguna;
use App\Domains\Foundation\Services\UserService;
use App\Domains\People\Models\Ptk;
use App\Domains\People\Services\PtkService;
use App\Filament\Resources\Ptks\PtkResource;
use App\Filament\Resources\Ptks\Schemas\PtkForm;
use App\Filament\Support\GalatValidasi;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/**
 * No header delete action: staff are never deleted. $nikTerbuka holds the
 * full NIK only after "Tampilkan NIK" (logged by UserService::tampilkanNik)
 * and only until the page is reloaded or saved; it is not shown through a
 * notification, because notifications pass through the database session.
 */
class EditPtk extends EditRecord
{
    protected static string $resource = PtkResource::class;

    #[Locked]
    public ?string $nikTerbuka = null;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('tampilkanNik')
                ->label('Tampilkan NIK')
                ->icon(Heroicon::OutlinedEye)
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Tampilkan NIK')
                ->modalDescription('NIK akan ditampilkan lengkap. Akses ini dicatat di log aktivitas.')
                ->modalSubmitActionLabel('Tampilkan')
                ->authorize(fn (Ptk $record): bool => (bool) Filament::auth()->user()?->can('viewNik', $record->user))
                ->visible(fn (Ptk $record): bool => $record->user->nik_hash !== null)
                ->action(function (Ptk $record): void {
                    try {
                        $this->nikTerbuka = app(UserService::class)->tampilkanNik($this->pelaku(), $record->user);
                    } catch (PelanggaranAturanPengguna $exception) {
                        Notification::make()->danger()->title($exception->getMessage())->send();
                    }
                }),
        ];
    }

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

    /**
     * Refill so the NIK field is empty again and a revealed NIK is hidden.
     */
    protected function afterSave(): void
    {
        $this->nikTerbuka = null;
        $this->getRecord()->refresh();
        $this->fillForm();
    }

    /**
     * @param  Ptk  $record
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return app(PtkService::class)->update($this->pelaku(), $record, $data);
        } catch (ValidationException $exception) {
            throw GalatValidasi::prefix($exception, 'data');
        } catch (PelanggaranAturanPengguna $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            throw (new Halt)->rollBackDatabaseTransaction();
        }
    }

    private function pelaku(): User
    {
        /** @var User $pelaku */
        $pelaku = Filament::auth()->user();

        return $pelaku;
    }
}
