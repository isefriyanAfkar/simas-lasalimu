<?php

namespace App\Filament\Resources\WaliMurids;

use App\Domains\People\Models\WaliMurid;
use App\Filament\Resources\WaliMurids\Pages\EditWaliMurid;
use App\Filament\Resources\WaliMurids\Pages\ListWaliMurids;
use App\Filament\Resources\WaliMurids\Schemas\WaliMuridForm;
use App\Filament\Resources\WaliMurids\Tables\WaliMuridsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * "Wali Murid". Excluded from Shield in config/filament-shield.php; access
 * is decided by the hand-written WaliMuridPolicy. There is no create page: a
 * parent is always added from a student (the Wali murid section on the
 * student's edit page).
 */
class WaliMuridResource extends Resource
{
    protected static ?string $model = WaliMurid::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHomeModern;

    protected static ?string $modelLabel = 'wali murid';

    protected static ?string $pluralModelLabel = 'wali murid';

    protected static ?string $navigationLabel = 'Wali Murid';

    protected static ?string $slug = 'wali-murid';

    public static function form(Schema $schema): Schema
    {
        return WaliMuridForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WaliMuridsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWaliMurids::route('/'),
            'edit' => EditWaliMurid::route('/{record}/edit'),
        ];
    }
}
