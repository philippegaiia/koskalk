<?php

use App\Models\Ingredient;
use App\Models\Plan;
use App\Models\ProductFamily;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\RecipePhase;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Services\RecipeFormulaItemLimitService;
use App\Services\RecipeWorkbenchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);

it('counts normalized ingredient rows across phases and ignores packaging', function (): void {
    $service = app(RecipeFormulaItemLimitService::class);
    $payload = formulaLimitPayload(2);
    $payload['phases'][1]['items'][] = $payload['phases'][0]['items'][0];
    $payload['packaging_items'] = array_fill(0, 20, ['name' => 'Jar']);

    expect($service->count($payload))->toBe(3);
});

it('enforces free, paid, unlimited, and zero formula line limits', function (): void {
    $service = app(RecipeFormulaItemLimitService::class);
    $freeUser = User::factory()->create();
    $freePlan = Plan::factory()->hasLimit('formula_items_per_recipe', 30)->create(['is_default' => true]);
    $freeUser->entitlements()->create([
        'plan_id' => $freePlan->id,
        'status' => 'active',
        'starts_at' => now(),
    ]);

    expect($service->limitFor($freeUser))->toBe(30)
        ->and($service->count(formulaLimitPayload(30)))->toBe(30);

    expect(fn () => $service->assertCreateAllowed($freeUser, formulaLimitPayload(31)))
        ->toThrow(ValidationException::class, '31 ingredient lines');

    $paidUser = User::factory()->create();
    $paidPlan = Plan::factory()->billable()->hasLimit('formula_items_per_recipe', 50)->create();
    $paidUser->entitlements()->create([
        'plan_id' => $paidPlan->id,
        'status' => 'active',
        'starts_at' => now(),
    ]);

    expect($service->limitFor($paidUser))->toBe(50);
    $service->assertCreateAllowed($paidUser, formulaLimitPayload(50));

    $unlimitedUser = User::factory()->create();
    $unlimitedPlan = Plan::factory()->hasLimit('formula_items_per_recipe', null)->create();
    $unlimitedUser->entitlements()->create([
        'plan_id' => $unlimitedPlan->id,
        'status' => 'active',
        'starts_at' => now(),
    ]);

    expect($service->limitFor($unlimitedUser))->toBeNull();
    $service->assertCreateAllowed($unlimitedUser, formulaLimitPayload(100));

    $zeroUser = User::factory()->create();
    $zeroPlan = Plan::factory()->hasLimit('formula_items_per_recipe', 0)->create();
    $zeroUser->entitlements()->create([
        'plan_id' => $zeroPlan->id,
        'status' => 'active',
        'starts_at' => now(),
    ]);

    $service->assertCreateAllowed($zeroUser, formulaLimitPayload(0));

    expect(fn () => $service->assertCreateAllowed($zeroUser, formulaLimitPayload(1)))
        ->toThrow(ValidationException::class, 'allows 0');
});

it('grandfathers an existing over-limit formula only until it is reduced', function (): void {
    $user = User::factory()->create();
    $plan = Plan::factory()->hasLimit('formula_items_per_recipe', 30)->create(['is_default' => true]);
    $user->entitlements()->create([
        'plan_id' => $plan->id,
        'status' => 'active',
        'starts_at' => now(),
    ]);

    $recipe = Recipe::factory()->create(['owner_id' => $user->id]);
    $version = RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'owner_id' => $user->id,
        'is_current' => true,
    ]);
    $phase = RecipePhase::factory()->create([
        'recipe_version_id' => $version->id,
        'owner_id' => $user->id,
    ]);
    $ingredient = Ingredient::factory()->create();

    RecipeItem::factory()->count(35)->create([
        'recipe_version_id' => $version->id,
        'recipe_phase_id' => $phase->id,
        'ingredient_id' => $ingredient->id,
        'owner_id' => $user->id,
    ]);

    $service = app(RecipeFormulaItemLimitService::class);
    $service->assertUpdateAllowed($user, formulaLimitPayload(35), $recipe);
    $service->assertUpdateAllowed($user, formulaLimitPayload(34), $recipe);

    RecipeItem::withoutGlobalScopes()
        ->where('recipe_version_id', $version->id)
        ->orderByDesc('id')
        ->limit(3)
        ->delete();

    expect(fn () => $service->assertUpdateAllowed($user, formulaLimitPayload(35), $recipe))
        ->toThrow(ValidationException::class, '35 ingredient lines');
    $service->assertUpdateAllowed($user, formulaLimitPayload(32), $recipe);
});

