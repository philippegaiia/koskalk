# Formula Deletion Policy Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add hard-delete functionality for recipes and recipe versions with server-enforced confirmation, following Laravel and Livewire 4 conventions.

**Architecture:** DELETE routes in controller with explicit ID lookup after `CurrentAppUserResolver` runs. Controller-backed form submissions for the recipe list and version page. A Livewire delete action only for the workbench, using the existing `recipeWorkbench(...)` Alpine/JS state. Server-side confirmation validation. DB cascade handles child cleanup.

**Tech Stack:** Laravel 13, Livewire 4, Alpine.js, Pest 4, PHP 8.5

---

### Task 1: Add DELETE Routes

**Files:**
- Modify: `routes/web.php`

- [ ] **Step 1: Add DELETE routes to the recipes route group**

Add two new routes inside the existing `Route::controller(RecipeController::class)->prefix('/dashboard/recipes')` group:

```php
// Add these two lines inside the route group, before the closing });
Route::delete('/{recipe}', 'destroy')->name('destroy');
Route::delete('/{recipe}/versions/{version}', 'destroyVersion')->name('versions.destroy');
```

The full route group should look like:

```php
Route::controller(RecipeController::class)
    ->prefix('/dashboard/recipes')
    ->name('recipes.')
    ->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::get('/new', 'create')->name('create');
        Route::get('/{recipe}/versions/{version}', 'version')->name('version');
        Route::post('/{recipe}/versions/{version}/use-as-draft', 'useVersionAsDraft')->name('use-version-as-draft');
        Route::get('/{recipe}/versions/{version}/print', 'printRecipe')->name('print.recipe');
        Route::get('/{recipe}/versions/{version}/print/details', 'printDetails')->name('print.details');
        Route::get('/{recipe}', 'edit')->name('edit');
        Route::delete('/{recipe}', 'destroy')->name('destroy');
        Route::delete('/{recipe}/versions/{version}', 'destroyVersion')->name('versions.destroy');
    });
```

- [ ] **Step 2: Verify routes register correctly**

Run: `php artisan route:list --path=dashboard/recipes`

Expected output should show both new DELETE routes alongside existing GET/POST routes.

- [ ] **Step 3: Commit**

```bash
git add routes/web.php
git commit -m "feat: add DELETE routes for recipe and version deletion"
```

---

### Task 2: Add Controller Delete Methods

**Files:**
- Modify: `app/Http/Controllers/RecipeController.php`

- [ ] **Step 1: Add imports and destroy() method to RecipeController**

Add these imports at the top of the file (after existing imports):

```php
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use App\Services\CurrentAppUserResolver;
```

Add the `destroy()` method after the existing `useVersionAsDraft()` method:

```php
public function destroy(
    int $recipe,
    CurrentAppUserResolver $currentAppUserResolver,
    Request $request,
): RedirectResponse {
    $user = $currentAppUserResolver->resolve();
    abort_unless($user !== null, 403);

    $recipe = Recipe::withoutGlobalScopes()->findOrFail($recipe);

    $this->authorize('delete', $recipe);

    if ($request->input('confirm_name') !== $recipe->name) {
        abort(403, 'Confirmation name does not match.');
    }

    $recipe->delete();

    return redirect()->route('recipes.index')
        ->with('status', 'Recipe deleted.');
}
```

- [ ] **Step 2: Add destroyVersion() method**

Add after the `destroy()` method:

```php
public function destroyVersion(
    int $recipe,
    int $version,
    CurrentAppUserResolver $currentAppUserResolver,
    Request $request,
): RedirectResponse {
    $user = $currentAppUserResolver->resolve();
    abort_unless($user !== null, 403);

    $recipe = Recipe::withoutGlobalScopes()->findOrFail($recipe);
    $version = RecipeVersion::withoutGlobalScopes()->findOrFail($version);

    abort_unless($version->recipe_id === $recipe->id, 404);

    $this->authorize('delete', $version);

    if (! $version->is_draft) {
        if ($request->input('confirm_name') !== $recipe->name) {
            abort(403, 'Confirmation name does not match.');
        }
    }

    $version->delete();

    $hasPublishedVersions = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_draft', false)
        ->exists();

    return redirect()->route('recipes.index')
        ->with('status', ! $hasPublishedVersions && ! $version->is_draft
            ? 'Last published version deleted. Recipe has no published versions.'
            : 'Version deleted.');
}
```

- [ ] **Step 3: Verify PHP syntax**

Run: `php -l app/Http/Controllers/RecipeController.php`

