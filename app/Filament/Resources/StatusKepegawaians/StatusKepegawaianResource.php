<?php

namespace App\Filament\Resources\StatusKepegawaians;

use App\Domains\People\Models\StatusKepegawaian;
use App\Filament\Resources\StatusKepegawaians\Pages\CreateStatusKepegawaian;
use App\Filament\Resources\StatusKepegawaians\Pages\EditStatusKepegawaian;
use App\Filament\Resources\StatusKepegawaians\Pages\ListStatusKepegawaians;
use App\Filament\Resources\StatusKepegawaians\Schemas\StatusKepegawaianForm;
use App\Filament\Resources\StatusKepegawaians\Tables\StatusKepegawaiansTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Excluded from Shield in config/filament-shield.php; access is decided by
 * the hand-written StatusKepegawaianPolicy (super_admin only). Statuses are
 * never deleted, so no page or table offers a delete action.
 */
class StatusKepegawaianResource extends Resource
{
    protected static ?string $model = StatusKepegawaian::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static ?string $recordTitleAttribute = 'nama';

    protected static ?string $modelLabel = 'status kepegawaian';

    protected static ?string $pluralModelLabel = 'status kepegawaian';

    protected static ?string $navigationLabel = 'Status Kepegawaian';

    protected static ?string $slug = 'status-kepegawaian';

    public static function form(Schema $schema): Schema
    {
        return StatusKepegawaianForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StatusKepegawaiansTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStatusKepegawaians::route('/'),
            'create' => CreateStatusKepegawaian::route('/create'),
            'edit' => EditStatusKepegawaian::route('/{record}/edit'),
        ];
    }
}
