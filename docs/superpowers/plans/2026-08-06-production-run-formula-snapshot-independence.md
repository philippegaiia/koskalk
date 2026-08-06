# Production Run Formula Snapshot Independence Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make each Production Bench production independently preserve its complete manufactured formula while retaining an optional, useful relationship to the current product and allowing normal formula-version pruning.

**Architecture:** `Recipe` remains the live product relationship, but `ProductionRun` becomes the historical aggregate root. A new `ProductionFormulaLine` table stores the complete manufactured formula, including calculated NaOH/KOH/water, while `ProductionRequirement` remains limited to stock-linked ingredient and packaging demand. Published `RecipeVersion` data is read once during creation or legacy backfill; quantity corrections rescale the production's own snapshots and never reopen a formula version.

**Tech Stack:** PHP 8.5, Laravel 13, Livewire 4, Blade, Alpine.js, Tailwind CSS 4, Pest 4, BCMath, PostgreSQL/SQLite migrations, and the existing Workbench soap-calculation services.

**Design authority:** [Production Run Formula Snapshot Independence Design](../specs/2026-08-06-production-run-formula-snapshot-independence-design.md). It supersedes the permanent production-to-`RecipeVersion` pin in the planning sections of the August 4 production design.

**Working-tree safety:** At plan authoring time, the branch contains uncommitted product-deletion/version-retention guards and an unrelated ingredient-table change. Preserve `app/Filament/Resources/Ingredients/Tables/IngredientsTable.php`. Replace the temporary production deletion/version-retention guards only in the tasks that explicitly name them. Stage files path-by-path.

---

## Product invariants

- A production uses a published formula once, when its snapshot is created.
- `recipe_id` remains the normal product-to-productions relationship while the product exists.
- `recipe_name_snapshot` is the historical display name and does not follow later renames.
- A complete production snapshot is readable without either `Recipe` or `RecipeVersion`.
- Formula lines answer “what was planned to be manufactured?” Requirements answer “what stock must be supplied or reserved?”
- Soap formula lines include explicit ingredients plus calculated NaOH, KOH, and water.
- Packaging remains a stock requirement, not a formula line.
- Draft and Planned productions without active reservations may be corrected by rescaling their own snapshots.
- Requirement IDs remain stable during rescaling so released/cancelled reservation audit rows survive.
- Stock reservation freezes formula and requirement quantities. Permanent batch-number assignment does not.
- A later Workbench publication never changes an existing production.
- The selected/default task set is pinned to the production before tasks need to be generated.
- Products with production history are archived through the normal UI; fully snapshotted history survives exceptional hard deletion.
- Normal formula-version retention ignores completed production snapshots and protects only transitional incomplete snapshots.
- No translated-name matching creates stock links for NaOH, KOH, or water.

## Data contract

### `production_runs` additions

- `recipe_name_snapshot`: non-empty historical product name.
- `source_formula_version_number`: nullable positive integer audit value.
- `formula_context_snapshot`: nullable JSON during transition, then required for newly created productions. Keys are `calculation_basis`, `lye_type`, `superfat_percentage`, `water_mode`, and `water_value`; cosmetic values unrelated to the formula family are `null`.
- `formula_snapshot_completed_at`: nullable timestamp; non-null means production no longer needs a source version.
- `recipe_id`: nullable foreign key with `nullOnDelete`.
- `recipe_version_id`: nullable transitional foreign key with `nullOnDelete`.

### `production_formula_lines`

- `production_run_id`: required, cascade on production deletion.
- `ingredient_id`: nullable navigation link, `nullOnDelete`.
- `recipe_item_id`: nullable source link, `nullOnDelete`.
- `component`: `ingredient`, `naoh`, `koh`, or `water`.
- `subject_name_snapshot`, `phase_key_snapshot`, `phase_name_snapshot`.
- `basis_percentage_snapshot`: positive percentage relative to `ProductionRun.basis_quantity_grams`.
- `planned_mass_grams`: positive canonical grams.
- `note_snapshot`: nullable line note.
- `sort_order`: positive integer unique within the production.

Do not add SOP, description, images, formula costing, or packaging to formula lines.

### `production_requirements` addition

- `note_snapshot`: nullable source formula-line note for stock preparation and formula snapshot construction.

---

### Task 1: Add the independent production-snapshot schema

**Files:**

- Create: `app/ProductionFormulaComponent.php`
- Create: `app/Models/ProductionFormulaLine.php`
- Create: `database/factories/ProductionFormulaLineFactory.php`
- Create: `database/migrations/2026_08_06_120000_add_independent_formula_snapshots_to_production_runs.php`
- Modify: `app/Models/ProductionRun.php`
- Modify: `app/Models/Recipe.php`
- Modify: `app/Models/RecipeVersion.php`
- Modify: `database/factories/ProductionRunFactory.php`
- Modify: `tests/Feature/ProductionPlanningSchemaTest.php`

