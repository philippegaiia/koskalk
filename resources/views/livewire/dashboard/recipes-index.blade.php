<div class="mx-auto max-w-[90rem] xl:max-w-[90rem] space-y-8">
 @if (session('status'))
 <div class="rounded-xl bg-[var(--color-success-soft)] px-6 py-4 text-sm text-[var(--color-success-strong)]">
 {{ session('status') }}
 </div>
 @endif

 <section class="grid gap-4 xl:grid-cols-[minmax(0,1.3fr)_22rem]">
 <div class="sk-card p-6">
 <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
 <div class="min-w-0">
 <p class="sk-eyebrow">Formulas</p>
 <h3 class="mt-3 text-3xl font-semibold text-[var(--color-ink-strong)]">One working draft, one current saved formula, and no visible version clutter.</h3>
 <p class="mt-4 max-w-3xl text-sm leading-7 text-[var(--color-ink-soft)]">
 Keep one editable working draft per recipe. Save the formula when you want to replace the current official state, and duplicate when you want a truly separate branch.
 </p>
 </div>

 <div class="flex flex-wrap gap-2 lg:justify-end">
 <a href="{{ route('recipes.create') }}" wire:navigate class="sk-btn sk-btn-primary">
 Create soap formula
 </a>
 <a href="{{ route('recipes.create', ['family' => 'cosmetic']) }}" wire:navigate class="sk-btn sk-btn-outline">
 Create cosmetic formula
 </a>
 </div>
 </div>
 </div>

 <div class="sk-card p-6">
 <p class="sk-eyebrow">Saved work</p>
 <div class="mt-4 space-y-4">
 <div>
 <p class="text-3xl font-semibold text-[var(--color-ink-strong)]">{{ $recipeCount }}</p>
 <p class="mt-1 text-sm text-[var(--color-ink-soft)]">
 {{ $currentUser ? 'Recipes currently visible for your account.' : 'Sign in through the public app or admin panel to see your saved formulas.' }}
 </p>
 </div>
 <div class="grid gap-3 sm:grid-cols-2">
 <div class="rounded-[1.5rem] border border-[var(--color-line)] bg-white p-4">
 <p class="sk-eyebrow">Drafts</p>
 <p class="mt-2 text-2xl font-semibold text-[var(--color-ink-strong)]">{{ $draftCount }}</p>
 </div>
 <div class="rounded-[1.5rem] border border-[var(--color-line)] bg-white p-4">
 <p class="sk-eyebrow">Saved formulas</p>
 <p class="mt-2 text-2xl font-semibold text-[var(--color-ink-strong)]">{{ $savedFormulaCount }}</p>
 </div>
 </div>
 </div>
 </div>
 </section>

 <section class="grid gap-4 lg:grid-cols-3">
 <div class="sk-card p-5">
 <p class="sk-eyebrow">Carrier oils</p>
 <p class="mt-4 text-4xl font-semibold text-[var(--color-ink-strong)]">{{ $catalogStats['carrier_oils'] }}</p>
 <p class="mt-2 text-sm text-[var(--color-ink-soft)]">Only truly saponifiable carrier oils belong in the reaction-core picker.</p>
 </div>
 <div class="sk-card p-5">
 <p class="sk-eyebrow">Aromatic materials</p>
 <p class="mt-4 text-4xl font-semibold text-[var(--color-ink-strong)]">{{ $catalogStats['aromatics'] }}</p>
 <p class="mt-2 text-sm text-[var(--color-ink-soft)]">Essential oils and aromatic extracts sit behind category filters and compliance context.</p>
 </div>
 <div class="sk-card p-5">
 <p class="sk-eyebrow">Additions</p>
 <p class="mt-4 text-4xl font-semibold text-[var(--color-ink-strong)]">{{ $catalogStats['additives'] }}</p>
 <p class="mt-2 text-sm text-[var(--color-ink-soft)]">Colorants, preservatives, and other post-reaction additions stay separate from the soap core.</p>
 </div>
 </section>

 <section class="space-y-4">
 <div class="space-y-4">
 <div>
 <p class="sk-eyebrow">Saved formulas</p>
 <h3 class="mt-1 text-xl font-semibold text-[var(--color-ink-strong)]">Recipes with their draft and saved states</h3>
 </div>
 @if ($currentUser)
 <div class="flex min-w-0 flex-col items-stretch gap-2">
 <div class="flex min-w-0 flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center">
 <label class="sk-field sm:min-w-80 lg:min-w-[24rem]">
 <span class="shrink-0 text-[var(--color-ink-soft)]">Search</span>
 <input
 wire:model.live.debounce.250ms="search"
 type="text"
 placeholder="Recipes, families, and types"
 class="sk-field-control"
 />
 </label>
 <label class="sk-field">
 <span class="shrink-0 text-[var(--color-ink-soft)]">Family</span>
 <select wire:model.live="productFamilyFilter" class="sk-select-control">
 <option value="">All</option>
 @foreach ($productFamilyOptions as $productFamilySlug => $productFamilyName)
 <option value="{{ $productFamilySlug }}">{{ $productFamilyName }}</option>
 @endforeach
 </select>
 </label>
 <label class="sk-field">
 <span class="shrink-0 text-[var(--color-ink-soft)]">Type</span>
 <select wire:model.live="productTypeFilter" class="sk-select-control">
 <option value="">All</option>
 @foreach ($productTypeOptions as $productTypeSlug => $productTypeName)
 <option value="{{ $productTypeSlug }}">{{ $productTypeName }}</option>
 @endforeach
 </select>
 </label>
 @if ($searchTerm !== '' || $selectedProductFamily !== '' || $selectedProductType !== '')
 <button type="button" wire:click="clearFilters" class="sk-btn sk-btn-outline shrink-0">
 Clear
 </button>
 @endif
 </div>
 <p class="px-1 text-xs text-[var(--color-ink-soft)]">
 {{ $recipeCount }} {{ $searchTerm !== '' || $selectedProductFamily !== '' || $selectedProductType !== '' ? 'matching formulas' : 'visible formulas' }}
 </p>
 </div>
 @endif
 </div>

 @if (! $currentUser)
 <div class="sk-card p-8 text-center">
 <h4 class="text-lg font-semibold text-[var(--color-ink-strong)]">No signed-in formula workspace yet</h4>
 <p class="mt-3 text-sm leading-7 text-[var(--color-ink-soft)]">Open the recipes workbench from the same account you use in the app or in the admin panel, then your saved drafts will appear here.</p>
 </div>
 @elseif ($recipes->isEmpty())
 <div class="sk-card p-8 text-center">
 <h4 class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ $searchTerm !== '' || $selectedProductFamily !== '' || $selectedProductType !== '' ? 'No formulas match these filters' : 'No formulas saved yet' }}</h4>
 <p class="mt-3 text-sm leading-7 text-[var(--color-ink-soft)]">
 {{ $searchTerm !== '' || $selectedProductFamily !== '' || $selectedProductType !== '' ? 'Try a recipe name, product family, or product type.' : 'Create the first soap formula, give it a name in the header, and save the draft to make it appear in this list.' }}
 </p>
 @if ($searchTerm === '' && $selectedProductFamily === '' && $selectedProductType === '')
 <div class="mt-5 flex flex-wrap justify-center gap-2">
 <a href="{{ route('recipes.create') }}" wire:navigate class="sk-btn sk-btn-primary">Create soap formula</a>
 <a href="{{ route('recipes.create', ['family' => 'cosmetic']) }}" wire:navigate class="sk-btn sk-btn-outline">Create cosmetic formula</a>
 </div>
 @endif
 </div>
 @else
 <div class="grid gap-4 xl:grid-cols-2">
 @foreach ($recipes as $recipe)
 @php
 $productFamilyName = $recipe->productFamily?->name ?? 'Formula';
 $productFamilySlug = $recipe->productFamily?->slug ?? 'formula';
 $productTypeName = $recipe->productType?->name;
 $thumbnailUrl = $recipe->featuredImageUrl() ?? $recipe->productType?->fallbackImageUrl();
 $fallbackThumbnailClasses = match ($productFamilySlug) {
 'soap' => 'border-[var(--color-accent-soft)] bg-[var(--color-accent-soft)] text-[var(--color-ink-strong)]',
 default => 'border-[var(--color-line)] bg-[var(--color-panel)] text-[var(--color-ink-soft)]',
 };
 $fallbackThumbnailLabel = \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($productTypeName ?? $productFamilyName, 0, 4));
 @endphp
 <article class="sk-card p-5">
 <div class="flex items-start gap-4">
 @if ($thumbnailUrl)
 <div class="h-20 w-20 shrink-0 overflow-hidden rounded-[1.25rem] border border-[var(--color-line)] bg-[var(--color-panel)]">
 <img src="{{ $thumbnailUrl }}" alt="{{ $recipe->name }}" class="h-full w-full object-cover" />
 </div>
 @else
 <div class="grid h-20 w-20 shrink-0 place-items-center rounded-[1.25rem] border text-center {{ $fallbackThumbnailClasses }}">
 <div>
 <p class="text-[10px] font-semibold tracking-[0.18em]">{{ $fallbackThumbnailLabel }}</p>
 <p class="mt-1 text-[11px] font-medium">{{ $productTypeName ?? $productFamilyName }}</p>
 </div>
 </div>
 @endif

 <div class="min-w-0 flex-1">
 <div class="flex flex-wrap items-center gap-2">
 <h4 class="truncate text-xl font-semibold text-[var(--color-ink-strong)]">{{ $recipe->name }}</h4>
 @if ($recipe->currentDraftVersion)
 <span class="sk-badge sk-badge-success">Draft</span>
 @endif
 @if ($recipe->currentSavedVersion)
 <span class="sk-badge sk-badge-neutral">Saved</span>
 @endif
 </div>
 <div class="mt-3 flex flex-wrap gap-2">
 @if ($productTypeName)
 <span class="sk-badge sk-badge-strong">
 {{ $productTypeName }}
 </span>
 @endif
 <span class="sk-badge sk-badge-neutral">
 {{ $productFamilyName }}
 </span>
 </div>
 <p class="mt-3 text-xs text-[var(--color-ink-soft)]">
 Updated {{ $recipe->updated_at?->diffForHumans() ?? 'just now' }} / Created {{ $recipe->created_at?->diffForHumans() ?? 'just now' }}
 </p>
 </div>
 </div>

 <div x-data="{ open: false, confirmText: '', recipeName: @js($recipe->name) }" class="mt-4">
 <div class="flex flex-wrap gap-2">
 <a href="{{ route('recipes.edit', $recipe->id) }}" wire:navigate class="sk-btn sk-btn-primary">
 Open draft
 </a>
 @if ($recipe->currentSavedVersion)
 <a href="{{ route('recipes.saved', $recipe->id) }}" wire:navigate class="sk-action-link">
 Open recipe
 </a>
 @endif
 <form method="POST" action="{{ route('recipes.duplicate', $recipe->id) }}">
 @csrf
 <button type="submit" class="sk-action-link">
 Duplicate
 </button>
 </form>

 <button type="button" @click="open = true" class="sk-btn sk-btn-danger">
 Delete recipe
 </button>
 </div>

 <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="open = false">
 <div class="sk-card w-full max-w-md p-6" @click.stop>
 <h3 class="text-lg font-semibold text-[var(--color-ink-strong)]">Delete &quot;{{ $recipe->name }}&quot;?</h3>
 <p class="mt-2 text-sm text-[var(--color-ink-soft)]">
 This will delete the recipe, its draft, saved formula, and hidden recovery snapshots. This action cannot be undone.
 </p>

 <button type="button" @click="confirmText = recipeName" class="sk-btn sk-btn-outline mt-4">
 Use recipe name
 </button>

 <input x-model="confirmText" type="text" placeholder="Paste recipe name to confirm" class="sk-input mt-4" />

 <form method="POST" action="{{ route('recipes.destroy', $recipe->id) }}" class="mt-4">
 @method('DELETE')
 @csrf
 <input type="hidden" name="confirm_name" :value="confirmText">
 <button type="submit" :disabled="confirmText !== recipeName" :class="confirmText !== recipeName ? 'cursor-not-allowed bg-[var(--color-line)] text-[var(--color-ink-soft)]' : 'bg-[var(--color-danger-strong)] text-white hover:bg-[var(--color-danger)]'" class="sk-btn w-full">
 Delete permanently
 </button>
 </form>

 <button type="button" @click="open = false" class="sk-btn sk-btn-outline mt-3 w-full">
 Cancel
 </button>
 </div>
 </div>
 </div>

 @if ($recipe->currentSavedVersion)
 <div class="mt-4 flex flex-wrap gap-2">
 <a href="{{ route('recipes.print.recipe', $recipe->id) }}" class="sk-action-link">
 Print recipe
 </a>
 </div>
 @endif
 </article>
 @endforeach
 </div>
 @endif
 </section>
</div>
