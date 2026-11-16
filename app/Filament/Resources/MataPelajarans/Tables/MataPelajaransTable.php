<?php

namespace App\Filament\Resources\MataPelajarans\Tables;

use App\Domains\Academic\Models\MataPelajaran;
use App\Domains\Academic\Services\MataPelajaranService;
use App\Domains\Foundation\Models\User;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * No delete or bulk actions: a subject is only deactivated.
 */
class MataPelajaransTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort(self::urutanTampilan(...))
            ->columns([
                TextColumn::make('urutan')
                    ->label('Urutan')
                    ->placeholder('—')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                        ->orderByRaw('urutan is null')
                        ->orderBy('urutan', $direction)),
                TextColumn::make('kode')
                    ->label('Kode')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('nama')
                    ->label('Nama')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status_aktif')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Aktif' : 'Tidak aktif')
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
            ])
            ->filters([
                TernaryFilter::make('status_aktif')
                    ->label('Status')
                    ->placeholder('Semua')
                    ->trueLabel('Aktif')
                    ->falseLabel('Tidak aktif'),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('nonaktifkan')
                    ->label('Nonaktifkan')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('Mata pelajaran tidak dihapus dan bisa diaktifkan lagi.')
                    ->visible(fn (MataPelajaran $record): bool => $record->status_aktif)
                    ->authorize('update')
                    ->action(function (MataPelajaran $record): void {
                        app(MataPelajaranService::class)->deactivate(self::pelaku(), $record);

                        Notification::make()->success()->title("Mata pelajaran {$record->nama} dinonaktifkan")->send();
                    }),
                Action::make('aktifkan')
                    ->label('Aktifkan')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->requiresConfirmation()
                    ->hidden(fn (MataPelajaran $record): bool => $record->status_aktif)
                    ->authorize('update')
                    ->action(function (MataPelajaran $record): void {
                        app(MataPelajaranService::class)->activate(self::pelaku(), $record);

                        Notification::make()->success()->title("Mata pelajaran {$record->nama} diaktifkan")->send();
                    }),
            ]);
    }

    /**
     * Default order when no column sort is chosen: by urutan (empty last),
     * then by nama.
     *
     * @param  Builder<MataPelajaran>  $query
     * @return Builder<MataPelajaran>
     */
    private static function urutanTampilan(Builder $query): Builder
    {
        return $query->urutanTampilan();
    }

    private static function pelaku(): User
    {
        /** @var User $pelaku */
        $pelaku = Filament::auth()->user();

        return $pelaku;
    }
}
