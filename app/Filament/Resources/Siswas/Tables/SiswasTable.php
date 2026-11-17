<?php

namespace App\Filament\Resources\Siswas\Tables;

use App\Domains\Foundation\Models\User;
use App\Domains\Foundation\Services\PelanggaranAturanPengguna;
use App\Domains\People\Enums\StatusSiswa;
use App\Domains\People\Models\Siswa;
use App\Domains\People\Services\PelanggaranAturanKesiswaan;
use App\Domains\People\Services\SiswaService;
use App\Filament\Support\GalatValidasi;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * No delete action: students are never deleted. The search box looks in the
 * name, the NIS and the NISN; it never searches a NIK.
 */
class SiswasTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('user'))
            ->defaultSort('nis')
            ->columns([
                TextColumn::make('nis')
                    ->label('NIS')
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label('Nama')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(fn (Builder $query): Builder => $query
                        ->whereHas('user', fn (Builder $user): Builder => $user->where('name', 'like', "%{$search}%"))
                        ->orWhere('nis', 'like', "%{$search}%")
                        ->orWhere('nisn', 'like', "%{$search}%")))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy(
                        User::query()->select('name')->whereColumn('users.id', 'siswa.user_id'),
                        $direction === 'desc' ? 'desc' : 'asc',
                    )),
                TextColumn::make('nisn')
                    ->label('NISN')
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (StatusSiswa $state): string => $state->label())
                    ->color(fn (StatusSiswa $state): string => $state === StatusSiswa::Aktif ? 'success' : 'gray'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(StatusSiswa::options()),
            ])
            ->recordActions([
                ViewAction::make()
                    ->hidden(fn (Siswa $record): bool => self::pelaku()->can('update', $record)),
                EditAction::make(),
                Action::make('ubahStatus')
                    ->label('Ubah status')
                    ->icon(Heroicon::OutlinedArrowRightStartOnRectangle)
                    ->color('warning')
                    ->modalHeading('Ubah status siswa')
                    ->modalDescription('Akun siswa ikut dinonaktifkan. Data tidak dihapus dan bisa diaktifkan kembali.')
                    ->schema([
                        Select::make('status')
                            ->label('Status')
                            ->options(StatusSiswa::opsiKeluar())
                            ->required(),
                        DatePicker::make('tanggal_keluar')
                            ->label('Tanggal')
                            ->maxDate(fn (): string => now()->toDateString())
                            ->required(),
                        Textarea::make('keterangan_keluar')
                            ->label('Keterangan')
                            ->maxLength(500)
                            ->required(),
                    ])
                    ->visible(fn (Siswa $record): bool => $record->status === StatusSiswa::Aktif)
                    ->authorize('update')
                    ->action(function (Siswa $record, array $data, Action $action): void {
                        try {
                            $siswa = app(SiswaService::class)->ubahStatus(self::pelaku(), $record, $data);
                        } catch (ValidationException $exception) {
                            throw GalatValidasi::prefix($exception, 'mountedActions.'.($action->getNestingIndex() ?? 0).'.data');
                        } catch (PelanggaranAturanKesiswaan|PelanggaranAturanPengguna $exception) {
                            Notification::make()->danger()->title($exception->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title("Status {$siswa->user->name} diubah menjadi {$siswa->status->label()}")->send();
                    }),
                Action::make('aktifkanKembali')
                    ->label('Aktifkan kembali')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Tanggal dan keterangan keluar dikosongkan. Akun siswa ikut diaktifkan kalau akun itu dinonaktifkan oleh perubahan status ini.')
                    ->hidden(fn (Siswa $record): bool => $record->status === StatusSiswa::Aktif)
                    ->authorize('update')
                    ->action(function (Siswa $record): void {
                        try {
                            $siswa = app(SiswaService::class)->aktifkanKembali(self::pelaku(), $record);
                        } catch (PelanggaranAturanKesiswaan|PelanggaranAturanPengguna $exception) {
                            Notification::make()->danger()->title($exception->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title("{$siswa->user->name} aktif kembali")->send();
                    }),
            ]);
    }

    private static function pelaku(): User
    {
        /** @var User $pelaku */
        $pelaku = Filament::auth()->user();

        return $pelaku;
    }
}