- [ ] **Step 1: Write failing schema and relationship tests**

Add tests proving that snapshot header columns exist, recipe/version links accept `NULL`, deleting a recipe/version sets the corresponding link to `NULL`, formula lines survive those deletions, component enum values are exact, and invalid/non-positive formula-line quantities are rejected.

Use assertions with this shape:

```php
expect(array_column(ProductionFormulaComponent::cases(), 'value'))->toBe([
    'ingredient',
    'naoh',
    'koh',
    'water',
]);

$line = ProductionFormulaLine::factory()->for($production, 'productionRun')->create([
    'component' => ProductionFormulaComponent::Ingredient,
    'basis_percentage_snapshot' => '25.000000000',
    'planned_mass_grams' => '2500.000000000',
]);

expect($production->formulaLines()->sole()->is($line))->toBeTrue();
```

- [ ] **Step 2: Run the focused schema test and verify RED**

Run:

```bash
php artisan test --compact tests/Feature/ProductionPlanningSchemaTest.php
```

Expected: FAIL because `ProductionFormulaComponent`, `ProductionFormulaLine`, and the new columns/table do not exist.

- [ ] **Step 3: Generate and implement the schema**

Generate the model/factory and migration with `php artisan make:model ProductionFormulaLine --factory --no-interaction` and `php artisan make:migration add_independent_formula_snapshots_to_production_runs --no-interaction`. Implement the migration in three ordered schema operations:

```php
Schema::table('production_runs', function (Blueprint $table): void {
    $table->string('recipe_name_snapshot')->nullable()->after('recipe_version_id');
    $table->unsignedInteger('source_formula_version_number')->nullable()->after('recipe_name_snapshot');
    $table->json('formula_context_snapshot')->nullable()->after('source_formula_version_number');
    $table->timestamp('formula_snapshot_completed_at')->nullable()->after('formula_context_snapshot');

    $table->dropForeign(['recipe_id']);
    $table->dropForeign(['recipe_version_id']);
    $table->foreignId('recipe_id')->nullable()->change();
    $table->foreignId('recipe_version_id')->nullable()->change();
    $table->foreign('recipe_id')->references('id')->on('recipes')->nullOnDelete();
    $table->foreign('recipe_version_id')->references('id')->on('recipe_versions')->nullOnDelete();
});

Schema::create('production_formula_lines', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('production_run_id')->constrained()->cascadeOnDelete();
    $table->foreignId('ingredient_id')->nullable()->constrained()->nullOnDelete();
    $table->foreignId('recipe_item_id')->nullable()->constrained()->nullOnDelete();
    $table->string('component', 24);
    $table->string('subject_name_snapshot');
    $table->string('phase_key_snapshot');
    $table->string('phase_name_snapshot');
    $table->decimal('basis_percentage_snapshot', 20, 9);
    $table->decimal('planned_mass_grams', 20, 9);
    $table->text('note_snapshot')->nullable();
    $table->unsignedInteger('sort_order');
    $table->timestamps();
    $table->unique(['production_run_id', 'sort_order']);
    $table->index(['ingredient_id', 'production_run_id']);
});

Schema::table('production_requirements', function (Blueprint $table): void {
    $table->text('note_snapshot')->nullable()->after('unit_snapshot');
});
```

Add PostgreSQL checks and equivalent SQLite triggers for the four component values, positive percentage/mass, and positive sort order. Do not require `ingredient_id` for an ingredient component because `nullOnDelete` must preserve historical lines.

- [ ] **Step 4: Implement enum, model, factory, casts, and relationships**

`ProductionRun` receives `formulaLines(): HasMany`, the new fillable attributes, JSON/datetime/integer casts, and a `displayRecipeName()` helper:

```php
public function displayRecipeName(): string
{
    return $this->recipe_name_snapshot
        ?? $this->recipe?->name
        ?? __('production_bench.production.unknown_product');
}
```

Keep `Recipe::productionRuns()` and `RecipeVersion::productionRuns()` during the transition.

- [ ] **Step 5: Verify Task 1 and commit**

```bash
php artisan test --compact tests/Feature/ProductionPlanningSchemaTest.php
vendor/bin/pint --dirty --format agent
git add app/ProductionFormulaComponent.php app/Models/ProductionFormulaLine.php app/Models/ProductionRun.php app/Models/Recipe.php app/Models/RecipeVersion.php database/factories/ProductionFormulaLineFactory.php database/factories/ProductionRunFactory.php database/migrations/2026_08_06_120000_add_independent_formula_snapshots_to_production_runs.php tests/Feature/ProductionPlanningSchemaTest.php
git commit -m "feat: add independent production formula snapshots"
```

