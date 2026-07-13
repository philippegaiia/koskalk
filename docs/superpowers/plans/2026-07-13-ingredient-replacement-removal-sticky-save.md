# Ingredient Replacement, Removal, and Sticky Save Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let users replace or remove a private ingredient across every editable formula record, permanently delete it to free a plan slot, clarify platform actions, and keep the ingredient editor save action visible.

**Architecture:** Add one focused `IngredientFormulaMutationService` that owns impact resolution, candidate compatibility, authorization, and transactional formula mutation. Keep Livewire responsible only for modal state and user feedback, while the existing usage read model continues to supply the distinct formula disclosure. Render the sticky save action in the existing ingredient editor form without changing Filament schema ownership.

**Tech Stack:** PHP 8.5, Laravel 13, Livewire 4, Filament 5 schemas, Pest 4, Tailwind CSS 4, Vite.

---

## File Map

- Create `app/Services/IngredientFormulaMutationService.php`: replacement candidates, impact summaries, authorization checks, replace/remove transactions, stale generated-list invalidation.
- Create `tests/Feature/IngredientFormulaMutationServiceTest.php`: domain behavior, compatibility, pricing, rollback, current/backup/archived coverage.
- Modify `app/Livewire/Dashboard/IngredientsIndex.php`: removal modal state, replacement selection, service calls, errors, and successful catalog refresh.
- Modify `resources/views/livewire/dashboard/ingredients-index.blade.php`: accessible platform dash, enabled manage action, replacement/removal dialog.
- Modify `tests/Feature/PublicIngredientPagesTest.php`: Livewire workflow and catalog copy/accessibility tests.
- Modify `resources/views/livewire/dashboard/ingredient-editor.blade.php`: responsive sticky action bar.
- Modify `tests/Feature/UserIngredientAuthoringTest.php`: sticky save markup contract.

No migration is required. Existing foreign keys already cascade ingredient-owned catalog data, but formula and costing rows are updated or removed explicitly so authorization, price handling, and generated-list invalidation remain deterministic.

### Task 1: Build the mutation read model and compatibility rules

**Files:**
- Create: `app/Services/IngredientFormulaMutationService.php`
- Create: `tests/Feature/IngredientFormulaMutationServiceTest.php`

- [ ] **Step 1: Write failing impact and candidate tests**

Create a Pest feature test using `RefreshDatabase`. Build one user-owned ingredient referenced by a current version, a saved backup, and an archived recipe. Assert that `impact()` returns distinct recipes rather than version counts and that candidates follow these rules:

```php
use App\IngredientCategory;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\RecipePhase;
use App\Models\RecipeVersion;
use App\Models\User;
use App\OwnerType;
use App\Services\IngredientFormulaMutationService;
use App\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('summarizes distinct affected formulas and returns compatible replacements', function (): void {
    $user = User::factory()->create();
    $source = privateMutationIngredient($user, IngredientCategory::EssentialOil, 'Lavender');
    $essentialOil = privateMutationIngredient($user, IngredientCategory::EssentialOil, 'Lavandin Super');
    $fragranceOil = Ingredient::factory()->create([
        'category' => IngredientCategory::FragranceOil,
        'display_name' => 'Lavender Fragrance',
        'is_active' => true,
    ]);
    $co2Extract = Ingredient::factory()->create([
        'category' => IngredientCategory::Co2Extract,
        'display_name' => 'Lavender CO2',
        'is_active' => true,
    ]);
    $clay = Ingredient::factory()->create([
        'category' => IngredientCategory::Clay,
        'display_name' => 'White Clay',
        'is_active' => true,
    ]);

    $recipe = privateMutationRecipeWithItem($user, $source, isCurrent: true);
    privateMutationRecipeWithItem($user, $source, recipe: $recipe, isCurrent: false);

    $service = app(IngredientFormulaMutationService::class);
    $candidateIds = $service->replacementCandidates($user, $source)->pluck('id')->all();

    expect($service->impact($user, $source)['formula_count'])->toBe(1)
        ->and($candidateIds)->toContain($essentialOil->id, $fragranceOil->id, $co2Extract->id)
        ->and($candidateIds)->not->toContain($source->id, $clay->id);
});
```

