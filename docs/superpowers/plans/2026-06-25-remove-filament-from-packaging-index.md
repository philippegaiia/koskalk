# Remove Filament From PackagingItemsIndex — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Migrate the user-facing packaging catalog (`PackagingItemsIndex`) off Filament Tables onto a custom Livewire + Blade table that matches the `sk-*` design system — the first surface in the "remove Filament from the user-facing UI" program.

**Architecture:** Replace the `Filament\Tables\TableComponent` with a plain `Livewire\Component` using `WithPagination`. The table markup is hand-written Blade using new shared `.sk-table` CSS classes (no generic component engine — 2 consumers don't justify rebuilding a mini-Filament). Inline price editing and delete call the **existing** `UserPackagingItemAuthoringService` unchanged (`updateUnitCost`, `delete`), so this is presentation-only — no domain logic moves. The Filament-table feature tests are rewritten to drive the new Livewire contract; the editor/service tests in the same file are left untouched (the editor still uses Filament and is migrated in a later plan).

**Tech Stack:** Laravel 13, Livewire 4, Blade, Alpine.js (minimal), Tailwind 4 (`sk-*` design tokens), Pest 4.

**Scope of THIS plan:** `PackagingItemsIndex` only. Follow-on plans: `IngredientsIndex`, then the editors (`PackagingItemEditor`, `IngredientEditor`), then `RecipeWorkbench` cleanup.

---

## Files

- **Modify** `resources/css/shared/soapkraft.css` — append `.sk-table` design-system classes (reused by every future catalog table).
- **Modify** `app/Models/UserPackagingItem.php` — add `featuredImageUrl()` accessor (mirrors `Recipe::featuredImageUrl()`).
- **Rewrite** `app/Livewire/Dashboard/PackagingItemsIndex.php` — non-Filament `Component`.
- **Rewrite** `resources/views/livewire/dashboard/packaging-items-index.blade.php` — custom table.
- **Rewrite (partial)** `tests/Feature/PackagingItemsIndexTest.php` — replace the index test cases; leave editor/service cases intact.

**Unchanged (do not touch):** `app/Services/UserPackagingItemAuthoringService.php`, `app/Livewire/Dashboard/PackagingItemEditor.php`, routes, the packaging editor view, and the editor/service test cases in `PackagingItemsIndexTest.php`.

---

## Task 1: Add the shared `.sk-table` design-system classes

**Files:**
- Modify: `resources/css/shared/soapkraft.css` (append at end of file)

These classes use the existing CSS custom properties (`--color-line`, `--color-panel`, `--color-surface`, `--color-ink`, `--color-ink-soft`) so the table matches the clinical aesthetic. They are reused by `IngredientsIndex` and later tables.

- [ ] **Step 1: Append the table styles**

Append exactly this block to `resources/css/shared/soapkraft.css`:

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

- [ ] **Step 2: Rebuild the CSS and verify it compiled**

Run: `npm run build`
Expected: Vite build completes with no errors.

- [ ] **Step 3: Commit**

```bash
git add resources/css/shared/soapkraft.css
git commit -m "Add shared sk-table catalog table styles"
```

---

## Task 2: Add `featuredImageUrl()` accessor to `UserPackagingItem`

Mirrors the existing `Recipe::featuredImageUrl()` pattern so the table can render the stored image. `Storage::disk(...)->url()` returns a URL without checking file existence, so this is safe in tests.

**Files:**
- Modify: `app/Models/UserPackagingItem.php`

- [ ] **Step 1: Add the accessor and imports**

In `app/Models/UserPackagingItem.php`, add these `use` statements alongside the existing imports:

```php
use App\Services\MediaStorage;
use Illuminate\Support\Facades\Storage;
```

Then add this method inside the `UserPackagingItem` class (after the `casts()` method):

```php
    /** Public URL for the stored featured image, or null when none is stored. */
    public function featuredImageUrl(): ?string
    {
        if (blank($this->featured_image_path)) {
            return null;
        }

        return Storage::disk(MediaStorage::publicDisk())->url($this->featured_image_path);
    }
```

- [ ] **Step 2: Verify the class still parses**

Run: `php artisan model:show UserPackagingItem`
Expected: command prints the model details with no PHP errors. (If `model:show` is unavailable, run `php artisan tinker --execute="echo App\Models\UserPackagingItem::first() ?? 'ok';"` — it must not throw a parse error.)

- [ ] **Step 3: Commit**

```bash
git add app/Models/UserPackagingItem.php
git commit -m "Add featuredImageUrl accessor to UserPackagingItem"
```

---

## Task 3: Rewrite the packaging index feature tests to the non-Filament contract (RED)

Replace only the **index** test cases in `tests/Feature/PackagingItemsIndexTest.php`. The editor/service cases (`renders the packaging item create page…`, `does not allow editing another users packaging item`, `creates a packaging item from the dedicated editor`, `stores the packaging image path through the packaging authoring service`) and the `redirects signed-out visitors` case stay **unchanged**.

**Files:**
- Modify: `tests/Feature/PackagingItemsIndexTest.php`

- [ ] **Step 1: Replace the index test cases**

Open `tests/Feature/PackagingItemsIndexTest.php`. The current file imports `Filament\Actions\Testing\TestAction` (line 15) — delete that import line; it is no longer used after the rewrite. Then replace the eight index test cases listed below with these exact new versions. Leave all other `it(...)` blocks in the file untouched.

Replace `it('lets a signed-in user open the packaging items page and see saved items', …)` with:

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

Replace `it('shows packaging prices in the users current default currency', …)` with:

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

Replace `it('only shows the signed-in users packaging items in the packaging table and supports searching', …)` with:

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

Replace `it('updates a packaging item unit price from the catalog table', …)` with:

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
        ->call('updateUnitCost', $packagingItem->id, '0.35');

    expect((float) $packagingItem->refresh()->unit_cost)->toBe(0.35);
});
```

Replace `it('updates the packaging item currency to the current default currency when editing inline', …)` with:

```php
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
        ->call('updateUnitCost', $packagingItem->id, '0.35');

    expect($packagingItem->refresh()->currency)->toBe('GBP');
});
```

Replace `it('keeps the packaging catalog unit price required when editing inline', …)` with:

```php
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

