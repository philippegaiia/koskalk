# Formula Ingredient-Line Plan Limits Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Enforce an admin-editable maximum number of ingredient rows per formula: 30 for the free plan and 50 for paid plans by default, while preserving safe access and controlled editing for formulas that exceed a later-lowered limit.

**Architecture:** Add `formula_items_per_recipe` to the existing `plan_limits` vocabulary rather than creating a new settings system. A focused `RecipeFormulaItemLimitService` reads the active workspace subscriber's entitlement, counts normalized phase items, and authorizes create/update/duplicate/restore before any recipe rows are deleted. The workbench receives the same limit for immediate Alpine feedback, while the server remains authoritative.

**Tech Stack:** PHP 8.5, Laravel 13, Filament 5, Livewire 4, PostgreSQL 17, SQLite test database, Pest 4, Blade, Alpine.js, Laravel translation loader.

---

## Implementation constraints

- Read `.ai/rules/index.md`, all matching rules, and search `.ai/rules` before changing files.
- Use Laravel Boost `search-docs` before framework-facing work and verify installed package APIs.
- Preserve unrelated dirty files. Stage only exact task paths and inspect the staged diff before each commit.
- The plan-limit key is exactly `formula_items_per_recipe`.
- `null` means unlimited. `0` means no ingredient rows can be saved.
- Count every normalized `phases[*].items[*]` row. The same ingredient in two phases counts twice.
- Do not count packaging rows, instructions, phase headings, or calculated water/lye that is not stored as a `recipe_items` row.
- New recipes, duplicates, and restores must not exceed the current plan limit.
- An existing over-limit recipe remains viewable/printable/exportable and may save or publish unchanged or with fewer rows, but may not increase its current row count.
- Validate before `RecipeVersionStructureSynchronizer` or any publisher/draft service deletes or replaces existing rows.
- Public unauthenticated calculators have no persisted formula entitlement and remain unlimited because `canPersist` is false.
- All new user-facing copy belongs in `lang/en/workbench.php` or `lang/en/plans.php` and the authoritative five-locale translation catalogue.

## Task 1: Add and backfill the editable plan-limit key

**Files:**

- Create: `app/Services/PlanLimitDefaultsService.php`
- Create: `database/migrations/2026_08_12_130000_backfill_formula_item_plan_limits.php`
- Modify: `database/seeders/PlanSeeder.php`
- Modify: `database/factories/PlanLimitFactory.php`
- Modify: `app/Filament/Resources/Plans/Schemas/PlanForm.php`
- Modify: `app/Filament/Resources/Plans/Tables/PlansTable.php`
- Modify: `app/Filament/Resources/Plans/Pages/CreatePlan.php`
- Modify: `app/Filament/Resources/Plans/Pages/EditPlan.php`
- Modify: `lang/en/plans.php`
- Modify: `database/seeders/data/interface-translations.json`
- Modify: `tests/Feature/EntitlementLimitsTest.php`
- Modify: `tests/Feature/Filament/CatalogResourcesTest.php`
- Modify: `tests/Feature/InterfaceTranslationCatalogueTest.php`

- [ ] **Step 1: Add failing defaults, migration, seeder, and admin tests**

Cover:

- `PlanSeeder` creates free-beta's missing value at 30;
- rerunning the seeder does not overwrite an administrator's value;
- the migration inserts 30 for `free-beta` and 50 for every billable plan missing the key;
- a pre-existing key is never changed;
- creating or editing a billable plan in admin ensures a missing default of 50 after relationship fields save;
- a free/internal plan is not assigned 50;
- Plan form options and Plans table expose the new key.

```php
$plan->limits()->where('key', 'formula_items_per_recipe')->update(['value' => 37]);
$this->seed(PlanSeeder::class);

expect($plan->fresh()->limits->firstWhere('key', 'formula_items_per_recipe')?->value)
    ->toBe(37);
```

- [ ] **Step 2: Run focused tests and confirm RED**

```bash
php artisan test --compact tests/Feature/EntitlementLimitsTest.php tests/Feature/Filament/CatalogResourcesTest.php tests/Feature/InterfaceTranslationCatalogueTest.php --filter="formula item|plan limit|initial free"
```

