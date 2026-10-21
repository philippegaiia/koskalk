<?php

namespace App\Filament\Resources\WorkspaceDossierGrants\Pages;

use App\Filament\Resources\WorkspaceDossierGrants\WorkspaceDossierGrantResource;
use Filament\Resources\Pages\CreateRecord;

class CreateWorkspaceDossierGrant extends CreateRecord
{
    protected static string $resource = WorkspaceDossierGrantResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return [
            ...$data,
            'granted_by_user_id' => auth()->id(),
            'granted_at' => now(),
            'revoked_at' => null,
        ];
    }
}
