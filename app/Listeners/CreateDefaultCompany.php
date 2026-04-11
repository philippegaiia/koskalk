<?php

namespace App\Listeners;

use App\Models\Workspace;
use App\WorkspaceMemberRole;
use Filament\Auth\Events\Registered;
use Illuminate\Support\Str;

class CreateDefaultCompany
{
    public function handle(Registered $event): void
    {
        $user = $event->user;

        $workspace = Workspace::withoutGlobalScopes()->create([
            'owner_user_id' => $user->id,
            'name' => explode(' ', trim($user->name ?? 'My Company'))[0]."'s Company",
            'slug' => Str::slug($user->name.'-'.Str::random(6)),
            'default_currency' => 'EUR',
        ]);

        $workspace->members()->create([
            'user_id' => $user->id,
            'role' => WorkspaceMemberRole::Owner->value,
        ]);
    }
}