Expected: focused test PASS.

---

### Task 2: Build complete formula snapshots, including lye and water

**Files:**

- Create: `app/Services/Production/ProductionFormulaSnapshotBuilder.php`
- Modify: `app/Services/Production/ProductionRequirementBuilder.php`
- Modify: `app/Models/ProductionRequirement.php`
- Modify: `database/factories/ProductionRequirementFactory.php`
- Modify: `database/migrations/2026_08_06_120000_add_independent_formula_snapshots_to_production_runs.php`
- Create: `tests/Feature/ProductionFormulaSnapshotTest.php`
- Modify: `tests/Feature/ProductionPlanningTest.php`

- [ ] **Step 1: Write failing formula snapshot tests**

Cover these exact cases:

- NaOH soap: oils/additions plus NaOH and water lines, no KOH line.
- KOH soap: KOH and water lines, no NaOH line.
- Dual-lye soap: NaOH, KOH, and water lines.
- Cosmetic: all phase ingredients and no calculated lye/water lines.
- Production basis different from the saved formula basis: all masses scale correctly.
- Ingredient line note, phase, ingredient ID, percentage, and order are copied.
- Context contains lye/water settings but never SOP text.
- A missing or invalid Workbench calculation rejects snapshot creation atomically.

Assert that a `14 kg` oils production based on a `75% / 25%` soap formula produces `10,500 g` and `3,500 g` oil lines and positive calculated lye/water lines.

- [ ] **Step 2: Run the new test and verify RED**

```bash
php artisan test --compact tests/Feature/ProductionFormulaSnapshotTest.php
```

Expected: FAIL because the snapshot builder does not exist and current requirements omit calculated components.

- [ ] **Step 3: Populate line notes in requirement-builder output**

Extend ingredient requirement arrays with `note_snapshot => $item->note`, and set packaging `note_snapshot` from the saved packaging-plan note. Update model fillable/factory/test coverage. The column was created in Task 1 so stock detail and formula construction share the same source fact.

- [ ] **Step 4: Implement `ProductionFormulaSnapshotBuilder`**

Use this public contract:

```php
/**
 * @param Collection<int, array<string, mixed>> $requirements
 * @return array{context: array<string, mixed>, lines: Collection<int, array<string, mixed>>}
 */
public function build(
    Recipe $recipe,
    RecipeVersion $version,
    string $basisQuantityGrams,
    Collection $requirements,
): array;
```

Implementation rules:

1. Convert ingredient requirements into formula lines in their existing phase/sort order.
2. For soap, load the saved snapshot through `RecipeWorkbenchService::versionSnapshot()`.
3. Convert the requested canonical grams into the saved draft unit with `MassConverter::fromGrams()`.
4. Replace only `draft.oilWeight`, then call `RecipeWorkbenchService::snapshotFromWorkbenchDraft()` so the established calculator recomputes lye and water.
5. Read `calculation.lye.selected.naoh_weight`, `koh_to_weigh`, and `calculation.lye.water.weight`; convert each positive result back to canonical grams.
6. Append calculated lines in phase `lye_water` with stable component order NaOH, KOH, Water.
7. Compute `basis_percentage_snapshot` with BCMath from canonical mass divided by production basis.
8. Build context from stable keys, never rendered labels or SOP.

Use translation keys only for `subject_name_snapshot`; component identity remains the enum value.

- [ ] **Step 5: Verify Task 2 and commit**

```bash
php artisan test --compact tests/Feature/ProductionFormulaSnapshotTest.php tests/Feature/ProductionPlanningTest.php
vendor/bin/pint --dirty --format agent
git add app/Services/Production/ProductionFormulaSnapshotBuilder.php app/Services/Production/ProductionRequirementBuilder.php app/Models/ProductionRequirement.php database/factories/ProductionRequirementFactory.php database/migrations/2026_08_06_120000_add_independent_formula_snapshots_to_production_runs.php tests/Feature/ProductionFormulaSnapshotTest.php tests/Feature/ProductionPlanningTest.php
git commit -m "feat: snapshot complete production formulas"
```

Expected: both files PASS.

---

### Task 3: Create productions atomically from the independent snapshot

**Files:**

- Modify: `app/Actions/Production/CreateProductionDraft.php`
- Modify: `app/Actions/Production/PlanProduction.php`
- Modify: `tests/Feature/ProductionPlanningTest.php`
- Modify: `tests/Feature/ProductionBenchProductionCreateTest.php`
- Modify: `tests/Feature/GenerateFlashProductionsTest.php`

