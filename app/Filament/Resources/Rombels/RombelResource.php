<?php

namespace App\Filament\Resources\Rombels;

use App\Domains\Academic\Models\Rombel;
use App\Filament\Resources\Rombels\Pages\CreateRombel;
use App\Filament\Resources\Rombels\Pages\EditRombel;
use App\Filament\Resources\Rombels\Pages\ListRombels;
use App\Filament\Resources\Rombels\Schemas\RombelForm;
use App\Filament\Resources\Rombels\Tables\RombelsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Excluded from Shield in config/filament-shield.php; access is decided by
 * the hand-written RombelPolicy. Deleting is offered only from the list, to
 * whoever holds Delete:Rombel (the super_admin).
 */
class RombelResource extends Resource
{
    protected static ?string $model = Rombel::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $modelLabel = 'rombel';

    protected static ?string $pluralModelLabel = 'rombel';

    protected static ?string $navigationLabel = 'Rombel';

    protected static ?string $slug = 'rombel';

    public static function form(Schema $schema): Schema
    {
        return RombelForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RombelsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRombels::route('/'),
            'create' => CreateRombel::route('/create'),
            'edit' => EditRombel::route('/{record}/edit'),
        ];
    }
}
