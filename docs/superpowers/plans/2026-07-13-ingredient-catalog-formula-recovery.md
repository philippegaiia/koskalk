# Ingredient Catalog and Formula Recovery Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Explain protected ingredient usage and plan limits, restore formula version recovery and formula contents, and correct the authenticated shell's trailing-scroll presentation.

**Architecture:** Keep `IngredientsIndex` responsible for interaction state while a focused `IngredientFormulaUsageService` builds an accessible, deduplicated usage read model. Keep all formula sheets on `RecipeVersionViewDataBuilder`, but pass the explicitly selected version through historical view, print, and export flows. Reuse the existing formula-sheet partial and harden the app shell with dynamic viewport and sticky desktop-sidebar constraints.

**Tech Stack:** PHP 8.5, Laravel 13, Livewire 4, Blade, Tailwind CSS 4, Pest 4, Vite.

---

## File map

- Create `app/Services/IngredientFormulaUsageService.php`: batch-load formula references for ingredients on the current page and group them by recipe.
- Create `tests/Feature/IngredientFormulaUsageServiceTest.php`: cover recipe-item, costing-only, historical-version, deduplication, and tenant isolation behavior.
- Modify `app/Livewire/Dashboard/IngredientsIndex.php`: provide entitlement usage, formula usage, and disclosure state to the Blade view.
- Modify `resources/views/livewire/dashboard/ingredients-index.blade.php`: render `Mine (N)`, allowance copy, and accessible usage disclosures.
- Modify `tests/Feature/PublicIngredientPagesTest.php`: verify protected deletion UX and server enforcement.
- Modify `tests/Feature/IngredientsIndexPriceTest.php`: verify count and limit presentation.
- Modify `app/Http/Controllers/RecipeController.php`: render explicit historical versions and propagate selected versions to print/export builders.
- Modify `resources/views/recipes/version.blade.php`: restore version history, historical-state labeling, and the reusable formula content.
- Modify `resources/views/recipes/partials/version-sheet.blade.php`: allow summary and detail sections to be included without duplication.
- Modify `tests/Feature/RecipeVersionPagesTest.php`: cover active and historical formula sheets, history, formula rows, restore links, print, and export version selection.
- Modify `resources/views/layouts/app-shell.blade.php`: apply the dynamic viewport and sticky-sidebar contract.
- Modify `tests/Feature/PublicShellPagesTest.php`: lock the authenticated shell contract.

### Task 1: Build the ingredient formula-usage read model

**Files:**
- Create: `app/Services/IngredientFormulaUsageService.php`
- Create: `tests/Feature/IngredientFormulaUsageServiceTest.php`

- [ ] **Step 1: Generate the service and test files**

Run:

```bash
php artisan make:class Services/IngredientFormulaUsageService --no-interaction
php artisan make:test --pest IngredientFormulaUsageServiceTest --no-interaction
```

Expected: both files are created without prompts.

- [ ] **Step 2: Write failing usage aggregation tests**

In `tests/Feature/IngredientFormulaUsageServiceTest.php`, create one user-owned ingredient and two saved versions of the same recipe. Attach the ingredient through `RecipeItem` in both versions, then assert one recipe result with a version count of two:

```php
$usage = app(IngredientFormulaUsageService::class)->forIngredients(
    $user,
    collect([$ingredient]),
);

expect($usage[$ingredient->id])->toHaveCount(1)
    ->and($usage[$ingredient->id][0])->toMatchArray([
        'recipe_id' => $recipe->id,
        'name' => $recipe->name,
        'version_count' => 2,
        'url' => route('recipes.edit', $recipe->id),
    ]);
```

Add separate tests that:

```php
expect($usage[$costingOnlyIngredient->id][0]['recipe_id'])->toBe($recipe->id);
expect($usage)->not->toHaveKey($otherUsersIngredient->id);
```

