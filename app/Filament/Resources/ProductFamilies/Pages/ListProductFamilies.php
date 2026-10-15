<?php

namespace App\Filament\Resources\ProductFamilies\Pages;

use App\Filament\Resources\ProductFamilies\ProductFamilyResource;
use Filament\Resources\Pages\ListRecords;

class ListProductFamilies extends ListRecords
{
    protected static string $resource = ProductFamilyResource::class;
}
