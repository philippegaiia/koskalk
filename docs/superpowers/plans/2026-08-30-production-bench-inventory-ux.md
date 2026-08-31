# Production Bench Inventory UX Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn Production Bench Inventory into a searchable, sortable material-position table, a focused lot register, and an auditable material detail page with workspace buffer stock.

**Architecture:** Keep immutable stock movements, reservations, purchase-order lines, and production requirements authoritative. Extract read behavior from the large Livewire index into workspace-scoped inventory query services; add one association model for optional buffer quantities and one Action/Service write path. Reuse the existing table and semantic colour system, and route material history and complete lot history through dedicated, shareable pages.

**Tech Stack:** PHP 8.5, Laravel 13, Livewire 4, Filament 5 forms/actions, PostgreSQL production schema, SQLite-compatible Pest tests, Blade, Tailwind CSS 4, BCMath.

---

## File Map

### New files

- `app/Models/WorkspaceMaterialSetting.php` — one workspace/material association with an optional canonical buffer quantity.
- `database/factories/WorkspaceMaterialSettingFactory.php` — ingredient and packaging factory states.
- `database/migrations/2026_08_30_130000_create_workspace_material_settings_table.php` — exact-subject constraint and subject-specific uniqueness.
- `app/Actions/Inventory/SaveMaterialBuffer.php` — authorized command entry point from Livewire.
- `app/Services/Inventory/WorkspaceMaterialSettings.php` — normalize, persist, and clear buffer settings.
- `app/Services/Inventory/WorkspaceMaterialInventoryQuery.php` — server-side material identity, search, taxonomy filtering, derived position filtering, sorting, and pagination.
- `app/Services/Inventory/MaterialActivityService.php` — period totals, opening/closing balances, reconciliation groups, and source rows.
- `app/Livewire/ProductionBench/InventoryMaterialDetail.php` — material detail state, buffer action, period presets, and open-lot presentation.
- `resources/views/livewire/production-bench/inventory-material-detail.blade.php` — current position, buffer, open lots, and period reconciliation.
- `resources/views/production-bench/inventory-material-detail.blade.php` — app-shell wrapper for ingredient and packaging detail routes.
- `tests/Feature/WorkspaceMaterialSettingTest.php` — schema and write-path coverage.
- `tests/Feature/WorkspaceMaterialInventoryQueryTest.php` — query membership, search, filters, sort, position, and pagination coverage.
- `tests/Feature/ProductionBenchInventoryMaterialDetailTest.php` — route, authorization, current-position, buffer, open-lot, and navigation coverage.
- `tests/Feature/MaterialActivityServiceTest.php` — movement grouping and reconciliation coverage.

### Existing files to modify

- `app/Models/Workspace.php` — material-settings relationship.
- `app/Models/Ingredient.php` — workspace-settings relationship.
- `app/Models/PackagingItem.php` — material-setting relationship.
- `app/Services/Production/WorkspaceMaterialCatalog.php` — include lot-only and settings-only tracked subjects, or delegate membership to the new query.
- `app/Livewire/ProductionBench/InventoryIndex.php` — URL-bound filter state, query-service delegation, and lot-register filters.
- `resources/views/livewire/production-bench/inventory-index.blade.php` — revised table columns, filter UI, linked material names, and Lot register copy.
- `resources/views/production-bench/inventory.blade.php` — Stock by material page title.
- `resources/views/production-bench/inventory-stock.blade.php` — Lot register page title.
- `app/Support/ProductionBenchNavigation.php` — submenu labels and active-route aliases.
- `routes/web.php` — ingredient and packaging material-detail routes.
- `lang/en/production_bench.php` — new operational labels, validation, empty states, filters, and reconciliation copy.
- `database/seeders/data/interface-translations.json` — mirror every new or changed interface key.
- `tests/Feature/WorkspaceMaterialCatalogTest.php` — lot-only and settings-only membership.
- `tests/Feature/ProductionBenchPagesTest.php` — table, filter, search, Lot register, and read-only assertions.
- `tests/Unit/ProductionBenchNavigationTest.php` — renamed submenu and detail-route active state.

Do not change `StockPositionService` arithmetic unless a failing characterization test proves a defect. Do not modify posting actions for receipts, production consumption, reversals, reservations, or stock movements.

---

### Task 1: Persist workspace buffer stock safely

**Files:**
- Create: `app/Models/WorkspaceMaterialSetting.php`
- Create: `database/factories/WorkspaceMaterialSettingFactory.php`
- Create: `database/migrations/2026_08_30_130000_create_workspace_material_settings_table.php`
- Modify: `app/Models/Workspace.php`
- Modify: `app/Models/Ingredient.php`
- Modify: `app/Models/PackagingItem.php`
- Test: `tests/Feature/WorkspaceMaterialSettingTest.php`

- [ ] **Step 1: Generate the model, migration, factory, and Pest test**

Run:

```bash
php artisan make:model WorkspaceMaterialSetting --migration --factory --no-interaction
php artisan make:test --pest WorkspaceMaterialSettingTest --no-interaction
```

Expected: the four files exist; no application behavior changes.

- [ ] **Step 2: Write failing schema-integrity tests**

Add tests that use factories for valid rows and raw inserts only for rejected database boundaries:

```php
<?php

use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\Workspace;
use App\Models\WorkspaceMaterialSetting;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('stores one ingredient buffer per workspace in canonical units', function (): void {
    $workspace = Workspace::factory()->create();
    $ingredient = Ingredient::factory()->create();

    $setting = WorkspaceMaterialSetting::factory()->for($workspace)->for($ingredient)->create([
        'buffer_quantity' => '1250.000000000',
    ]);

    expect($setting->buffer_quantity)->toBe('1250.000000000')
        ->and($setting->packaging_item_id)->toBeNull();
});

it('stores packaging buffers for the owning workspace', function (): void {
    $workspace = Workspace::factory()->create();
    $packaging = PackagingItem::factory()->for($workspace)->create();

    $setting = WorkspaceMaterialSetting::factory()->for($workspace)->for($packaging, 'packagingItem')->create();

    expect($setting->ingredient_id)->toBeNull()
        ->and($setting->packaging_item_id)->toBe($packaging->id);
});

it('rejects settings with zero or two subjects', function (array $subjectColumns): void {
    $workspace = Workspace::factory()->create();
    $ingredient = Ingredient::factory()->create();
    $packaging = PackagingItem::factory()->for($workspace)->create();

    expect(fn () => DB::table('workspace_material_settings')->insert([
        'workspace_id' => $workspace->id,
        'ingredient_id' => $subjectColumns['ingredient'] ? $ingredient->id : null,
        'packaging_item_id' => $subjectColumns['packaging'] ? $packaging->id : null,
        'buffer_quantity' => '1.000000000',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
})->with([
    'no subject' => [['ingredient' => false, 'packaging' => false]],
    'two subjects' => [['ingredient' => true, 'packaging' => true]],
]);

it('rejects duplicate workspace material settings', function (): void {
    $setting = WorkspaceMaterialSetting::factory()->create();

    expect(fn () => WorkspaceMaterialSetting::factory()->create([
        'workspace_id' => $setting->workspace_id,
        'ingredient_id' => $setting->ingredient_id,
    ]))->toThrow(QueryException::class);
});
```

- [ ] **Step 3: Run the test and verify the red state**

Run:

```bash
php artisan test --compact tests/Feature/WorkspaceMaterialSettingTest.php
```

Expected: FAIL because the table and model contract do not exist.

- [ ] **Step 4: Implement the migration with reversible PostgreSQL and SQLite constraints**

Use the same exact-subject pattern as `current_material_prices`, but keep `down()` reversible:

```php
Schema::create('workspace_material_settings', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
    $table->foreignId('ingredient_id')->nullable()->constrained()->cascadeOnDelete();
    $table->foreignId('packaging_item_id')->nullable()->constrained()->cascadeOnDelete();
    $table->decimal('buffer_quantity', 20, 9);
    $table->timestamps();
});

if (DB::getDriverName() === 'pgsql') {
    DB::statement('ALTER TABLE workspace_material_settings ADD CONSTRAINT workspace_material_settings_exact_subject_check CHECK (((ingredient_id IS NOT NULL)::int + (packaging_item_id IS NOT NULL)::int) = 1)');
    DB::statement('ALTER TABLE workspace_material_settings ADD CONSTRAINT workspace_material_settings_non_negative_buffer_check CHECK (buffer_quantity >= 0)');
} else {
    DB::statement("CREATE TRIGGER workspace_material_settings_exact_subject_insert BEFORE INSERT ON workspace_material_settings WHEN ((NEW.ingredient_id IS NOT NULL) + (NEW.packaging_item_id IS NOT NULL)) != 1 BEGIN SELECT RAISE(ABORT, 'workspace material setting requires exactly one subject'); END");
    DB::statement("CREATE TRIGGER workspace_material_settings_exact_subject_update BEFORE UPDATE OF ingredient_id, packaging_item_id ON workspace_material_settings WHEN ((NEW.ingredient_id IS NOT NULL) + (NEW.packaging_item_id IS NOT NULL)) != 1 BEGIN SELECT RAISE(ABORT, 'workspace material setting requires exactly one subject'); END");
    DB::statement("CREATE TRIGGER workspace_material_settings_buffer_insert BEFORE INSERT ON workspace_material_settings WHEN NEW.buffer_quantity < 0 BEGIN SELECT RAISE(ABORT, 'workspace material buffer cannot be negative'); END");
    DB::statement("CREATE TRIGGER workspace_material_settings_buffer_update BEFORE UPDATE OF buffer_quantity ON workspace_material_settings WHEN NEW.buffer_quantity < 0 BEGIN SELECT RAISE(ABORT, 'workspace material buffer cannot be negative'); END");
}

DB::statement('CREATE UNIQUE INDEX workspace_material_settings_workspace_ingredient_unique ON workspace_material_settings (workspace_id, ingredient_id) WHERE ingredient_id IS NOT NULL');
DB::statement('CREATE UNIQUE INDEX workspace_material_settings_workspace_packaging_unique ON workspace_material_settings (workspace_id, packaging_item_id) WHERE packaging_item_id IS NOT NULL');
```

In `down()`, drop the SQLite triggers when applicable, then call `Schema::dropIfExists('workspace_material_settings')`.

- [ ] **Step 5: Implement the model, factory states, and relationships**

Use the app-standard Fillable attribute and decimal cast:

```php
#[Fillable(['workspace_id', 'ingredient_id', 'packaging_item_id', 'buffer_quantity'])]
class WorkspaceMaterialSetting extends Model
{
    /** @use HasFactory<WorkspaceMaterialSettingFactory> */
    use HasFactory;

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class)->withoutGlobalScopes();
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class)->withoutGlobalScopes();
    }

    public function packagingItem(): BelongsTo
    {
        return $this->belongsTo(PackagingItem::class);
    }

    protected function casts(): array
    {
        return ['buffer_quantity' => 'decimal:9'];
    }
}
```

The factory defaults to an ingredient subject and exposes a packaging helper that keeps workspace ownership consistent:

```php
public function definition(): array
{
    return [
        'workspace_id' => Workspace::factory(),
        'ingredient_id' => Ingredient::factory(),
        'packaging_item_id' => null,
        'buffer_quantity' => '1000.000000000',
    ];
}

public function forPackagingItem(PackagingItem $packagingItem): static
{
    return $this->state(fn (): array => [
        'workspace_id' => $packagingItem->workspace_id,
        'ingredient_id' => null,
        'packaging_item_id' => $packagingItem->id,
    ]);
}
```

Add `materialSettings(): HasMany` on `Workspace` and `workspaceSettings(): HasMany` on `Ingredient`. Add `materialSetting(): HasOne` on `PackagingItem`.

- [ ] **Step 6: Verify schema and tests**

Run:

```bash
php artisan migrate --no-interaction
php artisan truss:diff
php artisan test --compact tests/Feature/WorkspaceMaterialSettingTest.php
```

Expected: Truss reports only `workspace_material_settings` and its indexes/constraints; tests PASS.

- [ ] **Step 7: Commit the persistence slice**

```bash
git add app/Models/WorkspaceMaterialSetting.php app/Models/Workspace.php app/Models/Ingredient.php app/Models/PackagingItem.php database/factories/WorkspaceMaterialSettingFactory.php database/migrations/*_create_workspace_material_settings_table.php tests/Feature/WorkspaceMaterialSettingTest.php
git commit -m "feat: add workspace material buffer settings"
```

---

### Task 2: Add the authorized buffer write path

**Files:**
- Create: `app/Actions/Inventory/SaveMaterialBuffer.php`
- Create: `app/Services/Inventory/WorkspaceMaterialSettings.php`
- Modify: `tests/Feature/WorkspaceMaterialSettingTest.php`

- [ ] **Step 1: Write failing behavior tests**

Cover create, update, clear, canonical precision, cross-workspace packaging rejection, inaccessible ingredient rejection, and read-only Production Bench rejection. The core test shape is:

```php
it('creates updates and clears an ingredient buffer', function (): void {
    ['user' => $user, 'workspace' => $workspace] = productionBenchWorkspace();
    $ingredient = Ingredient::factory()->create();
    $action = app(SaveMaterialBuffer::class);

    $action->handle($user, $workspace, $ingredient, '1250.000000000');
    expect(WorkspaceMaterialSetting::query()->sole()->buffer_quantity)->toBe('1250.000000000');

    $action->handle($user, $workspace, $ingredient, '900.000000000');
    expect(WorkspaceMaterialSetting::query()->sole()->buffer_quantity)->toBe('900.000000000');

    $action->handle($user, $workspace, $ingredient, null);
    expect(WorkspaceMaterialSetting::query()->count())->toBe(0);
});
```

- [ ] **Step 2: Run the focused test and verify failure**

Run: `php artisan test --compact tests/Feature/WorkspaceMaterialSettingTest.php`

Expected: FAIL because `SaveMaterialBuffer` is missing.

- [ ] **Step 3: Implement the capability service**

Create `WorkspaceMaterialSettings::synchronize()` with one subject-key builder and exact decimal writes:

```php
public function synchronize(
    Workspace $workspace,
    Ingredient|PackagingItem $subject,
    ?string $bufferQuantity,
): ?WorkspaceMaterialSetting {
    $keys = [
        'workspace_id' => $workspace->id,
        'ingredient_id' => $subject instanceof Ingredient ? $subject->id : null,
        'packaging_item_id' => $subject instanceof PackagingItem ? $subject->id : null,
    ];

    return DB::transaction(function () use ($bufferQuantity, $keys): ?WorkspaceMaterialSetting {
        $existing = WorkspaceMaterialSetting::query()->where($keys)->lockForUpdate()->first();

        if ($bufferQuantity === null) {
            $existing?->delete();

            return null;
        }

        return WorkspaceMaterialSetting::query()->updateOrCreate(
            $keys,
            ['buffer_quantity' => bcadd($bufferQuantity, '0', 9)],
        );
    }, attempts: 5);
}
```

- [ ] **Step 4: Implement the Action boundary**

`SaveMaterialBuffer::handle()` must call `ProductionBenchAccess::assertWritable()`, verify that packaging belongs to the workspace or that the ingredient is accessible using the same platform/workspace/legacy rules as `WorkspaceIngredientCodeService`, reject values below zero or above the database precision, and delegate persistence to `WorkspaceMaterialSettings`.

Use this public signature:

```php
public function handle(
    User $actor,
    Workspace $workspace,
    Ingredient|PackagingItem $subject,
    ?string $bufferQuantity,
): ?WorkspaceMaterialSetting
```

Throw field-scoped validation messages under `buffer_quantity` using `production_bench.inventory.validation.*` keys.

- [ ] **Step 5: Run tests and commit**

Run: `php artisan test --compact tests/Feature/WorkspaceMaterialSettingTest.php`

Expected: PASS.

```bash
git add app/Actions/Inventory/SaveMaterialBuffer.php app/Services/Inventory/WorkspaceMaterialSettings.php tests/Feature/WorkspaceMaterialSettingTest.php lang/en/production_bench.php database/seeders/data/interface-translations.json
git commit -m "feat: manage material buffer quantities"
```

---

### Task 3: Build a server-paginated material inventory query

**Files:**
- Create: `app/Services/Inventory/WorkspaceMaterialInventoryQuery.php`
- Modify: `app/Services/Production/WorkspaceMaterialCatalog.php`
- Modify: `tests/Feature/WorkspaceMaterialCatalogTest.php`
- Create: `tests/Feature/WorkspaceMaterialInventoryQueryTest.php`

- [ ] **Step 1: Characterize all tracked-material membership sources**

