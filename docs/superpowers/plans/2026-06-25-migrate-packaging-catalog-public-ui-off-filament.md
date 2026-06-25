# Migrate Packaging Catalog Public UI Off Filament Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the complete user-facing packaging catalog flow (`PackagingItemsIndex` and `PackagingItemEditor`) off Filament Tables/Forms and onto custom Livewire + Blade UI that matches the `sk-*` public design system.

**Architecture:** Replace the index `Filament\Tables\TableComponent` with a plain `Livewire\Component` using pagination, and replace the editor's Filament form schema with explicit Livewire state plus a hand-written Blade form. Keep `UserPackagingItemAuthoringService` as the unchanged domain boundary for create, update, inline price editing, and delete; the migration is presentation-only except for adding a `UserPackagingItem::featuredImageUrl()` convenience method that delegates to `MediaStorage`.

**Tech Stack:** Laravel 13, Livewire 4, Blade, Alpine.js (minimal modal/preview behavior), Tailwind 4 (`sk-*` design tokens), Pest 4.

**Scope of THIS plan:** Packaging catalog vertical slice only: index + editor. Follow-on plans: `IngredientsIndex`, then `IngredientEditor` with any reusable field/repeater kit that packaging proves is actually needed, then `RecipeWorkbench` residual cleanup.

---

## Files

- **Modify** `resources/css/shared/soapkraft.css` - append `.sk-table` public table styles.
- **Modify** `app/Models/UserPackagingItem.php` - add `featuredImageUrl()` using `MediaStorage::publicUrl()`.
- **Rewrite** `app/Livewire/Dashboard/PackagingItemsIndex.php` - non-Filament Livewire table component.
- **Rewrite** `resources/views/livewire/dashboard/packaging-items-index.blade.php` - custom catalog table.
- **Rewrite** `app/Livewire/Dashboard/PackagingItemEditor.php` - non-Filament Livewire editor component.
- **Rewrite** `resources/views/livewire/dashboard/packaging-item-editor.blade.php` - custom editor form.
- **Modify** `tests/Feature/PackagingItemsIndexTest.php` - replace Filament-specific table/form tests with public Livewire contract tests.

**Do not touch:** `app/Services/UserPackagingItemAuthoringService.php`, routes, controllers, migrations, or ingredient components. This first slice should prove the pattern without dragging in `IngredientEditor` complexity.

---

## Task 1: Add Shared Public Table Styles

**Files:**
- Modify: `resources/css/shared/soapkraft.css`

- [ ] **Step 1: Append the table styles**

Append this block to the end of `resources/css/shared/soapkraft.css`:

```css
@layer components {
    .sk-table-wrapper {
        overflow-x: auto;
    }

    .sk-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        font-size: 0.875rem;
    }

    .sk-table thead th {
        text-align: left;
        font-weight: 600;
        color: var(--color-ink-soft);
        font-size: 0.75rem;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        padding: 0.75rem 1rem;
        border-bottom: 1px solid var(--color-line);
        background: var(--color-panel);
        white-space: nowrap;
    }

    .sk-table tbody td {
        padding: 0.75rem 1rem;
        border-bottom: 1px solid var(--color-line);
        color: var(--color-ink);
        vertical-align: middle;
    }

    .sk-table tbody tr:nth-child(even) td {
        background: color-mix(in oklab, var(--color-panel) 50%, var(--color-surface) 50%);
    }

    .sk-table tbody tr:last-child td {
        border-bottom: 0;
    }

    .sk-table-sort-button {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        color: inherit;
        font-size: inherit;
        letter-spacing: inherit;
        text-transform: inherit;
    }
}
```

- [ ] **Step 2: Rebuild CSS**

Run:

```bash
npm run build
```

Expected: Vite completes with no errors.

- [ ] **Step 3: Commit**

```bash
git add resources/css/shared/soapkraft.css
git commit -m "Add shared catalog table styles"
```

---

## Task 2: Add `featuredImageUrl()` To `UserPackagingItem`

**Files:**
- Modify: `app/Models/UserPackagingItem.php`

- [ ] **Step 1: Add the import**

Add this import:

```php
use App\Services\MediaStorage;
```

- [ ] **Step 2: Add the method**

Add this method after `casts()`:

```php
    public function featuredImageUrl(): ?string
    {
        return MediaStorage::publicUrl($this->featured_image_path);
    }
```

Do not add `featured_image_path` to the model fillable list just for tests. The authoring service already persists that attribute explicitly.

- [ ] **Step 3: Verify parse**

Run:

```bash
php artisan model:show UserPackagingItem
```

Expected: command prints model details with no PHP errors.

- [ ] **Step 4: Format**

Run:

```bash
vendor/bin/pint --dirty --format agent
```

Expected: Pint completes.

- [ ] **Step 5: Commit**

```bash
git add app/Models/UserPackagingItem.php
git commit -m "Add packaging item featured image URL"
```

---

## Task 3: Rewrite Packaging Flow Tests To The Non-Filament Contract

**Files:**
- Modify: `tests/Feature/PackagingItemsIndexTest.php`

- [ ] **Step 1: Update imports**

Remove:

```php
use Filament\Actions\Testing\TestAction;
```

Add:

```php
use App\Services\MediaStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
```

- [ ] **Step 2: Replace the signed-in index smoke test**

Replace `it('lets a signed-in user open the packaging items page and see saved items', ...)` with:

```php
it('lets a signed-in user open the packaging items page and see saved items', function () {
    $user = User::factory()->create();

    UserPackagingItem::query()->create([
        'user_id' => $user->id,
        'name' => 'Tube 50 g',
        'unit_cost' => 0.1200,
        'currency' => 'EUR',
        'notes' => 'Reusable catalog item',
    ]);

    $this->actingAs($user)
        ->get(route('packaging-items.index'))
        ->assertSuccessful()
        ->assertSee('Manage packaging used in recipe costing.')
        ->assertSee('Packaging catalog')
        ->assertSee('Unit price (EUR)')
        ->assertSee('0.12')
        ->assertDontSee('0.1200')
        ->assertSee('Tube 50 g');
});
```

- [ ] **Step 3: Replace the default currency index test**

Replace `it('shows packaging prices in the users current default currency', ...)` with:

```php
it('shows packaging prices in the users current default currency', function () {
    $user = User::factory()->create();
    Workspace::factory()->create([
        'owner_user_id' => $user->id,
        'default_currency' => 'GBP',
    ]);

    UserPackagingItem::query()->create([
        'user_id' => $user->id,
        'name' => 'Tube 50 g',
        'unit_cost' => 0.1200,
        'currency' => 'EUR',
        'notes' => null,
    ]);

    $this->actingAs($user)
        ->get(route('packaging-items.index'))
        ->assertSuccessful()
        ->assertSee('Unit price (GBP)')
        ->assertDontSee('Unit price (EUR)');
});
```

- [ ] **Step 4: Replace the editor page smoke test**

Replace `it('renders the packaging item create page for signed in users', ...)` with:

```php
it('renders the packaging item create page for signed in users', function () {
    $user = User::factory()->create();
    Workspace::factory()->create([
        'owner_user_id' => $user->id,
        'default_currency' => 'GBP',
    ]);

    $this->actingAs($user)
        ->get(route('packaging-items.create'))
        ->assertSuccessful()
        ->assertSee('Packaging image')
        ->assertSee('Effective unit price (GBP)')
        ->assertDontSee('x-filament-actions');
});
```

- [ ] **Step 5: Replace the create editor test**

Replace `it('creates a packaging item from the dedicated editor', ...)` with:

```php
it('creates a packaging item from the dedicated editor', function () {
    $user = User::factory()->create();
    Workspace::factory()->create([
        'owner_user_id' => $user->id,
        'default_currency' => 'GBP',
    ]);

    actingAs($user);

    Livewire::test(PackagingItemEditor::class)
        ->set('data.name', 'Kraft soap box')
        ->set('data.unit_cost', '0.4200')
        ->set('data.notes', '100g rectangle')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('packaging-items.edit', 1));

    expect(UserPackagingItem::query()->where('user_id', $user->id)->first())
        ->not->toBeNull()
        ->name->toBe('Kraft soap box')
        ->currency->toBe('GBP');
});
```

- [ ] **Step 6: Add editor validation test**

Add this test after the create editor test:

```php
it('keeps packaging editor fields required and non-negative', function () {
    $user = User::factory()->create();

    actingAs($user);

    Livewire::test(PackagingItemEditor::class)
        ->set('data.name', '')
        ->set('data.unit_cost', '-1')
        ->call('save')
        ->assertHasErrors([
            'data.name' => 'required',
            'data.unit_cost' => 'min',
        ]);

    expect(UserPackagingItem::query()->where('user_id', $user->id)->exists())->toBeFalse();
});
```

- [ ] **Step 7: Add editor image upload test**

Add this test after the image-path service test:

```php
it('stores a packaging image uploaded through the custom editor', function () {
    Storage::fake(MediaStorage::publicDisk());

    $user = User::factory()->create();

    actingAs($user);

    Livewire::test(PackagingItemEditor::class)
        ->set('data.name', 'Picture Box')
        ->set('data.unit_cost', '0.3600')
        ->set('featuredImageUpload', UploadedFile::fake()->image('picture-box.jpg', 800, 800))
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('packaging-items.edit', 1));

    $packagingItem = UserPackagingItem::query()->firstOrFail();

    expect($packagingItem->featured_image_path)
        ->toStartWith('packaging/featured-images/')
        ->toEndWith('.webp');

    Storage::disk(MediaStorage::publicDisk())->assertExists($packagingItem->featured_image_path);
});
```

- [ ] **Step 8: Replace search table test**

Replace `it('only shows the signed-in users packaging items in the packaging table and supports searching', ...)` with:

```php
it('only shows the signed-in users packaging items in the packaging table and supports searching', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    UserPackagingItem::query()->create(['user_id' => $user->id, 'name' => 'Alpha Box', 'unit_cost' => 0.1000, 'currency' => 'EUR', 'notes' => 'First alpha entry']);
    UserPackagingItem::query()->create(['user_id' => $user->id, 'name' => 'Alpha Box', 'unit_cost' => 0.2000, 'currency' => 'EUR', 'notes' => 'Second alpha entry']);
    UserPackagingItem::query()->create(['user_id' => $user->id, 'name' => 'Beta Box', 'unit_cost' => 0.3000, 'currency' => 'EUR', 'notes' => 'Beta entry']);
    UserPackagingItem::query()->create(['user_id' => $otherUser->id, 'name' => 'Alpha Box', 'unit_cost' => 0.4000, 'currency' => 'EUR', 'notes' => 'Other user entry']);

    actingAs($user);

    Livewire::test(PackagingItemsIndex::class)
        ->assertSee('Alpha Box')
        ->assertSee('Beta Box')
        ->assertDontSee('Other user entry')
        ->set('search', 'Beta')
        ->assertSee('Beta Box')
        ->assertDontSee('First alpha entry')
        ->assertDontSee('Second alpha entry');
});
```

- [ ] **Step 9: Replace inline price tests**

Replace the three `updateTableColumnState` tests with:

```php
it('updates a packaging item unit price from the catalog table', function () {
    $user = User::factory()->create();

    $packagingItem = UserPackagingItem::query()->create([
        'user_id' => $user->id,
        'name' => 'Bottle label',
        'unit_cost' => 0.1200,
        'currency' => 'EUR',
        'notes' => null,
    ]);

    actingAs($user);

    Livewire::test(PackagingItemsIndex::class)
        ->call('updateUnitCost', $packagingItem->id, '0.35')
        ->assertHasNoErrors();

    expect((float) $packagingItem->refresh()->unit_cost)->toBe(0.35);
});

it('updates the packaging item currency to the current default currency when editing inline', function () {
    $user = User::factory()->create();
    Workspace::factory()->create([
        'owner_user_id' => $user->id,
        'default_currency' => 'GBP',
    ]);

    $packagingItem = UserPackagingItem::query()->create([
        'user_id' => $user->id,
        'name' => 'Bottle label',
        'unit_cost' => 0.1200,
        'currency' => 'EUR',
        'notes' => null,
    ]);

    actingAs($user);

    Livewire::test(PackagingItemsIndex::class)
        ->call('updateUnitCost', $packagingItem->id, '0.35')
        ->assertHasNoErrors();

    expect($packagingItem->refresh()->currency)->toBe('GBP');
});

it('keeps the packaging catalog unit price required when editing inline', function () {
    $user = User::factory()->create();

    $packagingItem = UserPackagingItem::query()->create([
        'user_id' => $user->id,
        'name' => 'Bottle label',
        'unit_cost' => 0.1200,
        'currency' => 'EUR',
        'notes' => null,
    ]);

    actingAs($user);

    Livewire::test(PackagingItemsIndex::class)
        ->call('updateUnitCost', $packagingItem->id, '')
        ->assertHasErrors(['unit_cost_' . $packagingItem->id]);

    expect((float) $packagingItem->refresh()->unit_cost)->toBe(0.12);
});
```

- [ ] **Step 10: Replace delete tests**

Replace the two `TestAction` delete tests with:

```php
it('allows deleting an unused packaging item from the catalog table', function () {
    $user = User::factory()->create();

    $packagingItem = UserPackagingItem::query()->create([
        'user_id' => $user->id,
        'name' => 'Delete me',
        'unit_cost' => 0.12,
        'currency' => 'EUR',
        'notes' => null,
    ]);

    actingAs($user);

    Livewire::test(PackagingItemsIndex::class)
        ->call('deletePackagingItem', $packagingItem->id)
        ->assertHasNoErrors();

    expect(UserPackagingItem::query()->find($packagingItem->id))->toBeNull();
});

it('disables deleting a packaging item that is already used in costing', function () {
    $user = User::factory()->create();

    $packagingItem = UserPackagingItem::query()->create([
        'user_id' => $user->id,
        'name' => 'Locked Box',
        'unit_cost' => 0.55,
        'currency' => 'EUR',
        'notes' => null,
    ]);

    $recipe = Recipe::factory()->create([
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
    ]);

    $recipeVersion = RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
    ]);

    $costing = RecipeVersionCosting::query()->create([
        'recipe_version_id' => $recipeVersion->id,
        'user_id' => $user->id,
        'oil_weight_for_costing' => 1000,
        'oil_unit_for_costing' => 'g',
        'units_produced' => 10,
        'currency' => 'EUR',
    ]);

    RecipeVersionCostingPackagingItem::query()->create([
        'recipe_version_costing_id' => $costing->id,
        'user_packaging_item_id' => $packagingItem->id,
        'name' => $packagingItem->name,
        'unit_cost' => $packagingItem->unit_cost,
        'quantity' => 1,
    ]);

    actingAs($user);

    Livewire::test(PackagingItemsIndex::class)
        ->assertSeeHtml('data-cannot-delete="' . $packagingItem->id . '"')
        ->call('deletePackagingItem', $packagingItem->id)
        ->assertHasNoErrors();

    expect(UserPackagingItem::query()->find($packagingItem->id))->not->toBeNull();
});
```

- [ ] **Step 11: Add image rendering test using stored media**