Expected: "No syntax errors detected"

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/RecipeController.php
git commit -m "feat: add destroy and destroyVersion controller methods with server-side confirmation"
```

---

### Task 3: Add Livewire Delete Actions

**Files:**
- Modify: `app/Livewire/Dashboard/RecipeWorkbench.php`

- [ ] **Step 1: Add deleteVersion() to RecipeWorkbench**

Add the `use` statement for `ValidationException` at the top (it already exists in the file, verify it's there). Add the method before the private helper methods (before `private function currentUser()`):

```php
public function deleteVersion(
    int $versionId,
    string $confirmName = '',
): void {
    abort_unless($this->currentUser() instanceof User, 403);

    $version = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $this->recipeId)
        ->findOrFail($versionId);

    $this->authorize('delete', $version);

    if (! $version->is_draft) {
        $recipe = Recipe::withoutGlobalScopes()->find($this->recipeId);
        if ($confirmName !== $recipe?->name) {
            throw ValidationException::withMessages([
                'confirmName' => 'Confirmation name does not match.',
            ]);
        }
    }

    $isDraft = $version->is_draft;

    $version->delete();

    if ($isDraft) {
        session()->flash('status', 'Draft deleted.');
        $this->redirect(route('recipes.index'), navigate: true);
    } else {
        $recipeWorkbenchService = app(RecipeWorkbenchService::class);
        $savedSnapshot = $recipeWorkbenchService->draftSnapshot($this->currentRecipe());
        $versionOptions = $recipeWorkbenchService->versionOptions($this->currentRecipe());
        $status = empty($versionOptions)
            ? 'Last published version deleted. Recipe has no published versions.'
            : 'Version deleted.';

        session()->flash('status', $status);

        $this->dispatch(
            'version-deleted',
            message: $status,
            recipe: $savedSnapshot['draft']['recipe'] ?? null,
            versionOptions: $versionOptions,
        );
    }
}
```

- [ ] **Step 2: Verify PHP syntax**

Run: `php -l app/Livewire/Dashboard/RecipeWorkbench.php`

Expected: "No syntax errors detected"

- [ ] **Step 3: Commit**

```bash
git add app/Livewire/Dashboard/RecipeWorkbench.php
git commit -m "feat: add workbench deleteVersion Livewire action"
```

---

### Task 4: Add Delete UI to Recipe List (RecipesIndex)

**Files:**
- Modify: `resources/views/livewire/dashboard/recipes-index.blade.php`

- [ ] **Step 1: Add status banner at the top of the component**

Add this right after the opening `<div>` tag (line 1), before the first `<section>`:

```blade
@if (session('status'))
    <div class="rounded-[2rem] border border-[var(--color-success-soft)] bg-[var(--color-success-soft)] px-6 py-4 text-sm text-[var(--color-success-strong)]">
        {{ session('status') }}
    </div>
@endif
```

- [ ] **Step 2: Add delete button and modal to each recipe card**

Find the recipe card article (around line 85-170). Inside the card, after the "Open working draft" link and before the stats grid, add a delete button. Locate this section:

```blade
<a href="{{ route('recipes.edit', $recipe->id) }}" wire:navigate class="inline-flex shrink-0 rounded-full border border-[var(--color-line-strong)] px-4 py-2 text-sm font-medium text-[var(--color-ink-strong)] transition hover:bg-[var(--color-panel)]">
    Open working draft
