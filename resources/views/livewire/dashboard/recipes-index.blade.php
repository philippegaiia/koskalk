<div class="mx-auto max-w-[90rem] xl:max-w-[90rem] space-y-8">
 @if (session('status'))
 <div class="rounded-xl bg-[var(--color-success-soft)] px-6 py-4 text-sm text-[var(--color-success-strong)]">
 {{ session('status') }}
 </div>
 @endif

 <section class="grid gap-4 xl:grid-cols-[minmax(0,1.3fr)_22rem]">
 <div class="rounded-xl bg-[var(--color-panel)] shadow-[0_2px_4px_rgba(60,50,30,0.04),0_12px_24px_rgba(60,50,30,0.08)] p-6">
 <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
 <div class="min-w-0">
 <p class="text-[0.6875rem] font-medium tracking-[0.05em] text-[var(--color-ink-soft)] uppercase">Formulas</p>
 <h3 class="mt-3 text-3xl font-semibold text-[var(--color-ink-strong)]">One working draft, one current saved formula, and no visible version clutter.</h3>
 <p class="mt-4 max-w-3xl text-sm leading-7 text-[var(--color-ink-soft)]">
 Keep one editable working draft per recipe. Save the formula when you want to replace the current official state, and duplicate when you want a truly separate branch.
 </p>
 </div>

 <a href="{{ route('recipes.create') }}" wire:navigate class="inline-flex shrink-0 rounded-full bg-[var(--color-accent)] px-5 py-2.5 text-sm font-medium text-white transition hover:bg-[var(--color-accent-hover)]">
 Create soap formula
 </a>
 </div>
 </div>

 <div class="rounded-xl bg-[var(--color-panel)] shadow-[0_2px_4px_rgba(60,50,30,0.04),0_12px_24px_rgba(60,50,30,0.08)] p-6">
 <p class="text-[0.6875rem] font-medium tracking-[0.05em] text-[var(--color-ink-soft)] uppercase">Saved work</p>
 <div class="mt-4 space-y-4">
 <div>
 <p class="text-3xl font-semibold text-[var(--color-ink-strong)]">{{ $recipeCount }}</p>
 <p class="mt-1 text-sm text-[var(--color-ink-soft)]">
 {{ $currentUser ? 'Recipes currently visible for your account.' : 'Sign in through the public app or admin panel to see your saved formulas.' }}
 </p>
 </div>
 <div class="grid gap-3 sm:grid-cols-2">
 <div class="rounded-[1.5rem] border border-[var(--color-line)] bg-white p-4">
 <p class="text-[0.6875rem] font-medium tracking-[0.05em] text-[var(--color-ink-soft)] uppercase">Drafts</p>
 <p class="mt-2 text-2xl font-semibold text-[var(--color-ink-strong)]">{{ $draftCount }}</p>
 </div>
 <div class="rounded-[1.5rem] border border-[var(--color-line)] bg-white p-4">
 <p class="text-[0.6875rem] font-medium tracking-[0.05em] text-[var(--color-ink-soft)] uppercase">Saved formulas</p>
 <p class="mt-2 text-2xl font-semibold text-[var(--color-ink-strong)]">{{ $savedFormulaCount }}</p>
 </div>
 </div>
 </div>
 </div>
 </section>

 <section class="grid gap-4 lg:grid-cols-3">
 <div class="rounded-xl bg-[var(--color-panel)] shadow-[0_2px_4px_rgba(60,50,30,0.04),0_12px_24px_rgba(60,50,30,0.08)] p-5">
 <p class="text-[0.6875rem] font-medium tracking-[0.05em] text-[var(--color-ink-soft)] uppercase">Carrier oils</p>
 <p class="mt-4 text-4xl font-semibold text-[var(--color-ink-strong)]">{{ $catalogStats['carrier_oils'] }}</p>
 <p class="mt-2 text-sm text-[var(--color-ink-soft)]">Only truly saponifiable carrier oils belong in the reaction-core picker.</p>
 </div>
 <div class="rounded-xl bg-[var(--color-panel)] shadow-[0_2px_4px_rgba(60,50,30,0.04),0_12px_24px_rgba(60,50,30,0.08)] p-5">
 <p class="text-[0.6875rem] font-medium tracking-[0.05em] text-[var(--color-ink-soft)] uppercase">Aromatic materials</p>
 <p class="mt-4 text-4xl font-semibold text-[var(--color-ink-strong)]">{{ $catalogStats['aromatics'] }}</p>
 <p class="mt-2 text-sm text-[var(--color-ink-soft)]">Essential oils and aromatic extracts sit behind category filters and compliance context.</p>
 </div>
 <div class="rounded-xl bg-[var(--color-panel)] shadow-[0_2px_4px_rgba(60,50,30,0.04),0_12px_24px_rgba(60,50,30,0.08)] p-5">
 <p class="text-[0.6875rem] font-medium tracking-[0.05em] text-[var(--color-ink-soft)] uppercase">Additions</p>
 <p class="mt-4 text-4xl font-semibold text-[var(--color-ink-strong)]">{{ $catalogStats['additives'] }}</p>
 <p class="mt-2 text-sm text-[var(--color-ink-soft)]">Colorants, preservatives, and other post-reaction additions stay separate from the soap core.</p>
 </div>
 </section>

 <section class="space-y-4">
 <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
 <div>
 <p class="text-[0.6875rem] font-medium tracking-[0.05em] text-[var(--color-ink-soft)] uppercase">Saved formulas</p>
 <h3 class="mt-1 text-xl font-semibold text-[var(--color-ink-strong)]">Recipes with their draft and saved states</h3>
 </div>
 <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
 @if ($currentUser)
 <label class="flex min-w-0 items-center gap-3 rounded-full border border-[var(--color-line)] bg-white px-4 py-2.5 text-sm text-[var(--color-ink-soft)] sm:min-w-80">
 <span class="shrink-0 text-[var(--color-ink-soft)]">Search</span>
 <input
 wire:model.live.debounce.250ms="search"
 type="text"
 placeholder="Recipes and families"
 class="min-w-0 flex-1 bg-transparent text-sm text-[var(--color-ink-strong)] outline-none placeholder:text-[var(--color-ink-soft)]"
 />
 @if ($searchTerm !== '')
 <button type="button" wire:click="$set('search', '')" class="shrink-0 rounded-full border border-[var(--color-line)] px-2.5 py-1 text-xs font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel)]">
 Clear
 </button>
 @endif
 </label>
 <span class="rounded-full border border-[var(--color-line)] bg-white px-4 py-2 text-sm text-[var(--color-ink-soft)]">
 {{ $recipeCount }} {{ $searchTerm !== '' ? 'matching' : 'visible' }}
 </span>
 @endif
 </div>
 </div>

 @if (! $currentUser)
 <div class="rounded-xl bg-[var(--color-panel)] shadow-[0_2px_4px_rgba(60,50,30,0.04),0_12px_24px_rgba(60,50,30,0.08)] p-8 text-center">
 <h4 class="text-lg font-semibold text-[var(--color-ink-strong)]">No signed-in formula workspace yet</h4>
 <p class="mt-3 text-sm leading-7 text-[var(--color-ink-soft)]">Open the recipes workbench from the same account you use in the app or in the admin panel, then your saved drafts will appear here.</p>
 </div>
 @elseif ($recipes->isEmpty())
 <div class="rounded-xl bg-[var(--color-panel)] shadow-[0_2px_4px_rgba(60,50,30,0.04),0_12px_24px_rgba(60,50,30,0.08)] p-8 text-center">
 <h4 class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ $searchTerm !== '' ? 'No formulas match this search' : 'No formulas saved yet' }}</h4>
 <p class="mt-3 text-sm leading-7 text-[var(--color-ink-soft)]">
 {{ $searchTerm !== '' ? 'Try a recipe name or product family.' : 'Create the first soap formula, give it a name in the header, and save the draft to make it appear in this list.' }}
 </p>
 @if ($searchTerm === '')
 <a href="{{ route('recipes.create') }}" wire:navigate class="mt-5 inline-flex rounded-full bg-[var(--color-ink-strong)] px-5 py-2.5 text-sm font-medium text-white transition hover:bg-[var(--color-accent-hover)]">Create soap formula</a>
 @endif
 </div>
 @else
 <div class="grid gap-4 xl:grid-cols-2">
 @foreach ($recipes as $recipe)
 @php
 $productFamilyName = $recipe->productFamily?->name ?? 'Formula';
 $productFamilySlug = $recipe->productFamily?->slug ?? 'formula';
 $fallbackThumbnailClasses = match ($productFamilySlug) {
 'soap' => 'border-[var(--color-accent-soft)] bg-[var(--color-accent-soft)] text-[var(--color-ink-strong)]',
 default => 'border-[var(--color-line)] bg-[var(--color-panel)] text-[var(--color-ink-soft)]',
 };
 $fallbackThumbnailLabel = \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($productFamilyName, 0, 4));
 @endphp
 <article class="rounded-xl bg-[var(--color-panel)] shadow-[0_2px_4px_rgba(60,50,30,0.04),0_12px_24px_rgba(60,50,30,0.08)] p-5">
 <div class="flex items-start gap-4">
 @if ($recipe->featuredImageUrl())
 <div class="h-20 w-20 shrink-0 overflow-hidden rounded-[1.25rem] border border-[var(--color-line)] bg-[var(--color-panel)]">
 <img src="{{ $recipe->featuredImageUrl() }}" alt="{{ $recipe->name }}" class="h-full w-full object-cover" />
 </div>
 @else
 <div class="grid h-20 w-20 shrink-0 place-items-center rounded-[1.25rem] border text-center {{ $fallbackThumbnailClasses }}">
 <div>
 <p class="text-[10px] font-semibold tracking-[0.18em]">{{ $fallbackThumbnailLabel }}</p>
 <p class="mt-1 text-[11px] font-medium">{{ $productFamilyName }}</p>
 </div>
 </div>
 @endif

 <div class="min-w-0 flex-1">
 <div class="flex flex-wrap items-center gap-2">
 <h4 class="truncate text-xl font-semibold text-[var(--color-ink-strong)]">{{ $recipe->name }}</h4>
 @if ($recipe->currentDraftVersion)
 <span class="rounded-full border border-[var(--color-success-soft)] bg-[var(--color-success-soft)] px-3 py-1 text-xs font-medium text-[var(--color-success-strong)]">Draft</span>
 @endif
 @if ($recipe->currentSavedVersion)
 <span class="rounded-full border border-[var(--color-line)] bg-[var(--color-panel)] px-3 py-1 text-xs font-medium text-[var(--color-ink-soft)]">Saved</span>
 @endif
 </div>
 <div class="mt-3 flex flex-wrap gap-2">
 <span class="rounded-full border border-[var(--color-line)] px-3 py-1 text-xs font-medium text-[var(--color-ink-soft)]">
 {{ $productFamilyName }}
 </span>
 </div>
 <p class="mt-3 text-xs text-[var(--color-ink-soft)]">
 Last updated {{ $recipe->updated_at?->diffForHumans() ?? 'just now' }}
 </p>
 </div>
 </div>

 <div x-data="{ open: false, confirmText: '', recipeName: @js($recipe->name) }" class="mt-4">
 <div class="flex flex-wrap gap-2">
 <a href="{{ route('recipes.edit', $recipe->id) }}" wire:navigate class="inline-flex rounded-full border border-[var(--color-line-strong)] px-4 py-2 text-sm font-medium text-[var(--color-ink-strong)] transition hover:bg-[var(--color-panel)]">
 Open draft
 </a>
 @if ($recipe->currentSavedVersion)
 <a href="{{ route('recipes.saved', $recipe->id) }}" wire:navigate class="inline-flex rounded-full border border-[var(--color-line)] px-4 py-2 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel)]">
 Open recipe
 </a>
 @endif
 <form method="POST" action="{{ route('recipes.duplicate', $recipe->id) }}">
 @csrf
 <button type="submit" class="inline-flex rounded-full border border-[var(--color-line)] px-4 py-2 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel)]">
 Duplicate
 </button>
 </form>

 <button type="button" @click="open = true" class="inline-flex rounded-full border border-[var(--color-danger-soft)] px-4 py-2 text-sm font-medium text-[var(--color-danger-strong)] transition hover:bg-[var(--color-danger-soft)]">
 Delete recipe
 </button>
 </div>

 <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="open = false">
 <div class="w-full max-w-md rounded-xl bg-[var(--color-panel)] shadow-[0_2px_4px_rgba(60,50,30,0.04),0_12px_24px_rgba(60,50,30,0.08)] p-6" @click.stop>
 <h3 class="text-lg font-semibold text-[var(--color-ink-strong)]">Delete &quot;{{ $recipe->name }}&quot;?</h3>
 <p class="mt-2 text-sm text-[var(--color-ink-soft)]">
 This will delete the recipe, its draft, saved formula, and hidden recovery snapshots. This action cannot be undone.
 </p>

 <button type="button" @click="confirmText = recipeName" class="mt-4 inline-flex items-center gap-2 rounded-full border border-[var(--color-line)] px-4 py-2 text-sm font-medium text-[var(--color-ink-strong)] transition hover:bg-[var(--color-panel)]">
 Use recipe name
 </button>

 <input x-model="confirmText" type="text" placeholder="Paste recipe name to confirm" class="mt-4 w-full rounded-[1.25rem] border border-[var(--color-line)] bg-[var(--color-panel)] px-4 py-3 text-sm text-[var(--color-ink-strong)] outline-none transition focus:border-[var(--color-line-strong)]" />

 <form method="POST" action="{{ route('recipes.destroy', $recipe->id) }}" class="mt-4">
 @method('DELETE')
 @csrf
 <input type="hidden" name="confirm_name" :value="confirmText">
 <button type="submit" :disabled="confirmText !== recipeName" :class="confirmText !== recipeName ? 'cursor-not-allowed bg-[var(--color-line)] text-[var(--color-ink-soft)]' : 'bg-[var(--color-danger-strong)] text-white hover:bg-[var(--color-danger)]'" class="w-full rounded-full px-4 py-2.5 text-sm font-medium transition">
 Delete permanently
 </button>
 </form>

 <button type="button" @click="open = false" class="mt-3 w-full rounded-full border border-[var(--color-line)] px-4 py-2 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel)]">
 Cancel
 </button>
 </div>
 </div>
 </div>

 @if ($recipe->currentSavedVersion)
 <div class="mt-4 flex flex-wrap gap-2">
 <a href="{{ route('recipes.print.recipe', $recipe->id) }}" class="inline-flex rounded-full border border-[var(--color-line)] px-4 py-2 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel)]">
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