Add this test at the end of the file:

```php
it('renders a packaging item image when one is stored', function () {
    Storage::fake(MediaStorage::publicDisk());

    $user = User::factory()->create();
    $path = 'packaging/featured-images/picture-box.webp';

    Storage::disk(MediaStorage::publicDisk())->put($path, 'fake-webp-content');

    app(UserPackagingItemAuthoringService::class)->create([
        'name' => 'Picture Box',
        'unit_cost' => 0.36,
        'notes' => null,
        'featured_image_path' => $path,
    ], $user);

    $this->actingAs($user)
        ->get(route('packaging-items.index'))
        ->assertSuccessful()
        ->assertSee('<img', false)
        ->assertSee('Picture Box');
});
```

- [ ] **Step 12: Run tests to confirm RED**

Run:

```bash
php artisan test --compact --filter=PackagingItemsIndexTest
```

Expected: the rewritten Filament-removal tests fail because `PackagingItemsIndex` still extends `Filament\Tables\TableComponent` and `PackagingItemEditor` still uses Filament Forms.

Do not commit yet.

---

## Task 4: Rebuild `PackagingItemsIndex` Off Filament

**Files:**
- Rewrite: `app/Livewire/Dashboard/PackagingItemsIndex.php`
- Rewrite: `resources/views/livewire/dashboard/packaging-items-index.blade.php`

- [ ] **Step 1: Replace the index component**

Replace `app/Livewire/Dashboard/PackagingItemsIndex.php` with:

```php
<?php

namespace App\Livewire\Dashboard;

use App\Models\User;
use App\Models\UserPackagingItem;
use App\Services\CurrentAppUserResolver;
use App\Services\UserPackagingItemAuthoringService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class PackagingItemsIndex extends Component
{
    use WithPagination;

    #[Locked]
    public ?int $currentUserId = null;

    #[Locked]
    public ?string $currentCurrency = null;

    public string $search = '';

    public string $sortField = 'name';

    public string $sortDirection = 'asc';

    public int $perPage = 25;

    public ?int $pendingDeleteId = null;

    public function mount(CurrentAppUserResolver $resolver): void
    {
        $user = $resolver->resolve();

        $this->currentUserId = $user?->id;
        $this->currentCurrency = $user?->defaultCurrency();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingPerPage(): void
    {
        $this->resetPage();
    }

    public function sortBy(string $field): void
    {
        if (! in_array($field, ['name', 'unit_cost'], true)) {
            return;
        }

        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';

            return;
        }

        $this->sortField = $field;
        $this->sortDirection = 'asc';
    }

    public function updateUnitCost(int $id, string $value): void
    {
        $user = $this->currentUser();

        if (! $user instanceof User) {
            return;
        }

        $packagingItem = $this->ownedPackagingItem($id, $user);

        if (! $packagingItem instanceof UserPackagingItem) {
            return;
        }

        try {
            app(UserPackagingItemAuthoringService::class)->updateUnitCost($packagingItem, $user, $value);
        } catch (ValidationException $exception) {
            $this->addError('unit_cost_'.$id, $exception->validator->errors()->first('unit_cost'));
        }
    }

    public function confirmDelete(int $id): void
    {
        $this->pendingDeleteId = $id;
    }

    public function cancelDelete(): void
    {
        $this->pendingDeleteId = null;
    }

    public function deletePackagingItem(int $id): void
    {
        $user = $this->currentUser();

        if (! $user instanceof User) {
            return;
        }

        $packagingItem = $this->ownedPackagingItem($id, $user);

        if (! $packagingItem instanceof UserPackagingItem) {
            return;
        }

        app(UserPackagingItemAuthoringService::class)->delete($packagingItem, $user);

        $this->pendingDeleteId = null;
    }

    public function render(): View
    {
        $items = $this->items();

        return view('livewire.dashboard.packaging-items-index', [
            'currentUser' => $this->currentUser(),
            'items' => $items,
            'unitPriceLabel' => sprintf('Unit price (%s)', $this->currentCurrency ?? config('currencies.default', 'EUR')),
            'pendingDeleteItem' => $this->pendingDeleteId === null
                ? null
                : $items->getCollection()->firstWhere('id', $this->pendingDeleteId),
        ]);
    }

    private function items(): LengthAwarePaginator
    {
        $user = $this->currentUser();

        if (! $user instanceof User) {
            return UserPackagingItem::query()->whereRaw('1 = 0')->paginate($this->perPage);
        }

        return UserPackagingItem::query()
            ->select(['id', 'user_id', 'name', 'unit_cost', 'currency', 'notes', 'featured_image_path', 'created_at', 'updated_at'])
            ->where('user_id', $user->id)
            ->withCount('costingItems')
            ->when($this->search !== '', fn (Builder $query): Builder => $query
                ->where(fn (Builder $where): Builder => $where
                    ->where('name', 'like', '%'.$this->search.'%')
                    ->orWhere('notes', 'like', '%'.$this->search.'%')))
            ->when($this->sortField === 'unit_cost', fn (Builder $query): Builder => $query->orderBy('unit_cost', $this->sortDirection)->orderBy('id'))
            ->when($this->sortField === 'name', fn (Builder $query): Builder => $query->orderBy('name', $this->sortDirection)->orderBy('id', $this->sortDirection))
            ->paginate($this->perPage);
    }

    private function ownedPackagingItem(int $id, User $user): ?UserPackagingItem
    {
        return UserPackagingItem::query()->where('user_id', $user->id)->find($id);
    }

    private function currentUser(): ?User
    {
        return app(CurrentAppUserResolver::class)->resolve($this->currentUserId);
    }
}
```

