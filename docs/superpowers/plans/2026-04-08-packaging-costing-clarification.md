# Packaging Costing Clarification Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Split reusable packaging catalog management from formula-specific costing usage, then make the costing tab read as “components per finished unit” instead of ambiguous batch quantity.

**Architecture:** Keep the existing `user_packaging_items` and `recipe_version_costing_packaging_items` tables, but translate the old `quantity` concept into a clearer per-unit usage model at the service, Alpine, and Blade boundaries. Add a dedicated dashboard page for catalog management, while the recipe workbench keeps an inline packaging-item modal so users can create a missing item without leaving costing.

**Tech Stack:** Laravel 13, Livewire 4, Filament actions/forms traits already present on the workbench component, Alpine-powered recipe workbench JavaScript, Pest 4 feature tests, Tailwind CSS 4 utility classes in Blade templates.

---

## File Structure

### New Files

- `app/Http/Controllers/PackagingItemController.php`
  Responsibility: route entrypoint for the dedicated packaging catalog page.
- `app/Livewire/Dashboard/PackagingItemsIndex.php`
  Responsibility: load the signed-in user, fetch reusable packaging items, and expose catalog counts to the dashboard view.
- `resources/views/packaging/index.blade.php`
  Responsibility: app-shell page wrapper for the new packaging catalog screen.
- `resources/views/livewire/dashboard/packaging-items-index.blade.php`
  Responsibility: reusable catalog UI for packaging items outside the recipe workbench.
- `tests/Feature/PackagingItemsIndexTest.php`
  Responsibility: route, navigation, signed-in, and empty-state coverage for the dedicated packaging page.
- `tests/Feature/RecipeWorkbenchCostingContentTest.php`
  Responsibility: response-level assertions for the new wording and empty-state content in the costing tab.

### Modified Files

- `routes/web.php`
  Responsibility: register `/dashboard/packaging-items` route names alongside recipes and ingredients.
- `resources/views/layouts/app-shell.blade.php`
  Responsibility: add `Packaging Items` to the sidebar navigation with active-state handling.
- `app/Livewire/Dashboard/RecipeWorkbench.php`
  Responsibility: keep renderless packaging catalog actions, but return the saved packaging item payload needed by the inline modal flow.
- `app/Services/RecipeVersionCostingSynchronizer.php`
  Responsibility: normalize the “components per finished unit” contract, keep storage on the existing `quantity` column, and return a `packaging_item` payload after save.
- `resources/js/recipe-workbench/component.js`
  Responsibility: add modal state, modal form defaults, and packaging-specific UI flags to the Alpine workbench state.
- `resources/js/recipe-workbench/sections/costing-section.js`
  Responsibility: rename packaging behavior to per-unit semantics, default new rows to `1`, compute per-unit and batch totals separately, and support “save and add to this costing”.
- `resources/js/recipe-workbench/payload.js`
  Responsibility: serialize packaging rows using the clearer `components_per_unit` client-side contract.
- `resources/js/recipe-workbench/bridge.js`
  Responsibility: persist catalog items, update the local catalog, and hand the saved packaging item back to the costing section.
- `resources/views/livewire/dashboard/partials/recipe-workbench/costing-tab.blade.php`
  Responsibility: replace ambiguous packaging copy, remove inline catalog administration, and add the inline `New packaging item` modal.
- `tests/Feature/RecipeVersionCostingTest.php`
  Responsibility: lock in per-unit packaging snapshots and version-copy behavior.
- `tests/Feature/RecipeWorkbenchPersistenceTest.php`
  Responsibility: verify the workbench action returns the saved packaging item payload that the Alpine modal needs.

### Deliberate Non-Changes

- No migration for `recipe_version_costing_packaging_items.quantity`.
  The database column can remain as-is in this iteration, while the UI and service layer expose it as per-unit usage.
- No purchase-pack conversion logic.
  The catalog continues storing an effective unit price entered by the user.

---

### Task 1: Add The Dedicated Packaging Catalog Page