- [ ] **Step 3: Implement defaults and the existing admin settings integration**

Generate the service and migration:

```bash
php artisan make:class Services/PlanLimitDefaultsService --no-interaction
php artisan make:migration backfill_formula_item_plan_limits --no-interaction
```

Rename the migration to the exact path above. Its `up()` inserts missing rows only:

- plan slug `free-beta`: 30;
- plans with a non-null `paddle_price_id`: 50.

Its `down()` deletes the `formula_items_per_recipe` rows from those targeted plan IDs. Use query builder code, not application models.

`PlanLimitDefaultsService::ensureFormulaItemsPerRecipe(Plan $plan)` uses `firstOrCreate`; it creates 50 only for a billable plan and never changes an existing value. Call it from `CreatePlan::afterCreate()` and `EditPlan::afterSave()`, after Filament relationship state has been saved.

Add this option to the existing limits repeater and table:

```php
'formula_items_per_recipe' => __('plans.limits.formula_items_per_recipe'),
```

Add `formula_items_per_recipe` to the `PlanLimitFactory` vocabulary and 30 to the `PlanSeeder` map. Add translated plan strings for **Ingredient lines per formula**, **Empty means unlimited**, and updated Limits guidance to English plus DE/ES/FR/IT/NL catalogue entries.

- [ ] **Step 4: Run Filament/PHP formatters and focused tests**

```bash
vendor/bin/filacheck --fix
vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Feature/EntitlementLimitsTest.php tests/Feature/Filament/CatalogResourcesTest.php tests/Feature/InterfaceTranslationCatalogueTest.php --filter="formula item|plan limit|initial free"
```

- [ ] **Step 5: Commit the plan vocabulary**

```bash
git add app/Services/PlanLimitDefaultsService.php database/migrations/2026_08_12_130000_backfill_formula_item_plan_limits.php database/seeders/PlanSeeder.php database/factories/PlanLimitFactory.php app/Filament/Resources/Plans/Schemas/PlanForm.php app/Filament/Resources/Plans/Tables/PlansTable.php app/Filament/Resources/Plans/Pages/CreatePlan.php app/Filament/Resources/Plans/Pages/EditPlan.php lang/en/plans.php database/seeders/data/interface-translations.json tests/Feature/EntitlementLimitsTest.php tests/Feature/Filament/CatalogResourcesTest.php tests/Feature/InterfaceTranslationCatalogueTest.php
git diff --cached --check
git commit -m "feat: configure formula line limits by plan"
```

## Task 2: Build the authoritative normalized-payload limit service

**Files:**

- Create: `app/Services/RecipeFormulaItemLimitService.php`
- Modify: `app/Services/EntitlementService.php`
- Modify: `lang/en/workbench.php`
- Modify: `database/seeders/data/interface-translations.json`
- Modify: `tests/Feature/InterfaceTranslationCatalogueTest.php`
- Create: `tests/Feature/RecipeFormulaItemLimitTest.php`

- [ ] **Step 1: Add failing service tests for every rule**

Use plan/user/workspace factories and normalized payload arrays to prove:

- free 30 allows 30 and rejects 31;
- paid 50 allows 50 and rejects 51;
- null allows any count;
- zero rejects one and permits zero;
- the same ingredient in two phases counts as two;
- packaging entries do not count;
- a new or duplicated formula always uses the strict plan limit;
- an existing 35-row recipe after downgrade to 30 can save 35 or 34 but not 36;
- after it is reduced to 32, it can no longer return to 35;
- restore uses the strict limit even when its target would shrink an existing over-limit recipe.

```php
expect(fn () => $service->assertUpdateAllowed(
    user: $user,
    normalizedPayload: normalizedFormulaWithLines(36),
    recipe: $existingRecipeWith35Lines,
))->toThrow(ValidationException::class, '30 ingredient lines');
```

- [ ] **Step 2: Run the focused test and confirm RED**

```bash
php artisan test --compact tests/Feature/RecipeFormulaItemLimitTest.php
```

- [ ] **Step 3: Expose the entitlement value and implement explicit mutation policies**

