<?php

namespace App\Filament\Resources\StatusKepegawaians\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

/**
 * Field rules here are only for convenience; StatusKepegawaianService
 * decides. There is no status field: a new status is always active, and it
 * changes only through the Nonaktifkan and Aktifkan actions.
 */
class StatusKepegawaianForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('nama')
                    ->label('Nama')
                    ->placeholder('PNS')
                    ->required()
                    ->maxLength(50),
                TextInput::make('urutan')
                    ->label('Urutan')
                    ->helperText('Boleh kosong. Daftar diurutkan menurut urutan, lalu nama.')
                    ->integer()
                    ->minValue(0)
                    ->maxValue(65535),
            ]);
    }
}
