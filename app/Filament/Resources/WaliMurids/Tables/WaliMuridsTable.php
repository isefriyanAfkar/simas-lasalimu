<?php

namespace App\Filament\Resources\WaliMurids\Tables;

use App\Domains\Foundation\Models\User;
use App\Domains\Foundation\Services\PelanggaranAturanPengguna;
use App\Domains\People\Models\SiswaWali;
use App\Domains\People\Models\WaliMurid;
use App\Domains\People\Services\PelanggaranAturanKesiswaan;
use App\Domains\People\Services\WaliMuridService;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The phone number is shown masked; the search box looks in the name and in
 * the phone number typed in any format (08..., +62..., in part). It never
 * searches a NIK. Only a parent without children may be deleted.
 */
class WaliMuridsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['user.roles', 'siswaWali.siswa.user'])
                ->withCount('siswaWali'))
            ->defaultSort(fn (Builder $query): Builder => $query->orderBy(
                User::query()->select('name')->whereColumn('users.id', 'wali_murid.user_id'),
            ))
            ->columns([
                TextColumn::make('user.name')
                    ->label('Nama')
                    ->description(fn (WaliMurid $record): ?string => $record->meninggal ? 'Meninggal' : null)
                    ->searchable(query: fn (Builder $query, string $search): Builder => app(WaliMuridService::class)->saringPencarian($query, $search)),
                TextColumn::make('no_hp')
                    ->label('Nomor HP')
                    ->state(fn (WaliMurid $record): ?string => $record->user->noHpTersamar())
                    ->placeholder('—'),
                TextColumn::make('anak')
                    ->label('Anak')
                    ->state(fn (WaliMurid $record): ?string => self::anak($record))
                    ->placeholder('—')
                    ->wrap(),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('tandaiMeninggal')
                    ->label('Tandai meninggal')
                    ->icon(Heroicon::OutlinedHeart)
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('Wali yang masih menjadi wali utama harus diganti dulu di halaman siswanya.')
                    ->visible(fn (WaliMurid $record): bool => ! $record->meninggal)
                    ->authorize('update')
                    ->action(fn (WaliMurid $record) => self::run(
                        fn (WaliMuridService $service, User $pelaku) => $service->ubahStatusMeninggal($pelaku, $record, true),
                        "{$record->user->name} ditandai meninggal",
                    )),
                Action::make('batalkanMeninggal')
                    ->label('Batalkan tanda meninggal')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('gray')
                    ->requiresConfirmation()
                    ->visible(fn (WaliMurid $record): bool => $record->meninggal)
                    ->authorize('update')
                    ->action(fn (WaliMurid $record) => self::run(
                        fn (WaliMuridService $service, User $pelaku) => $service->ubahStatusMeninggal($pelaku, $record, false),
                        "Tanda meninggal {$record->user->name} dibatalkan",
                    )),
                Action::make('hapus')
                    ->label('Hapus')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Data wali dan akunnya dihapus permanen.')
                    ->visible(fn (WaliMurid $record): bool => (int) $record->getAttribute('siswa_wali_count') === 0)
                    ->authorize('delete')
                    ->action(fn (WaliMurid $record) => self::run(
                        fn (WaliMuridService $service, User $pelaku) => $service->hapus($pelaku, $record),
                        "{$record->user->name} dihapus",
                    )),
            ]);
    }

    /**
     * The names of the parent's children, by name.
     */
    private static function anak(WaliMurid $wali): ?string
    {
        $nama = $wali->siswaWali
            ->map(fn (SiswaWali $siswaWali): string => $siswaWali->siswa->user->name)
            ->sort()
            ->implode(', ');

        return $nama === '' ? null : $nama;
    }

    /**
     * Run a WaliMuridService call and turn a rule violation into a
     * notification.
     *
     * @param  Closure(WaliMuridService, User): mixed  $operasi
     */
    private static function run(Closure $operasi, string $pesanBerhasil): void
    {
        /** @var User $pelaku */
        $pelaku = Filament::auth()->user();

        try {
            $operasi(app(WaliMuridService::class), $pelaku);
        } catch (PelanggaranAturanKesiswaan|PelanggaranAturanPengguna $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            return;
        }

        Notification::make()->success()->title($pesanBerhasil)->send();
    }
}
