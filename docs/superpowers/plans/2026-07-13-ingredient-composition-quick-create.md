# Ingredient Composition Quick Create Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make blend composition editing secure, locale-consistent, keyboard accessible, and able to create an active private component ingredient from an editable name and required category without leaving the editor.

**Architecture:** Keep persistence and authorization in `UserIngredientAuthoringService` and `IngredientDataEntryService`. Add a small Livewire quick-create state and action to `IngredientEditor`; render it progressively inside the existing custom composition Blade view. Keep percentage totals authoritative in PHP and use Alpine only for combobox interaction state.

**Tech Stack:** PHP 8.5, Laravel 13, Livewire 4, Filament 5 schema views, Alpine.js, Tailwind CSS 4, Pest 4.

---

## File Map

- Modify `app/Services/IngredientDataEntryService.php`: distinguish omitted source notes from explicitly blank source notes.
- Modify `app/Services/UserIngredientAuthoringService.php`: require active accessible blend components; continue using the shared active-by-default creation path.
- Modify `app/Livewire/Dashboard/IngredientEditor.php`: centralize custom composition state merging, expose quick-create state/actions, and keep totals server authoritative.
- Modify `resources/views/livewire/dashboard/partials/ingredient-composition-rows.blade.php`: add progressive quick creation and accessible active-option combobox behavior.
- Modify `resources/css/app.css`: preserve visible focus without broad `!important` suppression.
- Modify `tests/Feature/IngredientDataEntryServiceTest.php`: source-note omission and explicit-clearing coverage.
- Modify `tests/Feature/UserIngredientAuthoringTest.php`: inactive rejection, active defaults, quick creation, state merging, total, and rendered accessibility coverage.

Existing uncommitted changes overlap all implementation files. Do not create intermediate commits that accidentally capture unrelated user work. Use test checkpoints after every task and leave final staging or committing for explicit user authorization.

### Task 1: Preserve Omitted Sources and Clear Explicit Blanks

**Files:**
- Modify: `tests/Feature/IngredientDataEntryServiceTest.php`
- Modify: `app/Services/IngredientDataEntryService.php`

- [ ] **Step 1: Write failing explicit-clearing coverage**

Create three suitable parent records because category rules prevent one ingredient from retaining all three child types: a carrier oil with a fatty-acid row, an essential oil with an allergen row, and a blend with a component row. Give every child row legacy `source_notes`. Add a test that resyncs each matching row with `'source_notes' => ''` and asserts each persisted row has `source_notes === null`:

```php
expect($carrierOil->fresh()->fattyAcidEntries->first()->source_notes)->toBeNull()
    ->and($essentialOil->fresh()->allergenEntries->first()->source_notes)->toBeNull()
    ->and($blend->fresh()->components->first()->source_notes)->toBeNull();
```

- [ ] **Step 2: Run the focused test and verify RED**

Run `php artisan test --compact tests/Feature/IngredientDataEntryServiceTest.php --filter="clears explicitly blank per-row source notes"`.

Expected: FAIL because the current `filled()` fallback restores the legacy values.

- [ ] **Step 3: Implement missing-versus-present source semantics**

Add this helper to `IngredientDataEntryService` and use it in `syncSapProfile()`, `syncAllergenEntries()`, and `syncComponents()`:

```php
/**
 * @param  array<string, mixed>  $row
 */
private function sourceNotesForResync(array $row, mixed $existingSourceNotes): ?string
{
    if (! array_key_exists('source_notes', $row)) {
        return filled($existingSourceNotes) ? (string) $existingSourceNotes : null;
    }

    return filled($row['source_notes'])
        ? trim((string) $row['source_notes'])
        : null;
}
```

- [ ] **Step 4: Run preservation and clearing tests and verify GREEN**

Run `php artisan test --compact tests/Feature/IngredientDataEntryServiceTest.php --filter="per-row source notes"`.

Expected: omission-preservation and explicit-clearing tests PASS.

### Task 2: Enforce Active Components and Active Creation Defaults

**Files:**
- Modify: `tests/Feature/UserIngredientAuthoringTest.php`
- Modify: `app/Services/UserIngredientAuthoringService.php`

- [ ] **Step 1: Write the inactive-component regression test**

```php
it('rejects inactive blend components during server-side persistence validation', function () {
    $user = User::factory()->create();
    $inactiveIngredient = Ingredient::factory()->create([
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
        'is_active' => false,
    ]);

    expect(fn () => app(UserIngredientAuthoringService::class)->create([
        'name' => 'Inactive Component Blend',
        'category' => IngredientCategory::Additive->value,
        'ingredient_structure' => 'blend',
        'components' => [[
            'component_ingredient_id' => $inactiveIngredient->id,
            'percentage_in_parent' => 100,
        ]],
    ], $user))->toThrow(ValidationException::class, 'not available to you');
});
```

Extend the minimal normal-creation test and existing inline-component service test with `->and($ingredient->is_active)->toBeTrue()`.

