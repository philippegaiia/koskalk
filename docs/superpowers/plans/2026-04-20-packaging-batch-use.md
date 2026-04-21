# Packaging Batch Use Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make packaging a first-class recipe-version structure, keep costing as editable latest prices, and let users prepare a production batch from the official recipe without opening the draft editor.

**Architecture:** Add `RecipeVersionPackagingItem` as the source of truth for the packaging plan. The workbench saves packaging rows with the draft/publish payload, costing derives packaging cost rows from that plan, and the official saved page adds batch-use controls that pass operational context into existing browser print views.

**Tech Stack:** Laravel 13, Livewire 4, Alpine-backed workbench modules, Tailwind CSS v4 utility classes, Pest 4 feature tests.

---

## Scope Notes

- Do not backfill existing `recipe_version_costing_packaging_items` rows into the new packaging plan table.
- Existing development packaging rows may be deleted or ignored.
- Ingredient data and `user_ingredient_prices` must remain safe.
- Keep browser print views in v1; do not introduce server-side PDF generation.
- Use TDD: each behavior starts with a failing Pest test.

## File Map

- Create `database/migrations/2026_04_20_000000_create_recipe_version_packaging_items_table.php` for the first-class packaging plan table.
- Create `app/Models/RecipeVersionPackagingItem.php` for recipe-version packaging rows.
- Create `app/Policies/RecipeVersionPackagingItemPolicy.php` because tenant-aware models need explicit policies.
- Modify `app/Models/RecipeVersion.php` to add `packagingItems()`.
- Modify `app/Models/UserPackagingItem.php` to add `recipeVersionPackagingItems()`.
- Modify `app/Services/RecipeWorkbenchPayloadNormalizer.php` to normalize `packaging_items`.
- Modify `app/Services/RecipeWorkbenchDraftPayloadMapper.php` to include `packagingItems` in save payloads.
- Modify `app/Services/RecipeWorkbenchVersionPayloadMapper.php` and `RecipeWorkbenchVersionDataService.php` to emit packaging rows in workbench payloads.
- Modify `app/Services/RecipeVersionStructureSynchronizer.php` to replace packaging plan rows when a draft or official recipe is saved.
- Modify `app/Services/RecipeVersionCostingSynchronizer.php` so costing packaging rows are reconciled from the packaging plan and unit price edits remain price-only.
- Modify `resources/js/recipe-workbench/component.js`, `payload.js`, `sections/costing-section.js`, and new `sections/packaging-section.js`.
- Modify `resources/views/livewire/dashboard/partials/recipe-workbench/navigation.blade.php`, create `packaging-tab.blade.php`, and adjust `costing-tab.blade.php`.
- Modify `app/Services/RecipeVersionViewDataBuilder.php`, `resources/views/recipes/version.blade.php`, and `resources/views/recipes/print.blade.php` for official-page packaging and batch-use context.
- Add/update Pest coverage in `tests/Feature/RecipeVersionPackagingPlanTest.php`, `tests/Feature/RecipeVersionCostingTest.php`, and `tests/Feature/RecipeVersionPagesTest.php`.

---

### Task 1: Packaging Plan Data Model

**Files:**
- Create: `database/migrations/2026_04_20_000000_create_recipe_version_packaging_items_table.php`
- Create: `app/Models/RecipeVersionPackagingItem.php`
- Create: `app/Policies/RecipeVersionPackagingItemPolicy.php`
- Modify: `app/Models/RecipeVersion.php`
- Modify: `app/Models/UserPackagingItem.php`
- Test: `tests/Feature/RecipeVersionPackagingPlanTest.php`

- [ ] **Step 1: Write the failing model relationship test**

```php
it('stores packaging rows as recipe version structure', function () {
    $user = User::factory()->create();
    $version = RecipeVersion::factory()->create([
        'owner_id' => $user->id,
    ]);
    $catalogItem = UserPackagingItem::query()->create([
        'user_id' => $user->id,
        'name' => 'Amber jar',
        'unit_cost' => 0.62,
        'currency' => 'EUR',
    ]);

    $row = $version->packagingItems()->create([
        'user_packaging_item_id' => $catalogItem->id,
        'name' => 'Amber jar',
        'components_per_unit' => 1,
        'notes' => '100 ml',
        'position' => 1,
    ]);

    expect($row->recipeVersion->is($version))->toBeTrue()
        ->and($row->packagingItem->is($catalogItem))->toBeTrue()
        ->and((float) $row->components_per_unit)->toBe(1.0);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter="stores packaging rows as recipe version structure"`

