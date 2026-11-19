<?php

use App\Actions\Production\CreateProductionDraft;
use App\Enums\PackedLotSuffix;
use App\Enums\ProductionRequirementKind;
use App\Models\PackagingItem;
use App\Models\ProductFamily;
use App\Models\ProductionRunNumberSetting;
use App\Models\ProductSize;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\StockLot;
use App\Models\Workspace;
use App\Models\WorkspaceProductionEntitlement;
use App\Services\Production\PackedLotCodeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('checks the final truncated packed code when the same long SKU is packed again', function (): void {
    $workspace = Workspace::factory()->create();
    ProductionRunNumberSetting::query()->create(['workspace_id' => $workspace->id, 'packed_lot_suffix' => PackedLotSuffix::Sku]);
    $batch = str_repeat('B', 60);
    $sku = str_repeat('S', 32);
    $size = ProductSize::factory()->make(['sku' => $sku]);
    $generator = app(PackedLotCodeGenerator::class);
    $first = $generator->suggest($workspace, $batch, $size);
    StockLot::factory()->for($workspace)->create(['internal_lot_code' => strtolower($first)]);

    expect($first)->toBe(substr($batch, 0, 31).'-'.$sku)
        ->and($generator->suggest($workspace, $batch, $size))->toBe(substr($batch, 0, 29).'-'.$sku.'-2');
});

it('plans bulk without choosing a size or reserving packaging', function (): void {
    $workspace = Workspace::factory()->create();
    WorkspaceProductionEntitlement::factory()->for($workspace)->create();
    $product = Recipe::factory()->for($workspace)->for(ProductFamily::factory()->state(['calculation_basis' => 'total_formula']))->create();
    $version = RecipeVersion::factory()->for($product)->for($workspace)->create(['is_current' => false, 'manufacturing_mode' => 'blend_only']);
    $sizes = ProductSize::factory()->count(2)->for($product)->create();
    $version->productSizes()->attach($sizes->modelKeys());
    $packaging = $version->packagingItems()->create([
        'product_size_id' => $sizes->last()->id,
        'name' => 'Travel-size carton',
        'components_per_unit' => '1',
        'position' => 1,
    ]);

    $plan = fn () => app(CreateProductionDraft::class)->handle(
        actor: $workspace->owner,
        workspace: $workspace,
        recipe: $product,
        basisInputValue: '1',
        basisInputUnit: 'kg',
        expectedUnits: 1,
        idempotencyKey: 'bulk',
        packageLater: true,
    );
    expect($plan)->toThrow(ValidationException::class, 'Travel-size carton');
    expect($workspace->productionRuns()->exists())->toBeFalse();

    $packaging->update(['packaging_item_id' => PackagingItem::factory()->for($workspace)->create()->id]);
    $run = $plan();

    expect($run->package_later)->toBeTrue()
        ->and($run->product_size_id)->toBeNull()
        ->and($run->requirements()->where('kind', ProductionRequirementKind::Packaging)->exists())->toBeFalse();
});
