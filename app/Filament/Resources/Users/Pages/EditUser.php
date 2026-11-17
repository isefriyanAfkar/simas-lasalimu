<?php

namespace App\Filament\Resources\Users\Pages;

use App\Domains\Foundation\Models\User;
use App\Domains\Foundation\Services\PelanggaranAturanPengguna;
use App\Domains\Foundation\Services\UserService;
use App\Filament\Resources\Users\UserResource;
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
 * $nikTerbuka holds the full NIK only after "Tampilkan NIK" (logged by
 * UserService::tampilkanNik) and only until the page is reloaded or saved.
 * It is not shown through a notification, because notifications pass
 * through the session, which is stored in the database.
 */
class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

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
                ->authorize('viewNik')
                ->visible(fn (User $record): bool => $record->nik_hash !== null)
                ->action(function (User $record): void {
                    /** @var User $pelaku */
                    $pelaku = Filament::auth()->user();

                    $this->nikTerbuka = app(UserService::class)->tampilkanNik($pelaku, $record);
                }),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var User $record */
        $record = $this->getRecord();

        $data['peran'] = $record->getRoleNames()->first();
        $data['nik'] = null;
        $data['hapus_nik'] = false;
        $data['tanggal_lahir'] = $record->tanggal_lahir?->toDateString();

        return $data;
    }

    /**
     * Refill so the NIK field is empty again (with the new masked NIK as
     * placeholder) and a revealed NIK is hidden.
     */
    protected function afterSave(): void
    {
        $this->nikTerbuka = null;
        $this->fillForm();
    }

    /**
     * @param  User  $record
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var User $pelaku */
        $pelaku = Filament::auth()->user();

        try {
            return app(UserService::class)->update($pelaku, $record, $data);
        } catch (ValidationException $exception) {
            throw GalatValidasi::prefix($exception, 'data');
        } catch (PelanggaranAturanPengguna $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            throw (new Halt)->rollBackDatabaseTransaction();
        }
    }
}