</a>
```

Add the delete button right after it:

```blade
<div x-data="{ open: false, confirmText: '' }" class="mt-2">
    <button type="button" @click="open = true" class="inline-flex shrink-0 rounded-full border border-[var(--color-danger-soft)] px-4 py-2 text-sm font-medium text-[var(--color-danger-strong)] transition hover:bg-[var(--color-danger-soft)]">
        Delete recipe
    </button>

    <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="open = false">
        <div class="w-full max-w-md rounded-[2rem] border border-[var(--color-line)] bg-white p-6" @click.stop>
            <h3 class="text-lg font-semibold text-[var(--color-ink-strong)]">Delete "{{ $recipe->name }}"?</h3>
            <p class="mt-2 text-sm text-[var(--color-ink-soft)]">This will delete all {{ $recipe->published_versions_count }} version(s). This action cannot be undone.</p>

            <button type="button" @click="navigator.clipboard.writeText('{{ $recipe->name }}')" class="mt-4 inline-flex items-center gap-2 rounded-full border border-[var(--color-line)] px-4 py-2 text-sm font-medium text-[var(--color-ink-strong)] transition hover:bg-[var(--color-panel)]">
                Copy name
            </button>

            <input x-model="confirmText" type="text" placeholder="Paste recipe name to confirm" class="mt-4 w-full rounded-[1.25rem] border border-[var(--color-line)] bg-[var(--color-panel)] px-4 py-3 text-sm text-[var(--color-ink-strong)] outline-none transition focus:border-[var(--color-line-strong)]" />

            <form method="POST" action="{{ route('recipes.destroy', $recipe->id) }}" class="mt-4">
                @method('DELETE')
                @csrf
                <input type="hidden" name="confirm_name" :value="confirmText">
                <button type="submit" :disabled="confirmText !== '{{ $recipe->name }}'" :class="confirmText !== '{{ $recipe->name }}' ? 'cursor-not-allowed bg-[var(--color-line)] text-[var(--color-ink-soft)]' : 'bg-[var(--color-danger-strong)] text-white hover:bg-[var(--color-danger)]'" class="w-full rounded-full px-4 py-2.5 text-sm font-medium transition">
                    Delete permanently
                </button>
            </form>

            <button type="button" @click="open = false" class="mt-3 w-full rounded-full border border-[var(--color-line)] px-4 py-2 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel)]">
                Cancel
            </button>
        </div>
    </div>
</div>
```

Note: The `{{ $recipe->name }}` inside Alpine's `x-data` scope needs to use `@js()` for proper escaping. Update the x-data line to:

```blade
<div x-data="{ open: false, confirmText: '' }" class="mt-2">
```

And use `@js($recipe->name)` in the Alpine expressions:

```blade
<button type="button" @click="navigator.clipboard.writeText(@js($recipe->name))" ...>
```

```blade
<input x-model="confirmText" ... :disabled="confirmText !== @js($recipe->name)" ...>
<button type="submit" :disabled="confirmText !== @js($recipe->name)" ...>
```

- [ ] **Step 3: Commit**

```bash
git add resources/views/livewire/dashboard/recipes-index.blade.php
git commit -m "feat: add delete button with copy-paste confirmation modal to recipe list"
```

---

### Task 5: Add Delete UI to Workbench Editor

**Files:**
- Modify: `resources/views/livewire/dashboard/recipe-workbench.blade.php`
- Modify: `resources/js/app.js`

- [ ] **Step 1: Add delete button in the header action bar**

Find the header section with the Save draft / Save as new version / Duplicate buttons (around lines 9-19). Add a delete button after the "Duplicate" button:

```blade
<button type="button" x-show="hasSavedRecipe" x-cloak @click="openDeleteModal()" :disabled="isSaving" class="rounded-full border border-[var(--color-danger-soft)] px-4 py-2.5 text-sm font-medium text-[var(--color-danger-strong)] transition hover:bg-[var(--color-danger-soft)]">
    Delete
</button>
```

- [ ] **Step 2: Extend the existing `recipeWorkbench(...)` JS state**

Do **not** introduce a new `$currentDraftVersion` view variable or replace the root `x-data`. The workbench already tracks the current loaded version through its existing JS payload in `resources/js/app.js`.

Add delete-modal state directly inside `window.recipeWorkbench = (payload) => ({ ... })`:

```js
recipeName: payload.recipe?.name ?? '',
showDeleteModal: false,
deleteConfirmText: '',
```

Add helpers such as:

```js
openDeleteModal() {
    this.deleteConfirmText = '';
    this.showDeleteModal = true;
},

closeDeleteModal() {
    this.showDeleteModal = false;
    this.deleteConfirmText = '';
},