Expected: FAIL because `RecipeVersionPackagingItem` table/model/relationship does not exist.

- [ ] **Step 3: Implement the migration and model**

Create the table with:

```php
Schema::create('recipe_version_packaging_items', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('recipe_version_id')->constrained()->cascadeOnDelete();
    $table->foreignId('user_packaging_item_id')->nullable()->constrained()->nullOnDelete();
    $table->string('name');
    $table->decimal('components_per_unit', total: 10, places: 3)->default(1);
    $table->text('notes')->nullable();
    $table->unsignedInteger('position')->default(1);
    $table->timestamps();
});
```

Create `RecipeVersionPackagingItem` with fillable fields, `recipeVersion()` and `packagingItem()` relationships, and decimal cast for `components_per_unit`.

- [ ] **Step 4: Add relationships and policy**

Add to `RecipeVersion`:

```php
public function packagingItems(): HasMany
{
    return $this->hasMany(RecipeVersionPackagingItem::class)->orderBy('position');
}
```

Add to `UserPackagingItem`:

```php
public function recipeVersionPackagingItems(): HasMany
{
    return $this->hasMany(RecipeVersionPackagingItem::class);
}
```

Policy rule: owner can view/update/delete rows through `recipeVersion.owner_id`; other users cannot.

- [ ] **Step 5: Run the focused test**

Run: `php artisan test --compact --filter="stores packaging rows as recipe version structure"`

Expected: PASS.

---

### Task 2: Save Packaging Plan With Drafts And Official Recipes

**Files:**
- Modify: `app/Services/RecipeWorkbenchPayloadNormalizer.php`
- Modify: `app/Services/RecipeWorkbenchDraftPayloadMapper.php`
- Modify: `app/Services/RecipeVersionStructureSynchronizer.php`
- Modify: `app/Services/RecipeWorkbenchVersionPayloadMapper.php`
- Modify: `app/Services/RecipeWorkbenchVersionDataService.php`
- Test: `tests/Feature/RecipeVersionPackagingPlanTest.php`

- [ ] **Step 1: Write the failing save/publish test**

```php
it('saves and publishes packaging rows with the recipe version', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create(['slug' => 'soap', 'name' => 'Soap']);
    $ingredient = Ingredient::factory()->create();
    $packagingItem = UserPackagingItem::query()->create([
        'user_id' => $user->id,
        'name' => 'Soap box',
        'unit_cost' => 0.42,
        'currency' => 'EUR',
    ]);

    $payload = soapVersionDraftPayload($ingredient, 'Boxed soap') + [
        'packaging_items' => [
            [
                'user_packaging_item_id' => $packagingItem->id,
                'name' => 'Soap box',
                'components_per_unit' => 1,
                'notes' => 'Sleeve box',
            ],
        ],
    ];

    $service = app(RecipeWorkbenchService::class);
    $draft = $service->saveDraft($user, $soapFamily, $payload);
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draft->recipe_id);
    $service->saveRecipe($user, $soapFamily, $payload, $recipe);

    $published = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_draft', false)
        ->latest('version_number')
        ->firstOrFail();

    expect($draft->fresh()->packagingItems)->toHaveCount(1)
        ->and($published->packagingItems)->toHaveCount(1)
        ->and($published->packagingItems->first()->name)->toBe('Soap box')
        ->and((float) $published->packagingItems->first()->components_per_unit)->toBe(1.0);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter="saves and publishes packaging rows with the recipe version"`

Expected: FAIL because payload normalization/sync ignores `packaging_items`.

- [ ] **Step 3: Normalize packaging rows**

Add `packaging_items` to normalized payloads as an array of:

```php
[
    'user_packaging_item_id' => is_numeric($row['user_packaging_item_id'] ?? null) ? (int) $row['user_packaging_item_id'] : null,
    'name' => trim((string) ($row['name'] ?? '')),
    'components_per_unit' => max(0, round((float) ($row['components_per_unit'] ?? 1), 3)),
    'notes' => filled($row['notes'] ?? null) ? (string) $row['notes'] : null,
]
```

Filter empty names and zero components.

- [ ] **Step 4: Sync packaging plan rows in `RecipeVersionStructureSynchronizer`**