The costing-only fixture must create `RecipeVersionCosting` and `RecipeVersionCostingItem`. The isolation fixture must call the service as a different user.

- [ ] **Step 3: Run the service test and verify failure**

Run:

```bash
php artisan test --compact tests/Feature/IngredientFormulaUsageServiceTest.php
```

Expected: FAIL because `forIngredients()` is not implemented.

- [ ] **Step 4: Implement batched aggregation**

Implement this public contract in `IngredientFormulaUsageService`:

```php
/**
 * @param  Collection<int, Ingredient>  $ingredients
 * @return array<int, array<int, array{recipe_id: int, name: string, version_count: int, url: string}>>
 */
public function forIngredients(User $user, Collection $ingredients): array
```

Collect the page ingredient IDs, then load direct formula rows and costing rows with two queries. Each query must eager-load the chain to the recipe and constrain recipes to the signed-in user's ownership:

```php
$recipeItems = RecipeItem::withoutGlobalScopes()
    ->whereIn('ingredient_id', $ingredientIds)
    ->whereHas('recipeVersion.recipe', fn (Builder $query): Builder => $query
        ->where('owner_type', OwnerType::User->value)
        ->where('owner_id', $user->id))
    ->with('recipeVersion.recipe')
    ->get();

$costingItems = RecipeVersionCostingItem::query()
    ->whereIn('ingredient_id', $ingredientIds)
    ->whereHas('costing.recipeVersion.recipe', fn (Builder $query): Builder => $query
        ->where('owner_type', OwnerType::User->value)
        ->where('owner_id', $user->id))
    ->with('costing.recipeVersion.recipe')
    ->get();
```

Normalize both collections to `ingredient_id`, `recipe_id`, `recipe_name`, and `version_id`; group by ingredient and recipe; count unique version IDs; sort by recipe name; and return an empty array for an empty ingredient collection.

- [ ] **Step 5: Run the focused test**

Run:

```bash
php artisan test --compact tests/Feature/IngredientFormulaUsageServiceTest.php
```

Expected: PASS.

- [ ] **Step 6: Format and commit the service**

Run:

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/IngredientFormulaUsageService.php tests/Feature/IngredientFormulaUsageServiceTest.php
git commit -m "feat: resolve ingredient formula usage"
```

Expected: Pint succeeds and the commit contains only the service and its test.

### Task 2: Show private-ingredient usage and allowance in the catalog

**Files:**
- Modify: `app/Livewire/Dashboard/IngredientsIndex.php`
- Modify: `resources/views/livewire/dashboard/ingredients-index.blade.php`
- Modify: `tests/Feature/PublicIngredientPagesTest.php`
- Modify: `tests/Feature/IngredientsIndexPriceTest.php`

- [ ] **Step 1: Write failing Livewire and page assertions**

Update the ingredient tests to assert:

```php
Livewire::test(IngredientsIndex::class)
    ->assertSee('Mine (1)')
    ->assertSee('1 of 20 private ingredients');
```

For an ingredient referenced by two versions of one recipe, assert:

```php
Livewire::test(IngredientsIndex::class)
    ->assertSee('Used in 1 formula')
    ->assertSee($recipe->name)
    ->assertSee('2 saved versions')
    ->assertSee(route('recipes.edit', $recipe->id), false)
    ->assertDontSeeHtml('disabled="disabled"');
```

Retain the existing forced `deleteIngredient` call and database assertion so presentation cannot weaken server protection. Add a no-limit test by creating a plan entitlement whose `private_ingredients` limit is `null`, then assert `1 private ingredient` and no `/` limit.

- [ ] **Step 2: Run the ingredient tests and verify failure**

Run:

```bash
php artisan test --compact tests/Feature/PublicIngredientPagesTest.php tests/Feature/IngredientsIndexPriceTest.php
```

Expected: FAIL because usage and entitlement copy are absent.

- [ ] **Step 3: Add component state and view data**

Inject the existing services through `render()` and build the two read models once:

```php
public ?int $expandedUsageIngredientId = null;