- [ ] **Step 2: Replace the index view**

Replace `resources/views/livewire/dashboard/packaging-items-index.blade.php` with:

```blade
<div class="mx-auto w-full max-w-7xl space-y-6">
    <section class="sk-card p-5 sm:p-6" aria-label="Packaging catalog heading">
        <div class="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
            <div class="min-w-0">
                <p class="sk-eyebrow">Packaging items</p>
                <h3 class="mt-2 max-w-4xl text-xl font-semibold text-[var(--color-ink-strong)] sm:text-2xl">Manage packaging used in recipe costing.</h3>
                <p class="mt-2 max-w-3xl text-sm leading-7 text-[var(--color-ink-soft)]">
                    Add boxes, jars, labels, inserts, and other reusable packaging with a unit price. Saved items can be selected in recipe packaging and costing instead of retyped.
                </p>
            </div>

            <a href="{{ route('dashboard') }}" wire:navigate class="inline-flex min-h-11 items-center justify-center whitespace-nowrap rounded-full border border-[var(--color-line)] px-5 py-2.5 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel)]">
                Back to dashboard
            </a>
        </div>
    </section>

    @if (! $currentUser)
        <section class="sk-card p-8 text-center" aria-label="Sign in required">
            <h4 class="text-lg font-semibold text-[var(--color-ink-strong)]">Sign in to manage packaging items</h4>
            <p class="mt-3 text-sm leading-7 text-[var(--color-ink-soft)]">Open the dashboard from your signed-in app or admin session to create and reuse packaging items.</p>
        </section>
    @else
        <section class="overflow-hidden sk-card p-0" aria-label="Packaging catalog table">
            <div class="flex flex-col gap-3 border-b border-[var(--color-line)] bg-[var(--color-panel)] px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="text-sm font-medium text-[var(--color-ink-strong)]">Packaging catalog</p>
                    <p class="mt-1 text-xs text-[var(--color-ink-soft)]">Saved boxes, jars, labels, and inserts available to recipe costing.</p>
                </div>

                <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <label class="sk-field sm:min-w-72">
                        <span class="shrink-0 text-[var(--color-ink-soft)]">Search</span>
                        <input wire:model.live.debounce.250ms="search" type="text" placeholder="Name or notes" class="sk-field-control" aria-label="Search packaging items" />
                    </label>

                    <a href="{{ route('packaging-items.create') }}" wire:navigate class="sk-btn sk-btn-primary justify-center">Add packaging item</a>
                </div>
            </div>

            @if ($items->isEmpty())
                <div class="p-8 text-center">
                    <h4 class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ $search !== '' ? 'No packaging items match' : 'No packaging items yet' }}</h4>
                    <p class="mt-3 text-sm leading-7 text-[var(--color-ink-soft)]">Create reusable boxes, labels, jars, and inserts once, then pull them into recipe costing when needed.</p>
                    <div class="mt-5">
                        <a href="{{ route('packaging-items.create') }}" wire:navigate class="sk-btn sk-btn-primary">Add packaging item</a>
                    </div>
                </div>
            @else
                <div class="sk-table-wrapper">
                    <table class="sk-table">
                        <thead>
                            <tr>
                                <th scope="col">Picture</th>
                                <th scope="col"><button type="button" wire:click="sortBy('name')" class="sk-table-sort-button">Name</button></th>
                                <th scope="col"><button type="button" wire:click="sortBy('unit_cost')" class="sk-table-sort-button">{{ $unitPriceLabel }}</button></th>
                                <th scope="col">Notes</th>
                                <th scope="col" class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($items as $item)
                                @php $errorKey = 'unit_cost_' . $item->id; @endphp
                                <tr>
                                    <td>
                                        @if ($item->featuredImageUrl())
                                            <img src="{{ $item->featuredImageUrl() }}" alt="{{ $item->name }}" class="size-13 rounded-lg object-cover" />
                                        @else
                                            <span class="grid size-13 place-items-center rounded-lg bg-[var(--color-panel-strong)] text-[var(--color-ink-soft)]" aria-hidden="true">
                                                <span class="text-xs font-semibold">PKG</span>
                                            </span>
                                        @endif
                                    </td>
                                    <td class="font-semibold text-[var(--color-ink-strong)]">{{ $item->name }}</td>
                                    <td>
                                        <input type="number" step="0.01" inputmode="decimal" value="{{ number_format((float) $item->unit_cost, 2, '.', '') }}" wire:change="updateUnitCost({{ $item->id }}, $event.target.value)" class="sk-input w-32" aria-label="{{ $unitPriceLabel }} for {{ $item->name }}" />
                                        @error($errorKey)
                                            <p role="alert" class="mt-1 text-xs text-[var(--color-danger-strong)]">{{ $message }}</p>
                                        @enderror
                                    </td>
                                    <td class="text-[var(--color-ink-soft)]">{{ $item->notes ?? '-' }}</td>
                                    <td class="text-right">
                                        <div class="inline-flex items-center gap-1">
                                            <a href="{{ route('packaging-items.edit', $item->id) }}" wire:navigate class="grid size-9 place-items-center rounded-lg text-[var(--color-ink-soft)] hover:bg-[var(--color-panel-strong)]" aria-label="Edit {{ $item->name }}" title="Edit">Edit</a>
                                            @if ((int) ($item->costing_items_count ?? 0) > 0)
                                                <button type="button" disabled data-cannot-delete="{{ $item->id }}" title="Used in costing, so it cannot be deleted" class="grid size-9 cursor-not-allowed place-items-center rounded-lg text-[var(--color-ink-soft)] opacity-50" aria-label="Delete disabled for {{ $item->name }}">Del</button>
                                            @else
                                                <button type="button" wire:click="confirmDelete({{ $item->id }})" class="grid size-9 place-items-center rounded-lg text-[var(--color-danger-strong)] hover:bg-[var(--color-danger-soft)]" aria-label="Delete {{ $item->name }}" title="Delete">Del</button>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="flex flex-col gap-3 border-t border-[var(--color-line)] px-5 py-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-center gap-2 text-xs text-[var(--color-ink-soft)]">
                        <span>Per page</span>
                        <select wire:model.live="perPage" class="sk-select-control w-20" aria-label="Items per page">
                            <option value="25">25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                    </div>
                    {{ $items->links() }}
                </div>
            @endif
        </section>

        @if ($pendingDeleteItem)
            <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" x-data @click.self="$wire.cancelDelete()" role="dialog" aria-modal="true" aria-labelledby="packaging-delete-heading">
                <div class="sk-card w-full max-w-md p-6" @click.stop>
                    <h3 id="packaging-delete-heading" class="text-lg font-semibold text-[var(--color-ink-strong)]">Delete "{{ $pendingDeleteItem->name }}"?</h3>
                    <p class="mt-2 text-sm text-[var(--color-ink-soft)]">This removes the packaging item from your private catalog.</p>
                    <div class="mt-5 flex gap-2">
                        <button type="button" wire:click="cancelDelete()" class="sk-btn sk-btn-outline">Cancel</button>
                        <button type="button" wire:click="deletePackagingItem({{ $pendingDeleteItem->id }})" class="sk-btn flex-1 bg-[var(--color-danger-strong)] text-white hover:bg-[var(--color-danger)]">Delete</button>
                    </div>
                </div>
            </div>
        @endif
    @endif
</div>
```