Add failing tests proving that demanded-only, listed-only, lot-only, and settings-only ingredients and packaging appear once, while foreign-workspace packaging, lots, listings, and settings do not appear.

Add explicit lot-only and setting-only cases to `WorkspaceMaterialCatalogTest.php` before refactoring.

- [ ] **Step 2: Write query-service tests for search, filters, sorting, and pagination**

Use the public interface below in tests:

```php
$page = app(WorkspaceMaterialInventoryQuery::class)->paginate(
    workspace: $workspace,
    filters: [
        'search' => 'olea europaea',
        'type' => 'ingredient',
        'stock_state' => 'negative_forecast',
        'demand' => 'all',
        'category' => IngredientCategory::Lipids->value,
        'subcategory' => IngredientSubcategory::VegetableOils->value,
        'sort' => 'name',
        'direction' => 'asc',
    ],
    perPage: 25,
    pageName: 'materials',
);
```

Create fixtures for base/localized names, every INCI variant, aliases, workspace code, supplier SKU/item name, packaging code/name, negative forecast, quarantine, incoming, and below-buffer independently. Assert deterministic ordering for `priority`, `name`, `physical`, `available`, and `forecast`.

- [ ] **Step 3: Run the tests and verify failure**

Run:

```bash
php artisan test --compact tests/Feature/WorkspaceMaterialCatalogTest.php tests/Feature/WorkspaceMaterialInventoryQueryTest.php
```

Expected: the new membership tests fail and the query class is missing.

- [ ] **Step 4: Extend tracked membership before replacing presentation logic**

In `WorkspaceMaterialCatalog`, add subject keys from workspace stock lots and material settings alongside demanded and listed keys. Preserve deduplication and workspace scoping. Run `WorkspaceMaterialCatalogTest.php` until it passes before continuing.

- [ ] **Step 5: Implement the query service around a database union**

Build a `trackedSubjects()` subquery with four `unionAll` sources that each select `subject_type` and `subject_id`: active-demand requirements, listings, lots, and material settings. Wrap the union and select distinct subjects.

The service must join or aggregate these subqueries:

- lot physical and quarantined quantities from `stock_lots` plus `stock_movements`;
- active reservations from `stock_reservations` joined to released lots;
- incoming purchase-order quantities net of posted receipt packs;
- scheduled/reserved production demand;
- workspace buffer settings;
- display identity and taxonomy;
- existence-based search matches for translations, aliases, codes, and supplier listing references.

Select decimal aliases `physical`, `quarantined`, `reserved`, `available`, `incoming`, `required`, and `forecast`, using `COALESCE(..., 0)` and `CASE` expressions portable between PostgreSQL and SQLite. Define `is_shortage` as `forecast < 0` and `is_below_buffer` as a non-null buffer with `available < buffer_quantity`.

Apply an allow-list, never raw browser values, for sorting:

```php
$sortColumns = [
    'name' => 'sort_name',
    'physical' => 'physical',
    'available' => 'available',
    'forecast' => 'forecast',
];

if (($filters['sort'] ?? 'priority') === 'priority') {
    $query->orderByDesc('is_shortage')
        ->orderByDesc('has_demand')
        ->orderBy('sort_name')
        ->orderBy('subject_type')
        ->orderBy('subject_id');
} else {
    $column = $sortColumns[$filters['sort']] ?? 'sort_name';
    $direction = ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
    $query->orderBy($column, $direction)
        ->orderBy('subject_type')
        ->orderBy('subject_id');
}
```

Return a `LengthAwarePaginator` directly from SQL. Hydrate only the current page's Ingredient models with translations so `localizedDisplayName()` remains the visible name; do not reload all matching materials.

- [ ] **Step 6: Verify bounded query behavior**

Add a test with more than 25 materials and assert page totals, first/second page identities, and a bounded query count using `DB::enableQueryLog()` around the service call. The exact ceiling should be the observed implementation count plus one, not an arbitrary large number.

Run: `php artisan test --compact tests/Feature/WorkspaceMaterialCatalogTest.php tests/Feature/WorkspaceMaterialInventoryQueryTest.php`

Expected: PASS.

- [ ] **Step 7: Commit the query slice**

```bash
git add app/Services/Inventory/WorkspaceMaterialInventoryQuery.php app/Services/Production/WorkspaceMaterialCatalog.php tests/Feature/WorkspaceMaterialCatalogTest.php tests/Feature/WorkspaceMaterialInventoryQueryTest.php
git commit -m "feat: query workspace inventory by material"
```

---

### Task 4: Revise Stock by material without replacing its table

**Files:**
- Modify: `app/Livewire/ProductionBench/InventoryIndex.php`
- Modify: `resources/views/livewire/production-bench/inventory-index.blade.php`
- Modify: `resources/views/production-bench/inventory.blade.php`
- Modify: `app/Support/ProductionBenchNavigation.php`
- Modify: `lang/en/production_bench.php`
- Modify: `database/seeders/data/interface-translations.json`
- Modify: `tests/Feature/ProductionBenchPagesTest.php`
- Modify: `tests/Unit/ProductionBenchNavigationTest.php`

- [ ] **Step 1: Write failing navigation and table assertions**

Assert the page and submenu say `Stock by material`, the old `Materials` submenu label is absent, and the table headers appear in this exact order:

```php
->assertSeeInOrder([
    'Material',
    'Physical',
    'Available',
    'Reserved',
    'Quarantined',
    'Incoming',
    'Required',
    'Forecast',
]);
```

Add Livewire tests that set each filter and sort option, verify the matching records, verify the negative-forecast summary activates the same state, and verify query-string hydration produces the same result.