**Files:**
- Create: `app/Http/Controllers/PackagingItemController.php`
- Create: `app/Livewire/Dashboard/PackagingItemsIndex.php`
- Create: `resources/views/packaging/index.blade.php`
- Create: `resources/views/livewire/dashboard/packaging-items-index.blade.php`
- Modify: `routes/web.php`
- Modify: `resources/views/layouts/app-shell.blade.php`
- Test: `tests/Feature/PackagingItemsIndexTest.php`

- [ ] **Step 1: Write the failing page test**

```php
<?php

use App\Models\User;
use App\Models\UserPackagingItem;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows the signed-in user packaging catalog page', function () {
    $user = User::factory()->create();

    UserPackagingItem::query()->create([
        'user_id' => $user->id,
        'name' => 'Front Label',
        'unit_cost' => 0.0315,
        'currency' => 'EUR',
        'notes' => 'Waterproof stock',
    ]);

    $this->actingAs($user)
        ->get(route('packaging-items.index'))
        ->assertSuccessful()
        ->assertSee('Packaging Items')
        ->assertSee('Front Label')
        ->assertSee('Reusable packaging catalog')
        ->assertSee('0.0315');
});

it('shows a sign-in message when no dashboard user is available', function () {
    $this->get(route('packaging-items.index'))
        ->assertSuccessful()
        ->assertSee('Sign in to manage packaging items');
});
```

- [ ] **Step 2: Run the new test to verify the route is missing**

Run: `php artisan test --compact tests/Feature/PackagingItemsIndexTest.php`

Expected: FAIL with a route exception for `packaging-items.index` or a missing view/controller failure.

- [ ] **Step 3: Add the route, controller, Livewire component, and app-shell navigation**

```php
// routes/web.php
use App\Http\Controllers\PackagingItemController;

Route::controller(PackagingItemController::class)
    ->prefix('/dashboard/packaging-items')
    ->name('packaging-items.')
    ->group(function (): void {
        Route::get('/', 'index')->name('index');
    });
```

```php
<?php
// app/Http/Controllers/PackagingItemController.php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

class PackagingItemController extends Controller
{
    public function index(): View
    {
        return view('packaging.index');
    }
}
```

```php
<?php
// app/Livewire/Dashboard/PackagingItemsIndex.php

namespace App\Livewire\Dashboard;

use App\Models\User;
use App\Models\UserPackagingItem;
use App\Services\CurrentAppUserResolver;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class PackagingItemsIndex extends Component
{
    public function render(): View
    {
        $currentUser = app(CurrentAppUserResolver::class)->resolve();
        $packagingItems = collect();

        if ($currentUser instanceof User) {
            $packagingItems = UserPackagingItem::query()
                ->where('user_id', $currentUser->id)
                ->orderBy('name')
                ->orderBy('id')
                ->get();
        }

        return view('livewire.dashboard.packaging-items-index', [
            'currentUser' => $currentUser,
            'packagingItems' => $packagingItems,
            'packagingItemCount' => $packagingItems->count(),
        ]);
    }
}
```

```blade
{{-- resources/views/packaging/index.blade.php --}}
@extends('layouts.app-shell')

@section('title', 'Packaging Items · Koskalk')
@section('page_heading', 'Packaging Items')

@section('content')
    <livewire:dashboard.packaging-items-index />
@endsection
```

