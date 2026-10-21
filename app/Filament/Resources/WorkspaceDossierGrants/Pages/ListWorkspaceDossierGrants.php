<?php

namespace App\Filament\Resources\WorkspaceDossierGrants\Pages;

use App\Filament\Resources\WorkspaceDossierGrants\WorkspaceDossierGrantResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWorkspaceDossierGrants extends ListRecords
{
    protected static string $resource = WorkspaceDossierGrantResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
