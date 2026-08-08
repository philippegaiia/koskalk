---
paths:
  - 'app/Livewire/**'
---

# Livewire

## Enforce via authorize() calls, soft checks via can()
Enforce with the throwing authorize() call — $this->authorize('update', $recipe) in controllers and Livewire, Gate::forUser($user)->authorize('update', $recipe) in services (services receive the User as a parameter). Reserve $user->can(...) for non-throwing checks and @can for Blade buttons.

## Livewire class components, multi-file, nested in Blade pages
Write Livewire components as class-based, multi-file components: class in app/Livewire/ (namespaced by area) returning view('livewire.<kebab-name>') from render(), with the view in resources/views/livewire/, embedded via <livewire:...> tags in controller-rendered Blade pages. This is the app's established format; Volt/SFC/Route::livewire are valid Livewire 4 options not currently used.

## Mark route-bound IDs #[Locked]; bind query state with #[Url]
Mark immutable route-bound IDs with #[Locked]. Use #[Url(...)] for query-string-bound state (search, filters, order/recipe params), as in RecipesIndex/ReceiptCreate/ProductionIndex. Expose auth and tenant context via private user(): User and workspace(): Workspace helpers built on auth()->user() and $this->user()->company().

## Livewire table lists paginate with length-aware ->paginate()
Paginate Livewire table lists with length-aware ->paginate() so <x-table-pagination> can render page numbers and totals; reserve simplePaginate() for cursor-style JSON endpoints.

## Filament form components are the public form substrate
Build public form UI with Filament's form/schema components: public Livewire editors implement HasForms/HasActions and build Filament\Schemas\Schema from Filament\Forms\Components. Admin-only applies to the Filament panel/resources, not the form framework.

## Reference URLs via named routes with route()
Generate all URLs and redirects from named routes with route('name', $params); use redirect()->route(...) for redirects and {{ route('name') }} in Blade; do not use url('/path') or action([...]).
