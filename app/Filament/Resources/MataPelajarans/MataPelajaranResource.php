<?php

namespace App\Filament\Resources\MataPelajarans;

use App\Domains\Academic\Models\MataPelajaran;
use App\Filament\Resources\MataPelajarans\Pages\CreateMataPelajaran;
use App\Filament\Resources\MataPelajarans\Pages\EditMataPelajaran;
use App\Filament\Resources\MataPelajarans\Pages\ListMataPelajarans;
use App\Filament\Resources\MataPelajarans\Schemas\MataPelajaranForm;
use App\Filament\Resources\MataPelajarans\Tables\MataPelajaransTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Excluded from Shield in config/filament-shield.php; access is decided by
 * the hand-written MataPelajaranPolicy. Subjects are never deleted, so no
 * page or table offers a delete action.
 */
class MataPelajaranResource extends Resource
{
    protected static ?string $model = MataPelajaran::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?string $recordTitleAttribute = 'nama';

    protected static ?string $modelLabel = 'mata pelajaran';

    protected static ?string $pluralModelLabel = 'mata pelajaran';

    protected static ?string $navigationLabel = 'Mata Pelajaran';

    protected static ?string $slug = 'mata-pelajaran';

    public static function form(Schema $schema): Schema
    {
        return MataPelajaranForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MataPelajaransTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMataPelajarans::route('/'),
            'create' => CreateMataPelajaran::route('/create'),
            'edit' => EditMataPelajaran::route('/{record}/edit'),
        ];
    }
}
