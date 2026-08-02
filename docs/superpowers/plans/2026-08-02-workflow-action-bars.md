# Workflow Action Bars Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give Soapkraft forms one compact sticky action surface and one consistent 8px-radius vocabulary for workflow buttons.

**Architecture:** Add one anonymous Blade component that owns sticky positioning, safe-area spacing, translucent panel treatment, and leading/trailing action layout. Existing views supply their own Livewire actions and links through named and default slots, while the existing `sk-btn` variants remain the only button primitives.

**Tech Stack:** Laravel 13 Blade components, Livewire 4, Tailwind CSS 4, Pest 4, existing Soapkraft CSS tokens.

---

## File Map

- Create `resources/views/components/workflow-action-bar.blade.php`: reusable sticky action surface with optional leading slot.
- Create `tests/Feature/WorkflowActionBarTest.php`: component structure and responsive-layout regression coverage.
- Modify `resources/views/livewire/production-bench/purchasing/supplier-create.blade.php`: shared create-form actions.
- Modify `resources/views/livewire/production-bench/purchasing/supplier-edit.blade.php`: shared edit actions with Delete in the leading slot.
- Modify `resources/views/livewire/production-bench/purchasing/supplier-listing-create.blade.php`: shared create/edit actions with conditional Delete.
- Modify `resources/views/livewire/dashboard/ingredient-editor.blade.php`: shared Cancel and Save/Create actions.
- Modify `resources/views/livewire/dashboard/packaging-item-editor.blade.php`: remove duplicate header Back action and add shared sticky form actions.
- Modify supplier, ingredient, and packaging browse views: replace pill-shaped workflow links with `sk-btn` variants.
- Modify focused Pest files: assert component use, action placement, and absence of pill styling on workflow actions.

### Task 1: Create the shared workflow action bar

**Files:**
- Create: `resources/views/components/workflow-action-bar.blade.php`
- Create: `tests/Feature/WorkflowActionBarTest.php`

- [ ] **Step 1: Create the failing component test**

Run `php artisan make:test --pest WorkflowActionBarTest --no-interaction`, then replace the generated test with:

```php
<?php

use Illuminate\Support\Facades\Blade;

it('renders a compact sticky workflow surface with leading and trailing actions', function (): void {
    $html = Blade::render(<<<'BLADE'
        <x-workflow-action-bar data-example-save-bar>
            <x-slot:leading>
                <button type="button" class="sk-btn sk-btn-danger">Delete</button>
            </x-slot:leading>

            <a href="/cancel" class="sk-btn sk-btn-ghost">Cancel</a>
            <button type="submit" class="sk-btn sk-btn-primary">Save</button>
        </x-workflow-action-bar>
    BLADE);

    expect($html)
        ->toContain('data-workflow-action-bar')
        ->toContain('data-example-save-bar')
        ->toContain('fixed bottom-0 left-0 right-0')
        ->toContain('lg:left-[var(--app-sidebar-width,0rem)]')
        ->toContain('backdrop-blur-md')
        ->toContain('bg-[color-mix(in_oklab,var(--color-panel)_88%,transparent)]')
        ->toContain('sk-btn sk-btn-danger')
        ->toContain('sk-btn sk-btn-ghost')
        ->toContain('sk-btn sk-btn-primary');
});

it('right-aligns actions when no leading action is provided', function (): void {
    $html = Blade::render(<<<'BLADE'
        <x-workflow-action-bar>
            <button type="submit" class="sk-btn sk-btn-primary">Create</button>
        </x-workflow-action-bar>
    BLADE);

    expect($html)
        ->toContain('ml-auto flex flex-wrap items-center justify-end gap-2')
        ->not->toContain('rounded-full');
});
```

- [ ] **Step 2: Run the test and verify RED**

Run:

```bash
php artisan test --compact tests/Feature/WorkflowActionBarTest.php
```

Expected: FAIL because `workflow-action-bar` does not exist or the required structure is absent.

- [ ] **Step 3: Add the anonymous Blade component**

Create `resources/views/components/workflow-action-bar.blade.php`:

```blade
<div
    {{ $attributes->class([
        'pointer-events-none fixed bottom-0 left-0 right-0 z-30 px-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] sm:px-5 lg:left-[var(--app-sidebar-width,0rem)]',
    ]) }}
    data-workflow-action-bar
>
    <div class="pointer-events-auto mx-auto flex max-w-5xl flex-wrap items-center gap-2 rounded-xl border border-[var(--color-line)] bg-[color-mix(in_oklab,var(--color-panel)_88%,transparent)] px-3 py-3 shadow-[0_-8px_24px_rgba(60,50,30,0.10)] backdrop-blur-md sm:flex-nowrap sm:px-4">
        @isset($leading)
            @if ($leading->hasActualContent())
                <div class="flex min-w-0 items-center gap-2">
                    {{ $leading }}
                </div>
            @endif
        @endisset

        <div class="ml-auto flex flex-wrap items-center justify-end gap-2">
            {{ $slot }}
        </div>
    </div>
</div>
```

- [ ] **Step 4: Run the component test and verify GREEN**

Run:

```bash
php artisan test --compact tests/Feature/WorkflowActionBarTest.php
```

Expected: 2 tests pass.

- [ ] **Step 5: Commit the component**

```bash
git add resources/views/components/workflow-action-bar.blade.php tests/Feature/WorkflowActionBarTest.php
git commit -m "Add shared workflow action bar"
```

### Task 2: Migrate supplier and supplier-listing forms

**Files:**
- Modify: `resources/views/livewire/production-bench/purchasing/supplier-create.blade.php`
- Modify: `resources/views/livewire/production-bench/purchasing/supplier-edit.blade.php`
- Modify: `resources/views/livewire/production-bench/purchasing/supplier-listing-create.blade.php`
- Modify: `tests/Feature/ProductionBenchSupplierFormPagesTest.php`
- Modify: `tests/Feature/ProductionBenchSupplierListingCreatePageTest.php`

- [ ] **Step 1: Add failing form-action assertions**

Extend the supplier create/edit response assertions to require `data-workflow-action-bar`, `sk-btn sk-btn-ghost`, and `sk-btn sk-btn-primary`. For the edit response also require `sk-btn sk-btn-danger` and assert the Delete text appears before Cancel in the rendered HTML.

Add equivalent assertions to supplier-listing create/edit tests. The create response must not contain `sk-btn sk-btn-danger`; the edit response must contain it.

Use this ordering assertion for each edit page:

```php
$html = $response->getContent();

expect(strpos($html, 'Delete supplier'))
    ->toBeLessThan(strpos($html, '>Cancel</a>'));
```

For supplier listing use `Delete listing`.

- [ ] **Step 2: Run the focused tests and verify RED**

Run:

```bash
php artisan test --compact tests/Feature/ProductionBenchSupplierFormPagesTest.php tests/Feature/ProductionBenchSupplierListingCreatePageTest.php
```

Expected: FAIL because the views still contain duplicated transparent save-bar markup and plain text Delete/Cancel actions.

- [ ] **Step 3: Replace supplier create actions**

Replace the fixed save-bar block in `supplier-create.blade.php` with:

```blade
<x-workflow-action-bar data-production-bench-save-bar>
    <a href="{{ route('production-bench.purchasing.suppliers') }}" wire:navigate class="sk-btn sk-btn-ghost">
        {{ __('production_bench.common.cancel') }}
    </a>
    <button type="submit" class="sk-btn sk-btn-primary" wire:loading.attr="disabled" wire:target="save">
        {{ __('production_bench.supplier.save') }}
    </button>
</x-workflow-action-bar>
```

- [ ] **Step 4: Replace supplier edit actions**

Replace the fixed save-bar block in `supplier-edit.blade.php` with:

```blade
<x-workflow-action-bar data-production-bench-save-bar>
    <x-slot:leading>
        <button type="button" wire:click="delete" wire:confirm="{{ __('production_bench.supplier.delete_confirm') }}" class="sk-btn sk-btn-danger" wire:loading.attr="disabled" wire:target="delete">
            {{ __('production_bench.supplier.delete') }}
        </button>
    </x-slot:leading>

    <a href="{{ route('production-bench.purchasing.supplier', $supplier) }}" wire:navigate class="sk-btn sk-btn-ghost">
        {{ __('production_bench.common.cancel') }}
    </a>
    <button type="submit" class="sk-btn sk-btn-primary" wire:loading.attr="disabled" wire:target="save">
        {{ __('production_bench.supplier.save') }}
    </button>
</x-workflow-action-bar>
```

- [ ] **Step 5: Replace supplier-listing create/edit actions**

Replace the fixed save-bar block in `supplier-listing-create.blade.php` with:

```blade
<x-workflow-action-bar data-production-bench-save-bar>
    @if ($editingListingPublicId)
        <x-slot:leading>
            <button type="button" wire:click="delete" wire:confirm="{{ __('production_bench.listing.delete_confirm') }}" class="sk-btn sk-btn-danger" wire:loading.attr="disabled" wire:target="delete">
                {{ __('production_bench.listing.delete') }}
            </button>
        </x-slot:leading>
    @endif

    <a href="{{ $lockedSupplier ? route('production-bench.purchasing.supplier', $lockedSupplier) : route('production-bench.purchasing.listings') }}" wire:navigate class="sk-btn sk-btn-ghost">
        {{ __('production_bench.common.cancel') }}
    </a>
    <button type="submit" class="sk-btn sk-btn-primary" wire:loading.attr="disabled" wire:target="save">
        {{ $editingListingPublicId ? __('production_bench.listing.save_changes') : __('production_bench.listing.save') }}
    </button>
</x-workflow-action-bar>
```

- [ ] **Step 6: Run focused tests and verify GREEN**

Run:

```bash
php artisan test --compact tests/Feature/ProductionBenchSupplierFormPagesTest.php tests/Feature/ProductionBenchSupplierListingCreatePageTest.php
```

Expected: all tests pass.

- [ ] **Step 7: Commit supplier form migration**

```bash
git add resources/views/livewire/production-bench/purchasing/supplier-create.blade.php resources/views/livewire/production-bench/purchasing/supplier-edit.blade.php resources/views/livewire/production-bench/purchasing/supplier-listing-create.blade.php tests/Feature/ProductionBenchSupplierFormPagesTest.php tests/Feature/ProductionBenchSupplierListingCreatePageTest.php
git commit -m "Unify Production Bench form actions"
```

### Task 3: Migrate ingredient and packaging editors

**Files:**
- Modify: `resources/views/livewire/dashboard/ingredient-editor.blade.php`
- Modify: `resources/views/livewire/dashboard/packaging-item-editor.blade.php`
- Modify: `tests/Feature/IngredientEditorLocalizationTest.php`
- Modify: `tests/Feature/PackagingItemEditorLocalizationTest.php`
- Modify: `tests/Feature/PublicIngredientPagesTest.php`

- [ ] **Step 1: Add failing editor assertions**

In the ingredient editor tests assert the editable view contains:

```php
->assertSeeHtml('data-ingredient-save-bar')
->assertSeeHtml('data-workflow-action-bar')
->assertSeeHtml('class="sk-btn sk-btn-ghost"')
->assertSeeHtml('class="sk-btn sk-btn-primary"');
```

In the packaging editor test assert `data-packaging-save-bar`, `data-workflow-action-bar`, ghost Cancel, and primary Save/Create. Also assert `rounded-full` is absent from the form action area source.

- [ ] **Step 2: Run the editor tests and verify RED**

Run:

```bash
php artisan test --compact tests/Feature/IngredientEditorLocalizationTest.php tests/Feature/PackagingItemEditorLocalizationTest.php tests/Feature/PublicIngredientPagesTest.php
```

Expected: FAIL because the ingredient bar has only Save and the packaging editor has a non-sticky pill button.

- [ ] **Step 3: Replace the ingredient save bar**

Inside the existing `@unless ($isPlatformIngredient)` block use:

```blade
<x-workflow-action-bar data-ingredient-save-bar>
    <a href="{{ route('ingredients.index') }}" wire:navigate class="sk-btn sk-btn-ghost">
        {{ __('ingredients.actions.cancel') }}
    </a>
    <button type="submit" wire:loading.attr="disabled" wire:target="save" class="sk-btn sk-btn-primary">
        {{ $ingredient ? __('ingredients.editor.actions.save') : __('ingredients.editor.actions.create') }}
    </button>
</x-workflow-action-bar>
```

- [ ] **Step 4: Replace packaging editor actions**

Remove the header-level Back button block, add `pb-24` to the form, and replace the inline submit row with:

```blade
<x-workflow-action-bar data-packaging-save-bar>
    <a href="{{ route('packaging-items.index') }}" wire:navigate class="sk-btn sk-btn-ghost">
        {{ __('packaging.actions.cancel') }}
    </a>
    <button type="submit" wire:loading.attr="disabled" wire:target="save" class="sk-btn sk-btn-primary">
        {{ $packagingItem ? __('packaging.editor.actions.save') : __('packaging.editor.actions.create') }}
    </button>
</x-workflow-action-bar>
```

- [ ] **Step 5: Run editor tests and verify GREEN**

Run:

```bash
php artisan test --compact tests/Feature/IngredientEditorLocalizationTest.php tests/Feature/PackagingItemEditorLocalizationTest.php tests/Feature/PublicIngredientPagesTest.php
```

Expected: all selected tests pass.

- [ ] **Step 6: Commit editor migration**

```bash
git add resources/views/livewire/dashboard/ingredient-editor.blade.php resources/views/livewire/dashboard/packaging-item-editor.blade.php tests/Feature/IngredientEditorLocalizationTest.php tests/Feature/PackagingItemEditorLocalizationTest.php tests/Feature/PublicIngredientPagesTest.php
git commit -m "Unify ingredient and packaging form actions"
```

### Task 4: Standardize adjacent workflow links

**Files:**
- Modify: `resources/views/livewire/production-bench/purchasing/supplier-index.blade.php`
- Modify: `resources/views/livewire/production-bench/purchasing/supplier-listing-index.blade.php`
- Modify: `resources/views/livewire/production-bench/purchasing/supplier-detail.blade.php`
- Modify: `resources/views/livewire/dashboard/ingredients-index.blade.php`
- Modify: `resources/views/livewire/dashboard/packaging-items-index.blade.php`
- Modify: `tests/Feature/ProductionBenchSupplierPagesTest.php`
- Create: `tests/Feature/WorkflowButtonConsistencyTest.php`

- [ ] **Step 1: Add a failing workflow-button consistency test**

Run `php artisan make:test --pest WorkflowButtonConsistencyTest --no-interaction`, then use:

```php
<?php

it('uses app button primitives for browse-page workflow actions', function (string $view, string $routeNeedle): void {
    $source = file_get_contents(resource_path("views/{$view}.blade.php"));
    $needlePosition = strpos($source, $routeNeedle);

    expect($needlePosition)->not->toBeFalse();

    $anchorStart = strrpos(substr($source, 0, $needlePosition), '<a');
    $anchorEnd = strpos($source, '</a>', $needlePosition);
    $anchor = substr($source, $anchorStart, $anchorEnd - $anchorStart + 4);

    expect($anchor)
        ->toContain('sk-btn')
        ->not->toContain('rounded-full');
})->with([
    'add supplier' => ['livewire/production-bench/purchasing/supplier-index', "route('production-bench.purchasing.suppliers.create')"],
    'add listing from listing index' => ['livewire/production-bench/purchasing/supplier-listing-index', "route('production-bench.purchasing.listings.create')"],
    'edit supplier' => ['livewire/production-bench/purchasing/supplier-detail', "route('production-bench.purchasing.suppliers.edit', \$supplier)"],
    'add listing from supplier' => ['livewire/production-bench/purchasing/supplier-detail', "route('production-bench.purchasing.suppliers.listings.create', \$supplier)"],
    'back from ingredients' => ['livewire/dashboard/ingredients-index', "route('dashboard')"],
    'back from packaging' => ['livewire/dashboard/packaging-items-index', "route('dashboard')"],
]);
```