- [ ] **Step 2: Run the focused tests and verify failure**

Run:

```bash
php artisan test --compact tests/Feature/ProductionBenchPagesTest.php tests/Unit/ProductionBenchNavigationTest.php
```

Expected: FAIL on labels, column order, and missing controls.

- [ ] **Step 3: Replace collection pagination with the query service**

Inject `WorkspaceMaterialInventoryQuery` into `render()` and remove `materialRows()`, `paginate()`, and the collection-only material sorting path. Keep lot behavior untouched in this task.

Bind durable state with Livewire query-string aliases:

```php
protected function queryString(): array
{
    return [
        'filters.search' => ['as' => 'q', 'except' => ''],
        'filters.type' => ['as' => 'type', 'except' => 'all'],
        'filters.stock_state' => ['as' => 'state', 'except' => 'all'],
        'filters.demand' => ['as' => 'demand', 'except' => 'all'],
        'filters.category' => ['as' => 'category', 'except' => ''],
        'filters.subcategory' => ['as' => 'subcategory', 'except' => ''],
        'filters.sort' => ['as' => 'sort', 'except' => 'priority'],
        'filters.direction' => ['as' => 'direction', 'except' => 'asc'],
    ];
}
```

Reset the `materials` page after every material filter or sort update. When category changes, clear subcategory unless `IngredientSubcategory::tryFrom()` belongs to the selected category.

**Amended 2026-08-30 — the `filters.*` shape above was not used.** Durable state binds to flat
`#[Url]` properties instead. Dotted `queryString()` keys do work on Livewire 4.4.2, but neither that
nor any nested-key form is documented: the Livewire 4 `url` and `attribute-url` pages document
`#[Url]` and `queryString()` only on flat properties. Shareable URLs should not rest on an
implementation detail. The aliases are unchanged: `q`, `type`, `state`, `demand`, `category`,
`subcategory`, `sort`, `direction`.

- [ ] **Step 4: Implement the compact filters UI**

Keep Search and Sort visible. Put Type, Stock state, Demand, Category, and Subcategory behind one Filters action/panel. Render active state as removable chips.

**Amended 2026-08-30 (owner decision) — category and subcategory use `<x-search-combobox>`, not Filament Selects.** The line originally read "Use searchable, non-native Filament Select fields for category and subcategory; make subcategory options depend on `IngredientSubcategory::optionsFor($get('category'))` and disable it until category is filled." Superseded because:

- Design spec line 77 asks for "searchable comboboxes **or** compact state controls". The shared combobox is option one, and the spec is the authority on intent.
- `x-search-combobox` — used in 6 views, including the sibling `ingredients-index` — already delivers every listed behaviour: searchable, non-native, subcategory options derived from `IngredientSubcategory::optionsFor($this->categoryFilter)`, disabled until a category is chosen, and removable chips.
- `.ai/rules/forms.md` scopes Filament to "public form UI" and "public Livewire editors". A filter panel has no submit, validation, or persistence, so it falls outside that scope. `IngredientEditor` is the case the rule does cover.
- The measured cost was ~60 lines plus a new CSS bridge: `->searchable()` forces the non-native JS select (`Select.php:1838`), which `filament-soapkraft.css` does not bridge, and a Filament `Select` writes `null` for "no selection", which forces the backing properties nullable.

Retained requirement: subcategory options depend on the chosen category, and the subcategory control stays disabled until a category is filled.

- [ ] **Step 5: Preserve the table and add the exact columns**

Keep the current `<table>` structure and existing semantic CSS variables. Link the material name with `wire:navigate` to the correct ingredient or packaging detail route. Render light danger and warning treatments with existing tokens:

```blade
<tr class="{{ $row['is_shortage'] ? 'bg-[var(--color-danger-soft)]/40' : ($row['is_below_buffer'] ? 'bg-[var(--color-warning-soft)]/40' : '') }}">
```

Show text badges for `Negative forecast` and `Below buffer`; never rely on colour alone. Set the table minimum width to accommodate all eight columns without compressing numeric values.

- [ ] **Step 6: Update copy and interface translations**

Change material-facing keys to `Stock by material`; add filter, sort, state-chip, Physical, buffer, and validation keys to `lang/en/production_bench.php`. Mirror the complete key set in `database/seeders/data/interface-translations.json` so the interface translation catalogue test stays green.

- [ ] **Step 7: Run tests and commit**

Run:

```bash
php artisan test --compact tests/Feature/ProductionBenchPagesTest.php tests/Unit/ProductionBenchNavigationTest.php tests/Feature/InterfaceTranslationCatalogueTest.php
```

Expected: PASS.

```bash
git add app/Livewire/ProductionBench/InventoryIndex.php app/Support/ProductionBenchNavigation.php resources/views/livewire/production-bench/inventory-index.blade.php resources/views/production-bench/inventory.blade.php lang/en/production_bench.php database/seeders/data/interface-translations.json tests/Feature/ProductionBenchPagesTest.php tests/Unit/ProductionBenchNavigationTest.php tests/Feature/InterfaceTranslationCatalogueTest.php
git commit -m "feat: improve stock by material table"
```

---

### Task 5: Turn Stock into the Lot register

**Files:**
- Modify: `app/Livewire/ProductionBench/InventoryIndex.php`
- Modify: `resources/views/livewire/production-bench/inventory-index.blade.php`
- Modify: `resources/views/production-bench/inventory-stock.blade.php`
- Modify: `app/Support/ProductionBenchNavigation.php`
- Modify: `lang/en/production_bench.php`
- Modify: `database/seeders/data/interface-translations.json`
- Modify: `tests/Feature/ProductionBenchPagesTest.php`
- Modify: `tests/Unit/ProductionBenchNavigationTest.php`