async deleteCurrentVersion() {
    if (!this.currentVersionId) {
        return;
    }

    await this.$wire.deleteVersion(
        this.currentVersionId,
        this.currentVersionIsDraft ? '' : this.deleteConfirmText,
    );
}
```

Also register a Livewire event listener in `init()` so published-version deletion refreshes local version metadata without losing unsaved workbench state:

```js
this.$wire.$on('version-deleted', (event) => {
    this.handleVersionDeleted(event);
});
```

When snapshots are applied after save/load, keep `recipeName` aligned if needed in `applyDraft()`.

- [ ] **Step 3: Add the modal markup inside the existing workbench component**

Keep the existing root `<div x-data="recipeWorkbench(@js($workbench))" ...>` and add the modal near the end of the component template:

```blade
<div x-show="showDeleteModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="closeDeleteModal()">
    <div class="w-full max-w-md rounded-[2rem] border border-[var(--color-line)] bg-white p-6" @click.stop>
        <template x-if="currentVersionIsDraft">
            <div>
                <h3 class="text-lg font-semibold text-[var(--color-ink-strong)]">Delete draft?</h3>
                <p class="mt-2 text-sm text-[var(--color-ink-soft)]">This draft will be permanently deleted. This action cannot be undone.</p>
                <button type="button" @click="deleteCurrentVersion()" class="mt-4 w-full rounded-full bg-[var(--color-danger-strong)] px-4 py-2.5 text-sm font-medium text-white transition hover:bg-[var(--color-danger)]">
                    Delete draft
                </button>
            </div>
        </template>
        <template x-if="!currentVersionIsDraft">
            <div>
                <h3 class="text-lg font-semibold text-[var(--color-ink-strong)]">Delete version?</h3>
                <p class="mt-2 text-sm text-[var(--color-ink-soft)]">This version will be permanently deleted. This action cannot be undone.</p>
                <button type="button" @click="navigator.clipboard.writeText(recipeName)" class="mt-4 inline-flex items-center gap-2 rounded-full border border-[var(--color-line)] px-4 py-2 text-sm font-medium text-[var(--color-ink-strong)] transition hover:bg-[var(--color-panel)]">
                    Copy name
                </button>
                <input x-model="deleteConfirmText" type="text" placeholder="Paste recipe name to confirm" class="mt-4 w-full rounded-[1.25rem] border border-[var(--color-line)] bg-[var(--color-panel)] px-4 py-3 text-sm text-[var(--color-ink-strong)] outline-none transition focus:border-[var(--color-line-strong)]" />
                <button type="button" @click="deleteCurrentVersion()" :disabled="deleteConfirmText !== recipeName" :class="deleteConfirmText !== recipeName ? 'cursor-not-allowed bg-[var(--color-line)] text-[var(--color-ink-soft)]' : 'bg-[var(--color-danger-strong)] text-white hover:bg-[var(--color-danger)]'" class="mt-4 w-full rounded-full px-4 py-2.5 text-sm font-medium transition">
                    Delete permanently
                </button>
            </div>
        </template>
        <button type="button" @click="closeDeleteModal()" class="mt-3 w-full rounded-full border border-[var(--color-line)] px-4 py-2 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel)]">
            Cancel
        </button>
    </div>
</div>
```

- [ ] **Step 4: Commit**

```bash
git add resources/views/livewire/dashboard/recipe-workbench.blade.php resources/js/app.js
git commit -m "feat: add workbench deletion modal using existing recipeWorkbench state"
```

---

### Task 6: Add Delete UI to Version View Page

**Files:**
- Modify: `resources/views/recipes/version.blade.php`

- [ ] **Step 1: Check the version.blade.php structure**

First, read the file to understand its current structure. Look for the header section with action buttons.

- [ ] **Step 2: Add status banner**

Add the status banner inside the existing page container so it stays aligned with the rest of the page width and spacing.

```blade
@if (session('status'))
    <div class="rounded-[2rem] border border-[var(--color-success-soft)] bg-[var(--color-success-soft)] px-6 py-4 text-sm text-[var(--color-success-strong)]">
        {{ session('status') }}
    </div>
