<?php

use App\Enums\NominalContentUnit;
use App\Models\ProductionRun;
use App\Models\ProductSize;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\RecipeVersionCosting;
use App\Models\User;
use App\Services\ProductSizeResolver;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function productSizeLinkMigration(): object
{
    return require database_path('migrations/2026_11_14_084559_add_product_size_id_to_recipe_version_packaging_items_table.php');
}

it('carries the legacy yield to the first size packaged by each version', function (): void {
    $user = User::factory()->create();
    $recipe = Recipe::factory()->for($user, 'createdBy')->create();
    $version = RecipeVersion::factory()->for($recipe)->create();
    $first = ProductSize::factory()->for($recipe)->create(['position' => 1]);
    $packaged = ProductSize::factory()->for($recipe)->create(['position' => 2, 'archived_at' => now()]);
    $version->packagingItems()->create([
        'product_size_id' => $packaged->id,
        'name' => 'Box',
        'components_per_unit' => 1,
        'position' => 1,
    ]);
    $costing = RecipeVersionCosting::query()->create([
        'recipe_version_id' => $version->id,
        'user_id' => $user->id,
        'units_produced' => 12,
        'currency' => 'EUR',
    ]);

    $migration = require database_path('migrations/2026_11_15_084814_create_recipe_version_costing_size_yields_table.php');
    $migration->down();
    $migration->up();

    $size = $costing->sizeYields()->sole();
    expect($size->is($packaged))->toBeTrue()
        ->and((int) $size->pivot->units_produced)->toBe(12)
        ->and($first->fresh())->not->toBeNull()
        ->and($costing->fresh()->units_produced)->toBe(12);
});

it('gives a product with existing packaging one size holding every packaging line', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $recipe = Recipe::factory()->create([
        'owner_id' => $user->id,
        'nominal_content_value' => '100',
        'nominal_content_unit' => NominalContentUnit::Gram,
    ]);
    $draft = RecipeVersion::factory()->create(['recipe_id' => $recipe->id, 'owner_id' => $user->id]);
    $saved = RecipeVersion::factory()->create(['recipe_id' => $recipe->id, 'owner_id' => $user->id, 'version_number' => 2, 'is_current' => false]);
    $draftLine = $draft->packagingItems()->create(['name' => 'Soap box', 'components_per_unit' => 1, 'position' => 1]);
    $savedLine = $saved->packagingItems()->create(['name' => 'Soap box', 'components_per_unit' => 1, 'position' => 1]);
    $productWithoutPackaging = Recipe::factory()->create(['owner_id' => $user->id]);

    productSizeLinkMigration()->up();

    $size = ProductSize::query()->where('recipe_id', $recipe->id)->sole();

    expect($draftLine->fresh()->product_size_id)->toBe($size->id)
        ->and($savedLine->fresh()->product_size_id)->toBe($size->id)
        ->and($size->declared_content_value)->toBe('100.0000')
        ->and($size->declared_content_unit)->toBe(NominalContentUnit::Gram)
        ->and(ProductSize::query()->where('recipe_id', $productWithoutPackaging->id)->exists())->toBeFalse();
});

it('reuses a product\'s existing size when the packaging link migration runs again', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $recipe = Recipe::factory()->create(['owner_id' => $user->id]);
    $version = RecipeVersion::factory()->create(['recipe_id' => $recipe->id, 'owner_id' => $user->id]);
    $size = ProductSize::factory()->create(['recipe_id' => $recipe->id, 'name' => '50 g bar']);
    $line = $version->packagingItems()->create(['name' => 'Soap box', 'components_per_unit' => 1, 'position' => 1]);

    productSizeLinkMigration()->up();

    expect($line->fresh()->product_size_id)->toBe($size->id)
        ->and(ProductSize::query()->where('recipe_id', $recipe->id)->count())->toBe(1);
});

it('creates a product\'s first size from its nominal content', function () {
    $user = User::factory()->create();
    $recipe = Recipe::factory()->create([
        'owner_id' => $user->id,
        'nominal_content_value' => '30',
        'nominal_content_unit' => NominalContentUnit::Millilitre,
    ]);

    $size = app(ProductSizeResolver::class)->defaultForProduct($recipe->id);

    expect($size->declared_content_value)->toBe('30.0000')
        ->and($size->declared_content_unit)->toBe(NominalContentUnit::Millilitre)
        ->and(app(ProductSizeResolver::class)->defaultForProduct($recipe->id)->is($size))->toBeTrue();
});

it('authorizes sizes for the given user without relying on the logged-in session', function () {
    $owner = User::factory()->create();
    $outsider = User::factory()->create();
    $recipe = Recipe::factory()->create(['owner_id' => $owner->id]);
    $size = ProductSize::factory()->create(['recipe_id' => $recipe->id]);

    expect(Gate::forUser($owner)->allows('view', $size))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('update', $size))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('delete', $size))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('create', [ProductSize::class, $recipe]))->toBeTrue()
        ->and(Gate::forUser($outsider)->allows('view', $size))->toBeFalse()
        ->and(Gate::forUser($outsider)->allows('update', $size))->toBeFalse()
        ->and(Gate::forUser($outsider)->allows('delete', $size))->toBeFalse();
});

