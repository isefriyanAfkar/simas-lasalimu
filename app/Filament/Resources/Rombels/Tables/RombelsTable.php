<?php

namespace App\Filament\Resources\Rombels\Tables;

use App\Domains\Academic\Enums\Tingkat;
use App\Domains\Academic\Models\Rombel;
use App\Domains\Academic\Models\TahunAjaran;
use App\Domains\Academic\Services\PeriodeAkademik;
use App\Domains\Academic\Services\RombelService;
use App\Domains\Foundation\Settings\PengaturanSekolah;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * No bulk actions. Deleting is a row action shown only to whoever may delete.
 */
class RombelsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('tahunAjaran'))
            ->defaultSort(self::urutanTampilan(...))
            ->columns([
                TextColumn::make('nama')
                    ->label('Nama'),
                TextColumn::make('tahunAjaran.nama')
                    ->label('Tahun ajaran'),
                TextColumn::make('kapasitas')
                    ->label('Kapasitas')
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('tahun_ajaran_id')
                    ->label('Tahun ajaran')
                    ->options(fn (): array => TahunAjaran::query()->orderByDesc('tanggal_mulai')->pluck('nama', 'id')->all())
                    ->default(fn (): ?int => app(PeriodeAkademik::class)->tahunAjaranAktif()?->id),
                SelectFilter::make('tingkat')
                    ->label('Tingkat')
                    ->options(fn (): array => self::tingkatOptions()),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('hapus')
                    ->label('Hapus')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->authorize('delete')
                    ->action(function (Rombel $record): void {
                        app(RombelService::class)->delete($record);

                        Notification::make()->success()->title("Rombel {$record->nama} dihapus")->send();
                    }),
            ]);
    }

    /**
     * @param  Builder<Rombel>  $query
     * @return Builder<Rombel>
     */
    private static function urutanTampilan(Builder $query): Builder
    {
        return $query->urutanTampilan();
    }

    /**
     * The school's levels; every level while the jenjang is not set.
     *
     * @return array<int, string>
     */
    private static function tingkatOptions(): array
    {
        $jenjang = app(PengaturanSekolah::class)->jenjang;

        if ($jenjang !== null) {
            return Tingkat::optionsUntukJenjang($jenjang);
        }

        $options = [];

        foreach (Tingkat::cases() as $tingkat) {
            $options[$tingkat->value] = $tingkat->label();
        }

        return $options;
    }
}