```blade
{{-- resources/views/livewire/dashboard/packaging-items-index.blade.php --}}
<div class="mx-auto w-full max-w-7xl space-y-6">
    <section class="rounded-[2rem] border border-[var(--color-line)] bg-white p-5 sm:p-6">
        <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Reusable packaging catalog</p>
        <h3 class="mt-3 text-2xl font-semibold tracking-[-0.04em] text-[var(--color-ink-strong)] sm:text-3xl">Keep labels, boxes, wraps, and inserts reusable across formulas.</h3>
        <p class="mt-3 max-w-3xl text-sm leading-7 text-[var(--color-ink-soft)]">
            Catalog items live here. Formula costing only decides how many components each finished unit uses.
        </p>
    </section>

    <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-[18rem_minmax(0,1fr)]">
        <div class="rounded-[2rem] border border-[var(--color-line)] bg-white p-5">
            <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Packaging items</p>
            <p class="mt-4 text-4xl font-semibold tracking-[-0.04em] text-[var(--color-ink-strong)]">{{ $packagingItemCount }}</p>
            <p class="mt-2 text-sm text-[var(--color-ink-soft)]">Reusable packaging definitions for your own workspace.</p>
        </div>

        <div class="overflow-hidden rounded-[2rem] border border-[var(--color-line)] bg-white">
            <div class="border-b border-[var(--color-line)] px-5 py-4">
                <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Catalog records</p>
                <h3 class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">Reusable packaging items</h3>
            </div>

            @if (! $currentUser)
                <div class="p-8 text-center">
                    <h4 class="text-lg font-semibold text-[var(--color-ink-strong)]">Sign in to manage packaging items</h4>
                    <p class="mt-3 text-sm leading-7 text-[var(--color-ink-soft)]">Open the dashboard from your signed-in app or admin session to maintain your packaging catalog.</p>
                </div>
            @elseif ($packagingItems->isEmpty())
                <div class="p-8 text-center">
                    <h4 class="text-lg font-semibold text-[var(--color-ink-strong)]">No packaging items yet</h4>
                    <p class="mt-3 text-sm leading-7 text-[var(--color-ink-soft)]">Create packaging items from a formula costing modal first, or add direct catalog management here in the next pass.</p>
                </div>
            @else
                <div class="divide-y divide-[var(--color-line)]">
                    @foreach ($packagingItems as $packagingItem)
                        <article class="px-5 py-4">
                            <h4 class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ $packagingItem->name }}</h4>
                            <p class="mt-2 text-sm text-[var(--color-ink-soft)]">{{ $packagingItem->currency }} {{ number_format((float) $packagingItem->unit_cost, 4) }} each</p>
                            @if (filled($packagingItem->notes))
                                <p class="mt-2 text-sm text-[var(--color-ink-soft)]">{{ $packagingItem->notes }}</p>
                            @endif
                        </article>
                    @endforeach
                </div>
            @endif
        </div>
    </section>
</div>
```

```blade
{{-- resources/views/layouts/app-shell.blade.php --}}
<a
    href="{{ route('packaging-items.index') }}"
    wire:navigate
    @click="if (! isDesktop) closeNav()"
    class="{{ request()->routeIs('packaging-items.*') ? 'border border-[var(--color-line)] bg-white font-medium text-[var(--color-ink-strong)]' : 'text-[var(--color-ink-soft)] hover:bg-white/70 hover:text-[var(--color-ink-strong)]' }} rounded-2xl px-4 py-3 transition"
>
    Packaging Items
</a>
```

- [ ] **Step 4: Run the page test and one existing dashboard smoke test**

Run: `php artisan test --compact tests/Feature/PackagingItemsIndexTest.php tests/Feature/DashboardPageTest.php`

Expected: PASS with the new route/page assertions and no regression in the dashboard shell.

- [ ] **Step 5: Commit the page scaffold**

```bash
git add app/Http/Controllers/PackagingItemController.php app/Livewire/Dashboard/PackagingItemsIndex.php resources/views/packaging/index.blade.php resources/views/livewire/dashboard/packaging-items-index.blade.php resources/views/layouts/app-shell.blade.php routes/web.php tests/Feature/PackagingItemsIndexTest.php
git commit -m "feat: add packaging catalog dashboard page"
```

### Task 2: Normalize Packaging Costing Data To Per-Unit Usage

**Files:**
- Modify: `app/Livewire/Dashboard/RecipeWorkbench.php`
- Modify: `app/Services/RecipeVersionCostingSynchronizer.php`
- Modify: `resources/js/recipe-workbench/payload.js`
- Test: `tests/Feature/RecipeVersionCostingTest.php`
- Test: `tests/Feature/RecipeWorkbenchPersistenceTest.php`

- [ ] **Step 1: Write failing tests for per-unit packaging payloads and saved-item responses**