public function toggleUsage(int $ingredientId): void
{
    $this->expandedUsageIngredientId = $this->expandedUsageIngredientId === $ingredientId
        ? null
        : $ingredientId;
}
```

In `render()`, resolve the current user once and pass:

```php
'privateIngredientUsage' => $currentUser instanceof User
    ? $entitlementService->usageFor($currentUser)['private_ingredients']
    : ['used' => 0, 'limit' => null, 'remaining' => null, 'allowed' => false],
'formulaUsageByIngredient' => $currentUser instanceof User
    ? $ingredientFormulaUsageService->forIngredients($currentUser, $ingredients->getCollection())
    : [],
```

- [ ] **Step 4: Render the compact allowance and accessible usage disclosure**

Keep `ownershipFilterOptions()` unchanged and render the Mine label specially:

```blade
{{ $filterValue === 'mine' ? 'Mine ('.$privateIngredientUsage['used'].')' : $filterLabel }}
```

Near the filters render:

```blade
<p class="text-xs text-[var(--color-ink-soft)]">
    @if ($privateIngredientUsage['limit'] === null)
        {{ $privateIngredientUsage['used'] }} private ingredients
    @else
        {{ $privateIngredientUsage['used'] }} of {{ $privateIngredientUsage['limit'] }} private ingredients
    @endif
</p>
```

For referenced private ingredients, replace the disabled delete button with a focusable button using `wire:click="toggleUsage(...)"`, `aria-expanded`, and `aria-controls`. Render its disclosure directly below the row action group. Each recipe appears once, links to `recipes.edit`, and conditionally shows `N saved versions`. Unused ingredients retain the existing delete button.

- [ ] **Step 5: Run the ingredient tests**

Run:

```bash
php artisan test --compact tests/Feature/PublicIngredientPagesTest.php tests/Feature/IngredientsIndexPriceTest.php tests/Feature/IngredientFormulaUsageServiceTest.php
```

Expected: PASS.

- [ ] **Step 6: Format and commit the catalog UX**

Run:

```bash
vendor/bin/pint --dirty --format agent
git add app/Livewire/Dashboard/IngredientsIndex.php resources/views/livewire/dashboard/ingredients-index.blade.php tests/Feature/PublicIngredientPagesTest.php tests/Feature/IngredientsIndexPriceTest.php
git commit -m "feat: explain ingredient usage and limits"
```

Expected: the commit contains the Livewire catalog behavior and tests.

### Task 3: Restore active and historical formula-version routing

**Files:**
- Modify: `app/Http/Controllers/RecipeController.php`
- Modify: `resources/views/recipes/version.blade.php`
- Modify: `tests/Feature/RecipeVersionPagesTest.php`

- [ ] **Step 1: Replace the legacy-current test with failing selected-version assertions**

Create Formula A and Formula B published versions. Assert the normal sheet shows Formula B and the historical URL for Formula A shows Formula A with historical context:

```php
$this->actingAs($user)
    ->get(route('recipes.saved', $recipe))
    ->assertSee('Formula B')
    ->assertDontSee('Previous version');

$this->actingAs($user)
    ->get(route('recipes.version', [$recipe, $formulaA]))
    ->assertSee('Formula A')
    ->assertSee('Previous version')
    ->assertSee('Back to active formula');