Add rendered-page assertions to `ProductionBenchSupplierPagesTest.php` requiring `sk-btn sk-btn-primary` for Add and `sk-btn sk-btn-outline` for Edit supplier.

- [ ] **Step 2: Run consistency tests and verify RED**

Run:

```bash
php artisan test --compact tests/Feature/WorkflowButtonConsistencyTest.php tests/Feature/ProductionBenchSupplierPagesTest.php
```

Expected: FAIL on existing `rounded-full` Add, Edit, and Back links.

- [ ] **Step 3: Convert browse-page workflow links**

Use these variants consistently:

```blade
{{-- Primary Add action --}}
class="sk-btn sk-btn-primary"

{{-- Secondary Edit or Back action --}}
class="sk-btn sk-btn-outline"
```

Apply them to:

- Add supplier on supplier index
- Add listing on supplier-listing index
- Edit supplier and Add listing on supplier detail
- Back to dashboard on ingredient and packaging indexes

Leave status badges, ownership filters, sort controls, and segmented selectors unchanged.

- [ ] **Step 4: Run consistency tests and verify GREEN**

Run:

```bash
php artisan test --compact tests/Feature/WorkflowButtonConsistencyTest.php tests/Feature/ProductionBenchSupplierPagesTest.php
```

Expected: all tests pass.

- [ ] **Step 5: Commit workflow link standardization**

```bash
git add resources/views/livewire/production-bench/purchasing/supplier-index.blade.php resources/views/livewire/production-bench/purchasing/supplier-listing-index.blade.php resources/views/livewire/production-bench/purchasing/supplier-detail.blade.php resources/views/livewire/dashboard/ingredients-index.blade.php resources/views/livewire/dashboard/packaging-items-index.blade.php tests/Feature/ProductionBenchSupplierPagesTest.php tests/Feature/WorkflowButtonConsistencyTest.php
git commit -m "Standardize workflow action buttons"
```

### Task 5: Verify the completed UI consistency pass

**Files:**
- Verify all files modified in Tasks 1 through 4.

- [ ] **Step 1: Format PHP tests**

Run:

```bash
vendor/bin/pint --dirty --format agent
```

Expected: Pint reports `passed` after applying any formatting.

- [ ] **Step 2: Run the complete affected test set**

Run:

```bash
php artisan test --compact tests/Feature/WorkflowActionBarTest.php tests/Feature/WorkflowButtonConsistencyTest.php tests/Feature/ProductionBenchSupplierFormPagesTest.php tests/Feature/ProductionBenchSupplierListingCreatePageTest.php tests/Feature/ProductionBenchSupplierPagesTest.php tests/Feature/IngredientEditorLocalizationTest.php tests/Feature/PackagingItemEditorLocalizationTest.php tests/Feature/PublicIngredientPagesTest.php
```

Expected: all tests pass with zero failures.

- [ ] **Step 3: Build frontend assets**

Run:

```bash
npm run build
```

Expected: Vite exits successfully and emits production assets.

- [ ] **Step 4: Refresh the code graph**

Run:

```bash
graphify update .
```

Expected: Graphify rebuilds `graph.json`, `graph.html`, and `GRAPH_REPORT.md` without errors.

- [ ] **Step 5: Inspect the final diff and commit any verification formatting**

Run:

```bash
git status --short
git diff --check
```

If Pint changed tracked files, commit them:

```bash
git add tests/Feature/WorkflowActionBarTest.php tests/Feature/WorkflowButtonConsistencyTest.php tests/Feature/ProductionBenchSupplierFormPagesTest.php tests/Feature/ProductionBenchSupplierListingCreatePageTest.php tests/Feature/ProductionBenchSupplierPagesTest.php tests/Feature/IngredientEditorLocalizationTest.php tests/Feature/PackagingItemEditorLocalizationTest.php tests/Feature/PublicIngredientPagesTest.php
git commit -m "Format workflow action bar tests"
```

Expected: clean working tree and no whitespace errors.