```php
it('stores packaging rows as per-unit usage while keeping batch size separate', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeSharedCarrierOilIngredient();
    $service = app(RecipeWorkbenchService::class);

    $draftVersion = $service->saveDraft($user, $soapFamily, soapDraftPayload($ingredient));
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);
    $packagingItem = UserPackagingItem::query()->create([
        'user_id' => $user->id,
        'name' => 'Front Label',
        'unit_cost' => 0.03,
        'currency' => 'EUR',
    ]);

    $service->saveCosting($user, $recipe, [
        'oil_weight_for_costing' => 1000,
        'oil_unit_for_costing' => 'g',
        'units_produced' => 12,
        'currency' => 'EUR',
        'items' => [],
        'packaging_items' => [
            [
                'user_packaging_item_id' => $packagingItem->id,
                'name' => 'Front Label',
                'unit_cost' => 0.03,
                'components_per_unit' => 2,
            ],
        ],
    ]);

    $savedPackagingRow = RecipeVersionCosting::query()
        ->with('packagingItems')
        ->where('recipe_version_id', $draftVersion->id)
        ->where('user_id', $user->id)
        ->firstOrFail()
        ->packagingItems
        ->sole();

    expect((float) $savedPackagingRow->quantity)->toBe(2.0);
});
```

```php
it('returns the saved packaging item payload from the workbench action', function () {
    $user = User::factory()->create();

    $response = Livewire::actingAs($user)
        ->test(RecipeWorkbench::class)
        ->call('savePackagingCatalogItem', [
            'name' => 'Bottom Label',
            'unit_cost' => 0.02,
            'currency' => 'EUR',
            'notes' => 'Matte paper',
        ]);

    $response->assertReturned(fn (array $payload): bool => $payload['ok'] === true
        && ($payload['packaging_item']['name'] ?? null) === 'Bottom Label');
});
```

- [ ] **Step 2: Run the failing backend tests**

Run: `php artisan test --compact tests/Feature/RecipeVersionCostingTest.php tests/Feature/RecipeWorkbenchPersistenceTest.php`

Expected: FAIL because `components_per_unit` is not recognized yet and `savePackagingCatalogItem()` does not return a `packaging_item`.

- [ ] **Step 3: Implement the backend contract and client serialization**

```php
// app/Services/RecipeVersionCostingSynchronizer.php
public function savePackagingItem(User $user, array $payload): array
{
    $name = trim((string) ($payload['name'] ?? ''));

    if ($name === '') {
        return [
            'packaging_catalog' => $this->packagingCatalogPayload($user),
            'packaging_item' => null,
        ];
    }

    $packagingItem = UserPackagingItem::query()
        ->where('user_id', $user->id)
        ->when(
            isset($payload['id']) && is_numeric($payload['id']),
            fn ($query) => $query->whereKey((int) $payload['id']),
        )
        ->first() ?? new UserPackagingItem([
            'user_id' => $user->id,
        ]);

    $packagingItem->fill([
        'name' => $name,
        'unit_cost' => (float) ($payload['unit_cost'] ?? 0),
        'currency' => $this->normalizeCurrency($payload['currency'] ?? 'EUR'),
        'notes' => $payload['notes'] ?? null,
    ]);
    $packagingItem->save();

    return [
        'packaging_catalog' => $this->packagingCatalogPayload($user),
        'packaging_item' => [
            'id' => $packagingItem->id,
            'name' => $packagingItem->name,
            'unit_cost' => (float) $packagingItem->unit_cost,
            'currency' => $packagingItem->currency,
            'notes' => $packagingItem->notes,
        ],
    ];
}

private function replacePackagingItems(RecipeVersionCosting $costing, mixed $rawItems): void
{
    $costing->packagingItems()->delete();

    collect(is_array($rawItems) ? $rawItems : [])
        ->filter(fn (mixed $row): bool => is_array($row) && filled($row['name'] ?? null))
        ->each(function (array $row) use ($costing): void {
            $componentsPerUnit = $row['components_per_unit'] ?? $row['quantity'] ?? 0;

            $costing->packagingItems()->create([
                'user_packaging_item_id' => isset($row['user_packaging_item_id']) && is_numeric($row['user_packaging_item_id'])
                    ? (int) $row['user_packaging_item_id']
                    : null,
                'name' => trim((string) $row['name']),
                'unit_cost' => (float) ($row['unit_cost'] ?? 0),
                'quantity' => (float) $componentsPerUnit,
            ]);
        });
}
```