```

Update the recovery-history test to assert `Version history`, both saved names, dates, view links, and `Restore to current formula` actions.

- [ ] **Step 2: Run the version tests and verify failure**

Run:

```bash
php artisan test --compact tests/Feature/RecipeVersionPagesTest.php --filter="formula sheet|version history|historical"
```

Expected: FAIL because `version()` currently delegates back to the active sheet and the history UI is hidden.

- [ ] **Step 3: Extract one controller renderer and pass the selected version**

Create a private controller method with the dependencies already used by `saved()`:

```php
private function formulaSheetView(
    Recipe $recipe,
    RecipeVersion $version,
    Request $request,
    ?User $user,
    EntitlementService $entitlementService,
    RecipeVersionViewDataBuilder $viewDataBuilder,
    RecipeVersionCostPreviewBuilder $costPreviewBuilder,
    bool $isHistorical,
): View
```

Move the shared view-data construction from `saved()` into this method. Add `isHistorical` to the Blade data. `saved()` passes the active resolved version and `false`. `version()` uses both values returned from `accessibleSavedVersion()` and passes that exact version with `true`; it must not call `saved()`.

- [ ] **Step 4: Restore version history in the formula sheet**

In `resources/views/recipes/version.blade.php`:

- show a `Previous version` badge and `Back to active formula` link when `$isHistorical` is true;
- restore a native `<details>` section labeled `Version history` when older versions exist;
- list the saved timestamp, `View version` link, and a POST form to `recipes.use-version-as-current` labeled `Restore to current formula`;
- omit the version currently displayed from redundant actions;
- preserve the existing confirmation flow in `restoreCurrentVersion()`.

- [ ] **Step 5: Run the focused version tests**

Run:

```bash
php artisan test --compact tests/Feature/RecipeVersionPagesTest.php --filter="formula sheet|version history|historical|restore"
```

Expected: PASS.

- [ ] **Step 6: Format and commit version navigation**

Run:

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/RecipeController.php resources/views/recipes/version.blade.php tests/Feature/RecipeVersionPagesTest.php
git commit -m "fix: restore formula version navigation"
```

Expected: the selected historical version reaches the view builder and history is visible.

### Task 4: Restore formula contents without duplicate sections

**Files:**
- Modify: `resources/views/recipes/version.blade.php`
- Modify: `resources/views/recipes/partials/version-sheet.blade.php`
- Modify: `tests/Feature/RecipeVersionPagesTest.php`
- Modify: `tests/Feature/CosmeticRecipeWorkbenchTest.php`

- [ ] **Step 1: Write failing formula-content assertions**

Extend the saved formula test with the existing fixture's ingredient:

```php
->assertSee('How this recipe was calculated')
->assertSee('Lye and water')
->assertSee('Olive Oil')
->assertSee('OLEA EUROPAEA')
->assertSee('% oils')
->assertSee('Weight (g)');
```

Assert the headings `Batch weight` and `Final ingredient list` occur once in the response. In the cosmetic feature test, assert the ingredient phase table appears while `Lye and water` does not.

- [ ] **Step 2: Run the content tests and verify failure**

Run:

```bash
php artisan test --compact tests/Feature/RecipeVersionPagesTest.php tests/Feature/CosmeticRecipeWorkbenchTest.php --filter="formula sheet|saved formula"
```

Expected: FAIL because the version-sheet partial is no longer included.

- [ ] **Step 3: Make the reusable sheet sections configurable**

At the top of `version-sheet.blade.php`, add:

```blade
@php
    $showSummary = $showSummary ?? true;
    $showIngredientLists = $showIngredientLists ?? $showDetails;
@endphp
```

Wrap its summary block with `@if ($showSummary)`. Wrap final INCI/plain-language lists with `@if ($showIngredientLists)`. Keep recipe settings, category-appropriate lye information, phase tables, descriptions, and instructions reusable.

- [ ] **Step 4: Include the formula immediately after version history**

In `version.blade.php`, include:

```blade
@include('recipes.partials.version-sheet', [
    'recipe' => $recipe,
    'snapshot' => $snapshot,
    'phaseSections' => $phaseSections,
    'summaryCards' => $summaryCards,
    'contextRows' => $contextRows,
    'lyeRows' => $lyeRows,
    'showDetails' => true,
    'showSummary' => true,
    'showIngredientLists' => true,
])
```