- [ ] **Step 3: Run filtered tests**

Run:

```bash
php artisan test --compact --filter=PackagingItemsIndexTest
```

Expected: index tests now pass; editor tests that require the custom editor still fail.

- [ ] **Step 4: Format**

Run:

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 5: Commit**

```bash
git add app/Livewire/Dashboard/PackagingItemsIndex.php resources/views/livewire/dashboard/packaging-items-index.blade.php tests/Feature/PackagingItemsIndexTest.php
git commit -m "Migrate packaging catalog index off Filament"
```

---

## Task 5: Rebuild `PackagingItemEditor` Off Filament

**Files:**
- Rewrite: `app/Livewire/Dashboard/PackagingItemEditor.php`
- Rewrite: `resources/views/livewire/dashboard/packaging-item-editor.blade.php`

- [ ] **Step 1: Replace the editor component**

Replace `app/Livewire/Dashboard/PackagingItemEditor.php` with:

```php
<?php

namespace App\Livewire\Dashboard;

use App\Models\User;
use App\Models\UserPackagingItem;
use App\Services\CurrentAppUserResolver;
use App\Services\MediaStorage;
use App\Services\UserPackagingItemAuthoringService;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

class PackagingItemEditor extends Component
{
    use WithFileUploads;

    #[Locked]
    public ?int $actorUserId = null;

    public ?int $packagingItemId = null;

    /**
     * @var array{name:?string, unit_cost:mixed, notes:?string, featured_image_path:?string}
     */
    public array $data = [];

    public mixed $featuredImageUpload = null;

    public ?string $statusMessage = null;

    public string $statusType = 'idle';

    public function mount(?UserPackagingItem $packagingItem, UserPackagingItemAuthoringService $authoringService): void
    {
        $this->actorUserId = $this->currentUser()?->id;
        $this->packagingItemId = $packagingItem?->id;
        $this->data = $packagingItem instanceof UserPackagingItem
            ? $authoringService->formData($packagingItem)
            : $authoringService->blankState();
    }

    public function removeFeaturedImage(): void
    {
        $this->data['featured_image_path'] = null;
        $this->featuredImageUpload = null;
    }

    public function save(UserPackagingItemAuthoringService $authoringService): mixed
    {
        $user = $this->currentUser();
        $wasEditing = $this->isEditing();

        if (! $user instanceof User) {
            $this->statusType = 'error';
            $this->statusMessage = 'You need to be signed in before packaging items can be saved.';

            return null;
        }

        $this->validate([
            'data.name' => ['required', 'string', 'max:255'],
            'data.unit_cost' => ['required', 'numeric', 'min:0'],
            'data.notes' => ['nullable', 'string'],
            'featuredImageUpload' => ['nullable', 'image', 'mimes:jpg,jpeg,webp', 'max:'.MediaStorage::ingredientImagesMaxSize()],
        ]);

        $state = $this->data;

        if ($this->featuredImageUpload instanceof TemporaryUploadedFile) {
            $state['featured_image_path'] = MediaStorage::storeFittedWebp(
                $this->featuredImageUpload,
                'packaging/featured-images',
                MediaStorage::ingredientImageWidth(),
                MediaStorage::ingredientImageHeight(),
                MediaStorage::ingredientImagesQuality(),
            );
        }

        $currentPackagingItem = $this->currentPackagingItem();

        try {
            $packagingItem = $currentPackagingItem instanceof UserPackagingItem
                ? $authoringService->update($currentPackagingItem, $state, $user)
                : $authoringService->create($state, $user);
        } catch (ValidationException $exception) {
            throw $exception;
        }

        $this->packagingItemId = $packagingItem->id;
        $this->data = $authoringService->formData($packagingItem);
        $this->featuredImageUpload = null;
        $this->statusType = 'success';
        $this->statusMessage = $wasEditing
            ? 'Packaging item saved.'
            : 'Packaging item created. You can keep using it in recipe costing.';

        if (! $wasEditing) {
            return redirect()->route('packaging-items.edit', $packagingItem->id);
        }

        return null;
    }

    public function render(): View
    {
        $packagingItem = $this->currentPackagingItem();

        return view('livewire.dashboard.packaging-item-editor', [
            'packagingItem' => $packagingItem,
            'imageUrl' => $packagingItem?->featuredImageUrl(),
            'priceLabel' => $this->priceFieldLabel('Effective unit price'),
        ]);
    }

    private function currentPackagingItem(): ?UserPackagingItem
    {
        $user = $this->currentUser();

        if (! $user instanceof User || $this->packagingItemId === null) {
            return null;
        }

        return UserPackagingItem::query()
            ->where('user_id', $user->id)
            ->find($this->packagingItemId);
    }

    private function currentUser(): ?User
    {
        return app(CurrentAppUserResolver::class)->resolve($this->actorUserId);
    }

    private function isEditing(): bool
    {
        return $this->packagingItemId !== null;
    }

    private function priceFieldLabel(string $label): string
    {
        return sprintf('%s (%s)', $label, $this->currentUser()?->defaultCurrency() ?? 'EUR');
    }
}
```