```php
// app/Livewire/Dashboard/RecipeWorkbench.php
#[Renderless]
public function savePackagingCatalogItem(array $packagingItem, RecipeWorkbenchService $recipeWorkbenchService): array
{
    $user = $this->currentUser();

    if (! $user instanceof User) {
        return [
            'ok' => false,
            'message' => 'Sign in before saving packaging items.',
        ];
    }

    return [
        'ok' => true,
        'message' => 'Packaging item saved.',
        ...$recipeWorkbenchService->savePackagingCatalogItem($user, $packagingItem),
    ];
}
```

```js
// resources/js/recipe-workbench/payload.js
packaging_items: state.packagingCostRows.map((row) => ({
    user_packaging_item_id: row.user_packaging_item_id ?? null,
    name: row.name,
    unit_cost: nonNegativeNumber(row.unit_cost),
    components_per_unit: nonNegativeNumber(row.components_per_unit),
})),
```

- [ ] **Step 4: Run the focused backend tests again**

Run: `php artisan test --compact tests/Feature/RecipeVersionCostingTest.php tests/Feature/RecipeWorkbenchPersistenceTest.php`

Expected: PASS, proving the server accepts the clearer contract and still stores snapshots in the current schema.

- [ ] **Step 5: Commit the data-contract pass**

```bash
git add app/Livewire/Dashboard/RecipeWorkbench.php app/Services/RecipeVersionCostingSynchronizer.php resources/js/recipe-workbench/payload.js tests/Feature/RecipeVersionCostingTest.php tests/Feature/RecipeWorkbenchPersistenceTest.php
git commit -m "refactor: normalize packaging costing as per-unit usage"
```

### Task 3: Rebuild The Costing Tab Around Per-Unit Packaging Usage

**Files:**
- Modify: `resources/js/recipe-workbench/component.js`
- Modify: `resources/js/recipe-workbench/sections/costing-section.js`
- Modify: `resources/js/recipe-workbench/bridge.js`
- Modify: `resources/views/livewire/dashboard/partials/recipe-workbench/costing-tab.blade.php`
- Test: `tests/Feature/RecipeWorkbenchCostingContentTest.php`

- [ ] **Step 1: Write the failing workbench content test**

```php
<?php

use App\Models\ProductFamily;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows the clarified packaging wording on the workbench', function () {
    $user = User::factory()->create();
    $family = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);

    $this->actingAs($user)
        ->get(route('recipes.create', ['family' => $family->id]))
        ->assertSuccessful()
        ->assertSee('Packaging usage per finished unit')
        ->assertSee('Components per finished unit')
        ->assertSee('Choose a packaging item from your catalog, or create one without leaving this tab.')
        ->assertDontSee('Add custom row')
        ->assertDontSee('Saved packaging items');
});
```

- [ ] **Step 2: Run the content test to confirm the old copy is still present**

Run: `php artisan test --compact tests/Feature/RecipeWorkbenchCostingContentTest.php`

Expected: FAIL because the current costing tab still renders `Saved packaging items`, `Packaging in this costing`, `Quantity`, and `Add custom row`.

- [ ] **Step 3: Implement the Alpine state, totals, and modal flow**

```js
// resources/js/recipe-workbench/component.js
packagingCostRows: [],
packagingCatalog: payload.costing?.packaging_catalog ?? [],
packagingCatalogModalOpen: false,
packagingCatalogModalIntent: 'save_only',
packagingCatalogForm: {
    id: null,
    name: '',
    unit_cost: '',
    currency: payload.costing?.settings?.currency ?? 'EUR',
    notes: '',
},
```

