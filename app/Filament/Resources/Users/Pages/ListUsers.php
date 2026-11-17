<?php

namespace App\Filament\Resources\Users\Pages;

use App\Domains\Foundation\Models\User;
use App\Domains\Foundation\Services\UserService;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\GalatValidasi;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

/**
 * "Cari NIK" looks a user up by NIK in a modal, so the NIK never reaches the
 * page URL (the table search box is bound to it). The result names the user,
 * never the NIK.
 */
class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('cariNik')
                ->label('Cari NIK')
                ->icon(Heroicon::OutlinedMagnifyingGlass)
                ->color('gray')
                ->authorize(fn (): bool => (bool) Filament::auth()->user()?->can('searchNik', User::class))
                ->modalHeading('Cari pengguna lewat NIK')
                ->modalDescription('Pencarian ini dicatat di log aktivitas, tanpa angka NIK.')
                ->modalSubmitActionLabel('Cari')
                ->schema([
                    TextInput::make('nik')
                        ->label('NIK')
                        ->required()
                        ->maxLength(16)
                        ->autocomplete(false),
                ])
                ->action(function (array $data, Action $action): void {
                    /** @var User $pelaku */
                    $pelaku = Filament::auth()->user();

                    try {
                        $user = app(UserService::class)->cariNik($pelaku, (string) $data['nik']);
                    } catch (ValidationException $exception) {
                        throw GalatValidasi::prefix($exception, 'mountedActions.'.($action->getNestingIndex() ?? 0).'.data');
                    }

                    if ($user === null) {
                        Notification::make()->warning()->title('Tidak ada pengguna dengan NIK ini.')->send();

                        return;
                    }

                    $notification = Notification::make()->success()->title("NIK ditemukan: {$user->name} ({$user->username})");

                    if ($pelaku->can('update', $user)) {
                        $notification->actions([
                            Action::make('buka')
                                ->label('Buka')
                                ->url(UserResource::getUrl('edit', ['record' => $user])),
                        ]);
                    }

                    $notification->send();
                }),
            CreateAction::make(),
        ];
    }
}