- [ ] **Step 1: Write failing creation and idempotency tests**

Prove direct and Flash creation each persist, in the same transaction:

- product name and source version-number snapshots;
- formula context;
- complete formula lines;
- stock requirements;
- `formula_snapshot_completed_at`;
- explicitly selected task-set ID;
- no duplicate lines on an idempotent retry.

Also force the formula builder to throw and assert zero `ProductionRun`, `ProductionFormulaLine`, `ProductionRequirement`, and task rows remain.

- [ ] **Step 2: Run focused tests and verify RED**

```bash
php artisan test --compact tests/Feature/ProductionPlanningTest.php tests/Feature/ProductionBenchProductionCreateTest.php tests/Feature/GenerateFlashProductionsTest.php
```

Expected: FAIL because creation does not persist the new snapshots.

- [ ] **Step 3: Wire snapshot creation into `CreateProductionDraft`**

Inject `ProductionFormulaSnapshotBuilder`. After requirements are built and before the run is created, build the formula snapshot. Persist:

```php
'recipe_name_snapshot' => $lockedRecipe->name,
'source_formula_version_number' => $publishedVersion->version_number,
'formula_context_snapshot' => $formulaSnapshot['context'],
'formula_snapshot_completed_at' => now(),
```

Then create requirements and formula lines inside the existing retried transaction. Return the production loaded with `requirements`, `formulaLines`, and `tasks` where applicable.

- [ ] **Step 4: Keep the version FK transitional only**

New runs may temporarily store `recipe_version_id` for backfill diagnostics, but no new reader or mutation may require it. Add a test that nulls `recipe_version_id` immediately after planning and still renders/loads the production aggregate.

- [ ] **Step 5: Verify Task 3 and commit**

```bash
php artisan test --compact tests/Feature/ProductionPlanningTest.php tests/Feature/ProductionBenchProductionCreateTest.php tests/Feature/GenerateFlashProductionsTest.php
vendor/bin/pint --dirty --format agent
git add app/Actions/Production/CreateProductionDraft.php app/Actions/Production/PlanProduction.php tests/Feature/ProductionPlanningTest.php tests/Feature/ProductionBenchProductionCreateTest.php tests/Feature/GenerateFlashProductionsTest.php
git commit -m "feat: create self-contained production plans"
```

---

### Task 4: Rescale snapshots in place without reopening formula versions

**Files:**

- Create: `app/Services/Production/ProductionSnapshotRescaler.php`
- Modify: `app/Actions/Production/UpdateProductionPlan.php`
- Modify: `tests/Feature/ProductionPlanningTest.php`
- Modify: `tests/Feature/ProductionStockPreparationTest.php`

- [ ] **Step 1: Write failing rescaling and reservation-history tests**

Create a Planned production, reserve and release stock, then correct its batch quantity. Assert:

- the same requirement IDs remain;
- released reservation rows remain;
- ingredient requirement masses equal new basis × snapshotted percentage;
- packaging units equal `ceil(expected_units × components_per_unit_snapshot)`;
- every formula-line mass equals new basis × `basis_percentage_snapshot`;
- calculated NaOH/KOH/water lines rescale;
- `recipe_version_id = null` and a deleted source version do not affect correction;
- active reservations and Reserved/In production/Completed/Cancelled/Aborted states reject correction.

- [ ] **Step 2: Run focused tests and verify RED**

```bash
php artisan test --compact tests/Feature/ProductionPlanningTest.php tests/Feature/ProductionStockPreparationTest.php
```

Expected: FAIL because `UpdateProductionPlan` reloads the pinned version and deletes requirements.

- [ ] **Step 3: Implement the rescaler**

Use BCMath only. The service receives a locked production, new canonical basis grams, and new expected units. It locks formula lines and requirements in ID order and updates quantities in place:

```php
$mass = bcdiv(
    bcmul($basisQuantityGrams, (string) $line->basis_percentage_snapshot, 18),
    '100',
    18,
);

$line->update(['planned_mass_grams' => $this->roundStorage($mass)]);
```

Ingredient requirements use `percentage_snapshot`; packaging requirements use exact ceiling arithmetic on `components_per_unit_snapshot`. Reject a malformed legacy snapshot rather than falling back to a live version.

- [ ] **Step 4: Replace version rebuilding in `UpdateProductionPlan`**

Remove recipe and recipe-version queries from the update action. Preserve the production's snapshotted `basis_kind`, because the product family may no longer exist. Keep the current workspace, writable-bench, status, and active-reservation validations.

