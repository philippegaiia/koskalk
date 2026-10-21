<?php

use App\Actions\Purchasing\SaveSupplier;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceDossierGrant;
use App\Models\WorkspaceProductionEntitlement;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/**
 * @return array<string, mixed>
 */
function sharedSupplierAttributes(string $code = 'OLISUD'): array
{
    return ['code' => $code, 'name' => 'Olisud', 'default_currency' => 'EUR', 'is_active' => true];
}

it('lets a DIP-only workspace create a supplier without Production Bench', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create(['default_currency' => 'EUR']);
    WorkspaceDossierGrant::factory()->for($workspace)->create();

    $supplier = app(SaveSupplier::class)->handle($owner, $workspace, sharedSupplierAttributes());

    expect($supplier->workspace_id)->toBe($workspace->id)
        ->and(Supplier::query()->where('workspace_id', $workspace->id)->count())->toBe(1);
});

it('keeps supplier creation working for Production Bench workspaces without DIP', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create(['default_currency' => 'EUR']);
    WorkspaceProductionEntitlement::factory()->for($workspace)->create();

    expect(app(SaveSupplier::class)->handle($owner, $workspace, sharedSupplierAttributes())->exists)->toBeTrue();
});

it('refuses supplier creation when neither module is available', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create(['default_currency' => 'EUR']);

    app(SaveSupplier::class)->handle($owner, $workspace, sharedSupplierAttributes());
})->throws(ValidationException::class);

it('refuses supplier creation to a non-member even with both modules', function (): void {
    $workspace = Workspace::factory()->create(['default_currency' => 'EUR']);
    WorkspaceDossierGrant::factory()->for($workspace)->create();
    WorkspaceProductionEntitlement::factory()->for($workspace)->create();

    app(SaveSupplier::class)->handle(User::factory()->create(), $workspace, sharedSupplierAttributes());
})->throws(AuthorizationException::class);