- [ ] **Step 2: Replace the editor view**

Replace `resources/views/livewire/dashboard/packaging-item-editor.blade.php` with:

```blade
<div class="mx-auto w-full max-w-5xl space-y-6">
    <section class="sk-card p-5 sm:p-6">
        <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
            <div class="min-w-0">
                <p class="sk-eyebrow">Packaging item</p>
                <h3 class="mt-3 text-2xl font-semibold text-[var(--color-ink-strong)]">
                    {{ $packagingItem ? 'Refine the packaging record and keep it ready for costing.' : 'Create a reusable packaging item for recipe costing.' }}
                </h3>
                <p class="mt-3 max-w-3xl text-sm leading-7 text-[var(--color-ink-soft)]">
                    Packaging items stay private to your account and can be reused across formulas without retyping the same details every time.
                </p>
            </div>

            <a href="{{ route('packaging-items.index') }}" wire:navigate class="inline-flex justify-center rounded-full border border-[var(--color-line)] px-5 py-2.5 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel)]">
                Back to packaging
            </a>
        </div>
    </section>

    <form wire:submit="save" class="space-y-4">
        @if ($statusMessage)
            <div class="{{ $statusType === 'success' ? 'border-[var(--color-success-soft)] bg-[var(--color-success-soft)] text-[var(--color-success-strong)]' : 'border-[var(--color-danger-soft)] bg-[var(--color-danger-soft)] text-[var(--color-danger-strong)]' }} rounded-lg border px-4 py-3 text-sm">
                {{ $statusMessage }}
            </div>
        @endif

        <section class="sk-card p-5 sm:p-6">
            <div class="grid gap-4 md:grid-cols-2">
                <label class="sk-inset p-4">
                    <span class="sk-eyebrow">Name</span>
                    <input wire:model="data.name" type="text" maxlength="255" class="sk-input mt-3" autocomplete="off" />
                    @error('data.name')
                        <p class="mt-1 text-xs text-[var(--color-danger-strong)]">{{ $message }}</p>
                    @enderror
                </label>

                <label class="sk-inset p-4">
                    <span class="sk-eyebrow">{{ $priceLabel }}</span>
                    <input wire:model="data.unit_cost" type="number" step="0.0001" min="0" inputmode="decimal" class="sk-input mt-3" />
                    @error('data.unit_cost')
                        <p class="mt-1 text-xs text-[var(--color-danger-strong)]">{{ $message }}</p>
                    @enderror
                </label>

                <div class="sk-inset p-4">
                    <span class="sk-eyebrow">Packaging image</span>
                    <div class="mt-3 flex flex-col gap-3">
                        @if ($featuredImageUpload)
                            <img src="{{ $featuredImageUpload->temporaryUrl() }}" alt="Packaging image preview" class="size-28 rounded-lg object-cover" />
                        @elseif ($imageUrl)
                            <img src="{{ $imageUrl }}" alt="Current packaging image" class="size-28 rounded-lg object-cover" />
                        @else
                            <div class="grid size-28 place-items-center rounded-lg bg-[var(--color-panel-strong)] text-xs font-semibold text-[var(--color-ink-soft)]">No image</div>
                        @endif

                        <input wire:model="featuredImageUpload" type="file" accept="image/jpeg,image/webp" class="block w-full text-sm text-[var(--color-ink-soft)] file:mr-4 file:rounded-full file:border-0 file:bg-[var(--color-panel-strong)] file:px-4 file:py-2 file:text-sm file:font-medium file:text-[var(--color-ink-strong)]" />

                        @if ($data['featured_image_path'] ?? false)
                            <button type="button" wire:click="removeFeaturedImage" class="self-start text-sm font-medium text-[var(--color-danger-strong)]">Remove image</button>
                        @endif

                        @error('featuredImageUpload')
                            <p class="text-xs text-[var(--color-danger-strong)]">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <label class="sk-inset p-4">
                    <span class="sk-eyebrow">Notes</span>
                    <textarea wire:model="data.notes" rows="6" class="sk-input mt-3 min-h-36 resize-y"></textarea>
                    @error('data.notes')
                        <p class="mt-1 text-xs text-[var(--color-danger-strong)]">{{ $message }}</p>
                    @enderror
                </label>
            </div>
        </section>

        <div class="flex justify-end">
            <button type="submit" wire:loading.attr="disabled" class="sk-btn sk-btn-primary disabled:opacity-50">
                {{ $packagingItem ? 'Save packaging item' : 'Create packaging item' }}
            </button>
        </div>
    </form>
</div>
```