it('rejects an over-limit new formula before creating recipe records', function (): void {
    $user = formulaLimitUser(30);
    $cosmeticFamily = formulaLimitCosmeticFamily();
    $ingredients = Ingredient::factory()->count(31)->create();
    $service = app(RecipeWorkbenchService::class);

    expect(fn () => $service->save(
        $user,
        $cosmeticFamily,
        formulaLimitCosmeticPayload($ingredients),
    ))->toThrow(ValidationException::class, '31 ingredient lines');

    expect(Recipe::withoutGlobalScopes()->where('owner_id', $user->id)->count())->toBe(0)
        ->and(RecipeVersion::withoutGlobalScopes()->where('owner_id', $user->id)->count())->toBe(0);
});

it('rejects an over-limit update before replacing existing formula rows', function (): void {
    $user = formulaLimitUser(30);
    $cosmeticFamily = formulaLimitCosmeticFamily();
    $ingredients = Ingredient::factory()->count(31)->create();
    $service = app(RecipeWorkbenchService::class);
    $baselinePayload = formulaLimitCosmeticPayload($ingredients->take(30)->values());
    $version = $service->save($user, $cosmeticFamily, $baselinePayload);
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($version->recipe_id);
    $versionIds = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->orderBy('id')
        ->pluck('id')
        ->all();
    $itemIds = RecipeItem::withoutGlobalScopes()
        ->where('recipe_version_id', $version->id)
        ->orderBy('id')
        ->pluck('id')
        ->all();

    expect(fn () => $service->save(
        $user,
        $cosmeticFamily,
        formulaLimitCosmeticPayload($ingredients),
        $recipe,
    ))->toThrow(ValidationException::class, '31 ingredient lines');

    expect(RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->orderBy('id')
        ->pluck('id')
        ->all())->toBe($versionIds)
        ->and(RecipeItem::withoutGlobalScopes()
            ->where('recipe_version_id', $version->id)
            ->orderBy('id')
            ->pluck('id')
            ->all())->toBe($itemIds);
});

it('rejects duplicate and restore operations when their source exceeds the current limit', function (): void {
    $user = formulaLimitUser(40);
    $cosmeticFamily = formulaLimitCosmeticFamily();
    $ingredients = Ingredient::factory()->count(31)->create();
    $service = app(RecipeWorkbenchService::class);
    $payload = formulaLimitCosmeticPayload($ingredients);
    $version = $service->save($user, $cosmeticFamily, $payload);
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($version->recipe_id);
    $service->publish($user, $cosmeticFamily, $payload, $recipe);
    $publishedVersion = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_current', false)
        ->firstOrFail();
    $newPlan = Plan::factory()->hasLimit('formula_items_per_recipe', 30)->create();
    $user->entitlements()->latest('id')->firstOrFail()->update(['plan_id' => $newPlan->id]);
    $recipeCount = Recipe::withoutGlobalScopes()->count();

    expect(fn () => $service->duplicateRecipe($user, $recipe))
        ->toThrow(ValidationException::class, '31 ingredient lines')
        ->and(fn () => $service->restorePublishedFormula($user, $recipe, $publishedVersion->id))
        ->toThrow(ValidationException::class, '31 ingredient lines')
        ->and(Recipe::withoutGlobalScopes()->count())->toBe($recipeCount);
});

