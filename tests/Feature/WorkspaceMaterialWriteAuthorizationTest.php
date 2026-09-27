<?php

use App\Enums\MaterialPriceSource;
use App\Enums\OwnerType;
use App\Enums\Visibility;
use App\Enums\WorkspaceMemberRole;
use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\Plan;
use App\Models\User;
use App\Models\UserEntitlement;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\CurrentMaterialPriceService;
use App\Services\WorkspaceIngredientCodeService;
use App\Services\WorkspaceIngredientGuidanceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function writeWorkspaceMaterial(string $operation, User $actor, Workspace $workspace, Ingredient $ingredient, PackagingItem $packaging): void
{
    match ($operation) {
        'code' => app(WorkspaceIngredientCodeService::class)->synchronize($actor, $workspace, $ingredient, 'NEW'),
        'clear code' => app(WorkspaceIngredientCodeService::class)->synchronize($actor, $workspace, $ingredient, null),
        'guidance' => app(WorkspaceIngredientGuidanceService::class)->save($actor, $workspace, $ingredient, '<p>New guidance</p>'),
        'platform guidance' => app(WorkspaceIngredientGuidanceService::class)->usePlatform($actor, $workspace, $ingredient),
        'workspace guidance' => app(WorkspaceIngredientGuidanceService::class)->useWorkspace($actor, $workspace, $ingredient),
        'ingredient price' => app(CurrentMaterialPriceService::class)->rememberIngredient($workspace, $ingredient, '5', 'kg', 'EUR', MaterialPriceSource::ManualCosting, null, $actor),
        'packaging price' => app(CurrentMaterialPriceService::class)->rememberPackaging($workspace, $packaging, '5', 'EUR', MaterialPriceSource::ManualCosting, null, $actor),
        'forget price' => app(CurrentMaterialPriceService::class)->forgetIngredient($workspace, $ingredient, $actor),
        'restore ingredient price' => app(CurrentMaterialPriceService::class)->restoreIngredientProjection($workspace, $ingredient, null, null, null, null, null, $actor),
        'restore packaging price' => app(CurrentMaterialPriceService::class)->restorePackagingProjection($workspace, $packaging, null, null, null, null, null, $actor),
    };
}

it('rejects stale material writes after workspace authority changes', function (string $operation, string $change): void {
    $workspace = Workspace::factory()->create();
    $actor = User::factory()->create();
    $membership = WorkspaceMember::factory()->for($workspace)->for($actor)->create(['role' => WorkspaceMemberRole::Editor]);
    $entitlement = UserEntitlement::factory()->for($workspace->owner)->for(Plan::factory()->create(['allows_collaboration' => true]))->create();
    $ingredient = Ingredient::factory()->create();
    $packaging = PackagingItem::factory()->for($workspace)->create();
    app(WorkspaceIngredientCodeService::class)->synchronize($actor, $workspace, $ingredient, 'ORIGINAL');
    app(WorkspaceIngredientGuidanceService::class)->save($actor, $workspace, $ingredient, '<p>Original</p>');
    app(CurrentMaterialPriceService::class)->rememberIngredient($workspace, $ingredient, '1', 'kg', 'EUR', MaterialPriceSource::ManualCosting, null, $actor);
    app(CurrentMaterialPriceService::class)->rememberPackaging($workspace, $packaging, '1', 'EUR', MaterialPriceSource::ManualCosting, null, $actor);
    $actor->company();

    match ($change) {
        'demotion' => $membership->update(['role' => WorkspaceMemberRole::Viewer]),
        'removal' => $membership->delete(),
        'entitlement expiry' => $entitlement->update(['ends_at' => now()->subMinute()]),
        'selection' => User::query()->whereKey($actor->id)->update(['active_workspace_id' => Workspace::factory()->for($actor, 'owner')->create()->id]),
    };

    expect(fn () => writeWorkspaceMaterial($operation, $actor, $workspace, $ingredient, $packaging))
        ->toThrow(AuthorizationException::class);
    $this->assertDatabaseHas('workspace_ingredient_codes', ['workspace_id' => $workspace->id, 'material_code' => 'ORIGINAL']);
    $this->assertDatabaseHas('workspace_ingredient_guidances', ['workspace_id' => $workspace->id, 'guidance_html' => '<p>Original</p>', 'is_active' => true]);
    expect($workspace->currentMaterialPrices()->count())->toBe(2);
    expect($workspace->currentMaterialPrices()->where('ingredient_id', $ingredient->id)->sole()->price_per_canonical_unit)->toBe('0.001000000000');
    expect($workspace->currentMaterialPrices()->where('packaging_item_id', $packaging->id)->sole()->price_per_canonical_unit)->toBe('1.000000000000');
})->with([
    'code', 'clear code', 'guidance', 'platform guidance', 'workspace guidance',
    'ingredient price', 'packaging price', 'forget price', 'restore ingredient price', 'restore packaging price',
])->with(['demotion', 'removal', 'entitlement expiry', 'selection']);

it('keeps private guidance when an editor is demoted before clearing it', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = User::factory()->create();
    $membership = WorkspaceMember::factory()->for($workspace)->for($actor)->create(['role' => WorkspaceMemberRole::Editor]);
    UserEntitlement::factory()->for($workspace->owner)->for(Plan::factory()->create(['allows_collaboration' => true]))->create();
    $ingredient = Ingredient::factory()->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
    ]);
    $service = app(WorkspaceIngredientGuidanceService::class);
    $guidance = $service->save($actor, $workspace, $ingredient, '<p>Keep me</p>');
    $actor->company();
    $membership->update(['role' => WorkspaceMemberRole::Viewer]);

    expect(fn () => $service->clearWorkspaceOwned($actor, $workspace, $ingredient))
        ->toThrow(AuthorizationException::class);
    expect($guidance->fresh()->guidance_html)->toBe('<p>Keep me</p>');
});
