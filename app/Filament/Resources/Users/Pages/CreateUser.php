<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Services\EntitlementService;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function afterCreate(): void
    {
        if (! $this->record instanceof User) {
            return;
        }

        app(EntitlementService::class)->assignDefaultPlan($this->record);
    }
}
