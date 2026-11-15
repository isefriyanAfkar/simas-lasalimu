<?php

namespace App\Filament\Resources\TahunAjarans\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

/**
 * Field rules here are only for convenience; TahunAjaranService decides.
 */
class TahunAjaranForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('nama')
                    ->label('Nama')
                    ->placeholder('2026/2027')
                    ->helperText('Format tahun/tahun, misalnya 2026/2027.')
                    ->required()
                    ->maxLength(9),
                DatePicker::make('tanggal_mulai')
                    ->label('Tanggal mulai')
                    ->required(),
                DatePicker::make('tanggal_selesai')
                    ->label('Tanggal selesai')
                    ->required(),
            ]);
    }
}