Add to `EntitlementService`:

```php
public function formulaItemsPerRecipeLimitFor(User $user): ?int
{
    $workspace = $this->companyWorkspaceFor($user);
    $subscriber = $workspace?->owner ?? $user;
    $value = $this->limitsFor($subscriber)['formula_items_per_recipe'] ?? null;

    return $value === null ? null : max(0, (int) $value);
}
```

Generate `RecipeFormulaItemLimitService` and inject `EntitlementService`. Implement:

```php
public function limitFor(User $user): ?int;
public function count(array $normalizedPayload): int;
public function assertCreateAllowed(User $user, array $normalizedPayload): void;
public function assertUpdateAllowed(User $user, array $normalizedPayload, Recipe $recipe): void;
public function assertRestoreAllowed(User $user, array $normalizedPayload): void;
```

`count()` sums only the arrays at `phases.*.items`. `assertUpdateAllowed()` sets the maximum permitted proposed count to `max($limit, $currentRecipeItemCount)`. Query the current version's actual `recipe_items` count using `RecipeVersion::withoutGlobalScopes()` and `RecipeItem::withoutGlobalScopes()` so stale client payload cannot define the grandfathered baseline. Create and restore use the plan limit directly.

Return translated validation errors on the `formula_items` key using `workbench.messages.formula_item_limit`, including the current limit and proposed line count.

Add that English key and its DE/ES/FR/IT/NL catalogue entries in this task so the server policy never ships with an untranslated error. Preserve `:limit` and `:count` in every locale and extend the catalogue test with this exact placeholder contract.

- [ ] **Step 4: Re-run service tests**

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Feature/RecipeFormulaItemLimitTest.php tests/Feature/InterfaceTranslationCatalogueTest.php
```

- [ ] **Step 5: Commit the authoritative policy**

```bash
git add app/Services/EntitlementService.php app/Services/RecipeFormulaItemLimitService.php lang/en/workbench.php database/seeders/data/interface-translations.json tests/Feature/InterfaceTranslationCatalogueTest.php tests/Feature/RecipeFormulaItemLimitTest.php
git diff --cached --check
git commit -m "feat: enforce normalized formula line policies"
```

## Task 3: Enforce the limit before every recipe structure mutation

**Files:**

- Modify: `app/Services/RecipeWorkbenchService.php`
- Modify: `tests/Feature/RecipeFormulaItemLimitTest.php`
- Modify: `tests/Feature/RecipeWorkbenchDraftLifecycleTest.php`
- Modify: `tests/Feature/RecipeWorkbenchPersistenceTest.php`
- Modify: `tests/Feature/CosmeticRecipeWorkbenchTest.php`

- [ ] **Step 1: Add failing entry-point and non-destructive failure tests**

Exercise the real service entry points, not only the limit service:

- `save()` for new and existing recipes;
- `publish()` for new and existing recipes;
- `duplicate()` and `duplicateRecipe()`;
- `restoreCurrentVersion()`;
- `restorePublishedFormula()`.

For an over-limit update and restore, snapshot the current recipe version/phase/item IDs before the call, assert a `ValidationException`, then assert the same IDs and current-version flags remain. This proves validation happens before destructive replacement.

- [ ] **Step 2: Run the focused lifecycle tests and confirm RED**

```bash
php artisan test --compact tests/Feature/RecipeFormulaItemLimitTest.php tests/Feature/RecipeWorkbenchDraftLifecycleTest.php tests/Feature/RecipeWorkbenchPersistenceTest.php tests/Feature/CosmeticRecipeWorkbenchTest.php --filter="formula line|limit|duplicate|restore"
```

- [ ] **Step 3: Inject the policy and call it immediately after normalization**

Inject `RecipeFormulaItemLimitService` into `RecipeWorkbenchService`'s existing promoted constructor.

In `save()` and `publish()`, after payload normalization and before any saver/publisher closure is built:

```php
$recipe instanceof Recipe
    ? $this->recipeFormulaItemLimitService->assertUpdateAllowed($user, $normalizedPayload, $recipe)
    : $this->recipeFormulaItemLimitService->assertCreateAllowed($user, $normalizedPayload);
