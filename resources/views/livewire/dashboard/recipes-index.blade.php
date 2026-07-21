<div class="mx-auto max-w-[90rem] space-y-6">
    @if (session('status'))
        <div class="rounded-xl bg-[var(--color-success-soft)] px-6 py-4 text-sm text-[var(--color-success-strong)]" role="status">
            {{ session('status') }}
        </div>
    @endif

    <section class="sk-card p-6" aria-label="{{ __('products.page.aria_label') }}">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="min-w-0">
                <h3 class="text-2xl font-semibold text-[var(--color-ink-strong)]">{{ __('products.page.heading') }}</h3>
                <p class="mt-3 max-w-4xl text-sm leading-7 text-[var(--color-ink-soft)]">{{ __('products.page.intro') }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('recipes.create') }}" wire:navigate class="sk-btn sk-btn-primary">
                    {{ __('products.actions.new_soap') }}
                </a>
                <a href="{{ route('recipes.create', ['family' => 'cosmetic']) }}" wire:navigate class="sk-btn sk-btn-outline">
                    {{ __('products.actions.new_cosmetic') }}
                </a>
            </div>
        </div>
    </section>

    @php
        $hasFilters = $searchTerm !== '' || $selectedProductFamily !== '' || $selectedProductType !== '';
    @endphp

    @if ($currentUser)
        <div class="flex min-w-0 flex-col items-stretch gap-2">
            <div class="flex min-w-0 flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center" aria-label="{{ __('products.filters.aria_label') }}">
                <label class="sk-field sm:min-w-80 lg:min-w-[24rem]">
                    <span class="shrink-0 text-[var(--color-ink-soft)]">{{ __('products.filters.search.label') }}</span>
                    <input
                        wire:model.live.debounce.250ms="search"
                        type="text"
                        placeholder="{{ __('products.filters.search.placeholder') }}"
                        class="sk-field-control"
                        aria-label="{{ __('products.filters.search.aria_label') }}"
                    />
                </label>
                <label class="sk-field">
                    <span class="shrink-0 text-[var(--color-ink-soft)]">{{ __('products.filters.category.label') }}</span>
                    <select wire:model.live="productFamilyFilter" class="sk-select-control">
                        <option value="">{{ __('products.filters.category.all') }}</option>
                        @foreach ($productFamilyOptions as $productFamilySlug => $productFamilyName)
                            <option value="{{ $productFamilySlug }}">{{ $productFamilyName }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="sk-field">
                    <span class="shrink-0 text-[var(--color-ink-soft)]">{{ __('products.filters.type.label') }}</span>
                    <select wire:model.live="productTypeFilter" class="sk-select-control">
                        <option value="">{{ __('products.filters.type.all') }}</option>
                        @foreach ($productTypeOptions as $productTypeSlug => $productTypeName)
                            <option value="{{ $productTypeSlug }}">{{ $productTypeName }}</option>
                        @endforeach
                    </select>
                </label>
                @if ($hasFilters)
                    <button type="button" wire:click="clearFilters" class="sk-btn sk-btn-outline shrink-0">
                        {{ __('products.actions.clear_filters') }}
                    </button>
                @endif
            </div>
            <p class="px-1 text-xs text-[var(--color-ink-soft)]">
                {{ trans_choice($hasFilters ? 'products.count.matching' : 'products.count.all', $recipeCount, ['count' => $recipeCount]) }}
            </p>
        </div>
    @endif

    @if (! $currentUser)
        <section class="sk-card p-8 text-center" aria-label="{{ __('products.auth.aria_label') }}">
            <h4 class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('products.auth.heading') }}</h4>
            <p class="mt-3 text-sm leading-7 text-[var(--color-ink-soft)]">{{ __('products.auth.description') }}</p>
        </section>
    @elseif ($recipes->isEmpty())
        <section class="sk-card p-8 text-center">
            <h4 class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ $hasFilters ? __('products.empty.no_matches') : __('products.empty.no_items') }}</h4>
            <p class="mt-3 text-sm leading-7 text-[var(--color-ink-soft)]">
                {{ $hasFilters ? __('products.empty.try_again') : __('products.empty.description') }}
            </p>
            @if (! $hasFilters)
                <div class="mt-5 flex flex-wrap justify-center gap-2">
                    <a href="{{ route('recipes.create') }}" wire:navigate class="sk-btn sk-btn-primary">{{ __('products.actions.new_soap') }}</a>
                    <a href="{{ route('recipes.create', ['family' => 'cosmetic']) }}" wire:navigate class="sk-btn sk-btn-outline">{{ __('products.actions.new_cosmetic') }}</a>
                </div>
            @endif
        </section>
    @else
        <div class="grid gap-6 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-4">
            @foreach ($recipes as $recipe)
                @php
                    $productFamilyName = $recipe->productFamily?->name ?? __('products.card.default_category');
                    $productFamilySlug = $recipe->productFamily?->slug ?? 'product';
                    $productTypeName = $recipe->productType?->name;
                    $categoryLabel = $productTypeName ?? $productFamilyName;
                    $thumbnailUrl = $recipe->featuredImageUrl() ?? $recipe->productType?->fallbackImageUrl();
                    $isLocked = $recipe->isLocked();
                    $fallbackThumbnailClasses = match ($productFamilySlug) {
                        'soap' => 'bg-[var(--color-accent-soft)] text-[var(--color-accent-strong)]',
                        default => 'bg-[var(--color-panel-strong)] text-[var(--color-ink-soft)]',
                    };
                    $fallbackLabel = \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($categoryLabel, 0, 4));
                @endphp

                <article
                    class="sk-card overflow-hidden"
                    x-data="{ menuOpen: false, deleteOpen: false, confirmText: '', productName: @js($recipe->name) }"
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
                                aria-label="{{ __('products.accessibility.actions', ['product' => $recipe->name]) }}"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" class="size-5 text-[var(--color-ink-soft)]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
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
                                    <a href="{{ route('recipes.edit', $recipe) }}" wire:navigate @click="menuOpen = false" class="block rounded-lg px-3 py-3 text-sm text-[var(--color-ink)] hover:bg-[var(--color-panel-strong)]">
                                        {{ __('products.actions.open_workbench') }}
                                    </a>
                                    @if ($recipe->latestPublishedVersion)
                                        <a href="{{ route('recipes.saved', $recipe) }}" wire:navigate @click="menuOpen = false" class="block rounded-lg px-3 py-3 text-sm text-[var(--color-ink)] hover:bg-[var(--color-panel-strong)]">
                                            {{ __('products.actions.view_formula_production') }}
                                        </a>
                                    @endif
                                    <form method="POST" action="{{ route('recipes.duplicate', $recipe) }}">
                                        @csrf
                                        <button type="submit" class="w-full rounded-lg px-3 py-3 text-left text-sm text-[var(--color-ink)] hover:bg-[var(--color-panel-strong)]">
                                            {{ __('products.actions.duplicate') }}
                                        </button>
                                    </form>
                                    @if ($isLocked)
                                        <form method="POST" action="{{ route('recipes.unlock', $recipe) }}">
                                            @csrf
                                            <button type="submit" class="w-full rounded-lg px-3 py-3 text-left text-sm text-[var(--color-ink)] hover:bg-[var(--color-panel-strong)]">
                                                {{ __('products.actions.unlock') }}
                                            </button>
                                        </form>
                                    @else
                                        <form method="POST" action="{{ route('recipes.lock', $recipe) }}">
                                            @csrf
                                            <button type="submit" class="w-full rounded-lg px-3 py-3 text-left text-sm text-[var(--color-ink)] hover:bg-[var(--color-panel-strong)]">
                                                {{ __('products.actions.lock') }}
                                            </button>
                                        </form>
                                    @endif
                                    <hr class="my-1 border-[var(--color-line)]" />
                                    <button type="button" @click="deleteOpen = true; menuOpen = false" class="w-full rounded-lg px-3 py-3 text-left text-sm text-[var(--color-danger-strong)] hover:bg-[var(--color-danger-soft)]">
                                        {{ __('products.actions.delete') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="p-4">
                        <span class="sk-badge sk-badge-neutral">{{ $categoryLabel }}</span>
                        @if ($isLocked)
                            <span class="sk-badge sk-badge-neutral mt-2">{{ __('products.card.locked') }}</span>
                        @endif
                        <h4 class="mt-2 line-clamp-2 text-lg font-semibold leading-snug text-[var(--color-ink-strong)]">{{ $recipe->name }}</h4>
                        <p class="mt-1.5 text-xs text-[var(--color-ink-soft)]">
                            {{ __('products.card.updated', ['time' => $recipe->updated_at?->diffForHumans() ?? __('products.card.just_now')]) }}
                        </p>
                    </div>

                    <div x-show="deleteOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="deleteOpen = false" role="dialog" aria-modal="true" aria-labelledby="product-delete-heading-{{ $recipe->id }}">
                        <div class="sk-card w-full max-w-md p-6" @click.stop>
                            <h3 id="product-delete-heading-{{ $recipe->id }}" class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('products.deletion.heading', ['product' => $recipe->name]) }}</h3>
                            <p class="mt-2 text-sm text-[var(--color-ink-soft)]">{{ __('products.deletion.warning') }}</p>

                            <button type="button" @click="confirmText = productName" class="sk-btn sk-btn-outline mt-4">
                                {{ __('products.actions.use_name') }}
                            </button>

                            <input x-model="confirmText" type="text" placeholder="{{ __('products.deletion.confirmation_placeholder') }}" class="sk-input mt-4" />

                            <form method="POST" action="{{ route('recipes.destroy', $recipe) }}" class="mt-4">
                                @method('DELETE')
                                @csrf
                                <input type="hidden" name="confirm_name" :value="confirmText">
                                <button type="submit" :disabled="confirmText !== productName" :class="confirmText !== productName ? 'cursor-not-allowed bg-[var(--color-line)] text-[var(--color-ink-soft)]' : 'bg-[var(--color-danger-strong)] text-white hover:bg-[var(--color-danger)]'" class="sk-btn w-full">
                                    {{ __('products.actions.delete_permanently') }}
                                </button>
                            </form>

                            <button type="button" @click="deleteOpen = false" class="sk-btn sk-btn-outline mt-3 w-full">
                                {{ __('products.actions.cancel') }}
                            </button>
                        </div>
                    </div>
                </article>
            @endforeach
        </div>
    @endif
</div>