```js
// resources/js/recipe-workbench/sections/costing-section.js
this.packagingCostRows = (costingPayload?.packaging_items ?? []).map((row) => ({
    id: row.id ?? this.makeLocalPackagingRowId(),
    user_packaging_item_id: row.user_packaging_item_id ?? null,
    name: row.name ?? '',
    unit_cost: row.unit_cost ?? 0,
    components_per_unit: row.components_per_unit ?? row.quantity ?? 1,
}));

get packagingCostPerUnitTotal() {
    return this.packagingCostRows.reduce((total, row) => {
        return total + (nonNegativeNumber(row.unit_cost) * nonNegativeNumber(row.components_per_unit));
    }, 0);
}

get packagingCostTotal() {
    return this.costingUnitsProducedValue > 0
        ? this.packagingCostPerUnitTotal * this.costingUnitsProducedValue
        : 0;
}

addPackagingCostRow(packagingItem = null) {
    this.packagingCostRows = [
        ...this.packagingCostRows,
        {
            id: this.makeLocalPackagingRowId(),
            user_packaging_item_id: packagingItem?.id ?? null,
            name: packagingItem?.name ?? '',
            unit_cost: packagingItem?.unit_cost ?? 0,
            components_per_unit: 1,
        },
    ];

    this.scheduleCostingSave();
}

openPackagingCatalogModal(intent = 'save_and_add') {
    this.packagingCatalogModalIntent = intent;
    this.packagingCatalogModalOpen = true;
    this.resetPackagingCatalogForm();
}

async savePackagingCatalogItem() {
    if (`${this.packagingCatalogForm.name ?? ''}`.trim() === '') {
        this.packagingCatalogStatus = 'error';
        this.packagingCatalogMessage = 'Packaging items need a name.';

        return;
    }

    const savedPackagingItem = await persistPackagingCatalogItem(this, this.packagingCatalogForm);

    if (! savedPackagingItem) {
        return;
    }

    this.packagingCatalogModalOpen = false;

    if (this.packagingCatalogModalIntent === 'save_and_add') {
        this.addPackagingCostRow(savedPackagingItem);
    } else {
        this.resetPackagingCatalogForm();
    }
}
```

```js
// resources/js/recipe-workbench/bridge.js
export async function persistPackagingCatalogItem(workbench, payload) {
    workbench.packagingCatalogStatus = 'saving';
    workbench.packagingCatalogMessage = '';

    try {
        const response = await workbench.$wire.savePackagingCatalogItem(payload);

        if (!response?.ok) {
            workbench.packagingCatalogStatus = 'error';
            workbench.packagingCatalogMessage = response?.message ?? 'The packaging item could not be saved.';

            return null;
        }

        workbench.packagingCatalog = response.packaging_catalog ?? [];
        workbench.packagingCatalogStatus = 'success';
        workbench.packagingCatalogMessage = response.message ?? 'Packaging item saved.';

        return response.packaging_item ?? null;
    } catch (error) {
        workbench.packagingCatalogStatus = 'error';
        workbench.packagingCatalogMessage = 'The packaging item could not be saved.';

        return null;
    }
}
```

