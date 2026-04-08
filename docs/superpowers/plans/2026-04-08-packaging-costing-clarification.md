# Packaging Costing Clarification Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make packaging readable and usable by moving it below ingredient costing in the recipe workbench and turning the packaging catalog page into a real create-and-reuse manager.

**Architecture:** Keep the existing packaging persistence model and costing snapshots, but reshape the UI around one continuous costing flow: settings, ingredients, packaging, totals. Reuse the current Livewire and Alpine plumbing for catalog saves, add direct creation on the `Packaging Items` page, and rewrite the costing section from a sidebar card stack into a table-like block below ingredients.

**Tech Stack:** Laravel 13, Livewire 4, Alpine-based recipe workbench JavaScript, Blade templates, Tailwind CSS 4, Pest 4 feature tests, Laravel Pint.

---

## File Structure

### Modified Files

- `app/Livewire/Dashboard/PackagingItemsIndex.php`
  Responsibility: expose create-form state, persist catalog items for the current user, and return ordered packaging records to the page.
- `resources/views/livewire/dashboard/packaging-items-index.blade.php`
  Responsibility: render the packaging catalog intro, creation form, validation/error feedback, and saved items list.
- `resources/views/livewire/dashboard/partials/recipe-workbench/costing-tab.blade.php`
  Responsibility: remove the right-column packaging sidebar, render packaging below ingredient costing, simplify labels, and keep the modal for inline creation.
- `resources/js/recipe-workbench/sections/costing-section.js`
  Responsibility: support the revised packaging flow, add “choose existing item” behavior, preserve modal saves, and keep packaging totals aligned with the new table layout.
- `resources/js/recipe-workbench/component.js`
  Responsibility: ensure the costing section state still initializes the packaging modal and row data correctly after the layout change.
- `resources/js/recipe-workbench/bridge.js`
  Responsibility: keep the packaging catalog save bridge aligned with the revised create-and-add flow.
- `tests/Feature/PackagingItemsIndexTest.php`
  Responsibility: prove the packaging page can create items, shows the saved list, and remains scoped to the signed-in user.
- `tests/Feature/RecipeWorkbenchCostingContentTest.php`
  Responsibility: lock in the new packaging placement, wording, and actions in the costing tab response.
- `tests/Feature/RecipeWorkbenchPersistenceTest.php`
  Responsibility: verify inline packaging-item creation still returns the payload needed to add the new item directly to costing.

### Deliberate Non-Changes

- No migration for packaging costing storage.
  The existing `quantity` column remains the underlying storage while the UI continues to present the clearer per-unit model.
- No purchase-pack conversion logic.
  Unit price remains the effective per-component price entered by the user.

---

### Task 1: Turn The Packaging Items Page Into A Real Catalog Manager

**Files:**
- Modify: `app/Livewire/Dashboard/PackagingItemsIndex.php`
- Modify: `resources/views/livewire/dashboard/packaging-items-index.blade.php`
- Test: `tests/Feature/PackagingItemsIndexTest.php`

- [ ] **Step 1: Write the failing feature test for direct page creation**

```php
<?php

use App\Livewire\Dashboard\PackagingItemsIndex;
use App\Models\User;
use App\Models\UserPackagingItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

it('creates a packaging item directly from the packaging items page', function () {
    $user = User::factory()->create();

    actingAs($user);

    livewire(PackagingItemsIndex::class)
        ->set('form.name', 'Kraft soap box')
        ->set('form.unit_cost', '0.4200')
        ->set('form.notes', '100g rectangle')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('form.name', '')
        ->assertSee('Kraft soap box');

    expect(UserPackagingItem::query()->where('user_id', $user->id)->first())
        ->not->toBeNull()
        ->name->toBe('Kraft soap box');
});

it('shows only the signed-in user packaging items on the page', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    UserPackagingItem::query()->create([
        'user_id' => $user->id,
        'name' => 'Front sticker',
        'unit_cost' => 0.03,
        'currency' => 'EUR',
        'notes' => null,
    ]);

    UserPackagingItem::query()->create([
        'user_id' => $otherUser->id,
        'name' => 'Hidden competitor box',
        'unit_cost' => 0.99,
        'currency' => 'EUR',
        'notes' => null,
    ]);

    actingAs($user);

    $this->get(route('packaging-items.index'))
        ->assertSuccessful()
        ->assertSee('Front sticker')
        ->assertDontSee('Hidden competitor box')
        ->assertSee('Save packaging item');
});
```

