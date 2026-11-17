<?php

namespace App\Filament\Resources\StatusKepegawaians\Pages;

use App\Filament\Resources\StatusKepegawaians\StatusKepegawaianResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStatusKepegawaians extends ListRecords
{
    protected static string $resource = StatusKepegawaianResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