@endif
```

- [ ] **Step 3: Add delete button and modal**

Add a delete button near the existing action buttons (Back to draft / Use this version as draft / Print recipe / Print full details):

```blade
<div x-data="{ open: false, confirmText: '' }" class="inline-block">
    <button type="button" @click="open = true" class="inline-flex rounded-full border border-[var(--color-danger-soft)] px-4 py-2 text-sm font-medium text-[var(--color-danger-strong)] transition hover:bg-[var(--color-danger-soft)]">
        Delete version
    </button>

    <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="open = false">
        <div class="w-full max-w-md rounded-[2rem] border border-[var(--color-line)] bg-white p-6" @click.stop>
            <h3 class="text-lg font-semibold text-[var(--color-ink-strong)]">Delete version v{{ $version->version_number }}?</h3>
            <p class="mt-2 text-sm text-[var(--color-ink-soft)]">This version will be permanently deleted. This action cannot be undone.</p>

            <button type="button" @click="navigator.clipboard.writeText(@js($recipe->name))" class="mt-4 inline-flex items-center gap-2 rounded-full border border-[var(--color-line)] px-4 py-2 text-sm font-medium text-[var(--color-ink-strong)] transition hover:bg-[var(--color-panel)]">
                Copy name
            </button>

            <input x-model="confirmText" type="text" placeholder="Paste recipe name to confirm" class="mt-4 w-full rounded-[1.25rem] border border-[var(--color-line)] bg-[var(--color-panel)] px-4 py-3 text-sm text-[var(--color-ink-strong)] outline-none transition focus:border-[var(--color-line-strong)]" />

            <form method="POST" action="{{ route('recipes.versions.destroy', ['recipe' => $recipe->id, 'version' => $version->id]) }}" class="mt-4">
                @method('DELETE')
                @csrf
                <input type="hidden" name="confirm_name" :value="confirmText">
                <button type="submit" :disabled="confirmText !== @js($recipe->name)" :class="confirmText !== @js($recipe->name) ? 'cursor-not-allowed bg-[var(--color-line)] text-[var(--color-ink-soft)]' : 'bg-[var(--color-danger-strong)] text-white hover:bg-[var(--color-danger)]'" class="w-full rounded-full px-4 py-2.5 text-sm font-medium transition">
                    Delete permanently
                </button>
            </form>

            <button type="button" @click="open = false" class="mt-3 w-full rounded-full border border-[var(--color-line)] px-4 py-2 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel)]">
                Cancel
            </button>
        </div>
    </div>
</div>
```

- [ ] **Step 4: Commit**

```bash
git add resources/views/recipes/version.blade.php
git commit -m "feat: add delete button with confirmation modal to version view page"
```

---

### Task 7: Write Feature Tests

**Files:**
- Create: `tests/Feature/RecipeDeletionTest.php`

- [ ] **Step 1: Write the test file**

Create `tests/Feature/RecipeDeletionTest.php`.

Use the same data-building approach already used in `tests/Feature/RecipeVersionPagesTest.php` and `tests/Feature/RecipeWorkbenchPersistenceTest.php`:

- create a real soap `ProductFamily`
- create a real saponifiable ingredient plus SAP profile
- create recipe versions through `RecipeWorkbenchService` when you need realistic draft/published behavior
- avoid `Recipe::factory()->for($user, 'owner')`, because `Recipe` does not expose an `owner` relation in the current model/factory setup

Cover both controller routes and the Livewire workbench action:

- controller: delete recipe, delete published version, wrong confirmation rejected, cross-recipe version rejected, last-published flash message
- Livewire: deleting a draft redirects to dashboard
- Livewire: deleting a published version with wrong confirmation raises validation errors
- Livewire: deleting a published version dispatches `version-deleted`

Suggested skeleton:

```php
<?php

use App\IngredientCategory;
use App\Livewire\Dashboard\RecipeWorkbench;
use App\Models\Ingredient;
use App\Models\IngredientSapProfile;
use App\Models\ProductFamily;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Services\RecipeWorkbenchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use function Pest\Laravel\actingAs;
 
uses(RefreshDatabase::class);

// helper functions...

it('allows owner to delete a recipe via DELETE route', function (): void {
    // ...
});
```

Do not blindly copy the old factory-based example below. Replace it with realistic helpers tied to the current recipe/version persistence flow.

Example coverage details:

```php
it('deletes a workbench draft and redirects to dashboard', function (): void {
    // Livewire::test(RecipeWorkbench::class, ['recipe' => $recipe])
    //     ->call('deleteVersion', $draft->id)
    //     ->assertRedirect(route('recipes.index'));
});

it('rejects deleting a published workbench version when confirmation does not match', function (): void {
    // ->call('deleteVersion', $publishedVersion->id, 'Wrong Name')
    // ->assertHasErrors(['confirmName']);
});

it('dispatches version-deleted after deleting a published workbench version', function (): void {
    // ->call('deleteVersion', $publishedVersion->id, $recipe->name)
    // ->assertDispatched('version-deleted');
});
```

Legacy draft to replace:

```php
<?php

use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Models\Workspace;
use function Pest\Laravel\actingAs;
use function Pest\Laravel\delete;
use function Pest\Laravel\get;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create();
});

// -- Controller route tests --

it('allows owner to delete a recipe via DELETE route', function (): void {
    $recipe = Recipe::factory()->for($this->user, 'owner')->create();

    actingAs($this->user)
        ->delete(route('recipes.destroy', $recipe), [
            'confirm_name' => $recipe->name,
        ])
        ->assertRedirect(route('recipes.index'));

    expect(Recipe::withoutGlobalScopes()->find($recipe->id))->toBeNull();
});

