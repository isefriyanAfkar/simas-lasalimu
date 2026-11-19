<?php

namespace App\Filament\Resources\WaliMurids\Pages;

use App\Filament\Resources\WaliMurids\WaliMuridResource;
use Filament\Resources\Pages\ListRecords;

/**
 * No create action: a parent is added from a student.
 */
class ListWaliMurids extends ListRecords
{
    protected static string $resource = WaliMuridResource::class;
}