After phase/item sync, delete existing `RecipeVersionPackagingItem` rows for the version and recreate rows from normalized `packaging_items`.

When `user_packaging_item_id` is present, only keep it if the catalog item belongs to the current user. Snapshot the submitted/catalog name.

- [ ] **Step 5: Include packaging in workbench payload mapping**

`RecipeWorkbenchVersionPayloadMapper::toWorkbenchPayload()` should return:

```php
'packagingItems' => $version->packagingItems
    ->sortBy('position')
    ->map(fn (RecipeVersionPackagingItem $item): array => [
        'id' => 'saved-packaging-'.$item->id,
        'user_packaging_item_id' => $item->user_packaging_item_id,
        'name' => $item->name,
        'components_per_unit' => (float) $item->components_per_unit,
        'notes' => $item->notes,
    ])
    ->values()
    ->all(),
```

Load `packagingItems.packagingItem` in workbench relations.

- [ ] **Step 6: Run focused tests**

Run: `php artisan test --compact tests/Feature/RecipeVersionPackagingPlanTest.php`

Expected: PASS.

---

### Task 3: Workbench Packaging Tab

**Files:**
- Modify: `resources/js/recipe-workbench/component.js`
- Modify: `resources/js/recipe-workbench/payload.js`
- Create: `resources/js/recipe-workbench/sections/packaging-section.js`
- Modify: `resources/views/livewire/dashboard/partials/recipe-workbench/navigation.blade.php`
- Create: `resources/views/livewire/dashboard/partials/recipe-workbench/packaging-tab.blade.php`
- Modify: `resources/views/livewire/dashboard/recipe-workbench.blade.php`
- Test: `tests/Feature/RecipeVersionPackagingPlanTest.php`

- [ ] **Step 1: Write the failing render test**

```php
it('renders packaging as its own workbench tab', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('recipes.create'))
        ->assertSuccessful()
        ->assertSee('Packaging')
        ->assertSee('Packaging plan')
        ->assertSee('Components per unit');
});
```

- [ ] **Step 2: Run the render test to verify it fails**

Run: `php artisan test --compact --filter="renders packaging as its own workbench tab"`

Expected: FAIL because the tab/view does not exist.

- [ ] **Step 3: Add frontend state and serialization**

Add to component initial state:

```js
packagingPlanRows: (payload.packagingItems ?? []).map((row) => ({
    id: row.id ?? `packaging-${crypto.randomUUID?.() ?? Date.now()}`,
    user_packaging_item_id: row.user_packaging_item_id ?? null,
    name: row.name ?? '',
    components_per_unit: row.components_per_unit ?? 1,
    notes: row.notes ?? '',
})),
```

Add to `serializeDraft()`:

```js
packaging_items: state.packagingPlanRows.map((row) => ({
    user_packaging_item_id: row.user_packaging_item_id ?? null,
    name: row.name ?? '',
    components_per_unit: row.components_per_unit ?? 1,
    notes: row.notes ?? null,
})),
```

- [ ] **Step 4: Move packaging plan actions to `packaging-section.js`**

Implement methods:

```js
addPackagingPlanRow(packagingItem = null)
removePackagingPlanRow(rowId)
updatePackagingPlanRow(rowId, patch)
packagingCatalogItemForRow(row)
```

Do not write prices here except through catalog item creation.

- [ ] **Step 5: Add the Blade tab**

The Packaging tab should show item, components per unit, read-only catalog price, notes, remove, add-from-catalog, and new packaging item modal trigger.

- [ ] **Step 6: Run frontend build and focused tests**

Run: `php artisan test --compact --filter="renders packaging as its own workbench tab"`

Run: `npm run build`

Expected: both PASS.

---

### Task 4: Costing Uses Packaging Plan

**Files:**
- Modify: `app/Services/RecipeVersionCostingSynchronizer.php`
- Modify: `resources/js/recipe-workbench/sections/costing-section.js`
- Modify: `resources/views/livewire/dashboard/partials/recipe-workbench/costing-tab.blade.php`
- Test: `tests/Feature/RecipeVersionCostingTest.php`

- [ ] **Step 1: Write the failing costing derivation test**