it('rejects delete with wrong confirmation name', function (): void {
    $recipe = Recipe::factory()->for($this->user, 'owner')->create();

    actingAs($this->user)
        ->delete(route('recipes.destroy', $recipe), [
            'confirm_name' => 'Wrong Name',
        ])
        ->assertForbidden();

    expect(Recipe::withoutGlobalScopes()->find($recipe->id))->not->toBeNull();
});

it('rejects delete without confirmation', function (): void {
    $recipe = Recipe::factory()->for($this->user, 'owner')->create();

    actingAs($this->user)
        ->delete(route('recipes.destroy', $recipe), [
            'confirm_name' => '',
        ])
        ->assertForbidden();
});

it('rejects delete by unauthorized user', function (): void {
    $recipe = Recipe::factory()->create();
    $otherUser = User::factory()->create();

    actingAs($otherUser)
        ->delete(route('recipes.destroy', $recipe), [
            'confirm_name' => $recipe->name,
        ])
        ->assertForbidden();
});

it('cascades delete to all versions, phases, and items', function (): void {
    $recipe = Recipe::factory()->for($this->user, 'owner')->create();
    $version = RecipeVersion::factory()->for($recipe)->create(['is_draft' => false]);

    actingAs($this->user)
        ->delete(route('recipes.destroy', $recipe), [
            'confirm_name' => $recipe->name,
        ])
        ->assertRedirect();

    expect(\App\Models\RecipeVersion::withoutGlobalScopes()->find($version->id))->toBeNull();
});

it('allows owner to delete a published version', function (): void {
    $recipe = Recipe::factory()->for($this->user, 'owner')->create();
    $version = RecipeVersion::factory()->for($recipe)->create(['is_draft' => false]);

    actingAs($this->user)
        ->delete(route('recipes.versions.destroy', ['recipe' => $recipe->id, 'version' => $version->id]), [
            'confirm_name' => $recipe->name,
        ])
        ->assertRedirect(route('recipes.index'));

    expect(RecipeVersion::withoutGlobalScopes()->find($version->id))->toBeNull();
});

it('allows deleting a draft version without confirmation name', function (): void {
    $recipe = Recipe::factory()->for($this->user, 'owner')->create();
    $draft = RecipeVersion::factory()->for($recipe)->create(['is_draft' => true]);

    actingAs($this->user)
        ->delete(route('recipes.versions.destroy', ['recipe' => $recipe->id, 'version' => $draft->id]), [
            'confirm_name' => '',
        ])
        ->assertRedirect(route('recipes.index'));

    expect(RecipeVersion::withoutGlobalScopes()->find($draft->id))->toBeNull();
});

it('shows last published version warning in flash message', function (): void {
    $recipe = Recipe::factory()->for($this->user, 'owner')->create();
    $version = RecipeVersion::factory()->for($recipe)->create(['is_draft' => false]);

    actingAs($this->user)
        ->delete(route('recipes.versions.destroy', ['recipe' => $recipe->id, 'version' => $version->id]), [
            'confirm_name' => $recipe->name,
        ])
        ->assertRedirect(route('recipes.index'))
        ->assertSessionHas('status', 'Last published version deleted. Recipe has no published versions.');
});

it('rejects version delete when version belongs to different recipe', function (): void {
    $recipe1 = Recipe::factory()->for($this->user, 'owner')->create();
    $recipe2 = Recipe::factory()->for($this->user, 'owner')->create();
    $version = RecipeVersion::factory()->for($recipe2)->create(['is_draft' => false]);

    actingAs($this->user)
        ->delete(route('recipes.versions.destroy', ['recipe' => $recipe1->id, 'version' => $version->id]), [
            'confirm_name' => $recipe1->name,
        ])
        ->assertNotFound();
});
```

- [ ] **Step 2: Run the tests**

Run: `php artisan test --compact tests/Feature/RecipeDeletionTest.php`

Expected: All tests pass. If any fail, fix the controller or Livewire implementation.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/RecipeDeletionTest.php
git commit -m "test: add feature tests for recipe and version deletion"
```

---

### Task 8: Run Full Test Suite and Verify

- [ ] **Step 1: Run full test suite**

Run: `php artisan test --compact`

Expected: All tests pass, including existing recipe tests.

- [ ] **Step 2: Run Pint formatter**

Run: `vendor/bin/pint --dirty --format agent`

This ensures all modified PHP files match the project's code style.

- [ ] **Step 3: Final commit**

```bash
git add -A
git commit -m "chore: run pint formatter on deletion changes"
```