it('keeps the client count and add controls aligned with the formula line limit', function (): void {
    $script = <<<'JS'
import assert from 'node:assert/strict';
import fs from 'node:fs';

globalThis.window = {
    location: { hash: '' },
    localStorage: { getItem: () => null, setItem: () => {} },
    matchMedia: () => ({ matches: false }),
};
globalThis.document = {
    getElementById: () => null,
};

const source = fs
    .readFileSync('resources/js/recipe-workbench/component.js', 'utf8')
    .replace(/^import[\s\S]*?;\n/gm, '')
    .replace(/export function /g, 'function ');

const createFormulaSection = () => ({
    get isCosmeticFormula() {
        return this.productFamilySlug === 'cosmetic';
    },
    cosmeticFormulaRows() {
        return Object.values(this.phaseItems ?? {}).flatMap((rows) => Array.isArray(rows) ? rows : []);
    },
    cosmeticDefaultPhaseKey() {
        return this.phaseOrder[0]?.key ?? 'phase_a';
    },
});
const createPackagingSection = () => ({});
const createCostingSection = () => ({});
const createVersionSection = () => ({});
const createPresentationSection = () => ({});

eval(source + '\nglobalThis.createRecipeWorkbench = createRecipeWorkbench;');

const state = globalThis.createRecipeWorkbench({
    productFamily: { slug: 'cosmetic' },
    phases: [{ key: 'phase_a', name: 'Phase A' }],
    formulaItemLimit: 2,
    translations: {
        formula_items: { limit_reached: 'Limit :limit' },
    },
}, { blocksNavigation: () => false });

state.phaseItems.phase_a = [
    { id: 'one', ingredient_id: 1 },
    { id: 'two', ingredient_id: 2 },
];

assert.equal(state.formulaItemCount(), 2);
assert.equal(state.formulaItemLimitReached(), true);
state.addIngredient({ id: 3, name: 'Third' }, 'phase_a');
assert.equal(state.phaseItems.phase_a.length, 2);

state.removeIngredient('phase_a', 'one');
assert.equal(state.formulaItemCount(), 1);
assert.equal(state.formulaItemLimitReached(), false);
state.addIngredient({ id: 3, name: 'Third' }, 'phase_a');
assert.equal(state.formulaItemCount(), 2);

state.formulaItemLimit = 0;
state.phaseItems.phase_a = [];
state.addIngredient({ id: 4, name: 'First' }, 'phase_a');
assert.equal(state.formulaItemCount(), 0);

state.formulaItemLimit = null;
state.addIngredient({ id: 4, name: 'First' }, 'phase_a');
assert.equal(state.formulaItemCount(), 1);
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
});

/**
 * @return array<string, mixed>
 */
function formulaLimitPayload(int $rowCount): array
{
    $items = collect($rowCount > 0 ? range(1, $rowCount) : [])
        ->map(fn (int $index): array => [
            'ingredient_id' => $index,
            'percentage' => 1,
            'weight' => 1,
        ])
        ->all();

    return [
        'phases' => [
            ['key' => 'phase_a', 'items' => $items],
            ['key' => 'phase_b', 'items' => []],
        ],
        'packaging_items' => [],
    ];
}

function formulaLimitUser(int $formulaItemLimit): User
{
    $user = User::factory()->create();
    $plan = Plan::factory()->hasLimit('formula_items_per_recipe', $formulaItemLimit)->create();

    $user->entitlements()->create([
        'plan_id' => $plan->id,
        'status' => 'active',
        'starts_at' => now(),
    ]);

    return $user;
}

function formulaLimitCosmeticFamily(): ProductFamily
{
    return ProductFamily::factory()->create([
        'name' => 'Cosmetic',
        'slug' => 'cosmetic',
        'calculation_basis' => 'total_formula',
    ]);
}

/**
 * @param  Collection<int, Ingredient>  $ingredients
 * @return array<string, mixed>
 */
function formulaLimitCosmeticPayload(Collection $ingredients): array
{
    $percentage = 100 / max(1, $ingredients->count());
    $weight = 500 / max(1, $ingredients->count());

    return [
        'name' => 'Formula limit test',
        'product_type_id' => null,
        'oil_unit' => 'g',
        'oil_weight' => 500,
        'manufacturing_mode' => 'blend_only',
        'exposure_mode' => 'leave_on',
        'regulatory_regime' => 'eu',
        'editing_mode' => 'percentage',
        'ifra_product_category_id' => null,
        'phases' => [['key' => 'phase_a', 'name' => 'Phase A']],
        'phase_items' => [
            'phase_a' => $ingredients->map(fn (Ingredient $ingredient): array => [
                'ingredient_id' => $ingredient->id,
                'percentage' => $percentage,
                'weight' => $weight,
                'note' => null,
            ])->all(),
        ],
        'production_output_type' => 'finished_product',
        'output_ingredient_id' => null,
        'ready_delay_days' => null,
    ];
}