Do not call `$lockedProduction->requirements()->delete()`.

- [ ] **Step 5: Verify Task 4 and commit**

```bash
php artisan test --compact tests/Feature/ProductionPlanningTest.php tests/Feature/ProductionStockPreparationTest.php
vendor/bin/pint --dirty --format agent
git add app/Services/Production/ProductionSnapshotRescaler.php app/Actions/Production/UpdateProductionPlan.php tests/Feature/ProductionPlanningTest.php tests/Feature/ProductionStockPreparationTest.php
git commit -m "fix: rescale production snapshots in place"
```

---

### Task 5: Remove the delayed product dependency from task generation

**Files:**

- Modify: `app/Actions/Production/CreateProductionDraft.php`
- Modify: `app/Actions/Production/GenerateProductionTasks.php`
- Modify: `app/Livewire/ProductionBench/Production/ProductionCreate.php`
- Modify: `tests/Feature/ProductionTaskSchemaTest.php`
- Modify: `tests/Feature/ProductionPlanningTest.php`

- [ ] **Step 1: Write failing task-set independence tests**

Cover:

- omitted task set resolves the product's active default during production creation;
- explicitly selected applicable set wins;
- no default leaves `production_task_set_id` null;
- archiving or deleting the product after creation does not prevent generation from the stored set;
- task names, colour, department, duration, offset, and date remain snapshots;
- a deleted stored set before generation produces a clear validation message rather than querying the product default.

- [ ] **Step 2: Run focused tests and verify RED**

```bash
php artisan test --compact tests/Feature/ProductionTaskSchemaTest.php tests/Feature/ProductionPlanningTest.php
```

Expected: FAIL because `GenerateProductionTasks::resolveTaskSet()` still loads the recipe.

- [ ] **Step 3: Resolve and persist the task set at creation**

In `CreateProductionDraft`, resolve the provided set or active default while the locked recipe exists, validate applicability, and store its ID. Keep the UI preselection behavior, but do not rely on it for correctness.

- [ ] **Step 4: Simplify task generation**

`GenerateProductionTasks::resolveTaskSet()` must use only `production_task_set_id` and workspace ID. Remove its `Recipe` query and default relationship lookup. Existing generated tasks remain unchanged.

- [ ] **Step 5: Verify Task 5 and commit**

```bash
php artisan test --compact tests/Feature/ProductionTaskSchemaTest.php tests/Feature/ProductionPlanningTest.php
vendor/bin/pint --dirty --format agent
git add app/Actions/Production/CreateProductionDraft.php app/Actions/Production/GenerateProductionTasks.php app/Livewire/ProductionBench/Production/ProductionCreate.php tests/Feature/ProductionTaskSchemaTest.php tests/Feature/ProductionPlanningTest.php
git commit -m "fix: pin production task sets at planning"
```

---

### Task 6: Render production history only from snapshots

**Files:**

- Modify: `app/Livewire/ProductionBench/Production/ProductionDetail.php`
- Modify: `app/Livewire/ProductionBench/Production/ProductionIndex.php`
- Modify: `app/Livewire/ProductionBench/Production/ProductionCalendar.php`
- Modify: `app/Livewire/ProductionBench/Production/TaskIndex.php`
- Modify: `app/Livewire/ProductionBench/Production/StockPreparation.php`
- Modify: `resources/views/livewire/production-bench/production/production-detail.blade.php`
- Modify: `resources/views/livewire/production-bench/production/production-index.blade.php`
- Modify: `resources/views/livewire/production-bench/production/stock-preparation.blade.php`
- Modify: `lang/en/production_bench.php`
- Modify: `database/seeders/data/interface-translations.json`
- Modify: `tests/Feature/ProductionBenchProductionsTest.php`
- Modify: `tests/Feature/ProductionBenchProductionCalendarTest.php`
- Modify: `tests/Feature/ProductionBenchStockPreparationTest.php`

- [ ] **Step 1: Write failing snapshot-only rendering tests**

Plan a production, delete/null its source recipe version, rename its live recipe, and assert list/detail/calendar/task/stock preparation show the original `recipe_name_snapshot`. Then delete the recipe and assert the same screens still render without `unknown product` or a query exception.

Assert detail groups formula lines by phase and includes calculated lye/water. Requirements remain a separate section for stock and packaging.

- [ ] **Step 2: Run focused UI tests and verify RED**

```bash
php artisan test --compact tests/Feature/ProductionBenchProductionsTest.php tests/Feature/ProductionBenchProductionCalendarTest.php tests/Feature/ProductionBenchStockPreparationTest.php
```

Expected: FAIL because readers still use `$production->recipe?->name` and detail still displays the mandatory formula version.

