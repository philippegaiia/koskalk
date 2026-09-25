<?php

use App\Enums\MaterialPriceSource;
use App\Models\CurrentMaterialPrice;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\RecipeVersionCosting;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
});

function canonicalCostingMigration(): Migration
{
    return require database_path('migrations/2026_09_25_140409_canonicalize_recipe_version_costings.php');
}

/** @return array{User, RecipeVersion, RecipeVersionCosting} */
function legacyCanonicalCosting(bool $personal = false): array
{
    $author = User::factory()->create();
    $workspace = $personal ? null : Workspace::factory()->for($author, 'owner')->create();
    $recipe = Recipe::factory()->create(['owner_id' => $author->id, 'workspace_id' => $workspace?->id]);
    $version = RecipeVersion::factory()->create(['recipe_id' => $recipe->id, 'owner_id' => $author->id, 'workspace_id' => $workspace?->id]);
    $costing = RecipeVersionCosting::create(['recipe_version_id' => $version->id, 'user_id' => $author->id, 'currency' => 'EUR']);

    return [$author, $version, $costing];
}

it('round trips legacy costing without changing identifiers prices children or manual price provenance', function (): void {
    $migration = canonicalCostingMigration();
    $migration->down();
    [$author, $version, $costing] = legacyCanonicalCosting();
    $ingredient = Ingredient::factory()->create();
    $item = $costing->items()->create(['ingredient_id' => $ingredient->id, 'phase_key' => 'oils', 'position' => 1, 'price_per_kg' => '12.3456']);
    $packaging = $costing->packagingItems()->create(['name' => 'Box', 'unit_cost' => '0.42', 'quantity' => 2]);
    $price = CurrentMaterialPrice::factory()->create([
        'workspace_id' => $version->workspace_id, 'ingredient_id' => $ingredient->id,
        'price_per_canonical_unit' => '0.012345600000', 'currency' => 'EUR',
        'source_type' => MaterialPriceSource::ManualCosting, 'source_id' => $costing->id,
        'created_by_user_id' => $author->id,
    ]);
    $original = DB::table('recipe_version_costings')->where('id', $costing->id)->first();

    $migration->up();
    $migration->down();
    $migration->up();

    expect($costing->fresh()->updated_by_user_id)->toBeNull();
    $this->assertDatabaseHas('recipe_version_costings', (array) $original);
    $this->assertDatabaseHas('recipe_version_costing_items', ['id' => $item->id, 'recipe_version_costing_id' => $costing->id, 'price_per_kg' => '12.3456']);
    $this->assertDatabaseHas('recipe_version_costing_packaging_items', ['id' => $packaging->id, 'unit_cost' => '0.42', 'quantity' => 2]);
    expect($price->fresh()->source_id)->toBe($costing->id)
        ->and($price->fresh()->source_type)->toBe(MaterialPriceSource::ManualCosting);

    expect(fn () => RecipeVersionCosting::create(['recipe_version_id' => $version->id, 'user_id' => User::factory()->create()->id]))
        ->toThrow(QueryException::class);
});

it('refuses duplicate versions before changing any legacy data or columns', function (): void {
    $migration = canonicalCostingMigration();
    $migration->down();
    [, $version, $costing] = legacyCanonicalCosting();
    $duplicate = RecipeVersionCosting::create(['recipe_version_id' => $version->id, 'user_id' => User::factory()->create()->id]);
    $before = DB::table('recipe_version_costings')->orderBy('id')->get();

    expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'duplicate versions');
    expect(DB::table('recipe_version_costings')->orderBy('id')->get())->toEqual($before)
        ->and(Schema::hasColumn('recipe_version_costings', 'updated_by_user_id'))->toBeFalse();
    try {
        $migration->up();
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toContain('"id":'.$costing->id, '"id":'.$duplicate->id, '"recipe_version_id":'.$version->id);
    }
});

