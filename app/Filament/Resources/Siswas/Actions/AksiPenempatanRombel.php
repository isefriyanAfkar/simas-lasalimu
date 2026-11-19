<?php

namespace App\Filament\Resources\Siswas\Actions;

use App\Domains\Academic\Models\PenempatanRombel;
use App\Domains\Academic\Models\Rombel;
use App\Domains\Academic\Services\AktivitasAkademik;
use App\Domains\Academic\Services\PelanggaranAturanAkademik;
use App\Domains\Academic\Services\PenempatanRombelService;
use App\Domains\Academic\Services\RombelSiswa;
use App\Domains\Foundation\Models\User;
use App\Domains\Foundation\Services\PelanggaranAturanPengguna;
use App\Domains\People\Enums\StatusSiswa;
use App\Domains\People\Models\Siswa;
use App\Filament\Support\GalatValidasi;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The rombel placement actions of the Siswa list. They only call
 * PenempatanRombelService, which decides every rule.
 *
 * A move never happens silently: the single-student action shows the
 * student's current rombel in the chosen rombel's school year, and when it
 * differs the action becomes a move that has to be confirmed (naming the old
 * and the new rombel). The bulk action only places; a student who already
 * has a rombel in that school year is skipped and reported.
 */
final class AksiPenempatanRombel
{
    public static function tempatkan(): Action
    {
        return Action::make('tempatkan')
            ->label('Tempatkan ke rombel')
            ->icon(Heroicon::OutlinedUserPlus)
            ->color('gray')
            ->modalHeading(fn (Siswa $record): string => "Tempatkan {$record->user->name} ke rombel")
            ->modalSubmitActionLabel('Simpan')
            ->schema([
                Select::make('rombel_id')
                    ->label('Rombel')
                    ->helperText('Hanya rombel tahun ajaran aktif dan sesudahnya.')
                    ->options(fn (): array => app(PenempatanRombelService::class)->opsiRombel())
                    ->required()
                    ->live(),
                TextEntry::make('rombel_sekarang')
                    ->label('Rombel sekarang di tahun ajaran itu')
                    ->state(fn (Siswa $record, Get $get): string => self::rombelSekarang($record, $get('rombel_id'))->nama ?? 'Belum ada')
                    ->visible(fn (Get $get): bool => self::rombel($get('rombel_id')) !== null),
                Checkbox::make('konfirmasi_pindah')
                    ->label(fn (Siswa $record, Get $get): string => sprintf(
                        'Ya, pindahkan %s dari %s ke %s.',
                        $record->user->name,
                        self::rombelSekarang($record, $get('rombel_id'))?->nama,
                        self::rombel($get('rombel_id'))?->nama,
                    ))
                    ->visible(fn (Siswa $record, Get $get): bool => self::akanPindah($record, $get('rombel_id')))
                    ->accepted(),
            ])
            ->visible(fn (Siswa $record): bool => $record->status === StatusSiswa::Aktif)
            ->authorize('tempatkan')
            ->action(function (Siswa $record, array $data, Action $action): void {
                $rombel = self::rombel($data['rombel_id'] ?? null);

                if ($rombel === null) {
                    Notification::make()->danger()->title('Rombel tidak ditemukan.')->send();

                    return;
                }

                $sekarang = self::rombelSekarang($record, $rombel->getKey());
                $service = app(PenempatanRombelService::class);

                try {
                    if ($sekarang !== null && $sekarang->getKey() !== $rombel->getKey()) {
                        if (! filter_var($data['konfirmasi_pindah'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                            throw ValidationException::withMessages(['konfirmasi_pindah' => "Centang untuk memindahkan dari {$sekarang->nama} ke {$rombel->nama}."]);
                        }

                        $service->pindahkan(self::pelaku(), $record, $rombel);
                        Notification::make()->success()->title("{$record->user->name} dipindah dari {$sekarang->nama} ke {$rombel->nama}")->send();

                        return;
                    }

                    $service->tempatkan(self::pelaku(), $record, $rombel);
                } catch (ValidationException $exception) {
                    throw GalatValidasi::prefix($exception, 'mountedActions.'.($action->getNestingIndex() ?? 0).'.data');
                } catch (PelanggaranAturanAkademik|PelanggaranAturanPengguna $exception) {
                    Notification::make()->danger()->title($exception->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title("{$record->user->name} ditempatkan di {$rombel->nama}")->send();
            });
    }

    public static function hapusPenempatan(): Action
    {
        return Action::make('hapusPenempatan')
            ->label('Hapus penempatan')
            ->icon(Heroicon::OutlinedUserMinus)
            ->color('danger')
            ->modalHeading(fn (Siswa $record): string => "Hapus penempatan {$record->user->name}")
            ->modalDescription('Untuk memperbaiki salah tempat. Penghapusan dicatat di log aktivitas.')
            ->modalSubmitActionLabel('Hapus')
            ->schema([
                Select::make('penempatan_id')
                    ->label('Penempatan')
                    ->options(fn (Siswa $record): array => app(PenempatanRombelService::class)
                        ->penempatanYangBisaDihapus($record)
                        ->mapWithKeys(fn (PenempatanRombel $penempatan): array => [$penempatan->getKey() => AktivitasAkademik::label($penempatan->rombel)])
                        ->all())
                    ->required(),
            ])
            ->visible(fn (Siswa $record): bool => app(PenempatanRombelService::class)->adaPenempatanBisaDihapus($record))
            ->authorize('tempatkan')
            ->action(function (Siswa $record, array $data): void {
                $penempatan = PenempatanRombel::query()
                    ->with('rombel')
                    ->where('siswa_id', $record->getKey())
                    ->find($data['penempatan_id'] ?? null);

                if ($penempatan === null) {
                    Notification::make()->danger()->title('Penempatan tidak ditemukan.')->send();

                    return;
                }

                try {
                    app(PenempatanRombelService::class)->hapus(self::pelaku(), $penempatan);
                } catch (PelanggaranAturanAkademik|PelanggaranAturanPengguna $exception) {
                    Notification::make()->danger()->title($exception->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title("Penempatan {$record->user->name} di {$penempatan->rombel->nama} dihapus")->send();
            });
    }

    /**
     * Places each selected student on its own, in NIS order (so who gets the
     * last seats of a nearly full rombel is predictable): those that pass
     * stay placed, and every failure is listed with its reason.
     */
    public static function tempatkanBanyak(): BulkAction
    {
        return BulkAction::make('tempatkanBanyak')
            ->label('Tempatkan ke rombel')
            ->icon(Heroicon::OutlinedUserPlus)
            ->modalHeading('Tempatkan siswa terpilih ke rombel')
            ->modalDescription('Siswa yang sudah punya rombel di tahun ajaran itu dilewati, tidak dipindah.')
            ->modalSubmitActionLabel('Tempatkan')
            ->schema([
                Select::make('rombel_id')
                    ->label('Rombel')
                    ->options(fn (): array => app(PenempatanRombelService::class)->opsiRombel())
                    ->required(),
            ])
            ->authorize(fn (): bool => self::pelaku()->can('tempatkanBanyak', Siswa::class))
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records, array $data): void {
                $rombel = self::rombel($data['rombel_id'] ?? null);

                if ($rombel === null) {
                    Notification::make()->danger()->title('Rombel tidak ditemukan.')->send();

                    return;
                }

                $terpilih = Siswa::query()->with('user')->whereKey($records->modelKeys())->urutanNis()->get();
                $hasil = app(PenempatanRombelService::class)->tempatkanBanyak(self::pelaku(), $terpilih, $rombel);
                $jumlahBerhasil = count($hasil['berhasil']);

                if ($hasil['gagal'] === []) {
                    Notification::make()->success()->title("{$jumlahBerhasil} siswa ditempatkan di {$rombel->nama}")->send();

                    return;
                }

                $alasan = [];

                foreach ($hasil['gagal'] as $siswaId => $pesan) {
                    $nama = $terpilih->find($siswaId)->user->name ?? '';
                    $alasan[] = str_starts_with($pesan, $nama) ? $pesan : "{$nama}: {$pesan}";
                }

                Notification::make()
                    ->warning()
                    ->title("{$jumlahBerhasil} dari {$terpilih->count()} siswa ditempatkan di {$rombel->nama}")
                    ->body(implode(' ', $alasan))
                    ->persistent()
                    ->send();
            });
    }

    private static function rombel(mixed $rombelId): ?Rombel
    {
        return is_numeric($rombelId) ? Rombel::query()->with('tahunAjaran')->find((int) $rombelId) : null;
    }

    /**
     * The student's rombel in the school year of the chosen rombel.
     */
    private static function rombelSekarang(Siswa $siswa, mixed $rombelId): ?Rombel
    {
        $rombel = self::rombel($rombelId);

        return $rombel === null ? null : app(RombelSiswa::class)->rombelDi($siswa, $rombel->tahun_ajaran_id);
    }

    private static function akanPindah(Siswa $siswa, mixed $rombelId): bool
    {
        $sekarang = self::rombelSekarang($siswa, $rombelId);

        return $sekarang !== null && $sekarang->getKey() !== (int) $rombelId;
    }

    private static function pelaku(): User
    {
        /** @var User $pelaku */
        $pelaku = Filament::auth()->user();

        return $pelaku;
    }
}
