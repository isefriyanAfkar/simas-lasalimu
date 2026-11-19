<?php

namespace App\Filament\Resources\Ptks\Tables;

use App\Domains\Foundation\Models\User;
use App\Domains\Foundation\Services\PelanggaranAturanPengguna;
use App\Domains\People\Enums\JenisPtk;
use App\Domains\People\Enums\StatusPtk;
use App\Domains\People\Models\Ptk;
use App\Domains\People\Services\PelanggaranAturanKepegawaian;
use App\Domains\People\Services\PtkService;
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
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * No delete or bulk actions: staff are never deleted. The search box looks
 * in the name, the NIP and the code; it never searches a NIK.
 */
class PtksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['user.roles', 'statusKepegawaian']))
            ->defaultSort(fn (Builder $query): Builder => self::orderByName($query, 'asc'))
            ->columns([
                TextColumn::make('nama_lengkap')
                    ->label('Nama')
                    ->state(fn (Ptk $record): string => $record->nama_lengkap)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(fn (Builder $query): Builder => $query
                        ->whereHas('user', fn (Builder $user): Builder => $user->where('name', 'like', "%{$search}%"))
                        ->orWhere('nip', 'like', "%{$search}%")
                        ->orWhere('kode', 'like', "%{$search}%")))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => self::orderByName($query, $direction)),
                TextColumn::make('jenis_ptk')
                    ->label('Jenis')
                    ->badge()
                    ->formatStateUsing(fn (JenisPtk $state): string => $state->label()),
                TextColumn::make('statusKepegawaian.nama')
                    ->label('Status kepegawaian'),
                TextColumn::make('kode')
                    ->label('Kode')
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (StatusPtk $state): string => $state->label())
                    ->color(fn (StatusPtk $state): string => $state === StatusPtk::Aktif ? 'success' : 'gray'),
            ])
            ->filters([
                SelectFilter::make('jenis_ptk')
                    ->label('Jenis')
                    ->options(JenisPtk::options()),
                SelectFilter::make('status_kepegawaian_id')
                    ->label('Status kepegawaian')
                    ->relationship('statusKepegawaian', 'nama'),
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(StatusPtk::options()),
                TernaryFilter::make('sertifikasi')
                    ->label('Sertifikasi')
                    ->placeholder('Semua')
                    ->trueLabel('Sudah')
                    ->falseLabel('Belum'),
            ])
            ->recordActions([
                ViewAction::make()
                    ->hidden(fn (Ptk $record): bool => self::pelaku()->can('update', $record)),
                EditAction::make(),
                Action::make('ubahStatus')
                    ->label('Ubah status')
                    ->icon(Heroicon::OutlinedArrowRightStartOnRectangle)
                    ->color('warning')
                    ->modalHeading('Ubah status guru/staf')
                    ->modalDescription('Akun pengguna ikut dinonaktifkan, kecuali akun itu super admin atau operator. Data tidak dihapus dan bisa diaktifkan kembali.')
                    ->schema([
                        Select::make('status')
                            ->label('Status')
                            ->options(StatusPtk::opsiKeluar())
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
                    ->visible(fn (Ptk $record): bool => $record->status === StatusPtk::Aktif)
                    ->authorize('update')
                    ->action(function (Ptk $record, array $data, Action $action): void {
                        try {
                            $ptk = app(PtkService::class)->ubahStatus(self::pelaku(), $record, $data);
                        } catch (ValidationException $exception) {
                            throw GalatValidasi::prefix($exception, 'mountedActions.'.($action->getNestingIndex() ?? 0).'.data');
                        } catch (PelanggaranAturanKepegawaian|PelanggaranAturanPengguna $exception) {
                            Notification::make()->danger()->title($exception->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title("Status {$ptk->nama_lengkap} diubah menjadi {$ptk->status->label()}")->send();
                    }),
                Action::make('aktifkanKembali')
                    ->label('Aktifkan kembali')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Tanggal dan keterangan keluar dikosongkan. Akun pengguna ikut diaktifkan kalau akun itu dinonaktifkan oleh perubahan status ini.')
                    ->hidden(fn (Ptk $record): bool => $record->status === StatusPtk::Aktif)
                    ->authorize('update')
                    ->action(function (Ptk $record): void {
                        try {
                            $ptk = app(PtkService::class)->aktifkanKembali(self::pelaku(), $record);
                        } catch (PelanggaranAturanKepegawaian|PelanggaranAturanPengguna $exception) {
                            Notification::make()->danger()->title($exception->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title("{$ptk->nama_lengkap} aktif kembali")->send();
                    }),
            ]);
    }

    /**
     * @param  Builder<Ptk>  $query
     * @return Builder<Ptk>
     */
    private static function orderByName(Builder $query, string $direction): Builder
    {
        return $query->orderBy(
            User::query()->select('name')->whereColumn('users.id', 'ptk.user_id'),
            $direction === 'desc' ? 'desc' : 'asc',
        );
    }

    private static function pelaku(): User
    {
        /** @var User $pelaku */
        $pelaku = Filament::auth()->user();

        return $pelaku;
    }
}
