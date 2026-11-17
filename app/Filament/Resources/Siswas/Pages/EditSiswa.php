<?php

namespace App\Filament\Resources\Siswas\Pages;

use App\Domains\Foundation\Models\User;
use App\Domains\Foundation\Services\PelanggaranAturanPengguna;
use App\Domains\Foundation\Services\UserService;
use App\Domains\People\Models\Siswa;
use App\Domains\People\Services\SiswaService;
use App\Filament\Resources\Siswas\Schemas\SiswaForm;
use App\Filament\Resources\Siswas\SiswaResource;
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
 * No header delete action: students are never deleted. $nikTerbuka holds the
 * full NIK only after "Tampilkan NIK" (logged by UserService::tampilkanNik)
 * and only until the page is reloaded or saved; it is not shown through a
 * notification, because notifications pass through the database session.
 */
class EditSiswa extends EditRecord
{
    protected static string $resource = SiswaResource::class;

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
                ->authorize(fn (Siswa $record): bool => (bool) Filament::auth()->user()?->can('viewNik', $record->user))
                ->visible(fn (Siswa $record): bool => $record->user->nik_hash !== null)
                ->action(function (Siswa $record): void {
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
        /** @var Siswa $record */
        $record = $this->getRecord();

        return SiswaForm::fillFromRecord($record, $data);
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
     * @param  Siswa  $record
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return app(SiswaService::class)->update($this->pelaku(), $record, $data);
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
