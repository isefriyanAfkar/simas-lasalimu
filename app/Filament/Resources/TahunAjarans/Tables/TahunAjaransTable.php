<?php

namespace App\Filament\Resources\TahunAjarans\Tables;

use App\Domains\Academic\Models\TahunAjaran;
use App\Domains\Academic\Services\PelanggaranAturanAkademik;
use App\Domains\Academic\Services\TahunAjaranService;
use App\Domains\Foundation\Models\User;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TahunAjaransTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('tanggal_mulai', 'desc')
            ->columns([
                TextColumn::make('nama')
                    ->label('Nama')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('tanggal_mulai')
                    ->label('Mulai')
                    ->date('d M Y')
                    ->sortable(),
                TextColumn::make('tanggal_selesai')
                    ->label('Selesai')
                    ->date('d M Y'),
                TextColumn::make('status_aktif')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Aktif' : 'Tidak aktif')
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('aktifkan')
                    ->label('Aktifkan')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Tahun ajaran lain akan dinonaktifkan, dan semester yang sesuai dengan tanggal hari ini ikut diaktifkan.')
                    ->hidden(fn (TahunAjaran $record): bool => $record->status_aktif)
                    ->authorize('update')
                    ->action(function (TahunAjaran $record): void {
                        /** @var User $pelaku */
                        $pelaku = Filament::auth()->user();

                        app(TahunAjaranService::class)->activate($pelaku, $record);

                        Notification::make()->success()->title("Tahun ajaran {$record->nama} diaktifkan")->send();
                    }),
                Action::make('hapus')
                    ->label('Hapus')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Tahun ajaran beserta kedua semesternya, dan import siswa yang batal atau gagal, akan dihapus.')
                    ->visible(fn (TahunAjaran $record): bool => ! $record->status_aktif)
                    ->authorize('delete')
                    ->action(function (TahunAjaran $record): void {
                        try {
                            /** @var User $pelaku */
                            $pelaku = Filament::auth()->user();

                            app(TahunAjaranService::class)->delete($pelaku, $record);
                        } catch (PelanggaranAturanAkademik $exception) {
                            Notification::make()->danger()->title($exception->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title("Tahun ajaran {$record->nama} dihapus")->send();
                    }),
            ]);
    }
}