- [ ] **Step 3: Replace live-name readers**

Use `displayRecipeName()` everywhere. Eager-load `formulaLines` on detail only. Remove `recipeVersion` eager loading and replace the prominent version card with optional quiet audit text from `source_formula_version_number`.

Production search must query `recipe_name_snapshot` directly; do not require `orWhereHas('recipe')`.

- [ ] **Step 4: Build the formula section**

Render phase-grouped formula lines in stable order. Show workspace-localized mass units and adaptive decimals. Display line note only when present. Show formula context for soap in compact language and keep SOP absent.

- [ ] **Step 5: Translate contextually**

Add keys for Formula, Source formula version, Sodium hydroxide, Potassium hydroxide, Water, Archived product, Formula snapshot unavailable, and related production counts. Update every supported locale in `interface-translations.json` with domain-appropriate wording rather than literal English order.

- [ ] **Step 6: Verify Task 6 and commit**

```bash
php artisan test --compact tests/Feature/ProductionBenchProductionsTest.php tests/Feature/ProductionBenchProductionCalendarTest.php tests/Feature/ProductionBenchStockPreparationTest.php
npm run build
git add app/Livewire/ProductionBench/Production/ProductionDetail.php app/Livewire/ProductionBench/Production/ProductionIndex.php app/Livewire/ProductionBench/Production/ProductionCalendar.php app/Livewire/ProductionBench/Production/TaskIndex.php app/Livewire/ProductionBench/Production/StockPreparation.php resources/views/livewire/production-bench/production/production-detail.blade.php resources/views/livewire/production-bench/production/production-index.blade.php resources/views/livewire/production-bench/production/stock-preparation.blade.php lang/en/production_bench.php database/seeders/data/interface-translations.json tests/Feature/ProductionBenchProductionsTest.php tests/Feature/ProductionBenchProductionCalendarTest.php tests/Feature/ProductionBenchStockPreparationTest.php
git commit -m "feat: render production formula snapshots"
```

---

### Task 7: Replace hard-delete blocking with archive-first product history

**Files:**

- Modify: `routes/web.php`
- Modify: `app/Http/Controllers/RecipeController.php`
- Modify: `app/Livewire/Dashboard/RecipesIndex.php`
- Modify: `app/Livewire/ProductionBench/Production/ProductionIndex.php`
- Modify: `resources/views/livewire/dashboard/recipes-index.blade.php`
- Modify: `resources/views/livewire/production-bench/production/production-index.blade.php`
- Modify: `resources/views/layouts/app-shell.blade.php`
- Modify: `lang/en/products.php`
- Modify: `database/seeders/data/interface-translations.json`
- Modify: `tests/Feature/RecipeDeletionTest.php`
- Modify: `tests/Feature/RecipesIndexLocalizationTest.php`
- Modify: `tests/Feature/AppNotificationTest.php`
- Modify: `tests/Feature/ProductionBenchProductionsTest.php`

- [ ] **Step 1: Replace temporary guard tests with failing lifecycle tests**

Replace “product used by production cannot be deleted” expectations with:

- normal removal archives a product that has production history;
- archive keeps `recipe_id` and `Recipe::productionRuns()` intact;
- archived products are excluded from new production selectors;
- archived filter shows them and Restore reactivates them;
- product card shows production count linked to Production list filtered by recipe public ID;
- exceptional permanent deletion of a fully snapshotted archived product nulls `recipe_id` and preserves production history;
- permanent deletion is blocked if any related production snapshot is incomplete;
- a product without production history follows the existing typed-name permanent deletion flow.

- [ ] **Step 2: Run focused tests and verify RED**

```bash
php artisan test --compact tests/Feature/RecipeDeletionTest.php tests/Feature/RecipesIndexLocalizationTest.php tests/Feature/AppNotificationTest.php tests/Feature/ProductionBenchProductionsTest.php
```

Expected: FAIL because the current temporary guard disables deletion and no archive/restore workflow exists.

- [ ] **Step 3: Add explicit archive and restore actions**

Add named POST routes `recipes.archive` and `recipes.restore`. Controller actions authorize `update`, lock the recipe, set/clear `archived_at`, and redirect with the standard toast/session notification system.

The existing DELETE route remains permanent deletion. For products with history it requires `archived_at` and all related runs to have `formula_snapshot_completed_at`; otherwise return a localized validation error.

- [ ] **Step 4: Update product index behavior**

Add an `active|archived` filter, `withCount('productionRuns')`, Archive/Restore actions, and a production-history link. Do not disable the action with a tooltip. The modal must say exactly what will happen: active formula leaves the Workbench, production history remains, and the product can be restored.

