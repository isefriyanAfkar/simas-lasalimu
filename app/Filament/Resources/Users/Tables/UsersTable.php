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
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The NIK is never a column and the search box never matches it: the search
 * term is bound to the page URL. Looking a user up by NIK is the "Cari NIK"
 * action on ListUsers.
 *
 * Student accounts (hundreds of them, managed on the Siswa screen) are
 * hidden by default; the "Akun siswa" filter shows them.
 */
class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('roles'))
            ->columns([
                ImageColumn::make('foto')
                    ->label('Foto')
                    ->state(fn (User $record): ?string => $record->urlFoto())
                    ->circular()
                    ->imageHeight(32),
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
            ->filters([
                TernaryFilter::make('akun_siswa')
                    ->label('Akun siswa')
                    ->placeholder('Semua akun')
                    ->trueLabel('Hanya akun siswa')
                    ->falseLabel('Tanpa akun siswa')
                    ->default(false)
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereHas('siswa'),
                        false: fn (Builder $query): Builder => $query->whereDoesntHave('siswa'),
                    ),
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
