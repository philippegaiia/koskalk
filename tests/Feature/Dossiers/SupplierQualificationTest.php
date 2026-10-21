<?php

use App\Actions\Dossiers\SaveSupplierQualification;
use App\Actions\Purchasing\DeleteSupplier;
use App\Enums\OwnerType;
use App\Enums\SupplierQualificationStatus;
use App\Enums\Visibility;
use App\Models\Ingredient;
use App\Models\Supplier;
use App\Models\SupplierQualification;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceDossierGrant;
use App\Models\WorkspaceProductionEntitlement;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/**
 * @return array{0: User, 1: Workspace, 2: Supplier}
 */
function qualificationWorkspace(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    WorkspaceDossierGrant::factory()->for($workspace)->create();
    $supplier = Supplier::factory()->create(['workspace_id' => $workspace->id]);

    return [$owner, $workspace, $supplier];
}

it('qualifies a supplier for a platform ingredient and records who validated it', function (): void {
    [$owner, $workspace, $supplier] = qualificationWorkspace();
    $olive = Ingredient::factory()->create();

    $qualification = app(SaveSupplierQualification::class)->handle($owner, $workspace, [
        'ingredient_id' => $olive->id,
        'supplier_id' => $supplier->id,
        'supplier_reference' => 'HV OLIVE RAFF BIO',
        'status' => 'valide',
    ]);

    expect($qualification->status)->toBe(SupplierQualificationStatus::Valide)
        ->and($qualification->validated_by_user_id)->toBe($owner->id)
        ->and($qualification->validated_at)->not->toBeNull();
});

it('keeps one qualification per ingredient, supplier and reference', function (): void {
    [$owner, $workspace, $supplier] = qualificationWorkspace();
    $olive = Ingredient::factory()->create();
    $attributes = ['ingredient_id' => $olive->id, 'supplier_id' => $supplier->id, 'supplier_reference' => 'REF-1', 'status' => 'en_evaluation'];

    app(SaveSupplierQualification::class)->handle($owner, $workspace, $attributes);
    app(SaveSupplierQualification::class)->handle($owner, $workspace, [...$attributes, 'status' => 'retire']);

    expect(SupplierQualification::query()->sole()->status)->toBe(SupplierQualificationStatus::Retire)
        ->and(SupplierQualification::query()->sole()->validated_at)->toBeNull();
});

it('refuses another workspace supplier or private ingredient', function (string $case): void {
    [$owner, $workspace, $supplier] = qualificationWorkspace();
    $other = Workspace::factory()->create();
    $attributes = match ($case) {
        'supplier' => ['ingredient_id' => Ingredient::factory()->create()->id, 'supplier_id' => Supplier::factory()->create(['workspace_id' => $other->id])->id],
        'ingredient' => ['ingredient_id' => Ingredient::factory()->create([
            'owner_type' => OwnerType::Workspace, 'owner_id' => $other->id, 'workspace_id' => $other->id, 'visibility' => Visibility::Private,
        ])->id, 'supplier_id' => $supplier->id],
    };

    app(SaveSupplierQualification::class)->handle($owner, $workspace, [...$attributes, 'supplier_reference' => null, 'status' => 'valide']);
})->with(['supplier', 'ingredient'])->throws(ValidationException::class);

it('refuses qualification without the DIP module', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();

    app(SaveSupplierQualification::class)->handle($owner, $workspace, [
        'ingredient_id' => Ingredient::factory()->create()->id,
        'supplier_id' => Supplier::factory()->create(['workspace_id' => $workspace->id])->id,
        'status' => 'valide',
    ]);
})->throws(AuthorizationException::class);

it('deactivates instead of deleting a supplier referenced by a qualification', function (): void {
    [$owner, $workspace, $supplier] = qualificationWorkspace();
    WorkspaceProductionEntitlement::factory()->for($workspace)->create();
    SupplierQualification::factory()->create(['workspace_id' => $workspace->id, 'supplier_id' => $supplier->id]);

    expect(app(DeleteSupplier::class)->handle($owner, $workspace, $supplier))->toBeFalse()
        ->and($supplier->refresh()->is_active)->toBeFalse();
});
