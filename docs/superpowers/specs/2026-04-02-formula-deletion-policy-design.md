# Formula Deletion Policy Design

## Context

This application manages soap and cosmetic formulas through a Recipe → RecipeVersion → RecipePhase → RecipeItem hierarchy. Currently there is no deletion mechanism — only an `archived_at` flag that hides records from lists but doesn't delete them. No `SoftDeletes` trait is used. No delete routes, buttons, or UI exist.

Policies (`RecipePolicy`, `RecipeVersionPolicy`) define `delete()` methods but they are never called. Authorization is done manually via `accessibleRecipe()` and `accessibleSavedVersion()` helper methods in the controller, which resolve the user through `CurrentAppUserResolver`. The tenant scope (`OwnedByCurrentTenantScope`) depends on `Auth::user()`.

## Requirements

- Hard delete with confirmation (no soft delete, no trash)
- Delete individual versions (not just entire recipes)
- Drafts can be deleted freely (simple confirmation)
- Published versions require copy-paste name confirmation — enforced server-side
- Last published version deletion shows extra warning but is allowed
- Block deletion if external data references the version (none exist yet, prepare for future)
- Delete buttons in three locations: recipe list, workbench editor, version view page
- Follow Laravel conventions (DELETE routes, policies)
- Follow Livewire 4 conventions ($this->authorize(), wire:click, redirect + flash)

## Architecture

### Auth Approach

**Do NOT use `#[Authorize]` attribute** — the app uses `CurrentAppUserResolver` for user resolution and the tenant scope reads `Auth::user()` directly. The `#[Authorize]` attribute uses the default guard, which may differ for Filament-authenticated users, causing silent authorization failures.

**Use explicit `$this->authorize()`** in both controller and Livewire actions, matching the existing pattern where authorization is called after user resolution.

### Routes

```php
// web.php — add to existing recipes route group
Route::delete('/{recipe}', 'destroy')->name('destroy');
Route::delete('/{recipe}/versions/{version}', 'destroyVersion')->name('versions.destroy');
```

### Controller

```php
class RecipeController extends Controller
{
    public function destroy(
        Recipe $recipe,
        CurrentAppUserResolver $currentAppUserResolver,
        Request $request,
    ): RedirectResponse {
        $user = $currentAppUserResolver->resolve();
        abort_unless($user !== null, 403);

        $this->authorize('delete', $recipe);

        // Server-side confirmation enforcement
        if ($request->input('confirm_name') !== $recipe->name) {
            abort(403, 'Confirmation name does not match.');
        }

        $recipe->delete();

        return redirect()->route('dashboard')
            ->with('status', 'Recipe deleted.');
    }

    public function destroyVersion(
        Recipe $recipe,
        RecipeVersion $version,
        CurrentAppUserResolver $currentAppUserResolver,
        Request $request,
    ): RedirectResponse {
        $user = $currentAppUserResolver->resolve();
        abort_unless($user !== null, 403);

        abort_unless($version->recipe_id === $recipe->id, 404);

        $this->authorize('delete', $version);

        // Server-side confirmation enforcement for published versions
        if (! $version->is_draft) {
            if ($request->input('confirm_name') !== $recipe->name) {
                abort(403, 'Confirmation name does not match.');
            }
        }

        $isLastPublished = $recipe->publishedVersions()->count() === 1
            && $version->is_draft === false;

        $version->delete();

        return redirect()->route('dashboard')
            ->with('status', $isLastPublished
                ? 'Last published version deleted. Recipe has no published versions.'
                : 'Version deleted.');
    }
}
```

### Livewire Components

**RecipeWorkbench.php** — add delete action:

```php
public function deleteVersion(
    int $versionId,
    string $confirmName = '',
    RecipeWorkbenchService $recipeWorkbenchService,
): void {
    $version = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $this->recipeId)
        ->findOrFail($versionId);

    $this->authorize('delete', $version);

    // Server-side confirmation for published versions
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

    // Post-delete behavior:
    // - If deleted draft: redirect to recipe list (no working draft exists)
    // - If deleted published version: stay on workbench, latest published version shown
    if ($isDraft) {
        session()->flash('status', 'Draft deleted.');
        $this->redirect(route('dashboard'), navigate: true);
    } else {
        session()->flash('status', 'Version deleted.');
        $this->dispatch('version-deleted');
    }
}
```

**RecipesIndex.php** — add delete action:

```php
public function deleteRecipe(int $recipeId, string $confirmName = ''): void
{
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($recipeId);

    $this->authorize('delete', $recipe);

    // Server-side confirmation enforcement
    if ($confirmName !== $recipe->name) {
        throw ValidationException::withMessages([
            'confirmName' => 'Confirmation name does not match.',
        ]);
    }

    $recipe->delete();

    session()->flash('status', 'Recipe deleted.');
}
```

### Policies

