<?php

namespace App\Filament\Resources\TahunAjarans\RelationManagers;

use App\Domains\Academic\Enums\JenisSemester;
use App\Domains\Academic\Models\Semester;
use App\Domains\Academic\Services\PelanggaranAturanAkademik;
use App\Domains\Academic\Services\SemesterService;
use App\Domains\Foundation\Models\User;
use App\Filament\Support\GalatValidasi;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

/**
 * Semesters of one school year. They are created and deleted only with the
 * school year, so this table has no add or delete action; only the dates
 * change, and a semester can be activated.
 */
class SemesterRelationManager extends RelationManager
{
    protected static string $relationship = 'semester';

    protected static ?string $title = 'Semester';

    protected static ?string $modelLabel = 'semester';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitle(fn (Semester $record): string => $record->jenis->label())
            ->defaultSort('tanggal_mulai')
            ->paginated(false)
            ->columns([
                TextColumn::make('jenis')
                    ->label('Semester')
                    ->formatStateUsing(fn (JenisSemester $state): string => $state->label()),
                TextColumn::make('tanggal_mulai')
                    ->label('Mulai')
                    ->date('d M Y'),
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
                Action::make('ubahTanggal')
                    ->label('Ubah tanggal')
                    ->icon(Heroicon::OutlinedCalendar)
                    ->authorize('update')
                    ->fillForm(fn (Semester $record): array => [
                        'tanggal_mulai' => $record->tanggal_mulai->toDateString(),
                        'tanggal_selesai' => $record->tanggal_selesai->toDateString(),
                    ])
                    ->schema([
                        DatePicker::make('tanggal_mulai')->label('Tanggal mulai')->required(),
                        DatePicker::make('tanggal_selesai')->label('Tanggal selesai')->required(),
                    ])
                    ->action(function (Semester $record, array $data, Action $action): void {
                        try {
                            app(SemesterService::class)->updateDates($record, $data);
                        } catch (ValidationException $exception) {
                            throw GalatValidasi::prefix($exception, 'mountedActions.'.($action->getNestingIndex() ?? 0).'.data');
                        }

                        Notification::make()->success()->title("Tanggal semester {$record->jenis->label()} diubah")->send();
                    }),
                Action::make('aktifkan')
                    ->label('Aktifkan')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->requiresConfirmation()
                    ->hidden(fn (Semester $record): bool => $record->status_aktif || ! $record->tahunAjaran->status_aktif)
                    ->authorize('update')
                    ->action(function (Semester $record): void {
                        /** @var User $pelaku */
                        $pelaku = Filament::auth()->user();

                        try {
                            app(SemesterService::class)->activate($pelaku, $record);
                        } catch (PelanggaranAturanAkademik $exception) {
                            Notification::make()->danger()->title($exception->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title("Semester {$record->jenis->label()} diaktifkan")->send();
                    }),
            ]);
    }
}