- [ ] **Step 5: Add the production product filter**

Add a URL-query-synchronized `recipe` filter to `ProductionIndex`. Filter by `recipe_id` when the recipe exists and keep free-text snapshot-name search. Product-card links use the recipe public ID and named route generation.

- [ ] **Step 6: Verify Task 7 and commit**

```bash
php artisan test --compact tests/Feature/RecipeDeletionTest.php tests/Feature/RecipesIndexLocalizationTest.php tests/Feature/AppNotificationTest.php tests/Feature/ProductionBenchProductionsTest.php
vendor/bin/pint --dirty --format agent
git add routes/web.php app/Http/Controllers/RecipeController.php app/Livewire/Dashboard/RecipesIndex.php app/Livewire/ProductionBench/Production/ProductionIndex.php resources/views/livewire/dashboard/recipes-index.blade.php resources/views/livewire/production-bench/production/production-index.blade.php resources/views/layouts/app-shell.blade.php lang/en/products.php database/seeders/data/interface-translations.json tests/Feature/RecipeDeletionTest.php tests/Feature/RecipesIndexLocalizationTest.php tests/Feature/AppNotificationTest.php tests/Feature/ProductionBenchProductionsTest.php
git commit -m "feat: archive products while retaining production history"
```

---

### Task 8: Backfill legacy productions and correct formula-version retention

**Files:**

- Create: `app/Actions/Production/BackfillProductionFormulaSnapshot.php`
- Create: `app/Console/Commands/BackfillProductionFormulaSnapshots.php`
- Modify: `app/Services/RecipeVersionDeletionService.php`
- Modify: `tests/Feature/RecipeWorkbenchDraftLifecycleTest.php`
- Create: `tests/Feature/ProductionFormulaSnapshotBackfillTest.php`

- [ ] **Step 1: Write failing backfill and retention tests**

Cover:

- backfill creates complete context/formula lines for an existing run and is idempotent;
- an existing soap run gains NaOH/KOH/water, not just copied explicit requirements;
- a missing recipe/version leaves the run incomplete and reports its ID without partial lines;
- a completed snapshot does not protect a version from entitlement pruning or manual deletion;
- an incomplete transitional snapshot protects its source version;
- rerunning after correcting the missing source completes only the remaining run.

- [ ] **Step 2: Run focused tests and verify RED**

```bash
php artisan test --compact tests/Feature/ProductionFormulaSnapshotBackfillTest.php tests/Feature/RecipeWorkbenchDraftLifecycleTest.php
```

Expected: FAIL because no backfill action/command exists and the temporary retention rule protects every production-linked version.

- [ ] **Step 3: Implement an idempotent per-run backfill action**

Lock one run, return immediately when `formula_snapshot_completed_at` is non-null, require its current recipe/version links, load its existing requirements as read-only snapshot input, create all formula lines, populate header snapshots/context, and mark completion in one transaction. On failure, roll back the run entirely.

- [ ] **Step 4: Implement the command**

Command signature:

```php
protected $signature = 'production:backfill-formula-snapshots
    {--production-run= : Backfill one numeric production-run ID}
    {--chunk=100 : Number of productions per chunk}';
```

Process IDs in ascending order, print completed/skipped/failed counts, list failed IDs, return `Command::FAILURE` when any run remains incomplete, and never mutate completed snapshots.

- [ ] **Step 5: Narrow version protection to incomplete snapshots**

In both manual deletion and pruning, protect only a version having a production run where `formula_snapshot_completed_at IS NULL`. Remove the temporary broad `whereDoesntHave('productionRuns')` condition and replace it with an incomplete-snapshot predicate. Once backfill completes, ordinary retention applies.

- [ ] **Step 6: Verify Task 8 and commit**

```bash
php artisan test --compact tests/Feature/ProductionFormulaSnapshotBackfillTest.php tests/Feature/RecipeWorkbenchDraftLifecycleTest.php
vendor/bin/pint --dirty --format agent
git add app/Actions/Production/BackfillProductionFormulaSnapshot.php app/Console/Commands/BackfillProductionFormulaSnapshots.php app/Services/RecipeVersionDeletionService.php tests/Feature/ProductionFormulaSnapshotBackfillTest.php tests/Feature/RecipeWorkbenchDraftLifecycleTest.php
git commit -m "feat: backfill production formula snapshots"
```

After deployment, run:

```bash
php artisan production:backfill-formula-snapshots --chunk=100
```

Expected: exit code `0`, no failed IDs.

---

### Task 9: Regression, documentation reconciliation, and release verification

**Files:**