- [ ] **Step 2: Run the focused test and verify RED**

Run `php artisan test --compact tests/Feature/UserIngredientAuthoringTest.php --filter="rejects inactive blend components"`.

Expected: FAIL because `validateBlendComponents()` counts inactive accessible ingredients.

- [ ] **Step 3: Add the persistence constraint**

```php
$accessibleCount = Ingredient::query()
    ->accessibleTo($user)
    ->where('is_active', true)
    ->whereKey($componentIds->unique()->all())
    ->count();
```

Do not add a second creation path. `createInlineComponent()` continues delegating to `create()`, whose constructor state and `fillIngredient()` set `is_active` to true.

- [ ] **Step 4: Run active-status tests and verify GREEN**

Run `php artisan test --compact tests/Feature/UserIngredientAuthoringTest.php --filter="active|creates a minimal private|creates missing composite"`.

Expected: all selected tests PASS.

### Task 3: Add Test-First Livewire Quick Creation

**Files:**
- Modify: `tests/Feature/UserIngredientAuthoringTest.php`
- Modify: `app/Livewire/Dashboard/IngredientEditor.php`

- [ ] **Step 1: Write quick-create behavior tests**

```php
it('quick creates an active private ingredient and immediately adds it to the composition', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(IngredientEditor::class)
        ->set('data.ingredient_structure', 'blend')
        ->set('quickComponentName', 'Calendula Flowers')
        ->set('quickComponentCategory', IngredientCategory::BotanicalExtract->value)
        ->call('createAndAddComponent')
        ->assertSet('quickComponentName', '')
        ->assertSet('quickComponentCategory', null)
        ->assertSet('data.components.0.percentage_in_parent', null);

    $component = Ingredient::query()->where('display_name', 'Calendula Flowers')->sole();

    expect($component->owner_id)->toBe($user->id)
        ->and($component->visibility)->toBe(Visibility::Private)
        ->and($component->is_active)->toBeTrue();
});
```

Add validation coverage that calls the action with a blank name or category, asserts errors on both quick-create properties, preserves entered values, and creates no record. Add a 20-row capacity case that asserts a `data.components` error and no created record.

- [ ] **Step 2: Run quick-create tests and verify RED**

Run `php artisan test --compact tests/Feature/UserIngredientAuthoringTest.php --filter="quick create"`.

Expected: FAIL because the Livewire properties and action do not exist.

- [ ] **Step 3: Add Livewire state and action**

Add properties:

```php
public string $quickComponentName = '';

public ?string $quickComponentCategory = null;
```

Add the action and import `Illuminate\Validation\Rule`:

```php
public function createAndAddComponent(UserIngredientAuthoringService $authoringService): void
{
    $user = $this->currentUser();

    if (! $user instanceof User) {
        $this->addError('quickComponentName', 'Sign in before creating an ingredient.');

        return;
    }

    if (count($this->data['components'] ?? []) >= 20) {
        $this->addError('data.components', 'A blend can contain at most 20 components.');

        return;
    }

    $validated = $this->validate([
        'quickComponentName' => ['required', 'string', 'max:255'],
        'quickComponentCategory' => ['required', Rule::enum(IngredientCategory::class)],
    ]);

    $ingredient = $authoringService->createInlineComponent([
        'name' => $validated['quickComponentName'],
        'category' => $validated['quickComponentCategory'],
    ], $user);

    $this->addComponent($ingredient->id);
    $this->quickComponentName = '';
    $this->quickComponentCategory = null;
    $this->dispatch('component-created');
}
```

- [ ] **Step 4: Run quick-create tests and verify GREEN**

Run `php artisan test --compact tests/Feature/UserIngredientAuthoringTest.php --filter="quick create"`.

Expected: all quick-create tests PASS.

### Task 4: Centralize Custom State and Server Totals

**Files:**
- Modify: `tests/Feature/UserIngredientAuthoringTest.php`
- Modify: `app/Livewire/Dashboard/IngredientEditor.php`
- Modify: `resources/views/livewire/dashboard/partials/ingredient-composition-rows.blade.php`

- [ ] **Step 1: Add locale-total coverage**

```php
it('calculates composition totals with the server locale parser', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $component = Livewire::test(IngredientEditor::class)
        ->set('data.components', [
            ['percentage_in_parent' => '4 0,5'],
            ['percentage_in_parent' => '59,5'],
        ]);

    expect($component->instance()->componentPercentageTotal())->toBe(100.0);
});
```

- [ ] **Step 2: Run the total test and verify current server behavior**

Run `php artisan test --compact tests/Feature/UserIngredientAuthoringTest.php --filter="calculates composition totals"`.

Expected: PASS, proving the server parser is the desired authority.

- [ ] **Step 3: Extract custom persistence-state merging**

Replace the manual assignments in `save()` with:

```php
/** @var array<string, mixed> $state */
$state = $this->mergeCustomCompositionState($this->form->getState());
```

Add:

```php
/**
 * @param  array<string, mixed>  $state
 * @return array<string, mixed>
 */
private function mergeCustomCompositionState(array $state): array
{
    $state['components'] = $this->data['components'] ?? [];
    $state['composition_source_notes'] = $this->data['composition_source_notes'] ?? null;

    return $state;
}
```

Remove the composition section's Alpine `total` state and `x-on:input` parser. Render `componentPercentageTotal()` directly; `wire:model.live.debounce.300ms` triggers the authoritative rerender.

- [ ] **Step 4: Run persistence and total tests**

Run `php artisan test --compact tests/Feature/UserIngredientAuthoringTest.php --filter="saves a blend composition|calculates composition totals"`.

Expected: both tests PASS.

### Task 5: Build the Progressive Accessible Composer UI

**Files:**
- Modify: `resources/views/livewire/dashboard/partials/ingredient-composition-rows.blade.php`
- Modify: `resources/css/app.css`
- Modify: `tests/Feature/UserIngredientAuthoringTest.php`

- [ ] **Step 1: Write rendered-contract assertions**

Extend the composition visibility test:

```php
->assertSee('aria-autocomplete="list"', false)
->assertSee(':aria-activedescendant=', false)
->assertSee('Create ingredient')
->assertSee('quickComponentName', false)
->assertSee('quickComponentCategory', false)
->assertSee('Create and add');
```

- [ ] **Step 2: Run the rendered-contract test and verify RED**

Run `php artisan test --compact tests/Feature/UserIngredientAuthoringTest.php --filter="shows composition only"`.

Expected: FAIL because quick creation and active-descendant markup are absent.

- [ ] **Step 3: Implement active-option combobox behavior**

Add `activeIndex: -1` to Alpine state. Reset it when the query changes. Implement `moveActive(direction)` and `selectActiveComponent()` using `filteredOptions`. Give every option `:id="'composition-ingredient-option-' + option.id"`, bind `:aria-selected="(activeIndex === index).toString()"`, and bind the input:

```html
aria-autocomplete="list"
:aria-activedescendant="open && activeIndex >= 0 && filteredOptions[activeIndex]
    ? 'composition-ingredient-option-' + filteredOptions[activeIndex].id
    : null"
@keydown.arrow-down.prevent="open = true; moveActive(1)"
@keydown.arrow-up.prevent="open = true; moveActive(-1)"
@keydown.enter.prevent="selectActiveComponent()"
@keydown.escape.prevent="open = false; activeIndex = -1"
```

Keep focus in the input. Hovering an option updates `activeIndex`; selecting resets it.

- [ ] **Step 4: Implement the progressive quick creator**

Add Alpine `creating: false`. The **Create ingredient** button copies `query` to `$wire.quickComponentName` and opens an inset containing:

```html
<input wire:model="quickComponentName" type="text" maxlength="255" />
<select wire:model="quickComponentCategory">
    <option value="">Choose a category</option>
    @foreach (IngredientCategory::options() as $value => $label)
        <option value="{{ $value }}">{{ $label }}</option>
    @endforeach
</select>
<button type="button" wire:click="createAndAddComponent" wire:loading.attr="disabled">
    Create and add
</button>
<button type="button" @click="creating = false" wire:click="$set('quickComponentName', '')">
    Cancel
</button>
```

Render field-level `@error` messages. Listen for `component-created` on the Alpine root to close the creator and clear the query.

- [ ] **Step 5: Restore explicit focus visibility**

Remove the scoped `!important` focus suppression. Keep the wrapper ring for the text input and add direct `:focus-visible` styles for the toggle and quick-create buttons.

- [ ] **Step 6: Run rendered-contract and quick-create tests**

Run `php artisan test --compact tests/Feature/UserIngredientAuthoringTest.php --filter="shows composition only|quick create"`.

Expected: all selected tests PASS.

### Task 6: Required Verification

**Files:**
- Verify all files listed in the File Map.

- [ ] **Step 1: Run Filament compatibility fixes**

Run `vendor/bin/filacheck --fix`.

Expected: exit code 0 and no unresolved deprecated Filament usage.

- [ ] **Step 2: Format modified PHP files**

Run `vendor/bin/pint --dirty --format agent`.

Expected: exit code 0.

- [ ] **Step 3: Run focused ingredient tests**

Run `php artisan test --compact --filter=Ingredient`.

Expected: exit code 0 with zero failing tests.

- [ ] **Step 4: Build frontend assets**

Run `npm run build`.

Expected: exit code 0 with no Tailwind or Blade compilation error.

- [ ] **Step 5: Refresh the knowledge graph**

Run `graphify update .`.

Expected: exit code 0 and refreshed `graphify-out` artifacts.

- [ ] **Step 6: Review the final diff**

Run:

```bash
git diff --check
git status --short
git diff --stat
```

Expected: no whitespace errors and only intended ingredient changes, pre-existing user changes, generated graph updates, and approved documentation. Do not stage or commit implementation files without explicit user authorization.
