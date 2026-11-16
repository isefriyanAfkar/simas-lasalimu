<?php

namespace App\Filament\Resources\MataPelajarans\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

/**
 * Field rules here are only for convenience; MataPelajaranService decides.
 * There is no status field: a new subject is always active, and the status
 * changes only through the Nonaktifkan and Aktifkan actions.
 */
class MataPelajaranForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('kode')
                    ->label('Kode')
                    ->placeholder('MTK')
                    ->helperText('2 sampai 10 huruf atau angka. Disimpan dengan huruf besar.')
                    ->required()
                    ->maxLength(10),
                TextInput::make('nama')
                    ->label('Nama')
                    ->required()
                    ->maxLength(100),
                TextInput::make('urutan')
                    ->label('Urutan')
                    ->helperText('Boleh kosong. Daftar diurutkan menurut urutan, lalu nama.')
                    ->integer()
                    ->minValue(0)
                    ->maxValue(65535),
            ]);
    }
}
