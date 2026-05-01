<div class="mx-auto max-w-[90rem] space-y-6">
 @if (session('status'))
 <div class="rounded-xl bg-[var(--color-success-soft)] px-6 py-4 text-sm text-[var(--color-success-strong)]">
 {{ session('status') }}
 </div>
 @endif

 <section class="sk-card p-6">
 <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
 <div class="min-w-0">
 <p class="sk-eyebrow">Formulas</p>
 <h3 class="mt-2 text-2xl font-semibold text-[var(--color-ink-strong)]">Your recipes</h3>
 </div>
 <div class="flex flex-wrap gap-2">
 <a href="{{ route('recipes.create') }}" wire:navigate class="sk-btn sk-btn-primary">
 Create soap formula
 </a>
 <a href="{{ route('recipes.create', ['family' => 'cosmetic']) }}" wire:navigate class="sk-btn sk-btn-outline">
 Create cosmetic formula
 </a>
 </div>
 </div>
 </section>

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
 {{ $recipeCount }} {{ $searchTerm !== '' || $selectedProductFamily !== '' || $selectedProductType !== '' ? 'matching formulas' : 'formulas' }}
 </p>
 </div>
 @endif

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
 <div class="grid gap-6 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-4">
 @foreach ($recipes as $recipe)
 @php
 $productFamilyName = $recipe->productFamily?->name ?? 'Formula';
 $productFamilySlug = $recipe->productFamily?->slug ?? 'formula';
 $productTypeName = $recipe->productType?->name;
 $categoryLabel = $productTypeName ?? $productFamilyName;
 $thumbnailUrl = $recipe->featuredImageUrl() ?? $recipe->productType?->fallbackImageUrl();
 $fallbackThumbnailClasses = match ($productFamilySlug) {
 'soap' => 'bg-[var(--color-accent-soft)] text-[var(--color-accent-strong)]',
 default => 'bg-[var(--color-panel-strong)] text-[var(--color-ink-soft)]',
 };
 $fallbackLabel = \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($categoryLabel, 0, 4));
 @endphp

 <article
 class="sk-card overflow-hidden"
 x-data="{ menuOpen: false, deleteOpen: false, confirmText: '', recipeName: @js($recipe->name) }"
 >
 <div class="relative aspect-[4/3] {{ $thumbnailUrl ? '' : $fallbackThumbnailClasses }}">
 @if ($thumbnailUrl)
 <img src="{{ $thumbnailUrl }}" alt="{{ $recipe->name }}" class="h-full w-full object-cover" />
 @else
 <div class="grid h-full w-full place-items-center">
 <div class="text-center">
 <p class="text-lg font-semibold tracking-[0.18em]">{{ $fallbackLabel }}</p>
 <p class="mt-1 text-sm font-medium">{{ $categoryLabel }}</p>
 </div>
 </div>
 @endif

 <div class="absolute top-3 right-3">
 <button
 type="button"
 @click="menuOpen = !menuOpen"
 class="grid size-10 place-items-center rounded-lg bg-white/80 backdrop-blur transition hover:bg-white sm:size-8"
 >
 <span class="sr-only">Actions</span>
 <svg xmlns="http://www.w3.org/2000/svg" class="size-5 text-[var(--color-ink-soft)]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
 <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 12a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0ZM12.75 12a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0ZM18.75 12a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z" />
 </svg>
 </button>

 <div
 x-show="menuOpen"
 x-cloak
 @click.away="menuOpen = false"
 x-transition:enter="transition ease-out duration-100"
 x-transition:enter-start="opacity-0 scale-95"
 x-transition:enter-end="opacity-100 scale-100"
 x-transition:leave="transition ease-in duration-75"
 x-transition:leave-start="opacity-100 scale-100"
 x-transition:leave-end="opacity-0 scale-95"
 class="absolute right-0 top-full z-10 mt-1 w-48 rounded-xl bg-white shadow-lg ring-1 ring-[var(--color-line)]"
 >
 <div class="p-1.5">
 <a href="{{ route('recipes.edit', $recipe->id) }}" wire:navigate @click="menuOpen = false" class="block rounded-lg px-3 py-3 text-sm text-[var(--color-ink)] hover:bg-[var(--color-panel-strong)]">
 Open draft
 </a>
 <a href="{{ route('recipes.edit', $recipe->id) }}" wire:navigate @click="menuOpen = false" class="block rounded-lg px-3 py-3 text-sm text-[var(--color-ink)] hover:bg-[var(--color-panel-strong)]">
 Edit formula
 </a>
 @if ($recipe->currentSavedVersion)
 <a href="{{ route('recipes.saved', $recipe->id) }}" wire:navigate @click="menuOpen = false" class="block rounded-lg px-3 py-3 text-sm text-[var(--color-ink)] hover:bg-[var(--color-panel-strong)]">
 Use recipe
 </a>
 @endif
 <form method="POST" action="{{ route('recipes.duplicate', $recipe->id) }}">
 @csrf
 <button type="submit" class="w-full rounded-lg px-3 py-3 text-left text-sm text-[var(--color-ink)] hover:bg-[var(--color-panel-strong)]">
 Duplicate
 </button>
 </form>
 <hr class="my-1 border-[var(--color-line)]" />
 <button type="button" @click="deleteOpen = true; menuOpen = false" class="w-full rounded-lg px-3 py-3 text-left text-sm text-[var(--color-danger-strong)] hover:bg-[var(--color-danger-soft)]">
 Delete
 </button>
 </div>
 </div>
 </div>
 </div>

 <div class="p-4">
 <span class="sk-badge sk-badge-neutral">{{ $categoryLabel }}</span>
 <h4 class="mt-2 line-clamp-2 text-lg font-semibold leading-snug text-[var(--color-ink-strong)]">{{ $recipe->name }}</h4>
 <p class="mt-1.5 text-xs text-[var(--color-ink-soft)]">
 Updated {{ $recipe->updated_at?->diffForHumans() ?? 'just now' }}
 </p>
 </div>

 <div x-show="deleteOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="deleteOpen = false">
 <div class="sk-card w-full max-w-md p-6" @click.stop>
 <h3 class="text-lg font-semibold text-[var(--color-ink-strong)]">Delete &quot;{{ $recipe->name }}&quot;?</h3>
 <p class="mt-2 text-sm text-[var(--color-ink-soft)]">
 This will delete the recipe, its draft, official recipe, and hidden recovery snapshots. This action cannot be undone.
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

 <button type="button" @click="deleteOpen = false" class="sk-btn sk-btn-outline mt-3 w-full">
 Cancel
 </button>
 </div>
 </div>
 </article>
 @endforeach
 </div>
 @endif
</div>