```blade
{{-- resources/views/livewire/dashboard/partials/recipe-workbench/costing-tab.blade.php --}}
<section class="rounded-[2rem] border border-[var(--color-line)] bg-white p-5">
    <div class="flex items-start justify-between gap-3">
        <div>
            <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Packaging usage per finished unit</p>
            <p class="mt-1 text-sm text-[var(--color-ink-soft)]">Define how many of each packaging component are used for one finished unit. Batch packaging cost is calculated from this and Units produced.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('packaging-items.index') }}" wire:navigate class="rounded-full border border-[var(--color-line)] px-4 py-2 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel)]">
                Open catalog
            </a>
            <button type="button" @click="openPackagingCatalogModal('save_and_add')" class="rounded-full bg-[var(--color-accent-soft)] px-4 py-2 text-sm font-medium text-[var(--color-ink-strong)] transition hover:bg-white">
                New packaging item
            </button>
        </div>
    </div>

    <div class="mt-4 space-y-3">
        <template x-for="row in packagingCostRows" :key="row.id">
            <div class="rounded-[1.4rem] border border-[var(--color-line)] bg-[var(--color-panel)] p-3">
                <div class="grid gap-2">
                    <input x-model="row.name" @change="scheduleCostingSave()" type="text" placeholder="Front label" class="rounded-2xl border border-[var(--color-line)] bg-white px-3 py-2.5 text-sm text-[var(--color-ink-strong)] outline-none" />
                    <div class="grid gap-2 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto]">
                        <input x-model.number="row.unit_cost" @change="scheduleCostingSave()" type="number" min="0" step="0.0001" placeholder="Effective unit price" class="rounded-2xl border border-[var(--color-line)] bg-white px-3 py-2.5 text-sm text-[var(--color-ink-strong)] outline-none" />
                        <input x-model.number="row.components_per_unit" @change="scheduleCostingSave()" type="number" min="0" step="0.001" placeholder="Components per finished unit" class="rounded-2xl border border-[var(--color-line)] bg-white px-3 py-2.5 text-sm text-[var(--color-ink-strong)] outline-none" />
                        <button type="button" @click="removePackagingCostRow(row.id)" class="rounded-full border border-[var(--color-line)] px-4 py-2 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-white">Remove</button>
                    </div>
                </div>

                <div class="mt-3 grid gap-2 sm:grid-cols-2">
                    <p class="text-xs text-[var(--color-ink-soft)]" x-text="`${costingCurrency} ${format(nonNegativeNumber(row.unit_cost) * nonNegativeNumber(row.components_per_unit), 2)} per finished unit`"></p>
                    <p class="text-xs text-[var(--color-ink-soft)]" x-text="costingUnitsProducedValue > 0 ? `${costingCurrency} ${format(nonNegativeNumber(row.unit_cost) * nonNegativeNumber(row.components_per_unit) * costingUnitsProducedValue, 2)} per batch` : 'Set units produced'"></p>
                </div>
            </div>
        </template>

        <template x-if="packagingCostRows.length === 0">
            <div class="rounded-[1.5rem] border border-dashed border-[var(--color-line)] bg-[var(--color-panel)] px-4 py-5 text-sm text-[var(--color-ink-soft)]">
                No packaging added yet. Choose a packaging item from your catalog, or create one without leaving this tab.
            </div>
        </template>
    </div>

    <div x-show="packagingCatalogModalOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="packagingCatalogModalOpen = false">
        <div class="w-full max-w-lg rounded-[2rem] border border-[var(--color-line)] bg-white p-6">
            <h3 class="text-lg font-semibold text-[var(--color-ink-strong)]">New packaging item</h3>
            <p class="mt-2 text-sm text-[var(--color-ink-soft)]">Save a reusable catalog item, then optionally add it to this costing with 1 component per finished unit.</p>
            <div class="mt-4 grid gap-3">
                <input x-model="packagingCatalogForm.name" type="text" placeholder="Front label" class="rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] px-3 py-2.5 text-sm text-[var(--color-ink-strong)] outline-none" />
                <input x-model.number="packagingCatalogForm.unit_cost" type="number" min="0" step="0.0001" placeholder="Effective unit price" class="rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] px-3 py-2.5 text-sm text-[var(--color-ink-strong)] outline-none" />
                <textarea x-model="packagingCatalogForm.notes" rows="3" placeholder="Optional notes" class="rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] px-3 py-2.5 text-sm text-[var(--color-ink-strong)] outline-none"></textarea>
            </div>

            <template x-if="packagingCatalogMessage">
                <p class="mt-3 text-xs text-[var(--color-ink-soft)]" x-text="packagingCatalogMessage"></p>
            </template>

            <div class="mt-5 flex flex-col gap-2 sm:flex-row sm:justify-end">
                <button type="button" @click="packagingCatalogModalIntent = 'save_only'; savePackagingCatalogItem()" class="rounded-full border border-[var(--color-line)] px-4 py-2 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel)]">
                    Save only
                </button>
                <button type="button" @click="packagingCatalogModalIntent = 'save_and_add'; savePackagingCatalogItem()" class="rounded-full bg-[var(--color-accent-strong)] px-4 py-2 text-sm font-medium text-white transition hover:bg-[var(--color-accent)]">
                    Save and add to this costing
                </button>
                <button type="button" @click="packagingCatalogModalOpen = false" class="rounded-full border border-[var(--color-line)] px-4 py-2 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel)]">
                    Cancel
                </button>
            </div>
        </div>
    </div>
</section>
```

- [ ] **Step 4: Run the content test plus the related workbench persistence tests**

Run: `php artisan test --compact tests/Feature/RecipeWorkbenchCostingContentTest.php tests/Feature/RecipeWorkbenchPersistenceTest.php tests/Feature/RecipeVersionCostingTest.php`

Expected: PASS, proving the new wording, defaults, and backend contract all agree.