- [ ] **Step 2: Run the page test to verify the current page does not support creation**

Run: `php artisan test --compact tests/Feature/PackagingItemsIndexTest.php`

Expected: FAIL because `PackagingItemsIndex` does not yet have a `form` state, `save()` action, or create-form rendering.

- [ ] **Step 3: Add create-form state and save handling to the Livewire page**

```php
<?php
// app/Livewire/Dashboard/PackagingItemsIndex.php

namespace App\Livewire\Dashboard;

use App\Models\User;
use App\Models\UserPackagingItem;
use App\Services\CurrentAppUserResolver;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class PackagingItemsIndex extends Component
{
    /**
     * @var array{name: string, unit_cost: string, notes: string}
     */
    public array $form = [
        'name' => '',
        'unit_cost' => '',
        'notes' => '',
    ];

    #[Locked]
    public ?int $currentUserId = null;

    public function mount(CurrentAppUserResolver $resolver): void
    {
        $user = $resolver->resolve();

        $this->currentUserId = $user instanceof User ? $user->id : null;
    }

    /**
     * @return array<string, array<int, string>|string>
     */
    protected function rules(): array
    {
        return [
            'form.name' => ['required', 'string', 'max:255'],
            'form.unit_cost' => ['required', 'numeric', 'min:0'],
            'form.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function save(): void
    {
        abort_unless($this->currentUserId !== null, 403);

        $validated = $this->validate();

        UserPackagingItem::query()->create([
            'user_id' => $this->currentUserId,
            'name' => trim($validated['form']['name']),
            'unit_cost' => (float) $validated['form']['unit_cost'],
            'currency' => 'EUR',
            'notes' => blank($validated['form']['notes']) ? null : trim($validated['form']['notes']),
        ]);

        $this->form = [
            'name' => '',
            'unit_cost' => '',
            'notes' => '',
        ];
    }

    public function render(CurrentAppUserResolver $resolver): View
    {
        $currentUser = $resolver->resolve();
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

- [ ] **Step 4: Replace the page markup with a visible create form followed by the catalog list**

```blade
{{-- resources/views/livewire/dashboard/packaging-items-index.blade.php --}}
<div class="mx-auto max-w-[90rem] space-y-6">
    <section class="rounded-[2rem] border border-[var(--color-line)] bg-white p-6">
        <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Packaging items</p>
        <h3 class="mt-3 text-3xl font-semibold tracking-[-0.04em] text-[var(--color-ink-strong)]">Create reusable packaging items here, then reuse them in recipe costing.</h3>
        <p class="mt-4 max-w-3xl text-sm leading-7 text-[var(--color-ink-soft)]">
            This page manages your reusable catalog. Recipe costing decides how many of each packaging item one finished unit uses.
        </p>
    </section>

    @if (! $currentUser)
        <section class="rounded-[2rem] border border-[var(--color-line)] bg-white p-8 text-center">
            <h4 class="text-lg font-semibold text-[var(--color-ink-strong)]">Sign in to manage packaging items</h4>
            <p class="mt-3 text-sm leading-7 text-[var(--color-ink-soft)]">
                Open the dashboard from your signed-in app or admin session to create and reuse packaging items.
            </p>
        </section>
    @else
        <section class="rounded-[2rem] border border-[var(--color-line)] bg-white p-6">
            <div class="flex flex-col gap-6 xl:flex-row xl:items-start xl:justify-between">
                <div class="xl:max-w-xl">
                    <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">New packaging item</p>
                    <h4 class="mt-2 text-xl font-semibold text-[var(--color-ink-strong)]">Add a reusable packaging record</h4>
                    <p class="mt-2 text-sm leading-7 text-[var(--color-ink-soft)]">
                        Save boxes, labels, stickers, wraps, and inserts here so they are ready inside recipe costing.
                    </p>
                </div>

                <div class="rounded-[1.5rem] border border-[var(--color-line)] bg-[var(--color-panel)] px-4 py-3 text-sm text-[var(--color-ink-soft)]">
                    <span class="font-medium text-[var(--color-ink-strong)]">{{ $packagingItemCount }}</span>
                    {{ $packagingItemCount === 1 ? 'saved item' : 'saved items' }}
                </div>
            </div>

            <form wire:submit="save" class="mt-6 grid gap-4 xl:grid-cols-[minmax(0,1.8fr)_14rem]">
                <label class="rounded-[1.5rem] border border-[var(--color-line)] bg-[var(--color-panel)] p-4">
                    <span class="text-xs font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase">Name</span>
                    <input wire:model.blur="form.name" type="text" class="mt-3 w-full rounded-2xl border border-[var(--color-line)] bg-white px-3 py-2.5 text-sm text-[var(--color-ink-strong)] outline-none" placeholder="Box soap rectangle 100g" />
                    @error('form.name')
                        <p class="mt-2 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </label>

                <label class="rounded-[1.5rem] border border-[var(--color-line)] bg-[var(--color-panel)] p-4">
                    <span class="text-xs font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase">Effective unit price</span>
                    <input wire:model.blur="form.unit_cost" type="number" min="0" step="0.0001" class="mt-3 w-full rounded-2xl border border-[var(--color-line)] bg-white px-3 py-2.5 text-sm text-[var(--color-ink-strong)] outline-none" placeholder="0.4200" />
                    @error('form.unit_cost')
                        <p class="mt-2 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </label>

                <label class="rounded-[1.5rem] border border-[var(--color-line)] bg-[var(--color-panel)] p-4 xl:col-span-2">
                    <span class="text-xs font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase">Notes</span>
                    <textarea wire:model.blur="form.notes" rows="3" class="mt-3 w-full rounded-2xl border border-[var(--color-line)] bg-white px-3 py-2.5 text-sm text-[var(--color-ink-strong)] outline-none" placeholder="Optional context for size, finish, or pack variant"></textarea>
                    @error('form.notes')
                        <p class="mt-2 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </label>

                <div class="xl:col-span-2 flex justify-end">
                    <button type="submit" class="rounded-full bg-[var(--color-accent-strong)] px-5 py-2.5 text-sm font-medium text-white transition hover:bg-[var(--color-accent)]">
                        Save packaging item
                    </button>
                </div>
            </form>
        </section>

        <section class="overflow-hidden rounded-[2rem] border border-[var(--color-line)] bg-white">
            <div class="border-b border-[var(--color-line)] px-5 py-4">
                <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Saved packaging</p>
                <h4 class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">Reusable packaging items</h4>
            </div>

            @if ($packagingItems->isEmpty())
                <div class="px-5 py-8 text-sm text-[var(--color-ink-soft)]">
                    No packaging items yet. Create your first packaging item above.
                </div>
            @else
                <div class="divide-y divide-[var(--color-line)]">
                    @foreach ($packagingItems as $packagingItem)
                        <article class="px-5 py-4">
                            <div class="flex flex-col gap-2 md:flex-row md:items-start md:justify-between">
                                <div class="min-w-0">
                                    <h5 class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ $packagingItem->name }}</h5>
                                    @if (filled($packagingItem->notes))
                                        <p class="mt-2 text-sm leading-7 text-[var(--color-ink-soft)]">{{ $packagingItem->notes }}</p>
                                    @endif
                                </div>

                                <div class="rounded-full border border-[var(--color-line)] bg-[var(--color-panel)] px-4 py-2 text-sm font-medium text-[var(--color-ink-strong)]">
                                    {{ $packagingItem->currency }} {{ number_format((float) $packagingItem->unit_cost, 4, '.', '') }}
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>
    @endif