- [ ] **Step 3: Run filtered tests**

Run:

```bash
php artisan test --compact --filter=PackagingItemsIndexTest
```

Expected: all packaging index/editor tests pass.

- [ ] **Step 4: Format**

Run:

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 5: Re-run filtered tests**

Run:

```bash
php artisan test --compact --filter=PackagingItemsIndexTest
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/Dashboard/PackagingItemEditor.php resources/views/livewire/dashboard/packaging-item-editor.blade.php tests/Feature/PackagingItemsIndexTest.php
git commit -m "Migrate packaging item editor off Filament"
```

---

## Task 6: Verify Packaging Flow Is Filament-Free

**Files:** none unless verification reveals a cleanup.

- [ ] **Step 1: Confirm no Filament references remain in packaging public components**

Run:

```bash
rg "Filament|InteractsWithForms|InteractsWithActions|HasForms|HasActions|x-filament" app/Livewire/Dashboard/PackagingItemsIndex.php app/Livewire/Dashboard/PackagingItemEditor.php resources/views/livewire/dashboard/packaging-items-index.blade.php resources/views/livewire/dashboard/packaging-item-editor.blade.php
```

Expected: no matches.

- [ ] **Step 2: Run the full test suite**

Run:

```bash
composer test
```

Expected: all tests pass.

- [ ] **Step 3: Optional manual smoke**

Use the Herd URL for the app, sign in, and open the packaging catalog. Confirm: index renders without Filament styling, search filters, sort toggles, inline price saves, create/edit form saves name/price/notes/image, image removal works, delete confirmation deletes unused rows only, and used rows show disabled delete.

- [ ] **Step 4: Commit cleanup if needed**

Run:

```bash
git status --short
```

Expected: clean apart from pre-existing unrelated untracked folders. If verification required cleanup, commit it with:

```bash
git add app/Livewire/Dashboard/PackagingItemsIndex.php app/Livewire/Dashboard/PackagingItemEditor.php resources/views/livewire/dashboard/packaging-items-index.blade.php resources/views/livewire/dashboard/packaging-item-editor.blade.php tests/Feature/PackagingItemsIndexTest.php resources/css/shared/soapkraft.css app/Models/UserPackagingItem.php
git commit -m "Clean up packaging catalog Filament migration"
```

---

## Follow-On Plans

1. **`IngredientsIndex`** - same table pattern, plus ownership filter (all/mine/platform/priced), mixed platform/user rows, catalog image fallback (`icon_image_path ?: featured_image_path`), and inline user price memory.
2. **`IngredientEditor`** - custom form migration with the reusable field/repeater kit only after packaging proves the minimum primitives. Preserve the "users cannot create saponifiable oils that drive soap math" guardrail exactly.
3. **`RecipeWorkbench`** - remove residual Filament form/action dependencies after catalog surfaces are clean.
4. **Documentation cleanup** - after the public migrations land, update `CLAUDE.md` so Filament is described as admin-only in the achieved state rather than as a transitional contradiction.
