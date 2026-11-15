<?php

namespace App\Filament\Resources\TahunAjarans;

use App\Domains\Academic\Models\TahunAjaran;
use App\Filament\Resources\TahunAjarans\Pages\CreateTahunAjaran;
use App\Filament\Resources\TahunAjarans\Pages\EditTahunAjaran;
use App\Filament\Resources\TahunAjarans\Pages\ListTahunAjarans;
use App\Filament\Resources\TahunAjarans\RelationManagers\SemesterRelationManager;
use App\Filament\Resources\TahunAjarans\Schemas\TahunAjaranForm;
use App\Filament\Resources\TahunAjarans\Tables\TahunAjaransTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

/**
 * Excluded from Shield in config/filament-shield.php; access is decided by
 * the hand-written TahunAjaranPolicy and SemesterPolicy.
 */
class TahunAjaranResource extends Resource
{
    protected static ?string $model = TahunAjaran::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $recordTitleAttribute = 'nama';

    protected static ?string $modelLabel = 'tahun ajaran';

    protected static ?string $pluralModelLabel = 'tahun ajaran';

    protected static ?string $navigationLabel = 'Tahun Ajaran';

    protected static ?string $slug = 'tahun-ajaran';

    public static function form(Schema $schema): Schema
    {
        return TahunAjaranForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TahunAjaransTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            SemesterRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTahunAjarans::route('/'),
            'create' => CreateTahunAjaran::route('/create'),
            'edit' => EditTahunAjaran::route('/{record}/edit'),
        ];
    }

    /**
     * Service validation errors use plain keys (nama, tanggal_mulai); a
     * Filament form expects them under its state path.
     */
    public static function prefixValidationErrors(ValidationException $exception, string $statePath): ValidationException
    {
        $errors = [];

        foreach ($exception->errors() as $field => $messages) {
            $errors["{$statePath}.{$field}"] = $messages;
        }

        return ValidationException::withMessages($errors);
    }
}