</div>
```

- [ ] **Step 5: Run the page test to verify creation now works**

Run: `php artisan test --compact tests/Feature/PackagingItemsIndexTest.php`

Expected: PASS with the new create-from-page coverage green.

- [ ] **Step 6: Commit the catalog page task**

```bash
git add app/Livewire/Dashboard/PackagingItemsIndex.php resources/views/livewire/dashboard/packaging-items-index.blade.php tests/Feature/PackagingItemsIndexTest.php
git commit -m "feat: make packaging catalog page writable"
```

---

### Task 2: Move Packaging Below Ingredients And Simplify The Costing Layout

**Files:**
- Modify: `resources/views/livewire/dashboard/partials/recipe-workbench/costing-tab.blade.php`
- Test: `tests/Feature/RecipeWorkbenchCostingContentTest.php`

- [ ] **Step 1: Write the failing response test for the new packaging placement and wording**

```php
<?php

use App\Livewire\Dashboard\RecipeWorkbench;
use App\Models\ProductFamily;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

it('renders packaging below ingredient costing with the simplified wording', function () {
    $user = User::factory()->create();
    $family = ProductFamily::factory()->create();
    $recipe = Recipe::factory()->for($user, 'owner')->for($family)->create();
    $version = RecipeVersion::factory()->for($recipe)->for($user, 'owner')->draft()->create();

    actingAs($user);

    $html = livewire(RecipeWorkbench::class, ['recipe' => $recipe])->html();

    expect($html)->toContain('Ingredient costing');
    expect($html)->toContain('Packaging');
    expect($html)->toContain('Add packaging item');
    expect($html)->toContain('New packaging item');
    expect($html)->toContain('Components per unit');
    expect($html)->not->toContain('Packaging usage per finished unit');
});
```

- [ ] **Step 2: Run the costing content test to verify the current sidebar wording still fails**

Run: `php artisan test --compact tests/Feature/RecipeWorkbenchCostingContentTest.php`

Expected: FAIL because the current Blade still renders the sidebar packaging layout and older wording.

- [ ] **Step 3: Rewrite the costing Blade so packaging is a full-width section below ingredients**

```blade
{{-- resources/views/livewire/dashboard/partials/recipe-workbench/costing-tab.blade.php --}}
<div x-show="activeWorkbenchTab === 'costing'" class="space-y-6">
    <section class="rounded-[2rem] border border-[var(--color-line)] bg-white p-5">
        {{-- keep current costing settings block --}}
    </section>

    <section class="overflow-hidden rounded-[2rem] border border-[var(--color-line)] bg-white">
        {{-- keep current ingredient costing table --}}
    </section>

    <section class="overflow-hidden rounded-[2rem] border border-[var(--color-line)] bg-white">
        <div class="border-b border-[var(--color-line)] px-5 py-4">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Packaging</p>
                    <h3 class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">Packaging</h3>
                    <p class="mt-2 text-sm text-[var(--color-ink-soft)]">Add reusable packaging items used for one finished unit.</p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" @click="openPackagingPicker = ! openPackagingPicker" class="rounded-full border border-[var(--color-line)] px-4 py-2 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel)]">
                        Add packaging item
                    </button>
                    <button type="button" @click="openPackagingCatalogModal()" class="rounded-full bg-[var(--color-accent-strong)] px-4 py-2 text-sm font-medium text-white transition hover:bg-[var(--color-accent)]">
                        New packaging item
                    </button>
                </div>
            </div>
        </div>

        <div class="px-5 py-4" x-show="openPackagingPicker">
            <div class="flex flex-wrap gap-2">
                <template x-for="item in packagingCatalog" :key="item.id">
                    <button type="button" @click="addPackagingCostRow(item); openPackagingPicker = false" class="rounded-full border border-[var(--color-line)] bg-[var(--color-panel)] px-4 py-2 text-sm text-[var(--color-ink-strong)] transition hover:bg-white" x-text="item.name"></button>
                </template>
            </div>

            <template x-if="packagingCatalog.length === 0">
                <p class="text-sm text-[var(--color-ink-soft)]">No saved packaging items yet. Use “New packaging item” to create one.</p>
            </template>
        </div>

        <template x-if="packagingCostRows.length === 0">
            <div class="px-5 py-8 text-sm text-[var(--color-ink-soft)]">
                <p class="font-medium text-[var(--color-ink-strong)]">No packaging added yet.</p>
                <p class="mt-2">Add a reusable packaging item to include boxes, labels, stickers, and other unit-level packaging in this costing.</p>
            </div>
        </template>

        <template x-if="packagingCostRows.length > 0">
            <div class="overflow-x-auto">
                <div class="min-w-[56rem]">
                    <div class="grid grid-cols-[minmax(0,1.8fr)_8rem_8rem_8rem_8rem_7rem] gap-px bg-[var(--color-line)] text-sm">
                        <div class="bg-[var(--color-panel)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">Packaging item</div>
                        <div class="bg-[var(--color-panel)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">Components per unit</div>
                        <div class="bg-[var(--color-panel)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">Unit price</div>
                        <div class="bg-[var(--color-panel)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">Cost per unit</div>
                        <div class="bg-[var(--color-panel)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">Batch cost</div>
                        <div class="bg-[var(--color-panel)] px-4 py-3 font-medium text-[var(--color-ink-strong)]"></div>
                    </div>

                    <div class="divide-y divide-[var(--color-line)] bg-white">
                        <template x-for="row in packagingCostRows" :key="row.id">
                            <div class="grid grid-cols-[minmax(0,1.8fr)_8rem_8rem_8rem_8rem_7rem] gap-px bg-[var(--color-line)] text-sm">
                                <div class="bg-white px-4 py-3 font-medium text-[var(--color-ink-strong)]" x-text="row.name"></div>
                                <div class="bg-white px-3 py-3">
                                    <input x-model.number="row.quantity" @change="scheduleCostingSave()" type="number" min="0" step="0.001" class="w-full rounded-xl border border-[var(--color-line)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline-none" />
                                </div>
                                <div class="bg-white px-3 py-3">
                                    <input x-model.number="row.unit_cost" @change="scheduleCostingSave()" type="number" min="0" step="0.0001" class="w-full rounded-xl border border-[var(--color-line)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline-none" />
                                </div>
                                <div class="bg-white px-4 py-3 font-medium text-[var(--color-ink-strong)]" x-text="`${costingCurrency} ${format(packagingCostPerFinishedUnitForRow(row), 2)}`"></div>
                                <div class="bg-white px-4 py-3 font-medium text-[var(--color-ink-strong)]" x-text="costingUnitsProducedValue > 0 ? `${costingCurrency} ${format(packagingBatchCostForRow(row), 2)}` : 'Set units produced'"></div>
                                <div class="bg-white px-4 py-3 text-right">
                                    <button type="button" @click="removePackagingCostRow(row.id)" class="rounded-full border border-[var(--color-line)] px-3 py-1.5 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel)]">
                                        Remove
                                    </button>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </template>
    </section>

    <section class="rounded-[2rem] border border-[var(--color-line)] bg-white p-5">
        {{-- keep current cost summary block --}}
    </section>

    {{-- keep current packaging modal --}}