- [ ] **Step 1: Write failing Lot register tests**

Create released, quarantined, positive, zero, and negative lots; suppliers/listings; received and opening-balance origins; expiry dates; and more than one page. Assert:

- default Open includes positive and negative balances but excludes zero-balance lots without active reservations;
- Exhausted includes zero-balance lots;
- All includes both;
- material, supplier, status, origin, received date, and expiry filters work;
- search matches internal lot, supplier batch, supplier name, supplier SKU/item name, material name/code, and INCI;
- database pagination remains 25/50/100;
- the heading and submenu say Lot register.

- [ ] **Step 2: Run tests and verify failure**

Run: `php artisan test --compact tests/Feature/ProductionBenchPagesTest.php tests/Unit/ProductionBenchNavigationTest.php`

Expected: FAIL because only status and basic search exist.

- [ ] **Step 3: Add URL-bound lot filters and secure allow-lists**

Add stock-mode state for `lot_scope`, `material_type`, `material`, `supplier`, `status`, `origin`, `stocked_from`, `stocked_until`, `expiry`, and `sort`. Resolve public material and supplier IDs through workspace-scoped queries before applying them.

Implement scope with one portable correlated movement sum; do not filter the already-selected alias because PostgreSQL and SQLite differ on alias visibility:

```php
->when($scope === 'open', fn (Builder $query): Builder => $query
    ->whereRaw('(SELECT COALESCE(SUM(stock_movements.quantity_delta), 0) FROM stock_movements WHERE stock_movements.stock_lot_id = stock_lots.id) <> 0'))
->when($scope === 'exhausted', fn (Builder $query): Builder => $query
    ->whereRaw('(SELECT COALESCE(SUM(stock_movements.quantity_delta), 0) FROM stock_movements WHERE stock_movements.stock_lot_id = stock_lots.id) = 0')
    ->whereDoesntHave('reservations', fn (Builder $reservationQuery): Builder => $reservationQuery
        ->where('status', StockReservationStatus::Active)))
```

- [ ] **Step 4: Expand eager loading and search**

Load `supplierListing.supplier` as the stable supplier fallback in addition to `goodsReceiptLine.goodsReceipt.supplier`. Search both relationships and the supplier listing reference fields. Present supplier name from receipt first, then listing, then a neutral em dash.

- [ ] **Step 5: Rename UI and add filters without changing lot actions**

Change the page/submenu title to Lot register. Keep Add stock manually, Release, and Quarantine behavior intact. Add the filters and visible scope chips above the existing lot table. Show supplier name as a dedicated column or secondary line with accessible headers.

- [ ] **Step 6: Run tests and commit**

Run:

```bash
php artisan test --compact tests/Feature/ProductionBenchPagesTest.php tests/Unit/ProductionBenchNavigationTest.php tests/Feature/InterfaceTranslationCatalogueTest.php
```

Expected: PASS.

```bash
git add app/Livewire/ProductionBench/InventoryIndex.php app/Support/ProductionBenchNavigation.php resources/views/livewire/production-bench/inventory-index.blade.php resources/views/production-bench/inventory-stock.blade.php lang/en/production_bench.php database/seeders/data/interface-translations.json tests/Feature/ProductionBenchPagesTest.php tests/Unit/ProductionBenchNavigationTest.php tests/Feature/InterfaceTranslationCatalogueTest.php
git commit -m "feat: add focused inventory lot register"
```

---

### Task 6: Add material detail with current position, buffer, and open lots

**Files:**
- Create: `app/Livewire/ProductionBench/InventoryMaterialDetail.php`
- Create: `resources/views/livewire/production-bench/inventory-material-detail.blade.php`
- Create: `resources/views/production-bench/inventory-material-detail.blade.php`
- Modify: `routes/web.php`
- Modify: `app/Support/ProductionBenchNavigation.php`
- Modify: `lang/en/production_bench.php`
- Modify: `database/seeders/data/interface-translations.json`
- Create: `tests/Feature/ProductionBenchInventoryMaterialDetailTest.php`

- [ ] **Step 1: Generate the component and test**

Run:

```bash
php artisan make:livewire ProductionBench/InventoryMaterialDetail --class --no-interaction
php artisan make:test --pest ProductionBenchInventoryMaterialDetailTest --no-interaction
```

Expected: class-based multi-file Livewire component and Pest test exist.

- [ ] **Step 2: Write failing route, authorization, and rendering tests**

Assert both routes require authentication and resolve only accessible subjects:

```php
Route::view('/inventory/materials/ingredients/{ingredient}', 'production-bench.inventory-material-detail')
    ->name('inventory.ingredients.show');
Route::view('/inventory/materials/packaging/{packagingItem}', 'production-bench.inventory-material-detail')
    ->name('inventory.packaging.show');
```

Test an ingredient and packaging page, foreign packaging 404, inaccessible private ingredient 404, read-only visibility, exact current-position values, buffer save/clear permissions, supplier-aware open lots, and the View all lots URL containing material type, public ID, and `lot_scope=all`.

- [ ] **Step 3: Run the test and verify failure**

Run: `php artisan test --compact tests/Feature/ProductionBenchInventoryMaterialDetailTest.php`

Expected: FAIL because routes and component do not exist.

- [ ] **Step 4: Implement explicit wrapper inputs and component mounting**

Pass either route-bound model into the wrapper and then into Livewire. Give the component separate nullable locked identifiers rather than a writable union-model property:

```php
#[Locked]
public ?string $ingredientPublicId = null;

#[Locked]
public ?string $packagingPublicId = null;

public function mount(Ingredient|PackagingItem $subject): void
{
    $this->assertSubjectAccessible($subject);
    $this->ingredientPublicId = $subject instanceof Ingredient ? $subject->public_id : null;
    $this->packagingPublicId = $subject instanceof PackagingItem ? $subject->public_id : null;
}
```

Resolve the subject again through a workspace/access-scoped query on every mutation. Use `ProductionBenchAccess` for active/read-only state.

- [ ] **Step 5: Render current position and open lots**

Use `StockPositionService::forWorkspaceSubject()` for current values. Query open lots with movement and active-reservation sums, eager-load receipt/listing suppliers, order by expiry then stocked date and lot code, and limit the embedded list to a small fixed number such as 10. Include Physical, Available, Reserved, Quarantined, Incoming, Required, and Forecast in the header.

- [ ] **Step 6: Wire buffer editing through the Action**

Use a Filament Action modal with `LocalizedDecimalInput::make('buffer_quantity')`, canonical display-unit conversion through `MassConverter`, and explicit Save/Clear actions. Hide mutation controls for read-only access. Call only `SaveMaterialBuffer::handle()` from Livewire.

- [ ] **Step 7: Route View all lots to the filtered register**

Generate the named route with query values:

```php
route('production-bench.inventory.stock', [
    'material_type' => $subject instanceof Ingredient ? 'ingredient' : 'packaging',
    'material' => $subject->public_id,
    'lot_scope' => 'all',
])
```

Do not introduce a lots modal or duplicate release/quarantine controls on the detail page.

- [ ] **Step 8: Run tests and commit**

Run:

```bash
php artisan test --compact tests/Feature/ProductionBenchInventoryMaterialDetailTest.php tests/Feature/ProductionBenchPagesTest.php tests/Unit/ProductionBenchNavigationTest.php
```

Expected: PASS.

```bash
git add app/Livewire/ProductionBench/InventoryMaterialDetail.php resources/views/livewire/production-bench/inventory-material-detail.blade.php resources/views/production-bench/inventory-material-detail.blade.php routes/web.php app/Support/ProductionBenchNavigation.php lang/en/production_bench.php database/seeders/data/interface-translations.json tests/Feature/ProductionBenchInventoryMaterialDetailTest.php
git commit -m "feat: add inventory material detail"
```

---

### Task 7: Add period activity and exact stock reconciliation

**Files:**
- Create: `app/Services/Inventory/MaterialActivityService.php`
- Create: `tests/Feature/MaterialActivityServiceTest.php`
- Modify: `app/Livewire/ProductionBench/InventoryMaterialDetail.php`
- Modify: `resources/views/livewire/production-bench/inventory-material-detail.blade.php`
- Modify: `lang/en/production_bench.php`
- Modify: `database/seeders/data/interface-translations.json`
- Modify: `tests/Feature/ProductionBenchInventoryMaterialDetailTest.php`

- [ ] **Step 1: Write failing reconciliation tests**

Create dated movements for opening balance, purchase receipt, receipt reversal, production consumption from completed and aborted runs, production correction, stock-count adjustment, damage, sample, internal use, shipment, and production output. Assert inclusive date boundaries and this invariant at scale 9:

```php
expect(bcadd($summary['opening_physical'], $summary['net_change'], 9))
    ->toBe($summary['closing_physical']);
```

Assert `received` is the signed net of PurchaseReceipt and ReceiptReversal, `production_consumed` is the absolute value of negative ProductionConsumption movements only, corrections remain in adjustments, and the sum of displayed groups equals `net_change`.

- [ ] **Step 2: Run tests and verify failure**

Run: `php artisan test --compact tests/Feature/MaterialActivityServiceTest.php`

Expected: FAIL because the service is missing.

- [ ] **Step 3: Implement one workspace/subject movement scope**

Use a private scoped builder that always constrains the lot's workspace and exact subject:

```php
private function movements(Workspace $workspace, Ingredient|PackagingItem $subject): Builder
{
    return StockMovement::query()
        ->where('workspace_id', $workspace->id)
        ->whereHas('stockLot', fn (Builder $query): Builder => $query
            ->where('workspace_id', $workspace->id)
            ->when(
                $subject instanceof Ingredient,
                fn (Builder $subjectQuery): Builder => $subjectQuery->where('ingredient_id', $subject->id),
                fn (Builder $subjectQuery): Builder => $subjectQuery->where('packaging_item_id', $subject->id),
            ));
}
```

- [ ] **Step 4: Implement summary grouping with decimal strings**

Expose:

```php
public function summarize(
    Workspace $workspace,
    Ingredient|PackagingItem $subject,
    CarbonImmutable $from,
    CarbonImmutable $until,
): array
```

Calculate opening before `$from->startOfDay()`, group period movement sums by enum type through `$until->endOfDay()`, and derive:

- `received` = purchase receipt + receipt reversal;
- `production_consumed` = absolute negative production consumption;
- `other_inbound` = positive production output and other positive non-receipt/non-adjustment movements;
- `other_outbound` = absolute negative damage, sample, internal use, shipment, and other negative non-consumption/non-adjustment movements;
- `adjustments` = signed stock-count adjustment + production correction;
- `net_change` = signed sum of every period movement;
- `closing_physical` = opening + net change.

Use `bcadd`, `bcsub`, and `bccomp`; never cast quantities to float.

- [ ] **Step 5: Return paginated source rows**

Add a second method that returns newest-first movements for the period, eager-loaded with `source` and lot supplier relationships. Map `GoodsReceiptLine` through its receipt route, map `GoodsReceipt` reversals directly, and map `ProductionRun` through its production route. Return no URL and a neutral source label for every other source type or inaccessible record.

