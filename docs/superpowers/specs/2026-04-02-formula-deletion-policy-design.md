# Formula Deletion Policy Design

## Context

This application manages soap and cosmetic formulas through a Recipe → RecipeVersion → RecipePhase → RecipeItem hierarchy. Currently there is no deletion mechanism — only an `archived_at` flag that hides records from lists but doesn't delete them. No `SoftDeletes` trait is used. No delete routes, buttons, or UI exist.

Policies (`RecipePolicy`, `RecipeVersionPolicy`) define `delete()` methods but they are never called. Authorization is done manually via `accessibleRecipe()` and `accessibleSavedVersion()` helper methods in the controller.

## Requirements

- Hard delete with confirmation (no soft delete, no trash)
- Delete individual versions (not just entire recipes)
- Drafts can be deleted freely (simple confirmation)
- Published versions require copy-paste name confirmation
- Last published version deletion shows extra warning but is allowed
- Block deletion if external data references the version (none exist yet, prepare for future)
- Delete buttons in three locations: recipe list, workbench editor, version view page
- Follow Laravel conventions (DELETE routes, policies, #[Authorize] attribute)
- Follow Livewire 4 conventions ($this->authorize(), wire:click, redirect + flash)

## Architecture

### Routes

```php
// web.php — add to existing recipes route group
Route::delete('/{recipe}', 'destroy')->name('destroy');
Route::delete('/{recipe}/versions/{version}', 'destroyVersion')->name('versions.destroy');
```

### Controller

```php
use Illuminate\Routing\Attributes\Controllers\Authorize;

class RecipeController extends Controller
{
    #[Authorize('delete', 'recipe')]
    public function destroy(Recipe $recipe): RedirectResponse
    {
        $recipe->delete();

        return redirect()->route('dashboard')
            ->with('status', 'Recipe deleted.');
    }

    #[Authorize('delete', 'version')]
    public function destroyVersion(Recipe $recipe, RecipeVersion $version): RedirectResponse
    {
        abort_unless($version->recipe_id === $recipe->id, 404);

        // Extra warning check for last published version
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
public function deleteVersion(int $versionId): void
{
    $version = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $this->recipeId)
        ->findOrFail($versionId);

    $this->authorize('delete', $version);

    $version->delete();

    session()->flash('status', 'Version deleted.');

    $this->redirect(route('dashboard'), navigate: true);
}
```

**RecipesIndex.php** — add delete action:

```php
public function deleteRecipe(int $recipeId): void
{
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($recipeId);

    $this->authorize('delete', $recipe);

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

### Future External Reference Blocking

When external data references RecipeVersion (batches, orders), add a check before deletion:

```php
// In RecipeVersion model or a service
public function hasExternalReferences(): bool
{
    // Add checks here as new relationships are created
    return false;
}
```

Block deletion in controller if this returns true, showing what's using the version.

## UI Design

### Confirmation Modal Pattern

**Published version / entire recipe:**

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
            <button type="submit" :disabled="confirmText !== '{{ $recipe->name }}'">
                Delete permanently
            </button>
        </form>
    </div>
</div>
```

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
| RecipesIndex (recipe cards) | Entire recipe + all versions | Copy-paste name |
| RecipeWorkbench (editor) | Current version | Draft: simple confirm. Published: copy-paste |
| Version view page | That specific version | Copy-paste name |

### Last Published Version Warning

When deleting the last published version, show additional warning text:

> "This is the last published version. After deletion, this recipe will have no published versions. The draft (if any) will remain."

User can still proceed after acknowledging.

## Testing

- Feature test: delete recipe via DELETE route, verify cascade to versions
- Feature test: delete published version, verify phases/items cascade
- Feature test: delete draft version, verify no cascade needed
- Feature test: unauthorized user cannot delete (policy test)
- Feature test: copy-paste confirmation required for published versions
- Feature test: last published version shows warning but allows deletion

## Migration Needs

No migrations needed. All required columns (`archived_at`, foreign keys with cascade) already exist.

## Files to Create/Modify

### Modify
- `routes/web.php` — add DELETE routes
- `app/Http/Controllers/RecipeController.php` — add destroy(), destroyVersion()
- `app/Livewire/Dashboard/RecipeWorkbench.php` — add deleteVersion() action
- `app/Livewire/Dashboard/RecipesIndex.php` — add deleteRecipe() action
- `resources/views/livewire/dashboard/recipes-index.blade.php` — add delete button + modal
- `resources/views/recipes/workbench.blade.php` — add delete button + modal
- `resources/views/recipes/version.blade.php` — add delete button + modal

### No new files needed
Policies, models, and database structure are already in place.
