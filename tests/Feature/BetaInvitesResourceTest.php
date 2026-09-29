<?php

use App\Filament\Resources\BetaInvites\Pages\CreateBetaInvite;
use App\Models\BetaInvite;
use App\Models\User;
use App\Models\Workspace;
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

it('lets an administrator invite an existing member without an owned company', function (): void {
    Notification::fake();
    $member = User::factory()->create();
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(CreateBetaInvite::class)
        ->fillForm(['email' => $member->email, 'workspace_name' => 'My own company'])
        ->call('create')->assertHasNoFormErrors();

    expect(BetaInvite::query()->sole()->email)->toBe($member->email);
    Notification::assertSentOnDemand(BetaWorkspaceInvitation::class);
});

it('displays an existing owner rejection on the admin email field', function (): void {
    Notification::fake();
    $workspace = Workspace::factory()->create();
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(CreateBetaInvite::class)
        ->fillForm(['email' => $workspace->owner->email, 'workspace_name' => 'Second company'])
        ->call('create')->assertHasFormErrors(['email']);

    $this->assertDatabaseCount('beta_invites', 0);
    Notification::assertNothingSent();
});