```php
it('derives costing packaging rows from the packaging plan', function () {
    $user = User::factory()->create();
    $recipe = Recipe::factory()->create(['owner_id' => $user->id]);
    $version = RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'owner_id' => $user->id,
        'is_draft' => true,
    ]);
    $catalogItem = UserPackagingItem::query()->create([
        'user_id' => $user->id,
        'name' => 'Label',
        'unit_cost' => 0.08,
        'currency' => 'EUR',
    ]);

    $version->packagingItems()->create([
        'user_packaging_item_id' => $catalogItem->id,
        'name' => 'Label',
        'components_per_unit' => 2,
        'position' => 1,
    ]);

    $payload = app(RecipeWorkbenchService::class)->costingPayload($recipe, $user);

    expect($payload['packaging_items'])->toHaveCount(1)
        ->and($payload['packaging_items'][0]['name'])->toBe('Label')
        ->and($payload['packaging_items'][0]['components_per_unit'])->toBe(2.0)
        ->and($payload['packaging_items'][0]['unit_cost'])->toBe(0.08);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter="derives costing packaging rows from the packaging plan"`

Expected: FAIL because costing does not inspect `recipe_version_packaging_items`.

- [ ] **Step 3: Reconcile costing packaging rows from packaging plan**

In `ensureCosting()`, after `syncFormulaItems()`, add `syncPackagingItems($costing)`.

Rules:
- Desired rows come from `RecipeVersionPackagingItem`.
- Existing costing row for same `user_packaging_item_id` keeps its `unit_cost`.
- New linked row defaults to `UserPackagingItem::unit_cost`.
- New unlinked row defaults to `0`.
- Deleted packaging plan row removes costing row.

- [ ] **Step 4: Keep packaging price edits price-only**

`replacePackagingItems()` should accept only unit-cost updates for existing derived rows. It should not create arbitrary new packaging plan rows from costing.

- [ ] **Step 5: Update JS and Blade**

Costing tab packaging section becomes read-only structure:
- packaging item name read-only
- components per unit read-only
- unit price editable
- cost per unit computed
- batch cost computed

- [ ] **Step 6: Run focused costing tests**

Run: `php artisan test --compact tests/Feature/RecipeVersionCostingTest.php`

Expected: PASS.

---

### Task 5: Official Recipe Batch Use And Print Context

**Files:**
- Modify: `app/Services/RecipeVersionViewDataBuilder.php`
- Modify: `app/Http/Controllers/RecipeController.php`
- Modify: `resources/views/recipes/version.blade.php`
- Modify: `resources/views/recipes/print.blade.php`
- Test: `tests/Feature/RecipeVersionPagesTest.php`

- [ ] **Step 1: Write failing saved-page batch controls test**

```php
it('shows batch use controls on the official recipe page', function () {
    [$user, $recipe] = createSavedRecipeVersion();

    $this->actingAs($user)
        ->get(route('recipes.saved', ['recipe' => $recipe->id]))
        ->assertSuccessful()
        ->assertSee('Prepare batch')
        ->assertSee('Batch number')
        ->assertSee('Manufacture date')
        ->assertSee('Units produced');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter="shows batch use controls on the official recipe page"`

Expected: FAIL because batch controls are not present.

- [ ] **Step 3: Add batch context parsing**

Read query params:
- `batch_number`
- `manufacture_date` default `today()`
- `batch_basis`
- `units_produced`

Normalize date to `Y-m-d`, positive numeric batch basis, and positive integer units.

- [ ] **Step 4: Render batch controls and pass query params to print links**

Official page keeps formula read-only and adds a `Prepare batch` section with GET form controls. Print links include normalized context.

- [ ] **Step 5: Inject batch context into print views**

Production sheet includes batch number, manufacture date, batch basis, units produced, and packaging plan rows. Costing sheet includes batch context and current costing prices.

- [ ] **Step 6: Run focused page tests**

Run: `php artisan test --compact tests/Feature/RecipeVersionPagesTest.php`

Expected: PASS.

---

### Task 6: Full Verification

**Files:**
- All changed PHP/JS/Blade files

- [ ] **Step 1: Format PHP**

Run: `vendor/bin/pint --dirty --format agent`

Expected: PASS, files formatted.

- [ ] **Step 2: Run full tests**

Run: `php artisan test --compact`

Expected: PASS.

- [ ] **Step 3: Build frontend**

Run: `npm run build`

Expected: PASS.

- [ ] **Step 4: Check whitespace**

Run: `git diff --check`

Expected: no output.
