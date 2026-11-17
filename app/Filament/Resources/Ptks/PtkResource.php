<?php

namespace App\Filament\Resources\Ptks;

use App\Domains\People\Models\Ptk;
use App\Filament\Resources\Ptks\Pages\CreatePtk;
use App\Filament\Resources\Ptks\Pages\EditPtk;
use App\Filament\Resources\Ptks\Pages\ListPtks;
use App\Filament\Resources\Ptks\Pages\ViewPtk;
use App\Filament\Resources\Ptks\Schemas\PtkForm;
use App\Filament\Resources\Ptks\Tables\PtksTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * "Guru dan Staf". Excluded from Shield in config/filament-shield.php; access
 * is decided by the hand-written PtkPolicy. Staff are never deleted, so no
 * page or table offers a delete action. A record the user may not update
 * (an operator looking at another operator) opens on the read-only view page.
 */
class PtkResource extends Resource
{
    protected static ?string $model = Ptk::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static ?string $modelLabel = 'guru/staf';

    protected static ?string $pluralModelLabel = 'guru dan staf';

    protected static ?string $navigationLabel = 'Guru dan Staf';

    protected static ?string $slug = 'guru-dan-staf';

    public static function form(Schema $schema): Schema
    {
        return PtkForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PtksTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPtks::route('/'),
            'create' => CreatePtk::route('/create'),
            'view' => ViewPtk::route('/{record}'),
            'edit' => EditPtk::route('/{record}/edit'),
        ];
    }
}
