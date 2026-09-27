<?php

use App\Actions\Production\DeleteDepartment;
use App\Actions\Production\DeleteEmployee;
use App\Actions\Production\DeleteProductionRun;
use App\Enums\WorkspaceMemberRole;
use App\Livewire\ProductionBench\Production\ProductionIndex;
use App\Livewire\ProductionBench\Production\SettingsIndex;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Plan;
use App\Models\ProductionRun;
use App\Models\User;
use App\Models\UserEntitlement;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Models\WorkspaceProductionEntitlement;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('restricts eligible production record deletion to owners and admins', function (string $modelClass, string $actionClass, WorkspaceMemberRole $role): void {
    $workspace = Workspace::factory()->create();
    UserEntitlement::factory()->for($workspace->owner)->for(Plan::factory()->create(['allows_collaboration' => true]))->create();
    WorkspaceProductionEntitlement::factory()->for($workspace)->create();
    $actor = $role === WorkspaceMemberRole::Owner ? $workspace->owner : User::factory()->create(['active_workspace_id' => $workspace->id]);
    if ($role !== WorkspaceMemberRole::Owner) {
        WorkspaceMember::factory()->for($workspace)->for($actor)->create(['role' => $role]);
    }
    $record = $modelClass::factory()->for($workspace)->create();
    $delete = fn () => $record instanceof ProductionRun
        ? app($actionClass)->handle($actor, $record)
        : app($actionClass)->handle($actor, $workspace, $record);

    if (in_array($role, [WorkspaceMemberRole::Owner, WorkspaceMemberRole::Admin], true)) {
        $delete();
        expect($modelClass::query()->whereKey($record->id)->exists())->toBeFalse();
    } else {
        expect($delete)->toThrow(AuthorizationException::class);
        expect($modelClass::query()->whereKey($record->id)->exists())->toBeTrue();
    }
})->with([
    'employee' => [Employee::class, DeleteEmployee::class],
    'department' => [Department::class, DeleteDepartment::class],
    'production' => [ProductionRun::class, DeleteProductionRun::class],
])->with(WorkspaceMemberRole::cases());

it('hides production deletion from an editor and rejects a crafted delete request', function (): void {
    $workspace = Workspace::factory()->create();
    UserEntitlement::factory()->for($workspace->owner)->for(Plan::factory()->create(['allows_collaboration' => true]))->create();
    WorkspaceProductionEntitlement::factory()->for($workspace)->create();
    $editor = User::factory()->create(['active_workspace_id' => $workspace->id]);
    WorkspaceMember::factory()->for($workspace)->for($editor)->create(['role' => WorkspaceMemberRole::Editor]);
    $production = ProductionRun::factory()->for($workspace)->create();

    Livewire::actingAs($editor)->test(ProductionIndex::class)
        ->assertDontSeeHtml('data-production-delete-action')
        ->call('deleteProduction', $production->id)
        ->assertForbidden();

    expect($production->fresh())->not->toBeNull();
});

it('hides setup record deletion from an editor and rejects a crafted delete request', function (string $section, string $modelClass, string $method): void {
    $workspace = Workspace::factory()->create();
    UserEntitlement::factory()->for($workspace->owner)->for(Plan::factory()->create(['allows_collaboration' => true]))->create();
    WorkspaceProductionEntitlement::factory()->for($workspace)->create();
    $editor = User::factory()->create(['active_workspace_id' => $workspace->id]);
    WorkspaceMember::factory()->for($workspace)->for($editor)->create(['role' => WorkspaceMemberRole::Editor]);
    $record = $modelClass::factory()->for($workspace)->create();

    Livewire::actingAs($editor)->test(SettingsIndex::class, ['section' => $section])
        ->assertDontSeeHtml('wire:click="'.$method.'(')
        ->call($method, $record->id)
        ->assertForbidden();

    expect($record->fresh())->not->toBeNull();
})->with([
    'employee' => ['employees', Employee::class, 'deleteEmployee'],
    'department' => ['departments', Department::class, 'deleteDepartment'],
]);
