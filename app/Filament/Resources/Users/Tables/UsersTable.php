<?php

namespace App\Filament\Resources\Users\Tables;

use App\Domains\Foundation\Enums\Peran;
use App\Domains\Foundation\Models\User;
use App\Domains\Foundation\Services\PelanggaranAturanPengguna;
use App\Domains\Foundation\Services\UserService;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('roles'))
            ->columns([
                TextColumn::make('name')
                    ->label('Nama')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('username')
                    ->label('Nama pengguna')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('roles.name')
                    ->label('Role')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Peran::tryFrom($state)?->label() ?? $state),
                TextColumn::make('status_aktif')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Aktif' : 'Nonaktif')
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('nonaktifkan')
                    ->label('Nonaktifkan')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (User $record): bool => $record->status_aktif)
                    ->authorize('update')
                    ->action(fn (User $record) => self::runRule(
                        fn (UserService $userService, User $pelaku) => $userService->deactivate($pelaku, $record),
                        'Pengguna dinonaktifkan',
                    )),
                Action::make('aktifkan')
                    ->label('Aktifkan')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->requiresConfirmation()
                    ->hidden(fn (User $record): bool => $record->status_aktif)
                    ->authorize('update')
                    ->action(fn (User $record) => self::runRule(
                        fn (UserService $userService, User $pelaku) => $userService->activate($pelaku, $record),
                        'Pengguna diaktifkan',
                    )),
                Action::make('aturUlangKataSandi')
                    ->label('Atur ulang kata sandi')
                    ->icon(Heroicon::OutlinedKey)
                    ->color('gray')
                    ->authorize('update')
                    ->schema([
                        TextInput::make('password')
                            ->label('Kata sandi baru')
                            ->password()
                            ->revealable()
                            ->required()
                            ->rules(UserService::passwordRules())
                            ->confirmed(),
                        TextInput::make('password_confirmation')
                            ->label('Ulangi kata sandi baru')
                            ->password()
                            ->required(),
                    ])
                    ->action(fn (User $record, array $data) => self::runRule(
                        fn (UserService $userService, User $pelaku) => $userService->resetPassword($pelaku, $record, $data['password']),
                        'Kata sandi diatur ulang',
                    )),
            ]);
    }

    /**
     * Run a UserService call and turn a rule violation into a notification.
     *
     * @param  Closure(UserService, User): mixed  $operation
     */
    private static function runRule(Closure $operation, string $successMessage): void
    {
        /** @var User $pelaku */
        $pelaku = Filament::auth()->user();

        try {
            $operation(app(UserService::class), $pelaku);
        } catch (PelanggaranAturanPengguna $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            return;
        }

        Notification::make()->success()->title($successMessage)->send();
    }
}