Add helpers at the end of the test file that create tenant-owned recipes, phases, versions, and recipe items with explicit `owner_type`, `owner_id`, and `visibility` values.

- [ ] **Step 2: Run the tests and confirm RED**

Run:

```bash
php artisan test --compact tests/Feature/IngredientFormulaMutationServiceTest.php
```

Expected: failure because `IngredientFormulaMutationService` does not exist.

- [ ] **Step 3: Implement the service boundary and candidate query**

Create the service with explicit result shapes:

```php
<?php

namespace App\Services;

use App\IngredientCategory;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class IngredientFormulaMutationService
{
    /**
     * @return array{formula_count: int, recipes: Collection<int, Recipe>, blocked_recipes: Collection<int, Recipe>, requires_soap_carrier: bool}
     */
    public function impact(User $user, Ingredient $ingredient): array
    {
        $recipes = $this->affectedRecipes($ingredient);

        return [
            'formula_count' => $recipes->count(),
            'recipes' => $recipes,
            'blocked_recipes' => $recipes->reject(fn (Recipe $recipe): bool => $user->can('update', $recipe))->values(),
            'requires_soap_carrier' => RecipeItem::withoutGlobalScopes()
                ->where('ingredient_id', $ingredient->id)
                ->whereHas('recipePhase', fn ($query) => $query->withoutGlobalScopes()->where('slug', 'saponified_oils'))
                ->exists(),
        ];
    }

    /** @return EloquentCollection<int, Ingredient> */
    public function replacementCandidates(User $user, Ingredient $ingredient): EloquentCollection
    {
        $requiresSoapCarrier = $this->impact($user, $ingredient)['requires_soap_carrier'];

        return Ingredient::query()
            ->with('sapProfile')
            ->whereKeyNot($ingredient->id)
            ->where('is_active', true)
            ->accessibleTo($user)
            ->orderBy('display_name')
            ->get()
            ->filter(fn (Ingredient $candidate): bool => $this->isCompatible(
                $ingredient,
                $candidate,
                $requiresSoapCarrier,
            ))
            ->values();
    }

    private function isCompatible(Ingredient $source, Ingredient $candidate, bool $requiresSoapCarrier): bool
    {
        if ($source->requiresAromaticCompliance()) {
            return $candidate->requiresAromaticCompliance();
        }

        if ($source->category === IngredientCategory::CarrierOil) {
            return $candidate->category === IngredientCategory::CarrierOil
                && (! $requiresSoapCarrier || $candidate->canDriveSoapSaponification());
        }

        return $source->category === $candidate->category;
    }
}
```

Implement `affectedRecipes()` with `Recipe::withoutGlobalScopes()` and one `whereHas` branch for direct `recipeVersion.items` plus one branch for `recipeVersion.costings.items`. Deduplicate at the recipe query rather than in PHP.

- [ ] **Step 4: Add carrier-oil and inaccessible-candidate cases**

Add tests proving:

```php
expect($candidateIds)->not->toContain($carrierWithoutSap->id)
    ->and($candidateIds)->toContain($carrierWithSap->id)
    ->and($candidateIds)->not->toContain($otherUsersPrivateIngredient->id)
    ->and($candidateIds)->not->toContain($inactiveIngredient->id);
```

The test must put the source carrier in a phase whose slug is `saponified_oils`; an additive-only carrier does not require the stricter SAP check.

- [ ] **Step 5: Run focused tests and commit**

Run:

```bash
php artisan test --compact tests/Feature/IngredientFormulaMutationServiceTest.php
vendor/bin/pint --dirty --format agent
```

Expected: all mutation-service tests pass and Pint reports success.

Commit:

```bash
git add app/Services/IngredientFormulaMutationService.php tests/Feature/IngredientFormulaMutationServiceTest.php
git commit -m "feat: resolve ingredient replacement impact"
```

### Task 2: Replace an ingredient everywhere transactionally

**Files:**
- Modify: `app/Services/IngredientFormulaMutationService.php`
- Modify: `tests/Feature/IngredientFormulaMutationServiceTest.php`

- [ ] **Step 1: Write the failing replacement transaction test**

Create current, backup, and archived formula versions with the source ingredient. Give rows distinct percentages, weights, phases, positions, and notes. Add costings and remembered replacement pricing. Assert:

```php
$service->replaceEverywhereAndDelete($user, $source, $replacement);

expect(Ingredient::query()->whereKey($source->id)->exists())->toBeFalse()
    ->and(RecipeItem::withoutGlobalScopes()->where('ingredient_id', $source->id)->exists())->toBeFalse()
    ->and(RecipeItem::withoutGlobalScopes()->where('ingredient_id', $replacement->id)->count())->toBe(3)
    ->and(RecipeItem::withoutGlobalScopes()->where('ingredient_id', $replacement->id)->pluck('percentage')->all())
    ->toEqualCanonicalizing(['10.0000', '12.5000', '15.0000'])
    ->and(RecipeVersionCostingItem::query()->where('ingredient_id', $replacement->id)->pluck('price_per_kg')->unique()->all())
    ->toBe(['19.5000']);
```

Also assert each affected version has these fields cleared:

```php
expect($version->fresh())
    ->final_ingredient_list->toBeNull()
    ->final_ingredient_list_basis_hash->toBeNull()
    ->final_plain_ingredient_list->toBeNull()
    ->final_plain_ingredient_list_basis_hash->toBeNull();
```

- [ ] **Step 2: Run the replacement test and confirm RED**

Run:

```bash
php artisan test --compact tests/Feature/IngredientFormulaMutationServiceTest.php --filter='replaces an ingredient everywhere'
```

Expected: failure because `replaceEverywhereAndDelete()` is undefined.

- [ ] **Step 3: Implement replacement with locks and one transaction**

Add:

```php
public function replaceEverywhereAndDelete(User $user, Ingredient $ingredient, Ingredient $replacement): void
{
    DB::transaction(function () use ($user, $ingredient, $replacement): void {
        $lockedIngredient = $this->ownedIngredientForMutation($user, $ingredient->id);
        $lockedReplacement = Ingredient::query()->with('sapProfile')->lockForUpdate()->findOrFail($replacement->id);
        $impact = $this->validatedImpact($user, $lockedIngredient);

        throw_unless(
            $lockedReplacement->is_active
                && $lockedReplacement->isAccessibleBy($user)
                && $this->isCompatible($lockedIngredient, $lockedReplacement, $impact['requires_soap_carrier']),
            ValidationException::withMessages(['replacementIngredientId' => 'Choose a compatible replacement ingredient.']),
        );

        $replacementPrice = UserIngredientPrice::query()
            ->where('user_id', $user->id)
            ->where('ingredient_id', $lockedReplacement->id)
            ->value('price_per_kg');

        $versionIds = $this->affectedVersionIds($lockedIngredient);

        RecipeItem::withoutGlobalScopes()
            ->whereIn('recipe_version_id', $versionIds)
            ->where('ingredient_id', $lockedIngredient->id)
            ->update(['ingredient_id' => $lockedReplacement->id, 'updated_at' => now()]);

        RecipeVersionCostingItem::query()
            ->where('ingredient_id', $lockedIngredient->id)
            ->whereHas('costing', fn ($query) => $query->whereIn('recipe_version_id', $versionIds))
            ->update([
                'ingredient_id' => $lockedReplacement->id,
                'price_per_kg' => $replacementPrice,
                'updated_at' => now(),
            ]);

        $this->invalidateGeneratedIngredientLists($versionIds);
        $this->deleteIngredientMedia($lockedIngredient);
        $lockedIngredient->delete();
    });
}
```

Implement these private helpers with explicit return types:

```php
private function ownedIngredientForMutation(User $user, int $ingredientId): Ingredient;
private function validatedImpact(User $user, Ingredient $ingredient): array;
private function affectedVersionIds(Ingredient $ingredient): Collection;
private function invalidateGeneratedIngredientLists(Collection $versionIds): void;
private function deleteIngredientMedia(Ingredient $ingredient): void;
```

`validatedImpact()` throws a `ValidationException` containing the blocked recipe names when `blocked_recipes` is not empty. `ownedIngredientForMutation()` must require `owner_type=user` and `owner_id=$user->id` under `lockForUpdate()`.

- [ ] **Step 4: Add rollback and duplicate-row coverage**

Add tests that:

- a workspace Viewer cannot replace an ingredient used by a workspace formula;
- a workspace Editor can replace it;
- an incompatible candidate aborts without changing any rows;
- the replacement already appearing in the same formula remains a separate row;
- a costing with no remembered replacement price becomes `null`;
- deletion also removes the old ingredient's media files through `MediaStorage`.

Use `expect(fn () => $service->replaceEverywhereAndDelete($user, $source, $incompatibleReplacement))->toThrow(ValidationException::class)` and then assert the source ingredient and every original row still exist.

- [ ] **Step 5: Run focused tests and commit**

Run:

```bash
php artisan test --compact tests/Feature/IngredientFormulaMutationServiceTest.php
vendor/bin/pint --dirty --format agent
```

Commit:

```bash
git add app/Services/IngredientFormulaMutationService.php tests/Feature/IngredientFormulaMutationServiceTest.php
git commit -m "feat: replace ingredients across formulas"
```

### Task 3: Remove an ingredient everywhere transactionally

**Files:**
- Modify: `app/Services/IngredientFormulaMutationService.php`
- Modify: `tests/Feature/IngredientFormulaMutationServiceTest.php`

- [ ] **Step 1: Write failing remove-everywhere tests**

Use a source ingredient referenced by direct formula rows and costing-only rows across current, backup, and archived versions. Assert:

```php
$service->removeEverywhereAndDelete($user, $source);

expect(Ingredient::query()->whereKey($source->id)->exists())->toBeFalse()
    ->and(RecipeItem::withoutGlobalScopes()->where('ingredient_id', $source->id)->exists())->toBeFalse()
    ->and(RecipeVersionCostingItem::query()->where('ingredient_id', $source->id)->exists())->toBeFalse();
```

Assert unrelated formula rows remain and all affected generated-list fields are null. Add a formula containing only the source ingredient and assert the recipe/version remain after the row is removed.

- [ ] **Step 2: Run the removal test and confirm RED**

Run:

```bash
php artisan test --compact tests/Feature/IngredientFormulaMutationServiceTest.php --filter='removes an ingredient everywhere'
```

Expected: failure because `removeEverywhereAndDelete()` is undefined.

- [ ] **Step 3: Implement removal using the same authorization boundary**

Add:

```php
public function removeEverywhereAndDelete(User $user, Ingredient $ingredient): void
{
    DB::transaction(function () use ($user, $ingredient): void {
        $lockedIngredient = $this->ownedIngredientForMutation($user, $ingredient->id);
        $this->validatedImpact($user, $lockedIngredient);
        $versionIds = $this->affectedVersionIds($lockedIngredient);

        RecipeVersionCostingItem::query()
            ->where('ingredient_id', $lockedIngredient->id)
            ->whereHas('costing', fn ($query) => $query->whereIn('recipe_version_id', $versionIds))
            ->delete();

        RecipeItem::withoutGlobalScopes()
            ->whereIn('recipe_version_id', $versionIds)
            ->where('ingredient_id', $lockedIngredient->id)
            ->delete();

        $this->invalidateGeneratedIngredientLists($versionIds);
        $this->deleteIngredientMedia($lockedIngredient);
        $lockedIngredient->delete();
    });
}
```

Delete costings before formula rows so explicit behavior is independent of foreign-key cascade order.

- [ ] **Step 4: Add permission and ownership rollback tests**

Test a blocked shared formula, forced platform-ingredient call, another user's private ingredient, and stale/deleted source ID. Every failed call must leave all formula rows, costings, generated lists, and media unchanged.

- [ ] **Step 5: Run focused tests and commit**

Run:

```bash
php artisan test --compact tests/Feature/IngredientFormulaMutationServiceTest.php
vendor/bin/pint --dirty --format agent
```

Commit:

```bash
git add app/Services/IngredientFormulaMutationService.php tests/Feature/IngredientFormulaMutationServiceTest.php
git commit -m "feat: remove ingredients across formulas"
```

### Task 4: Wire the catalog management dialog

**Files:**
- Modify: `app/Livewire/Dashboard/IngredientsIndex.php`
- Modify: `resources/views/livewire/dashboard/ingredients-index.blade.php`
- Modify: `tests/Feature/PublicIngredientPagesTest.php`

- [ ] **Step 1: Replace old deletion-lock tests with failing workflow tests**

Add Livewire tests that assert:

```php
Livewire::test(IngredientsIndex::class)
    ->assertSee('Used in 1 formula')
    ->call('confirmDelete', $source->id)
    ->assertSet('pendingDeleteId', $source->id)
    ->assertSee('Replace everywhere and delete')
    ->assertSee('Remove everywhere and delete')
    ->set('replacementIngredientId', $replacement->id)
    ->call('replaceEverywhereAndDelete')
    ->assertSet('pendingDeleteId', null)
    ->assertHasNoErrors();
```

Add a removal test calling `removeEverywhereAndDelete`, a blocked-formula test asserting `ingredientRemoval` errors, and a platform-row response test:

```php
$this->actingAs($user)
    ->get(route('ingredients.index'))
    ->assertSuccessful()
    ->assertSee('aria-label="Not applicable"', false)
    ->assertDontSee('Reference');
```

- [ ] **Step 2: Run the Livewire tests and confirm RED**

Run:

```bash
php artisan test --compact tests/Feature/PublicIngredientPagesTest.php --filter='replace|remove everywhere|not applicable'
```

Expected: failures because the modal state/actions and copy do not exist.

- [ ] **Step 3: Add locked modal state and Livewire actions**

Add properties:

```php
#[Locked]
public ?int $pendingDeleteId = null;

public ?int $replacementIngredientId = null;
```

Update `confirmDelete()` to reset replacement state and errors. Add methods:

```php
public function replaceEverywhereAndDelete(IngredientFormulaMutationService $service): void
{
    $user = $this->currentUser();
    $ingredient = $user instanceof User ? $this->ownedIngredient($this->pendingDeleteId ?? 0, $user) : null;
    $replacement = $this->replacementIngredientId === null ? null : Ingredient::query()->find($this->replacementIngredientId);

    if (! $user instanceof User || ! $ingredient instanceof Ingredient || ! $replacement instanceof Ingredient) {
        $this->addError('replacementIngredientId', 'Choose a replacement ingredient.');
        return;
    }

    $service->replaceEverywhereAndDelete($user, $ingredient, $replacement);
    $this->closeIngredientRemovalDialog();
}

public function removeEverywhereAndDelete(IngredientFormulaMutationService $service): void
{
    $user = $this->currentUser();
    $ingredient = $user instanceof User ? $this->ownedIngredient($this->pendingDeleteId ?? 0, $user) : null;

    if (! $user instanceof User || ! $ingredient instanceof Ingredient) {
        return;
    }

    $service->removeEverywhereAndDelete($user, $ingredient);
    $this->closeIngredientRemovalDialog();
}
```

Keep `deleteIngredient()` for the unused simple path, but delegate its hard delete to the new service so media and ownership behavior have one implementation. Add view data for `pendingDeleteImpact` and `replacementCandidates` only when a modal is open.

- [ ] **Step 4: Render the actions and dialog**

For platform rows render:

```blade
<span class="text-xs text-[var(--color-ink-soft)]" aria-label="Not applicable">—</span>
```

For used private rows, keep the current `Used in N formulas` disclosure and add an enabled trash/manage button with an explicit accessible label. The modal must:

- display `Used in {{ $pendingDeleteImpact['formula_count'] }} {{ \Illuminate\Support\Str::plural('formula', $pendingDeleteImpact['formula_count']) }}` only;
- list blocked formula links when present;
- show a searchable native/Filament-consistent replacement select;
- make `Replace everywhere and delete` the primary path;
- render `Remove everywhere and delete` with danger styling and carrier-oil warning copy;
- disable both mutations while blocked formulas exist;
- preserve the simple confirmation for unused ingredients.

Use `wire:loading.attr="disabled"` and visible focus styles on every modal action.

- [ ] **Step 5: Run catalog tests and commit**

Run:

```bash
php artisan test --compact tests/Feature/PublicIngredientPagesTest.php tests/Feature/IngredientFormulaUsageServiceTest.php tests/Feature/IngredientFormulaMutationServiceTest.php
vendor/bin/pint --dirty --format agent
```

Commit:

```bash
git add app/Livewire/Dashboard/IngredientsIndex.php resources/views/livewire/dashboard/ingredients-index.blade.php tests/Feature/PublicIngredientPagesTest.php
git commit -m "feat: manage used ingredients from catalog"
```