```

`duplicate()` remains strict because it calls `save()` without a destination recipe. Before `restoreCurrentVersion()` delegates to `save()`, normalize the target and call `assertRestoreAllowed()` so restore cannot inherit the more permissive edit rule. Call the same strict method in `restorePublishedFormula()` after normalization and before `RecipeVersionPublisher::restore()`.

Do not put entitlement logic inside `RecipeVersionStructureSynchronizer`; it must remain a persistence mechanism and must only receive already-authorized normalized data.

- [ ] **Step 4: Re-run lifecycle tests**

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Feature/RecipeFormulaItemLimitTest.php tests/Feature/RecipeWorkbenchDraftLifecycleTest.php tests/Feature/RecipeWorkbenchPersistenceTest.php tests/Feature/CosmeticRecipeWorkbenchTest.php --filter="formula line|limit|duplicate|restore"
```

- [ ] **Step 5: Commit server enforcement**

```bash
git add app/Services/RecipeWorkbenchService.php tests/Feature/RecipeFormulaItemLimitTest.php tests/Feature/RecipeWorkbenchDraftLifecycleTest.php tests/Feature/RecipeWorkbenchPersistenceTest.php tests/Feature/CosmeticRecipeWorkbenchTest.php
git diff --cached --check
git commit -m "feat: guard every formula line mutation"
```

## Task 4: Surface the plan limit and current count in the workbench

**Files:**

- Modify: `app/Services/RecipeWorkbenchViewDataBuilder.php`
- Modify: `resources/js/recipe-workbench/component.js`
- Modify: `resources/views/livewire/dashboard/partials/recipe-workbench/formula-tab.blade.php`
- Modify: `resources/views/livewire/dashboard/partials/recipe-workbench/ingredient-browser.blade.php`
- Modify: `lang/en/workbench.php`
- Modify: `database/seeders/data/interface-translations.json`
- Modify: `tests/Feature/RecipeWorkbenchViewDataBuilderTest.php`
- Modify: `tests/Feature/RecipeWorkbenchPersistenceTest.php`
- Modify: `tests/Feature/InterfaceTranslationCatalogueTest.php`

- [ ] **Step 1: Add failing payload, Blade contract, and Node behavior tests**

Assert an authenticated free-plan user receives `formulaItemLimit = 30`, a paid user receives 50, an unlimited plan receives null, and an unauthenticated public workbench receives null.

Add a Node test using the file's established test harness. It must prove:

- `formulaItemCount()` sums rows across all phases;
- existing loaded rows above the limit remain present;
- `addIngredient()` does not append a new row at the limit;
- removing a row re-enables adding;
- duplicate-within-phase behavior is unchanged;
- the limit zero case blocks the first line;
- null remains unlimited.

Assert Blade includes a compact count and an accessible translated warning, and all add buttons have reactive disabled/ARIA state.

- [ ] **Step 2: Run focused workbench tests and confirm RED**

```bash
php artisan test --compact tests/Feature/RecipeWorkbenchViewDataBuilderTest.php tests/Feature/RecipeWorkbenchPersistenceTest.php tests/Feature/InterfaceTranslationCatalogueTest.php --filter="formula item|ingredient line|plan limit"
```

- [ ] **Step 3: Add the limit to view data and client state**

Inject `RecipeFormulaItemLimitService` into `RecipeWorkbenchViewDataBuilder` and add:

```php
'formulaItemLimit' => $user instanceof User
    ? $this->recipeFormulaItemLimitService->limitFor($user)
    : null,
```

In `component.js`, initialize `formulaItemLimit`, `formulaItemLimitMessage`, and methods:

```js
formulaItemCount() {
    return Object.values(this.phaseItems ?? {})
        .reduce((total, rows) => total + (Array.isArray(rows) ? rows.length : 0), 0);
},

formulaItemLimitReached() {
    return this.formulaItemLimit !== null
        && this.formulaItemCount() >= this.formulaItemLimit;
},
```

In `addIngredient()`, retain phase and duplicate checks first; before pushing a genuinely new row, stop when the limit is reached and set the translated message. Clear the message when a row removal places the formula below the limit.