Existing policies are already correct and will now be used:

- `RecipePolicy::delete()` — owner or workspace admin can delete
- `RecipeVersionPolicy::delete()` — owner or workspace admin can delete
- `forceDelete()` returns `false` — hard force-delete blocked at policy level

### Database Cascade

DB-level foreign keys handle child cleanup automatically:

- RecipeVersion → RecipePhase (cascadeOnDelete)
- RecipeVersion → RecipeItem (cascadeOnDelete)
- Recipe → RecipeVersion (cascadeOnDelete)

No application-level cleanup needed for owned children.

### Post-Delete Draft Behavior

The `RecipeWorkbenchService` guarantees "one working draft" during save flows. Deleting a draft breaks that invariant. The behavior after deletion:

| Action | Result |
|---|---|
| Delete draft | Redirect to dashboard (recipe list). No working draft exists. |
| Delete published version | Stay on workbench. The draft (if any) remains. If no draft, show latest published version read-only. |
| Delete last published version | Same as above. Flash message warns recipe has no published versions. |

### Future External Reference Blocking

When external data references RecipeVersion (batches, orders), add a check before deletion:

```php
// In RecipeVersion model
public function hasExternalReferences(): bool
{
    // Add checks here as new relationships are created
    return false;
}
```

Block deletion in controller/Livewire if this returns true, showing what's using the version.

## UI Design

### Confirmation Modal Pattern

**Published version / entire recipe (server-enforced):**

```html
<div x-data="{ open: false, confirmText: '' }">
    <button @click="open = true">Delete</button>

    <div x-show="open" x-cloak>
        <h3>Delete "{{ $recipe->name }}"?</h3>
        <p>This action cannot be undone.</p>

        <button @click="navigator.clipboard.writeText('{{ $recipe->name }}')">
            Copy name
        </button>

        <input x-model="confirmText" placeholder="Paste recipe name to confirm">

        <form method="POST" action="{{ route('recipes.destroy', $recipe) }}">
            @method('DELETE')
            @csrf
            <input type="hidden" name="confirm_name" :value="confirmText">
            <button type="submit" :disabled="confirmText !== '{{ $recipe->name }}'">
                Delete permanently
            </button>
        </form>
    </div>
</div>
```

The `confirm_name` hidden input is validated server-side. The disabled button is UX-only; the real gate is the controller check.

**Draft version:**

```html
<form method="POST" action="{{ route('recipes.versions.destroy', [...]) }}"
      onsubmit="return confirm('Delete this draft? This cannot be undone.')">
    @method('DELETE')
    @csrf
    <button type="submit">Delete draft</button>
</form>
```

### Delete Button Locations

| Location | What's Deleted | Confirmation |
|---|---|---|
| RecipesIndex (recipe cards) | Entire recipe + all versions | Copy-paste name (server-enforced) |
| RecipeWorkbench (editor) | Current version | Draft: simple confirm. Published: copy-paste (server-enforced) |
| Version view page | That specific version | Copy-paste name (server-enforced) |

### Last Published Version Warning

When deleting the last published version, show additional warning text:

> "This is the last published version. After deletion, this recipe will have no published versions. The draft (if any) will remain."

User can still proceed after acknowledging.

### Status Banner Rendering

The `session('status')` flash message must render on all affected pages:

- **Workbench** — already renders status banner at `recipe-workbench.blade.php:50`
- **RecipesIndex** — does NOT render status. Add status banner to `recipes-index.blade.php`
- **Version page** — does NOT render status. Add status banner to `version.blade.php`

## Testing

- Feature test: authorized delete recipe via DELETE route, verify cascade to versions
- Feature test: authorized delete published version, verify phases/items cascade
- Feature test: delete draft version, verify redirect to dashboard
- Feature test: unauthorized user cannot delete (policy test)
- Feature test: wrong confirmation name is rejected server-side (403)
- Feature test: last published version deletes with warning flash message
- Feature test: delete recipe with multiple versions, only that recipe is affected
- Feature test: crafted DELETE request without confirmation is rejected

## Migration Needs

No migrations needed. All required columns (`archived_at`, foreign keys with cascade) already exist.

## Files to Create/Modify

### Modify
- `routes/web.php` — add DELETE routes
- `app/Http/Controllers/RecipeController.php` — add destroy(), destroyVersion()
- `app/Livewire/Dashboard/RecipeWorkbench.php` — add deleteVersion() action
- `app/Livewire/Dashboard/RecipesIndex.php` — add deleteRecipe() action
- `resources/views/livewire/dashboard/recipes-index.blade.php` — add delete button + modal + status banner
- `resources/views/livewire/dashboard/recipe-workbench.blade.php` — add delete button + modal
- `resources/views/recipes/version.blade.php` — add delete button + modal + status banner

### No new files needed
Policies, models, and database structure are already in place.
