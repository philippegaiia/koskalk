<?php

namespace App\Filament\Resources\HelpTopics\Pages;

use App\Filament\Resources\HelpTopics\HelpTopicResource;
use Filament\Resources\Pages\ListRecords;
use Livewire\Attributes\Locked;

class ListHelpTopics extends ListRecords
{
    protected static string $resource = HelpTopicResource::class;

    #[Locked]
    public array $helpBulkSnapshot = [];

    protected function getHeaderActions(): array
    {
        return [];
    }
}
