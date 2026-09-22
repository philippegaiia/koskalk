<?php

namespace App\Filament\Resources\HelpTopics\Pages;

use App\Filament\Resources\HelpTopics\HelpTopicResource;
use Filament\Resources\Pages\ListRecords;

class ListHelpTopics extends ListRecords
{
    protected static string $resource = HelpTopicResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
