<?php

use App\Enums\FormulaShareStatus;
use App\Enums\OwnerType;
use App\Models\FormulaShare;
use App\Models\Ingredient;
use App\Models\IngredientShareMapping;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Services\UserIngredientAuthoringService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('preserves an accepted Product and its offer when source records are deleted', function (): void {
    $sourceWorkspace = Workspace::factory()->create();
    $source = Recipe::factory()->create(['workspace_id' => $sourceWorkspace->id]);
    $destination = Workspace::factory()->create();
    $accepted = Recipe::factory()->create(['workspace_id' => $destination->id]);
    $share = FormulaShare::factory()->create([
        'source_workspace_id' => $source->workspace_id,
        'recipient_workspace_id' => $destination->id,
        'source_recipe_id' => $source->id,
        'accepted_recipe_id' => $accepted->id,
        'status' => FormulaShareStatus::Accepted,
        'snapshot' => ['name' => 'Immutable offered formula'],
    ]);

    $source->delete();
    $share->sourceWorkspace->delete();

    expect($share->fresh()->source_recipe_id)->toBeNull()
        ->and($share->fresh()->source_workspace_id)->toBeNull()
        ->and($share->fresh()->snapshot)->toBe(['name' => 'Immutable offered formula']);
    $this->assertModelExists($accepted);
});

it('rejects duplicate send request identities', function (): void {
    $share = FormulaShare::factory()->create();

    expect(fn () => FormulaShare::factory()->create([
        'source_workspace_id' => $share->source_workspace_id,
        'request_key' => $share->request_key,
    ]))->toThrow(QueryException::class);
});

it('invalidates mappings when their local ingredient is deleted', function (): void {
    $mapping = IngredientShareMapping::factory()->create();
    $mapping->ingredient->delete();

    expect($mapping->fresh()->ingredient_id)->toBeNull();
});

it('rejects duplicate workspace mapping identities', function (): void {
    $mapping = IngredientShareMapping::factory()->create();

    expect(fn () => IngredientShareMapping::factory()->create([
        'workspace_id' => $mapping->workspace_id,
        'lineage_key' => $mapping->lineage_key,
        'fingerprint_version' => $mapping->fingerprint_version,
        'incoming_fingerprint' => $mapping->incoming_fingerprint,
    ]))->toThrow(QueryException::class);
});

it('hides snapshot receipts lineage and internal relationship identifiers from serialization', function (): void {
    $share = FormulaShare::factory()->create(['snapshot' => ['secret' => 'SECRET'], 'import_receipt' => ['local' => 123]]);
    $share->load(['sourceWorkspace', 'recipientWorkspace', 'acceptedRecipe']);
    $mapping = IngredientShareMapping::factory()->create();
    $ingredient = Ingredient::factory()->create(['owner_type' => OwnerType::Workspace]);
    $ingredient->share_lineage_key = (string) Str::uuid();
    $ingredient->save();

    expect($share->toArray())->toHaveKeys(['public_id', 'status', 'sent_at', 'expires_at'])
        ->not->toHaveKeys(['id', 'snapshot', 'options', 'import_receipt', 'snapshot_hash', 'source_workspace_id', 'recipient_workspace_id', 'accepted_recipe', 'source_workspace'])
        ->and($mapping->toArray())->toBe([])
        ->and($ingredient->toArray())->not->toHaveKey('share_lineage_key')
        ->and($ingredient->getFillable())->not->toContain('share_lineage_key');
});

it('reverses and reapplies only the sharing schema on the SQLite test database', function (): void {
    $paths = collect(glob(database_path('migrations/*_*.php')))
        ->filter(fn (string $path): bool => str_contains($path, 'formula_shares_table') || str_contains($path, 'ingredient_share_mappings_table') || str_contains($path, 'share_lineage_key_to_ingredients_table'))
        ->sort()->values();
    expect($paths)->toHaveCount(3);
    foreach ($paths->reverse() as $path) {
        (require $path)->down();
    }
    expect(Schema::hasTable('formula_shares'))->toBeFalse()
        ->and(Schema::hasTable('ingredient_share_mappings'))->toBeFalse()
        ->and(Schema::hasColumn('ingredients', 'share_lineage_key'))->toBeFalse();
    foreach ($paths as $path) {
        (require $path)->up();
    }
    $share = FormulaShare::factory()->create();
    $this->assertModelExists($share);
});

it('retains grant metadata when source versions and actors are deleted', function (): void {
    $version = RecipeVersion::factory()->create();
    $actor = User::factory()->create();
    $share = FormulaShare::factory()->create(['source_version_id' => $version->id, 'sent_by_user_id' => $actor->id]);

    $version->delete();
    $actor->delete();

    expect($share->fresh()->source_version_id)->toBeNull()
        ->and($share->fresh()->sent_by_user_id)->toBeNull()
        ->and($share->fresh()->snapshot)->toBe(['schema_version' => 1]);
});

it('cascades grants and mappings from the recipient while keeping source materials', function (): void {
    $share = FormulaShare::factory()->create();
    $mapping = IngredientShareMapping::factory()->create(['workspace_id' => $share->recipient_workspace_id]);
    $ingredient = $mapping->ingredient;

    $share->recipientWorkspace->delete();

    $this->assertModelMissing($share);
    $this->assertModelMissing($mapping);
    $this->assertModelExists($ingredient);
});

it('keeps private copied lineage server owned and clears a platform lineage fallback', function (): void {
    $private = Ingredient::factory()->create(['owner_type' => OwnerType::Workspace]);
    $platform = Ingredient::factory()->create(['owner_type' => null]);

    expect($private->sharingLineageKey())->toBe($private->public_id)
        ->and($platform->sharingLineageKey())->toBeNull();
});

it('retains imported private lineage through normal duplication and excludes platform lineage', function (): void {
    $owner = User::factory()->create();
    $platform = Ingredient::factory()->create(['owner_type' => null, 'owner_id' => null, 'workspace_id' => null, 'is_active' => true]);
    $platform->share_lineage_key = (string) Str::uuid();
    $platform->save();
    $service = app(UserIngredientAuthoringService::class);

    $copy = $service->duplicate($platform, $owner);
    expect($copy->share_lineage_key)->toBeNull();
    $copy->share_lineage_key = (string) Str::uuid();
    $copy->save();
    $second = $service->duplicate($copy, $owner);
    expect($second->share_lineage_key)->toBe($copy->share_lineage_key);
});
