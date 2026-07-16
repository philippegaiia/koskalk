<?php

use App\Filament\Resources\BetaInvites\Pages\CreateBetaInvite;
use App\Models\BetaInvite;
use App\Models\User;
use App\Notifications\BetaWorkspaceInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('lets an administrator issue a Free beta invitation from the admin panel', function () {
    Notification::fake();

    $administrator = User::factory()->admin()->create();

    $this->actingAs($administrator);

    Livewire::test(CreateBetaInvite::class)
        ->fillForm([
            'email' => 'filament.beta@example.com',
            'workspace_name' => 'Filament Beta Studio',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(BetaInvite::query()
        ->where('email', 'filament.beta@example.com')
        ->sole()
        ->isPending())->toBeTrue();

    Notification::assertSentOnDemand(BetaWorkspaceInvitation::class);
});