- Modify: `docs/superpowers/specs/2026-08-04-production-planning-execution-design.md`
- Modify: `docs/superpowers/plans/2026-07-28-production-bench-phase-3-planning-reservations.md`
- Modify: `docs/superpowers/plans/2026-08-05-production-review-fixes.md`
- Verify: all files changed by Tasks 1–8

- [ ] **Step 1: Reconcile old documentation**

Replace claims that a production permanently pins a `RecipeVersion` with links to the August 6 design. Preserve the rule that later formula publication never rewrites an existing production, but explain that this is enforced by owned formula snapshots rather than permanent version retention.

- [ ] **Step 2: Run the complete affected backend suite**

```bash
php artisan test --compact \
  tests/Feature/ProductionPlanningSchemaTest.php \
  tests/Feature/ProductionFormulaSnapshotTest.php \
  tests/Feature/ProductionFormulaSnapshotBackfillTest.php \
  tests/Feature/ProductionPlanningTest.php \
  tests/Feature/ProductionBenchProductionCreateTest.php \
  tests/Feature/GenerateFlashProductionsTest.php \
  tests/Feature/FlashProductionSimulatorTest.php \
  tests/Feature/ProductionTaskSchemaTest.php \
  tests/Feature/ProductionStockPreparationTest.php \
  tests/Feature/ProductionBenchProductionsTest.php \
  tests/Feature/ProductionBenchProductionCalendarTest.php \
  tests/Feature/ProductionBenchStockPreparationTest.php \
  tests/Feature/RecipeDeletionTest.php \
  tests/Feature/RecipeWorkbenchDraftLifecycleTest.php \
  tests/Feature/RecipesIndexLocalizationTest.php \
  tests/Feature/AppNotificationTest.php
```

Expected: all tests PASS.

- [ ] **Step 3: Run formatting, production build, and static Filament check when applicable**

```bash
vendor/bin/pint --dirty --format agent
npm run build
```

No `app/Filament` file belongs to this plan. If implementation nevertheless modifies an `app/Filament` file, run `vendor/bin/filacheck --fix` and fix every remaining report before proceeding.

- [ ] **Step 4: Run the full suite**

```bash
php artisan test --compact
```

Expected: PASS, or document only a proven pre-existing unrelated failure with its exact test name and evidence.

- [ ] **Step 5: Refresh the knowledge graph**

```bash
graphify update .
```

Expected: graph update completes and includes `ProductionFormulaLine`, `ProductionFormulaSnapshotBuilder`, and the revised relationship edges.

- [ ] **Step 6: Review database integrity on PostgreSQL**

Run the backfill command, then use Laravel Boost `database-query` to assert:

```sql
SELECT COUNT(*) AS incomplete
FROM production_runs
WHERE formula_snapshot_completed_at IS NULL;
```

Expected: `incomplete = 0` before enabling permanent deletion of archived products in production.

- [ ] **Step 7: Commit documentation and final verification adjustments**

```bash
git add docs/superpowers/specs/2026-08-04-production-planning-execution-design.md docs/superpowers/specs/2026-08-06-production-run-formula-snapshot-independence-design.md docs/superpowers/plans/2026-07-28-production-bench-phase-3-planning-reservations.md docs/superpowers/plans/2026-08-05-production-review-fixes.md docs/superpowers/plans/2026-08-06-production-run-formula-snapshot-independence.md
git commit -m "docs: finalize independent production snapshots"
```

---

## Review checkpoint

Demonstrate the following in one workspace:

1. A soap product with oils, an additive, fragrance, packaging, NaOH or dual lye, and a non-default water setting.
2. Plan a production and confirm the formula includes every explicit component plus calculated NaOH/KOH/water.
3. Confirm Inventory requirements remain limited to real stock-linked ingredients and packaging.
4. Publish a changed formula and rename the product; the existing production retains its original name and formula.
5. Correct the Planned quantity and verify formula/requirements rescale while requirement IDs and released reservation history remain.
6. Reserve stock and verify further quantity correction is rejected.
7. Prune/delete the source formula version and open list, detail, calendar, tasks, and stock preparation successfully.
8. Archive the product and navigate from its archived card to its filtered production history.
9. Permanently delete the fully snapshotted archived product and confirm the production remains readable with a null live link.
10. Run the legacy backfill twice and confirm the second pass performs no duplicate work.

## Explicitly deferred

- Mapping calculated NaOH/KOH/water lines to stock ingredients and supplier listings.
- Production execution, actual lot consumption, output lots, and actual historical cost.
- Copying SOP or media into production history.
- Automatic adoption of later formula changes.
- Merging Basic `ProductionBatch` snapshots with Production Bench `ProductionRun` records.