Display `:count / :limit ingredient lines` when limited and `:count ingredient lines` when unlimited. Disable each direct add button and phase-menu add option with `:disabled`, `:aria-disabled`, and existing disabled styling. Do not hide ingredients or remove existing rows.

Add English and DE/ES/FR/IT/NL catalogue entries for count singular/plural, limited count, limit-reached guidance, and server error. Preserve placeholders exactly in every locale.

- [ ] **Step 4: Run focused UI tests**

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Feature/RecipeWorkbenchViewDataBuilderTest.php tests/Feature/RecipeWorkbenchPersistenceTest.php tests/Feature/InterfaceTranslationCatalogueTest.php --filter="formula item|ingredient line|plan limit"
```

- [ ] **Step 5: Commit the workbench feedback**

```bash
git add app/Services/RecipeWorkbenchViewDataBuilder.php resources/js/recipe-workbench/component.js resources/views/livewire/dashboard/partials/recipe-workbench/formula-tab.blade.php resources/views/livewire/dashboard/partials/recipe-workbench/ingredient-browser.blade.php lang/en/workbench.php database/seeders/data/interface-translations.json tests/Feature/RecipeWorkbenchViewDataBuilderTest.php tests/Feature/RecipeWorkbenchPersistenceTest.php tests/Feature/InterfaceTranslationCatalogueTest.php
git diff --cached --check
git commit -m "feat: show formula line capacity in workbench"
```

## Task 5: Run cross-cutting verification and review

**Files:**

- Modify only files required to address confirmed findings from the checks below

- [ ] **Step 1: Run the complete entitlement and workbench regression set**

```bash
php artisan test --compact tests/Feature/EntitlementLimitsTest.php tests/Feature/RecipeFormulaItemLimitTest.php tests/Feature/RecipeWorkbenchViewDataBuilderTest.php tests/Feature/RecipeWorkbenchDraftLifecycleTest.php tests/Feature/RecipeWorkbenchPersistenceTest.php tests/Feature/CosmeticRecipeWorkbenchTest.php tests/Feature/Filament/CatalogResourcesTest.php tests/Feature/InterfaceTranslationCatalogueTest.php
```

- [ ] **Step 2: Run framework and repository quality gates**

```bash
vendor/bin/filacheck --fix
vendor/bin/pint --dirty --format agent
graphify update .
git diff --check
```

- [ ] **Step 3: Perform targeted manual smoke checks**

Using the existing Herd site, verify:

- Admin → Plans can set free to 30, paid to 50, zero, and empty/unlimited;
- a 30-line free formula shows `30 / 30` and cannot add a 31st row;
- a paid formula can add rows through 50;
- the same ingredient in two phases consumes two lines;
- packaging changes do not affect the count;
- an intentionally grandfathered over-limit formula opens unchanged and can shrink;
- duplicate and restore show the translated server error when over limit.

- [ ] **Step 4: Request and address code review**

Use `superpowers:requesting-code-review`. Confirm reviewers specifically inspect normalization-before-counting, current-version baseline queries, restore strictness, pre-delete validation order, workspace subscriber entitlement selection, translation placeholders, and all null/zero semantics. Fix every confirmed high/medium issue and rerun its focused tests.

- [ ] **Step 5: Commit final verified corrections**

If review changes were required, stage each reviewed file by its explicit path, run `git diff --cached --check`, and commit with `git commit -m "fix: harden formula line plan limits"`. If no files changed, do not create an empty commit.

## Acceptance checklist

- [ ] Free defaults to 30 and paid defaults to 50 without overwriting admin edits.
- [ ] Admin can set an integer, zero, or empty/unlimited in the existing Plan settings.
- [ ] The server counts normalized formula rows across phases and excludes packaging/calculated-only data.
- [ ] Every save, publish, duplicate, and restore path is protected before destructive persistence.
- [ ] Grandfathered over-limit formulas remain usable without allowing growth.
- [ ] Workbench count and add-button behavior match the server limit for free, paid, zero, and unlimited plans.
- [ ] Public non-persisted calculator behavior is unchanged.