Place it before production and packaging. Remove the current hand-built summary cards and final ingredient-list cards so those sections appear only once. For cosmetic recipes, hide the entire lye article rather than displaying the soap-only empty message.

- [ ] **Step 5: Run the content tests**

Run:

```bash
php artisan test --compact tests/Feature/RecipeVersionPagesTest.php tests/Feature/CosmeticRecipeWorkbenchTest.php --filter="formula sheet|saved formula"
```

Expected: PASS with formula rows visible and no duplicate summary/list sections.

- [ ] **Step 6: Commit the restored formula body**

Run:

```bash
git add resources/views/recipes/version.blade.php resources/views/recipes/partials/version-sheet.blade.php tests/Feature/RecipeVersionPagesTest.php tests/Feature/CosmeticRecipeWorkbenchTest.php
git commit -m "fix: restore formula sheet ingredients"
```

Expected: the commit is limited to formula presentation and tests.

### Task 5: Preserve historical selection in print and export actions

**Files:**
- Modify: `app/Http/Controllers/RecipeController.php`
- Modify: `resources/views/recipes/version.blade.php`
- Modify: `tests/Feature/RecipeVersionPagesTest.php`

- [ ] **Step 1: Write failing historical print and export tests**

Request an older version page and assert its action URLs include `version=<id>`. Then request the technical print and CSV export with that query and assert Formula A data is present while Formula B data is absent.

```php
$query = ['recipe' => $recipe->id, 'version' => $formulaA->id];

$this->actingAs($user)
    ->get(route('recipes.print.technical', $query))
    ->assertSee('Formula A')
    ->assertDontSee('Formula B');
```

For CSV, capture streamed content and assert it contains Formula A's unique ingredient row.

- [ ] **Step 2: Run the focused tests and verify failure**

Run:

```bash
php artisan test --compact tests/Feature/RecipeVersionPagesTest.php --filter="historical.*print|historical.*export"
```

Expected: FAIL because print/export methods always resolve the latest published version.

- [ ] **Step 3: Add one selected-sheet-version resolver**

Add:

```php
/** @return array{0: Recipe, 1: RecipeVersion} */
private function accessibleSheetVersion(
    int $recipeId,
    Request $request,
    CurrentAppUserResolver $resolver,
): array {
    $versionId = $request->integer('version');

    return $versionId > 0
        ? $this->accessibleSavedVersion($recipeId, $versionId, $resolver)
        : $this->accessibleLatestPublishedFormula($recipeId, $resolver);
}
```

Use it in production, technical, costing, XLSX, and CSV methods. Update legacy version print methods to add the requested version to a cloned request or render that version directly rather than delegating to a method that discards it.

- [ ] **Step 4: Propagate version through formula-sheet actions**

When `$isHistorical` is true, add `version => $version->id` to `$printQuery` and to export URLs. Active sheets omit the query parameter.

- [ ] **Step 5: Run print/export tests**

Run:

```bash
php artisan test --compact tests/Feature/RecipeVersionPagesTest.php --filter="print|export|historical"
```

Expected: PASS.

- [ ] **Step 6: Format and commit selected-version outputs**

Run:

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/RecipeController.php resources/views/recipes/version.blade.php tests/Feature/RecipeVersionPagesTest.php
git commit -m "fix: preserve formula version in outputs"
```

Expected: historical print and export output uses the selected snapshot.

### Task 6: Correct the authenticated shell height behavior

**Files:**
- Modify: `resources/views/layouts/app-shell.blade.php`
- Modify: `tests/Feature/PublicShellPagesTest.php`

- [ ] **Step 1: Add a failing authenticated-shell contract test**

Add an authenticated dashboard request that asserts the layout has explicit markers and dynamic viewport classes:

```php
$this->actingAs(User::factory()->create())
    ->get(route('ingredients.index'))
    ->assertSuccessful()
    ->assertSee('data-app-shell', false)
    ->assertSee('min-h-dvh', false)
    ->assertSee('lg:h-dvh', false)
    ->assertDontSee('data-app-footer', false);