</div>
```

- [ ] **Step 4: Run the costing content test to verify the new structure passes**

Run: `php artisan test --compact tests/Feature/RecipeWorkbenchCostingContentTest.php`

Expected: PASS with the simplified packaging wording present and the legacy heading gone.

- [ ] **Step 5: Commit the costing layout task**

```bash
git add resources/views/livewire/dashboard/partials/recipe-workbench/costing-tab.blade.php tests/Feature/RecipeWorkbenchCostingContentTest.php
git commit -m "feat: place packaging below ingredient costing"
```

---

### Task 3: Keep The Packaging Interactions Working In The New Layout

**Files:**
- Modify: `resources/js/recipe-workbench/sections/costing-section.js`
- Modify: `resources/js/recipe-workbench/component.js`
- Modify: `resources/js/recipe-workbench/bridge.js`
- Test: `tests/Feature/RecipeWorkbenchPersistenceTest.php`

- [ ] **Step 1: Write the failing persistence test for inline create-and-add after the layout change**

```php
<?php

use App\Livewire\Dashboard\RecipeWorkbench;
use App\Models\ProductFamily;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Models\UserPackagingItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('returns the saved packaging item payload so costing can add it immediately', function () {
    $user = User::factory()->create();
    $family = ProductFamily::factory()->create();
    $recipe = Recipe::factory()->for($user, 'owner')->for($family)->create();
    $version = RecipeVersion::factory()->for($recipe)->for($user, 'owner')->draft()->create();

    actingAs($user);

    $component = app(RecipeWorkbench::class);
    $component->recipe = $recipe;

    $response = $component->savePackagingCatalogItem([
        'name' => 'Bottom label',
        'unit_cost' => 0.025,
        'currency' => 'EUR',
        'notes' => 'Matte white',
    ]);

    expect($response['ok'])->toBeTrue();
    expect($response['packaging_item']['name'])->toBe('Bottom label');
    expect($response['packaging_item']['unit_cost'])->toBe(0.025);
    expect(UserPackagingItem::query()->where('user_id', $user->id)->count())->toBe(1);
});
```

- [ ] **Step 2: Run the persistence test to verify the changed layout still needs explicit state support**

Run: `php artisan test --compact tests/Feature/RecipeWorkbenchPersistenceTest.php --filter=returns_the_saved_packaging_item_payload`

Expected: FAIL if the revised JavaScript state or modal flow is incomplete after the layout rewrite.

- [ ] **Step 3: Update Alpine costing state for the inline picker and modal**

```js
// resources/js/recipe-workbench/sections/costing-section.js
export function createCostingSection(payload) {
    return {
        initializeCostingState() {
            this.openPackagingPicker = false;
            this.applyCostingPayload(payload.costing ?? null);
        },

        addPackagingCostRow(packagingItem = null) {
            this.packagingCostRows = [
                ...this.packagingCostRows,
                {
                    id: this.makeLocalPackagingRowId(),
                    user_packaging_item_id: packagingItem?.id ?? null,
                    name: packagingItem?.name ?? '',
                    unit_cost: packagingItem?.unit_cost ?? 0,
                    quantity: 1,
                },
            ];

            this.openPackagingPicker = false;
            this.scheduleCostingSave();
        },

        openPackagingCatalogModal() {
            this.packagingCatalogStatus = null;
            this.packagingCatalogMessage = '';
            this.resetPackagingCatalogForm();
            this.packagingCatalogModalOpen = true;
        },

        async savePackagingCatalogItem(addToCosting = false) {
            if (`${this.packagingCatalogForm.name ?? ''}`.trim() === '') {
                this.packagingCatalogStatus = 'error';
                this.packagingCatalogMessage = 'Packaging items need a name.';

                return null;
            }

            const saved = await persistPackagingCatalogItem(this, this.packagingCatalogForm);

            if (!saved) {
                return null;
            }

            if (addToCosting) {
                this.addPackagingCostRow(saved);
            }

            this.closePackagingCatalogModal(true);

            if (!addToCosting) {
                this.packagingCatalogMessage = 'Packaging item saved.';
                this.packagingCatalogStatus = 'success';
            }

            return saved;
        },
    };
}
```

```js
// resources/js/recipe-workbench/component.js
export function createRecipeWorkbench(payload) {
    return {
        openPackagingPicker: false,
        packagingCatalogModalOpen: false,
        packagingCatalogStatus: null,
        packagingCatalogMessage: '',
        packagingCatalogForm: {
            id: null,
            name: '',
            unit_cost: '',
            currency: 'EUR',
            notes: '',
        },
        // keep existing workbench state
    };
}
```

```js
// resources/js/recipe-workbench/bridge.js
export async function persistPackagingCatalogItem(component, form) {
    const response = await component.$wire.savePackagingCatalogItem({
        id: form.id,
        name: form.name,
        unit_cost: form.unit_cost,
        currency: form.currency,
        notes: form.notes,
    });

    if (!response?.ok) {
        component.packagingCatalogStatus = 'error';
        component.packagingCatalogMessage = response?.message ?? 'Packaging item could not be saved.';

        return null;
    }

    component.packagingCatalog = response.packaging_catalog ?? component.packagingCatalog ?? [];
    component.packagingCatalogStatus = 'success';
    component.packagingCatalogMessage = response.message ?? 'Packaging item saved.';

    return response.packaging_item ?? null;
}
```

- [ ] **Step 4: Strengthen the persistence test around create-and-add behavior**

```php
it('adds a newly created packaging item to costing with one component per unit by default', function () {
    $componentState = testRecipeWorkbenchCostingSection();

    $componentState->packagingCatalog = [];
    $componentState->packagingCostRows = [];

    $saved = [
        'id' => 15,
        'name' => 'Soap sleeve',
        'unit_cost' => 0.18,
        'currency' => 'EUR',
        'notes' => null,
    ];

    mockPersistPackagingCatalogItem($saved);

    $result = $componentState->savePackagingCatalogItem(true);

    expect($result['id'])->toBe(15);
    expect($componentState->packagingCostRows)->toHaveCount(1);
    expect($componentState->packagingCostRows[0]['name'])->toBe('Soap sleeve');
    expect($componentState->packagingCostRows[0]['quantity'])->toBe(1);
});
```

- [ ] **Step 5: Run the persistence test file to verify the interaction flow**

Run: `php artisan test --compact tests/Feature/RecipeWorkbenchPersistenceTest.php`

Expected: PASS with both the backend payload and JavaScript-assisted interaction coverage green.

- [ ] **Step 6: Commit the interaction task**

```bash
git add resources/js/recipe-workbench/sections/costing-section.js resources/js/recipe-workbench/component.js resources/js/recipe-workbench/bridge.js tests/Feature/RecipeWorkbenchPersistenceTest.php
git commit -m "feat: streamline packaging costing interactions"
```

---

### Task 4: Run The Full Packaging Regression Slice And Final Cleanup

**Files:**
- Modify as needed: only files touched by failed verification
- Test: `tests/Feature/PackagingItemsIndexTest.php`
- Test: `tests/Feature/RecipeWorkbenchCostingContentTest.php`
- Test: `tests/Feature/RecipeWorkbenchPersistenceTest.php`
- Test: `tests/Feature/RecipeVersionCostingTest.php`

- [ ] **Step 1: Run the full packaging-related regression slice**

Run: `php artisan test --compact tests/Feature/PackagingItemsIndexTest.php tests/Feature/RecipeWorkbenchCostingContentTest.php tests/Feature/RecipeWorkbenchPersistenceTest.php tests/Feature/RecipeVersionCostingTest.php`

Expected: PASS with all packaging catalog, costing content, persistence, and snapshot tests green.

- [ ] **Step 2: Run formatting on the touched PHP files**

Run: `vendor/bin/pint --dirty --format agent`

Expected: `{"result":"pass"}`

- [ ] **Step 3: Build the frontend assets because Blade and Alpine files changed**

Run: `npm run build`

Expected: Vite build succeeds with exit code `0`.

- [ ] **Step 4: If verification exposes failures, make the minimal fix only in the affected files and rerun the exact failing command**

```bash
php artisan test --compact <failing-test-file>
vendor/bin/pint --dirty --format agent
npm run build
```

- [ ] **Step 5: Commit the verified final state**

```bash
git add app/Livewire/Dashboard/PackagingItemsIndex.php resources/views/livewire/dashboard/packaging-items-index.blade.php resources/views/livewire/dashboard/partials/recipe-workbench/costing-tab.blade.php resources/js/recipe-workbench/sections/costing-section.js resources/js/recipe-workbench/component.js resources/js/recipe-workbench/bridge.js tests/Feature/PackagingItemsIndexTest.php tests/Feature/RecipeWorkbenchCostingContentTest.php tests/Feature/RecipeWorkbenchPersistenceTest.php tests/Feature/RecipeVersionCostingTest.php
git commit -m "feat: simplify packaging costing flow"
```

---

## Self-Review Checklist

- Spec coverage:
  - packaging page direct creation is covered in Task 1
  - packaging below ingredients is covered in Task 2
  - simplified wording and row structure are covered in Task 2
  - inline costing creation and add-to-costing behavior are covered in Task 3
  - regression verification and build checks are covered in Task 4
- Placeholder scan:
  - no `TODO`, `TBD`, or “implement later” placeholders remain
- Type consistency:
  - the plan consistently uses `quantity` as the persisted row field and `components per unit` as the UI label
  - the packaging create action is consistently named `save()` on the page and `savePackagingCatalogItem()` in the workbench flow
