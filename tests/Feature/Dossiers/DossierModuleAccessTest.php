<?php

use App\Enums\WorkspaceMemberRole;
use App\Enums\WorkspaceModule;
use App\Models\Plan;
use App\Models\User;
use App\Models\UserEntitlement;
use App\Models\Workspace;
use App\Models\WorkspaceDossierGrant;
use App\Models\WorkspaceMember;
use App\Services\WorkspaceAuthorization;
use App\Services\WorkspaceCapabilities;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{0: User, 1: Workspace, 2: User}
 */
function dossierAccessWorkspaceWithMember(WorkspaceMemberRole $role): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $plan = Plan::factory()->create(['allows_collaboration' => true]);
    UserEntitlement::factory()->for($owner)->for($plan)->create();
    $member = User::factory()->create();
    WorkspaceMember::factory()->for($workspace)->for($member)->create(['role' => $role]);

    return [$owner, $workspace, $member];
}

it('hides the dossier module from a workspace without a grant', function (): void {
    [$owner, $workspace] = dossierAccessWorkspaceWithMember(WorkspaceMemberRole::Admin);
    $authorization = app(WorkspaceAuthorization::class);

    expect(app(WorkspaceCapabilities::class)->allowsProductDossiers($workspace->id))->toBeFalse()
        ->and($authorization->canViewModule($owner, $workspace->id, WorkspaceModule::ProductDossiers))->toBeFalse()
        ->and($authorization->canEditModule($owner, $workspace->id, WorkspaceModule::ProductDossiers))->toBeFalse();
});

it('opens the dossier module to the owner and admins of a granted workspace only', function (WorkspaceMemberRole $role, bool $allowed): void {
    [$owner, $workspace, $member] = dossierAccessWorkspaceWithMember($role);
    WorkspaceDossierGrant::factory()->for($workspace)->create();
    $authorization = app(WorkspaceAuthorization::class);

    expect(app(WorkspaceCapabilities::class)->allowsProductDossiers($workspace->id))->toBeTrue()
        ->and($authorization->canEditModule($owner, $workspace->id, WorkspaceModule::ProductDossiers))->toBeTrue()
        ->and($authorization->canViewModule($member, $workspace->id, WorkspaceModule::ProductDossiers))->toBe($allowed)
        ->and($authorization->canEditModule($member, $workspace->id, WorkspaceModule::ProductDossiers))->toBe($allowed);
})->with([
    'admin' => [WorkspaceMemberRole::Admin, true],
    'editor' => [WorkspaceMemberRole::Editor, false],
    'viewer' => [WorkspaceMemberRole::Viewer, false],
]);

it('closes the dossier module when the grant is revoked', function (): void {
    [$owner, $workspace] = dossierAccessWorkspaceWithMember(WorkspaceMemberRole::Admin);
    WorkspaceDossierGrant::factory()->for($workspace)->revoked()->create();

    expect(app(WorkspaceCapabilities::class)->allowsProductDossiers($workspace->id))->toBeFalse()
        ->and(app(WorkspaceAuthorization::class)->canEditModule($owner, $workspace->id, WorkspaceModule::ProductDossiers))->toBeFalse();
});

it('does not let a grant on one workspace open another', function (): void {
    [$owner, $workspace] = dossierAccessWorkspaceWithMember(WorkspaceMemberRole::Admin);
    WorkspaceDossierGrant::factory()->create();

    expect(app(WorkspaceAuthorization::class)->canEditModule($owner, $workspace->id, WorkspaceModule::ProductDossiers))->toBeFalse();
});
