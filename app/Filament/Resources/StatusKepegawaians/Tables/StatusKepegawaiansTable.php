<?php

namespace App\Filament\Resources\StatusKepegawaians\Tables;

use App\Domains\Foundation\Models\User;
use App\Domains\People\Models\StatusKepegawaian;
use App\Domains\People\Services\StatusKepegawaianService;
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
 * No delete or bulk actions: a status is only deactivated. An inactive
 * status stays on the staff who already hold it, but is not offered for new
 * staff.
 */
class StatusKepegawaiansTable
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
                    ->modalDescription('Status tidak dihapus dan tetap tercatat pada guru dan staf yang sudah memakainya, tetapi tidak bisa dipilih untuk data baru. Status bisa diaktifkan lagi.')
                    ->visible(fn (StatusKepegawaian $record): bool => $record->status_aktif)
                    ->authorize('update')
                    ->action(function (StatusKepegawaian $record): void {
                        app(StatusKepegawaianService::class)->deactivate(self::pelaku(), $record);

                        Notification::make()->success()->title("Status kepegawaian {$record->nama} dinonaktifkan")->send();
                    }),
                Action::make('aktifkan')
                    ->label('Aktifkan')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->requiresConfirmation()
                    ->hidden(fn (StatusKepegawaian $record): bool => $record->status_aktif)
                    ->authorize('update')
                    ->action(function (StatusKepegawaian $record): void {
                        app(StatusKepegawaianService::class)->activate(self::pelaku(), $record);

                        Notification::make()->success()->title("Status kepegawaian {$record->nama} diaktifkan")->send();
                    }),
            ]);
    }

    /**
     * Default order when no column sort is chosen: by urutan (empty last),
     * then by nama.
     *
     * @param  Builder<StatusKepegawaian>  $query
     * @return Builder<StatusKepegawaian>
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
