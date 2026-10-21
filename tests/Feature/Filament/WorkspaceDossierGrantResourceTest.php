<?php

use App\Filament\Resources\WorkspaceDossierGrants\Pages\CreateWorkspaceDossierGrant;
use App\Filament\Resources\WorkspaceDossierGrants\Pages\ListWorkspaceDossierGrants;
use App\Filament\Resources\WorkspaceDossierGrants\WorkspaceDossierGrantResource;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceDossierGrant;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('lets an admin grant the dossier module to a workspace', function (): void {
    $admin = User::factory()->admin()->create();
    $workspace = Workspace::factory()->create();
    $this->actingAs($admin);

    Livewire::test(CreateWorkspaceDossierGrant::class)
        ->fillForm(['workspace_id' => $workspace->id])
        ->call('create')
        ->assertHasNoFormErrors();

    $grant = WorkspaceDossierGrant::query()->sole();

    expect($grant->workspace_id)->toBe($workspace->id)
        ->and($grant->granted_by_user_id)->toBe($admin->id)
        ->and($grant->granted_at)->not->toBeNull()
        ->and($grant->isActive())->toBeTrue();
});

it('revokes and restores a grant from the list', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $grant = WorkspaceDossierGrant::factory()->create();

    Livewire::test(ListWorkspaceDossierGrants::class)
        ->callAction(TestAction::make('revoke')->table($grant));

    expect($grant->refresh()->isActive())->toBeFalse();

    Livewire::test(ListWorkspaceDossierGrants::class)
        ->callAction(TestAction::make('restore')->table($grant));

    expect($grant->refresh()->isActive())->toBeTrue();
});

it('keeps the grant resource out of reach of non-admin users', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get(WorkspaceDossierGrantResource::getUrl(panel: 'admin'))->assertForbidden();
});