- [ ] **Step 6: Add period state to the material detail**

Support `30`, `365`, and `custom` presets with URL-bound state. For custom periods, validate ISO dates, require from <= until, and attach errors to the date fields. Presets use today as the inclusive end and subtract 29 or 364 days for the inclusive start.

Render opening, received, production consumed, other inbound/outbound, adjustments, net change, and closing in a readable reconciliation strip. Keep the current-position section independent of period state.

- [ ] **Step 7: Run tests and commit**

Run:

```bash
php artisan test --compact tests/Feature/MaterialActivityServiceTest.php tests/Feature/ProductionBenchInventoryMaterialDetailTest.php tests/Feature/InterfaceTranslationCatalogueTest.php
```

Expected: PASS.

```bash
git add app/Services/Inventory/MaterialActivityService.php app/Livewire/ProductionBench/InventoryMaterialDetail.php resources/views/livewire/production-bench/inventory-material-detail.blade.php lang/en/production_bench.php database/seeders/data/interface-translations.json tests/Feature/MaterialActivityServiceTest.php tests/Feature/ProductionBenchInventoryMaterialDetailTest.php tests/Feature/InterfaceTranslationCatalogueTest.php
git commit -m "feat: reconcile material inventory activity"
```

---

### Task 8: Verify the complete inventory redesign

**Files:**
- Modify only files required by failures found below.

- [ ] **Step 1: Run the complete affected inventory and production suite**

```bash
php artisan test --compact \
  tests/Feature/WorkspaceMaterialSettingTest.php \
  tests/Feature/WorkspaceMaterialCatalogTest.php \
  tests/Feature/WorkspaceMaterialInventoryQueryTest.php \
  tests/Feature/ProductionBenchInventoryMaterialDetailTest.php \
  tests/Feature/MaterialActivityServiceTest.php \
  tests/Feature/ProductionBenchPagesTest.php \
  tests/Feature/ProductionForecastTest.php \
  tests/Feature/ProcurementLifecycleTest.php \
  tests/Feature/DirectGoodsReceiptPostingTest.php \
  tests/Feature/ProductionExecutionTest.php \
  tests/Feature/InterfaceTranslationCatalogueTest.php \
  tests/Unit/ProductionBenchNavigationTest.php
```

Expected: PASS with no failures.

- [ ] **Step 2: Format modified PHP files**

Run:

```bash
vendor/bin/pint --dirty --format agent
```

Expected: Pint completes successfully; rerun the affected test set if it changes files.

- [ ] **Step 3: Run Filament compatibility checks when applicable**

No `app/Filament` file is expected to change. If implementation introduces or modifies one, run:

```bash
vendor/bin/filacheck --fix
```

Expected: no unresolved issues.

- [ ] **Step 4: Confirm the live schema**

Run:

```bash
php artisan truss:doctor
php artisan truss:diff
```

Expected: no new doctor finding for `workspace_material_settings`; diff matches the one new table and its intended constraints/indexes.

- [ ] **Step 5: Refresh the code graph**

Run:

```bash
graphify update .
```

Expected: graph update completes; Inventory query, buffer settings, material detail, and activity service appear in the graph.

- [ ] **Step 6: Review the final branch diff**

Run:

```bash
git status --short
git diff --stat HEAD~7..HEAD
git diff --check HEAD~7..HEAD
```

Expected: only the planned Inventory files plus unavoidable generated translation/graph artifacts are present; no whitespace errors; unrelated workspace-guidance changes remain untouched.

- [ ] **Step 7: Commit verification-only fixes if any**

If verification required changes, stage only the complete planned path set and inspect the staged list before committing:

```bash
git add app/Actions/Inventory/SaveMaterialBuffer.php app/Livewire/ProductionBench/InventoryIndex.php app/Livewire/ProductionBench/InventoryMaterialDetail.php app/Models/Workspace.php app/Models/Ingredient.php app/Models/PackagingItem.php app/Models/WorkspaceMaterialSetting.php app/Services/Inventory/WorkspaceMaterialInventoryQuery.php app/Services/Inventory/WorkspaceMaterialSettings.php app/Services/Inventory/MaterialActivityService.php app/Services/Production/WorkspaceMaterialCatalog.php app/Support/ProductionBenchNavigation.php database/factories/WorkspaceMaterialSettingFactory.php database/migrations/2026_08_30_130000_create_workspace_material_settings_table.php database/seeders/data/interface-translations.json lang/en/production_bench.php resources/views/livewire/production-bench/inventory-index.blade.php resources/views/livewire/production-bench/inventory-material-detail.blade.php resources/views/production-bench/inventory.blade.php resources/views/production-bench/inventory-stock.blade.php resources/views/production-bench/inventory-material-detail.blade.php routes/web.php tests/Feature/WorkspaceMaterialSettingTest.php tests/Feature/WorkspaceMaterialCatalogTest.php tests/Feature/WorkspaceMaterialInventoryQueryTest.php tests/Feature/ProductionBenchInventoryMaterialDetailTest.php tests/Feature/MaterialActivityServiceTest.php tests/Feature/ProductionBenchPagesTest.php tests/Feature/InterfaceTranslationCatalogueTest.php tests/Unit/ProductionBenchNavigationTest.php
git diff --cached --name-only
git commit -m "fix: complete inventory ux verification"
```

If no changes were required, do not create an empty commit.

- [ ] **Step 8: Request the complete suite**

Report the affected suite result and ask the user to run:

```bash
php artisan test --compact
```

Do not claim the entire repository suite passes unless that exact command has completed successfully.