Replace `it('allows deleting an unused packaging item from the catalog table', …)` with:

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
        ->call('deletePackagingItem', $packagingItem->id);

    expect(UserPackagingItem::query()->find($packagingItem->id))->toBeNull();
});
```

Replace `it('disables deleting a packaging item that is already used in costing', …)` with (the costing setup is unchanged; only the assertions change):

```php
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
        ->call('deletePackagingItem', $packagingItem->id);

    expect(UserPackagingItem::query()->find($packagingItem->id))->not->toBeNull();
});
```

Replace `it('shows only the signed-in user packaging items on the page', …)` with (unchanged body — keep as-is; it already uses `get()->assertSee/assertDontSee` and needs no edit):

> This case requires **no changes** — leave it exactly as written in the current file.

Finally, **add** one new case at the end of the file (before the closing) to cover image rendering:

```php
it('renders a packaging item image when one is stored', function () {
    $user = User::factory()->create();

    UserPackagingItem::query()->create([
        'user_id' => $user->id,
        'name' => 'Picture Box',
        'unit_cost' => 0.36,
        'currency' => 'EUR',
        'notes' => null,
        'featured_image_path' => 'packaging/featured-images/picture-box.webp',
    ]);

    $this->actingAs($user)
        ->get(route('packaging-items.index'))
        ->assertSuccessful()
        ->assertSee('<img', false);
});
```

- [ ] **Step 2: Run the index tests to confirm they FAIL (RED)**

Run: `php artisan test --filter=PackagingItemsIndexTest`
Expected: the rewritten index cases FAIL (the component still extends `Filament\Tables\TableComponent`, so methods like `updateUnitCost`/`deletePackagingItem`/`search`/`pendingDeleteId` do not exist, and `assertSee('Packaging catalog')` fails because that heading currently comes from the Filament table). The editor/service/redirect cases should still PASS.

- [ ] **Step 3: Do NOT commit yet** — the next task makes them pass.

---

## Task 4: Rebuild `PackagingItemsIndex` and its view off Filament (GREEN)

**Files:**
- Rewrite: `app/Livewire/Dashboard/PackagingItemsIndex.php`
- Rewrite: `resources/views/livewire/dashboard/packaging-items-index.blade.php`

- [ ] **Step 1: Replace the component**

Overwrite `app/Livewire/Dashboard/PackagingItemsIndex.php` entirely with:

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
        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'asc';
        }
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
            $this->addError('unit_cost_' . $id, $exception->validator->errors()->first('unit_cost'));
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
                    ->where('name', 'like', '%' . $this->search . '%')
                    ->orWhere('notes', 'like', '%' . $this->search . '%')))
            ->when($this->sortField === 'unit_cost', fn (Builder $query): Builder => $query->orderBy('unit_cost', $this->sortDirection))
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

- [ ] **Step 2: Replace the view**

Overwrite `resources/views/livewire/dashboard/packaging-items-index.blade.php` entirely with:

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
            <p class="mt-3 text-sm leading-7 text-[var(--color-ink-soft)]">
                Open the dashboard from your signed-in app or admin session to create and reuse packaging items.
            </p>
        </section>
    @else
        <section class="overflow-hidden sk-card p-0" aria-label="Packaging catalog table">
            <div class="flex flex-col gap-3 border-b border-[var(--color-line)] bg-[var(--color-panel)] px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="text-sm font-medium text-[var(--color-ink-strong)]">Packaging catalog</p>
                    <p class="mt-1 text-xs text-[var(--color-ink-soft)]">Saved boxes, jars, labels, and inserts available to recipe costing.</p>
                </div>
                <label class="sk-field sm:min-w-72">
                    <span class="shrink-0 text-[var(--color-ink-soft)]">Search</span>
                    <input wire:model.live.debounce.250ms="search" type="text" placeholder="Name or notes" class="sk-field-control" aria-label="Search packaging items" />
                </label>
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
                                        @if ($item->featured_image_path && $item->featuredImageUrl())
                                            <img src="{{ $item->featuredImageUrl() }}" alt="{{ $item->name }}" class="size-13 rounded-lg object-cover" />
                                        @else
                                            <span class="grid size-13 place-items-center rounded-lg bg-[var(--color-panel-strong)] text-[var(--color-ink-soft)]">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9" />
                                                </svg>
                                            </span>
                                        @endif
                                    </td>
                                    <td class="font-semibold text-[var(--color-ink-strong)]">{{ $item->name }}</td>
                                    <td>
                                        <input
                                            type="number"
                                            step="0.01"
                                            inputmode="decimal"
                                            value="{{ number_format((float) $item->unit_cost, 2, '.', '') }}"
                                            wire:change="updateUnitCost({{ $item->id }}, $event.target.value)"
                                            class="sk-input w-32"
                                            aria-label="{{ $unitPriceLabel }} for {{ $item->name }}"
                                        />
                                        @error($errorKey)
                                            <p role="alert" class="mt-1 text-xs text-[var(--color-danger-strong)]">{{ $message }}</p>
                                        @enderror
                                    </td>
                                    <td class="text-[var(--color-ink-soft)]">{{ $item->notes ?? '—' }}</td>
                                    <td class="text-right">
                                        <div class="inline-flex items-center gap-1">
                                            <a href="{{ route('packaging-items.edit', $item->id) }}" wire:navigate class="grid size-9 place-items-center rounded-lg text-[var(--color-ink-soft)] hover:bg-[var(--color-panel-strong)]" aria-label="Edit {{ $item->name }}" title="Edit">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125" />
                                                </svg>
                                            </a>
                                            @if ((int) ($item->costing_items_count ?? 0) > 0)
                                                <button type="button" disabled data-cannot-delete="{{ $item->id }}" title="Used in costing, so it cannot be deleted" class="grid size-9 cursor-not-allowed place-items-center rounded-lg text-[var(--color-ink-soft)] opacity-50" aria-label="Delete disabled for {{ $item->name }}">
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                                                    </svg>
                                                </button>
                                            @else
                                                <button type="button" wire:click="confirmDelete({{ $item->id }})" class="grid size-9 place-items-center rounded-lg text-[var(--color-danger-strong)] hover:bg-[var(--color-danger-soft)]" aria-label="Delete {{ $item->name }}" title="Delete">
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                                                    </svg>
                                                </button>
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
                    <h3 id="packaging-delete-heading" class="text-lg font-semibold text-[var(--color-ink-strong)]">Delete &quot;{{ $pendingDeleteItem->name }}&quot;?</h3>
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

- [ ] **Step 3: Run the packaging index tests to confirm they PASS (GREEN)**

Run: `php artisan test --filter=PackagingItemsIndexTest`
Expected: ALL cases pass, including the untouched editor/service/redirect cases.

- [ ] **Step 4: Format with Pint**

Run: `./vendor/bin/pint app/Livewire/Dashboard/PackagingItemsIndex.php app/Models/UserPackagingItem.php tests/Feature/PackagingItemsIndexTest.php`
Expected: Pint reports the files are formatted (or already clean).

- [ ] **Step 5: Re-run the filtered suite to confirm still green after formatting**

Run: `php artisan test --filter=PackagingItemsIndexTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/Dashboard/PackagingItemsIndex.php resources/views/livewire/dashboard/packaging-items-index.blade.php tests/Feature/PackagingItemsIndexTest.php
git commit -m "Migrate PackagingItemsIndex off Filament to custom Livewire table"
```

---

## Task 5: Verify Filament is gone from the index and the wider suite is green

**Files:** none (verification only)

- [ ] **Step 1: Confirm no Filament usage remains in the index component**

Run: `grep -n "Filament" app/Livewire/Dashboard/PackagingItemsIndex.php || echo "clean"`
Expected: prints `clean` (the component no longer references Filament).

- [ ] **Step 2: Run the full test suite**

Run: `composer test`
Expected: the entire suite passes (this guards against the Filament removal breaking anything that depended on the component being a `TableComponent`).

- [ ] **Step 3: Manual smoke test (optional but recommended)**

Start the app (`composer run dev`), sign in, open the packaging items page, and confirm: the table renders in the clinical style, inline price edits persist, search filters, sort toggles, pagination works, and the delete confirmation modal appears and deletes only unused items.

- [ ] **Step 4: Commit only if any stray cleanup was needed; otherwise nothing to commit**

```bash
git status --short
```
Expected: clean working tree (apart from pre-existing unrelated changes). If you touched anything, stage and commit it with a clear message.

---

## Follow-on plans (out of scope here)

1. **`IngredientsIndex`** — same pattern, plus the ownership filter (all/mine/platform/priced), platform-vs-user row mix, derived catalog image (`icon_image_path ?: featured_image_path`), and inline price via `UserIngredientPriceMemory` (note the existing `ingredients.update-price` JSON endpoint already covers currency/validation).
2. **`PackagingItemEditor`** then **`IngredientEditor`** — form migration (reusable form-component kit + Alpine repeater); preserve the "users cannot create saponifiable oils" guardrail verbatim.
3. **`RecipeWorkbench`** residual Filament cleanup.

After the index surfaces and editors are migrated, update `CLAUDE.md` to state Filament is admin-only (matching the new reality).