it('restores the size index when the link migration is retried after a partial run', function () {
    DB::statement('DROP INDEX "recipe_version_packaging_items_product_size_id_index"');

    productSizeLinkMigration()->up();

    expect(Schema::hasIndex('recipe_version_packaging_items', 'recipe_version_packaging_items_product_size_id_index'))->toBeTrue();
})->skip(fn (): bool => DB::getDriverName() !== 'sqlite', 'Only the SQLite path adds the column and index as separate steps.');

it('refuses to delete a size that a formula\'s packaging still uses', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $recipe = Recipe::factory()->create(['owner_id' => $user->id]);
    $version = RecipeVersion::factory()->create(['recipe_id' => $recipe->id, 'owner_id' => $user->id]);
    $size = ProductSize::factory()->create(['recipe_id' => $recipe->id]);
    $line = $version->packagingItems()->create([
        'product_size_id' => $size->id,
        'name' => 'Soap box',
        'components_per_unit' => 1,
        'position' => 1,
    ]);

    expect(fn () => DB::transaction(fn () => $size->delete()))->toThrow(QueryException::class)
        ->and($line->fresh()->product_size_id)->toBe($size->id);
});

it('removes a product\'s sizes and their packaging lines with the product', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $recipe = Recipe::factory()->create(['owner_id' => $user->id]);
    $version = RecipeVersion::factory()->create(['recipe_id' => $recipe->id, 'owner_id' => $user->id]);
    $size = ProductSize::factory()->create(['recipe_id' => $recipe->id]);
    $line = $version->packagingItems()->create([
        'product_size_id' => $size->id,
        'name' => 'Soap box',
        'components_per_unit' => 1,
        'position' => 1,
    ]);

    $recipe->delete();

    expect($size->fresh())->toBeNull()
        ->and($line->fresh())->toBeNull();
});

it('refuses to roll back the size link on SQLite inside a transaction with foreign keys enabled', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $recipe = Recipe::factory()->create(['owner_id' => $user->id]);
    $version = RecipeVersion::factory()->create(['recipe_id' => $recipe->id, 'owner_id' => $user->id]);
    $line = $version->packagingItems()->create(['name' => 'Soap box', 'components_per_unit' => 1, 'position' => 1]);
    DB::table('production_requirements')->insert([
        'production_run_id' => ProductionRun::factory()->create()->id,
        'packaging_item_id' => createPackagingItemForWorkspace(['user_id' => $user->id, 'name' => 'Soap box'])->id,
        'recipe_version_packaging_item_id' => $line->id,
        'kind' => 'packaging',
        'required_units' => 1,
        'components_per_unit_snapshot' => 1,
        'subject_name_snapshot' => 'Soap box',
        'unit_snapshot' => 'unit',
        'sort_order' => 1,
    ]);

    expect(fn () => productSizeLinkMigration()->down())->toThrow(RuntimeException::class)
        ->and(DB::table('production_requirements')->value('recipe_version_packaging_item_id'))->toBe($line->id);
})->skip(fn (): bool => DB::getDriverName() !== 'sqlite', 'The rebuild guard only applies to SQLite.');

it('keeps sizes and production requirement links through a PostgreSQL rollback and re-run', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $recipe = Recipe::factory()->create(['owner_id' => $user->id]);
    $version = RecipeVersion::factory()->create(['recipe_id' => $recipe->id, 'owner_id' => $user->id]);
    $size = ProductSize::factory()->create(['recipe_id' => $recipe->id]);
    $line = $version->packagingItems()->create(['product_size_id' => $size->id, 'name' => 'Soap box', 'components_per_unit' => 1, 'position' => 1]);
    DB::table('production_requirements')->insert([
        'production_run_id' => ProductionRun::factory()->create()->id,
        'packaging_item_id' => createPackagingItemForWorkspace(['user_id' => $user->id, 'name' => 'Soap box'])->id,
        'recipe_version_packaging_item_id' => $line->id,
        'kind' => 'packaging',
        'required_units' => 1,
        'components_per_unit_snapshot' => 1,
        'subject_name_snapshot' => 'Soap box',
        'unit_snapshot' => 'unit',
        'sort_order' => 1,
    ]);

    productSizeLinkMigration()->down();

    expect($size->fresh())->not->toBeNull();

    productSizeLinkMigration()->up();

    expect($line->fresh()->product_size_id)->toBe($size->id)
        ->and(ProductSize::query()->where('recipe_id', $recipe->id)->count())->toBe(1)
        ->and(DB::table('production_requirements')->value('recipe_version_packaging_item_id'))->toBe($line->id);
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'Schema round trip runs on disposable PostgreSQL; SQLite rebuilds cannot safely run inside RefreshDatabase transactions.');