it('refuses inconsistent recipe and version ownership before changing the schema', function (string $mismatch): void {
    $migration = canonicalCostingMigration();
    $migration->down();
    [, $version, $costing] = legacyCanonicalCosting($mismatch === 'personal owner');
    $changes = match ($mismatch) {
        'workspace' => ['workspace_id' => Workspace::factory()->create()->id],
        'one null' => ['workspace_id' => null],
        default => ['owner_id' => User::factory()->create()->id],
    };
    $version->update($changes);
    $before = DB::table('recipe_version_costings')->where('id', $costing->id)->first();

    expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'consistent recipe/version ownership');
    expect(DB::table('recipe_version_costings')->where('id', $costing->id)->first())->toEqual($before)
        ->and(Schema::hasColumn('recipe_version_costings', 'updated_by_user_id'))->toBeFalse();
})->with(['workspace', 'one null', 'personal owner']);

it('preserves matching personal ownership with both workspace ids null', function (): void {
    $migration = canonicalCostingMigration();
    $migration->down();
    [, , $costing] = legacyCanonicalCosting(personal: true);

    $migration->up();

    expect($costing->fresh()->updated_by_user_id)->toBeNull();
    $this->assertModelExists($costing);
});

it('keeps costing after author deletion and refuses an unsafe rollback without changing data', function (): void {
    [$author, , $costing] = legacyCanonicalCosting();
    $editor = User::factory()->create();
    $costing->update(['updated_by_user_id' => $editor->id]);
    $costing->recipeVersion->recipe()->withoutGlobalScopes()->firstOrFail()->workspace()->withoutGlobalScopes()->update(['owner_user_id' => $editor->id]);
    $author->delete();
    $before = DB::table('recipe_version_costings')->where('id', $costing->id)->first();

    expect(fn () => canonicalCostingMigration()->down())->toThrow(RuntimeException::class, 'costing IDs: ['.$costing->id.']');
    expect(DB::table('recipe_version_costings')->where('id', $costing->id)->first())->toEqual($before)
        ->and($costing->fresh()->user)->toBeNull()
        ->and($costing->fresh()->updatedBy->id)->toBe($editor->id)
        ->and(Schema::hasColumn('recipe_version_costings', 'updated_by_user_id'))->toBeTrue();
});

it('clears deleted last editor attribution without deleting the costing or original author', function (): void {
    [$author, , $costing] = legacyCanonicalCosting();
    $editor = User::factory()->create();
    $costing->update(['updated_by_user_id' => $editor->id]);

    $editor->delete();

    expect($costing->fresh()->updatedBy)->toBeNull()
        ->and($costing->fresh()->user_id)->toBe($author->id);
    $this->assertModelExists($costing);
});

it('preserves SQLite costing triggers and partial indexes in both migration directions', function (): void {
    if (DB::getDriverName() !== 'sqlite') {
        $this->markTestSkipped('SQLite table rebuild preservation only.');
    }

    $migration = canonicalCostingMigration();
    $migration->down();
    [, , $costing] = legacyCanonicalCosting();
    DB::statement('CREATE INDEX costing_positive_units ON recipe_version_costings (units_produced) WHERE units_produced > 0');
    DB::unprepared("CREATE TRIGGER costing_nonnegative_units BEFORE UPDATE ON recipe_version_costings WHEN NEW.units_produced < 0 BEGIN SELECT RAISE(ABORT, 'negative units'); END");

    $migration->up();
    $migration->down();

    expect(DB::table('sqlite_master')->where('name', 'costing_positive_units')->value('sql'))->toContain('WHERE units_produced > 0');
    expect(fn () => $costing->update(['units_produced' => -1]))->toThrow(QueryException::class, 'negative units');
    expect($costing->fresh()->units_produced)->toBeNull();
});

it('refuses transactional SQLite rebuilds before any schema or data changes', function (): void {
    if (DB::getDriverName() !== 'sqlite') {
        $this->markTestSkipped('SQLite foreign-key pragma transaction restriction only.');
    }

    [, , $costing] = legacyCanonicalCosting();
    $before = DB::table('recipe_version_costings')->where('id', $costing->id)->first();

    expect(fn () => DB::transaction(fn () => canonicalCostingMigration()->down()))
        ->toThrow(RuntimeException::class, 'outside an active transaction');
    expect(DB::table('recipe_version_costings')->where('id', $costing->id)->first())->toEqual($before)
        ->and(Schema::hasColumn('recipe_version_costings', 'updated_by_user_id'))->toBeTrue();
});
