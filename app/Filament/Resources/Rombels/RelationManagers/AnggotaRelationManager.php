<?php

namespace App\Filament\Resources\Rombels\RelationManagers;

use App\Domains\Academic\Models\Rombel;
use App\Domains\Academic\Services\RombelSiswa;
use App\Domains\Foundation\Models\User;
use App\Domains\People\Enums\StatusSiswa;
use App\Domains\People\Models\Siswa;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Members of one rombel, read only: placing and moving happen on the Siswa
 * screen. Students who left stay listed with their status (their placement
 * is history). The heading counts only active students against the
 * capacity, like PenempatanRombelService does.
 */
class AnggotaRelationManager extends RelationManager
{
    protected static string $relationship = 'penempatan';

    protected static ?string $title = 'Anggota';

    protected static ?string $modelLabel = 'anggota';

    /**
     * Whoever may view the rombel and the student list. There is no policy
     * for placements: they are written only through PenempatanRombelService.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $user->can('view', $ownerRecord) && $user->can('viewAny', Siswa::class);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(fn (): string => self::judul($this->getOwnerRecord()))
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('siswa.user'))
            ->defaultSort(fn (Builder $query): Builder => $query->orderBy(
                User::query()->select('users.name')
                    ->join('siswa', 'siswa.user_id', '=', 'users.id')
                    ->whereColumn('siswa.id', 'penempatan_rombel.siswa_id'),
            ))
            ->paginated(false)
            ->columns([
                TextColumn::make('siswa.nis')
                    ->label('NIS'),
                TextColumn::make('siswa.user.name')
                    ->label('Nama'),
                TextColumn::make('siswa.status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (StatusSiswa $state): string => $state->label())
                    ->color(fn (StatusSiswa $state): string => $state === StatusSiswa::Aktif ? 'success' : 'gray'),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }

    /**
     * "Anggota (28 dari 32)", or "Anggota (28, tanpa batas)" without a
     * capacity; only active students are counted.
     */
    public static function judul(Model $rombel): string
    {
        /** @var Rombel $rombel */
        $jumlah = app(RombelSiswa::class)->jumlahSiswaAktif($rombel);

        return $rombel->kapasitas === null
            ? "Anggota ({$jumlah}, tanpa batas)"
            : "Anggota ({$jumlah} dari {$rombel->kapasitas})";
    }
}