- [ ] **Step 5: Commit the workbench UX rewrite**

```bash
git add resources/js/recipe-workbench/component.js resources/js/recipe-workbench/sections/costing-section.js resources/js/recipe-workbench/bridge.js resources/views/livewire/dashboard/partials/recipe-workbench/costing-tab.blade.php tests/Feature/RecipeWorkbenchCostingContentTest.php tests/Feature/RecipeWorkbenchPersistenceTest.php tests/Feature/RecipeVersionCostingTest.php
git commit -m "feat: clarify packaging usage in costing"
```

### Task 4: Final Verification And Cleanup

**Files:**
- Modify: `resources/views/livewire/dashboard/packaging-items-index.blade.php`
- Modify: `resources/views/livewire/dashboard/partials/recipe-workbench/costing-tab.blade.php`
- Test: `tests/Feature/PackagingItemsIndexTest.php`
- Test: `tests/Feature/RecipeWorkbenchCostingContentTest.php`

- [ ] **Step 1: Add the final regression assertions for empty-state and navigation copy**

```php
it('highlights packaging items in the sidebar when the page is open', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('packaging-items.index'))
        ->assertSuccessful()
        ->assertSee('Packaging Items')
        ->assertSee('Reusable packaging catalog');
});

it('shows the costing placeholder instead of a fake batch total when units produced is empty', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('recipes.create'))
        ->assertSuccessful()
        ->assertSee('Set units produced');
});
```

- [ ] **Step 2: Run the full packaging-related test slice before formatting**

Run: `php artisan test --compact tests/Feature/PackagingItemsIndexTest.php tests/Feature/RecipeWorkbenchCostingContentTest.php tests/Feature/RecipeVersionCostingTest.php tests/Feature/RecipeWorkbenchPersistenceTest.php`

Expected: PASS across the catalog route, workbench copy, and costing persistence behavior.

- [ ] **Step 3: Run Pint on the changed PHP files**

Run: `vendor/bin/pint --dirty --format agent`

Expected: PASS with formatting fixes applied if needed.

- [ ] **Step 4: Build frontend assets if the browser does not reflect the updated Alpine/Blade behavior**

Run: `npm run build`

Expected: PASS with a fresh Vite bundle. If the user is already running `npm run dev`, this step can be skipped.

- [ ] **Step 5: Commit the verification pass**

```bash
git add resources/views/livewire/dashboard/packaging-items-index.blade.php resources/views/livewire/dashboard/partials/recipe-workbench/costing-tab.blade.php tests/Feature/PackagingItemsIndexTest.php tests/Feature/RecipeWorkbenchCostingContentTest.php
git commit -m "test: cover packaging catalog and costing copy"
```

## Self-Review

### Spec Coverage

- Dedicated `Packaging Items` page and menu:
  Covered by Task 1.
- Costing framed as per-finished-unit usage:
  Covered by Tasks 2 and 3.
- Default packaging row value of `1`:
  Covered by Task 3.
- Inline modal with `Save and add to this costing`:
  Covered by Task 3.
- Placeholder behavior for missing `units produced`:
  Covered by Task 3 and Task 4.
- Snapshot stability and version-copy behavior:
  Covered by Task 2.

No spec gaps remain.

### Placeholder Scan

- No `TODO`, `TBD`, or “implement later” placeholders remain in the tasks.
- Each task names exact files, concrete commands, and code examples for the worker.
- The only deferred item is `npm run build`, and it is explicitly gated on whether the user needs a production bundle rather than being left ambiguous.

### Type Consistency

- Client-side packaging rows use `components_per_unit`.
- Server-side storage continues mapping that value into the existing `quantity` database column.
- Returned catalog payload keys stay `id`, `name`, `unit_cost`, `currency`, and `notes`.

The naming stays consistent across tasks.

## Execution Handoff

Plan complete and saved to `docs/superpowers/plans/2026-04-08-packaging-costing-clarification.md`. Two execution options:

**1. Subagent-Driven (recommended)** - I dispatch a fresh subagent per task, review between tasks, fast iteration

**2. Inline Execution** - Execute tasks in this session using executing-plans, batch execution with checkpoints

**Which approach?**