### Task 5: Add the sticky ingredient save action

**Files:**
- Modify: `resources/views/livewire/dashboard/ingredient-editor.blade.php`
- Modify: `tests/Feature/UserIngredientAuthoringTest.php`

- [ ] **Step 1: Write the failing sticky-action test**

Add an authenticated editor-page test that parses the response and asserts one submit button sits inside a sticky action container:

```php
$response = $this->actingAs($user)->get(route('ingredients.edit', $ingredient));

$response->assertSuccessful()
    ->assertSee('data-ingredient-save-bar', false)
    ->assertSee('sticky bottom-0', false)
    ->assertSee('sm:w-auto', false)
    ->assertSee('w-full', false)
    ->assertSee('Save ingredient');
```

Also cover the create label `Create ingredient`.

- [ ] **Step 2: Run the editor test and confirm RED**

Run:

```bash
php artisan test --compact tests/Feature/UserIngredientAuthoringTest.php --filter='sticky save'
```

Expected: failure because the sticky marker and class contract are absent.

- [ ] **Step 3: Replace the trailing action row with a sticky action bar**

Keep the button inside the existing `<form wire:submit="save">` and replace the final action wrapper with:

```blade
<div
    data-ingredient-save-bar
    class="sticky bottom-0 z-20 -mx-4 border-t border-[var(--color-line)] bg-[var(--color-surface)] px-4 pb-[max(1rem,env(safe-area-inset-bottom))] pt-3 sm:mx-0 sm:flex sm:justify-end sm:px-0"
>
    <button
        type="submit"
        wire:loading.attr="disabled"
        wire:target="save"
        class="w-full rounded-lg bg-[var(--color-accent)] px-5 py-2.5 text-sm font-medium text-white transition hover:bg-[var(--color-accent-hover)] focus-visible:outline-2 focus-visible:outline-offset-2 disabled:cursor-wait disabled:opacity-60 sm:w-auto"
    >
        {{ $ingredient ? 'Save ingredient' : 'Create ingredient' }}
    </button>
</div>
```

Use the opaque authenticated surface and a structural top border. Do not add a card, backdrop blur, fixed positioning, or box-shadow spectacle.

- [ ] **Step 4: Run tests and production build**

Run:

```bash
php artisan test --compact tests/Feature/UserIngredientAuthoringTest.php
npm run build
```

Expected: tests and Vite production build pass.

- [ ] **Step 5: Commit**

```bash
git add resources/views/livewire/dashboard/ingredient-editor.blade.php tests/Feature/UserIngredientAuthoringTest.php
git commit -m "feat: keep ingredient save action visible"
```

### Task 6: Full verification and review

**Files:**
- Review every file changed since the plan base.

- [ ] **Step 1: Run formatters and focused suites**

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Feature/IngredientFormulaMutationServiceTest.php tests/Feature/IngredientFormulaUsageServiceTest.php tests/Feature/PublicIngredientPagesTest.php tests/Feature/UserIngredientAuthoringTest.php
```

Expected: Pint succeeds and all focused tests pass.

- [ ] **Step 2: Run broader ingredient and recipe regression suites**

```bash
php -d memory_limit=512M vendor/bin/pest --compact
```

Expected: the complete Pest suite passes with zero failures.

- [ ] **Step 3: Verify frontend and graph**

```bash
npm run build
graphify update .
git diff --check
git status --short
```

Expected: Vite succeeds, Graphify refreshes, diff check is silent, and status contains only intended feature files plus pre-existing user changes outside the isolated worktree.

- [ ] **Step 4: Perform whole-change review**

Review for:

- authorization on every affected recipe before mutation;
- no cross-tenant replacement candidates;
- complete rollback on every validation failure;
- current, backup, archived, direct, and costing-only coverage;
- stale generated-list invalidation;
- formula percentages and weights preserved during replacement;
- price reset behavior;
- accessible modal controls and platform dash;
- sticky bar keyboard, mobile, safe-area, and loading states;
- bounded query behavior for catalog rendering.

- [ ] **Step 5: Finish the branch**

Use `superpowers:verification-before-completion`, then `superpowers:finishing-a-development-branch`. Do not merge into a dirty base branch until the user chooses the integration option and all existing uncommitted work has been preserved.