```

- [ ] **Step 2: Run the shell test and verify failure**

Run:

```bash
php artisan test --compact tests/Feature/PublicShellPagesTest.php --filter="authenticated shell"
```

Expected: FAIL because the layout uses `min-h-screen` and a document-flow desktop sidebar.

- [ ] **Step 3: Apply the viewport and sidebar contract**

In `app-shell.blade.php`:

- change body and shell wrappers from `min-h-screen` to `min-h-dvh`;
- add `items-stretch` to the desktop grid;
- add `lg:sticky lg:top-0 lg:h-dvh lg:self-start` to the desktop sidebar while retaining fixed mobile behavior;
- change the content column to `min-h-dvh`;
- keep `<main>` as `flex-1` and do not add a footer or artificial minimum content height.

- [ ] **Step 4: Run the shell test**

Run:

```bash
php artisan test --compact tests/Feature/PublicShellPagesTest.php --filter="authenticated shell"
```

Expected: PASS.

- [ ] **Step 5: Build assets and visually verify the page boundary**

Run:

```bash
npm run build
```

Expected: Vite build succeeds. In the authenticated Ingredients page at desktop and mobile widths, scroll to the final pagination row and verify the document ends after normal page padding, the sidebar remains viewport-height on desktop, and no footer appears.

- [ ] **Step 6: Commit the shell correction**

Run:

```bash
git add resources/views/layouts/app-shell.blade.php tests/Feature/PublicShellPagesTest.php public/build/manifest.json public/build/assets
git commit -m "fix: constrain authenticated shell height"
```

Expected: the shell and generated assets are committed according to the repository's existing build-asset convention. If `public/build` is ignored, stage only source and test files.

### Task 7: Full verification and graph refresh

**Files:**
- Modify only if verification exposes a defect in the files already listed.

- [ ] **Step 1: Run Filament compatibility checks if Filament files changed**

No Filament files are planned. If overlap resolution required editing `app/Filament`, run:

```bash
vendor/bin/filacheck --fix
```

Expected: all rules pass and every reported issue is fixed.

- [ ] **Step 2: Format all modified PHP**

Run:

```bash
vendor/bin/pint --dirty --format agent
```

Expected: Pint completes successfully.

- [ ] **Step 3: Run the focused regression suite**

Run:

```bash
php artisan test --compact tests/Feature/IngredientFormulaUsageServiceTest.php tests/Feature/PublicIngredientPagesTest.php tests/Feature/IngredientsIndexPriceTest.php tests/Feature/RecipeVersionPagesTest.php tests/Feature/CosmeticRecipeWorkbenchTest.php tests/Feature/PublicShellPagesTest.php
```

Expected: all focused tests pass.

- [ ] **Step 4: Run the broader ingredient and recipe suites**

Run:

```bash
php artisan test --compact --filter=Ingredient
php artisan test --compact --filter=Recipe
```

Expected: both groups pass.

- [ ] **Step 5: Rebuild frontend assets**

Run:

```bash
npm run build
```

Expected: Vite build succeeds without warnings that affect delivery.

- [ ] **Step 6: Refresh the knowledge graph**

Run:

```bash
graphify update .
```

Expected: `graphify-out/` refresh completes without API use.

- [ ] **Step 7: Check the final diff**

Run:

```bash
git status --short
git diff --check
git diff --stat HEAD~6..HEAD
```

Expected: no whitespace errors; only the scoped implementation, tests, build artifacts if tracked, and graph output changed. Preserve all pre-existing uncommitted ingredient work and the unrelated `.impeccable/critique` artifact.

- [ ] **Step 8: Commit verification-only corrections if needed**

If verification required corrections, stage only those scoped files and run:

```bash
git commit -m "fix: harden ingredient and formula recovery"
```

Expected: the working tree still retains any unrelated user-owned changes.
