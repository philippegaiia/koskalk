<div class="mx-auto max-w-app space-y-6">
    <script type="application/json" data-contextual-help-scope>{!! \Illuminate\Support\Js::encode($contextualHelp) !!}</script>
    <section class="sk-card p-6" aria-label="{{ __('products.page.aria_label') }}">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="min-w-0">
                <div data-contextual-help-heading class="flex flex-wrap items-center gap-3">
                    <h3 class="text-2xl font-semibold text-[var(--color-ink-strong)]">{{ __('products.page.heading') }}</h3>
                    <x-contextual-help.index-button :help="$contextualHelp" tab="page" />
                </div>
                <p class="mt-3 max-w-4xl text-sm leading-7 text-[var(--color-ink-soft)]">{{ __('products.page.intro') }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('recipes.start') }}" wire:navigate class="sk-btn sk-btn-primary">
                    {{ __('products.actions.new_product') }}
                </a>
            </div>
        </div>
    </section>

    @php
        $classificationFilters = collect([
            $selectedProductArea !== '' ? $productAreaOptions->get($selectedProductArea, $selectedProductArea) : null,
            $selectedProductCategory !== '' ? $productCategoryOptions->get($selectedProductCategory, $selectedProductCategory) : null,
            $selectedProductType !== '' ? $productTypeOptions->get($selectedProductType, $selectedProductType) : null,
        ])->filter();
        $hasFilters = $searchTerm !== '' || $classificationFilters->isNotEmpty() || $archivedFilter !== 'active';
    @endphp

    @if ($currentUser)
        <section
            data-product-filters
            x-data="{ filtersOpen: false }"
            x-on:keydown.escape.window="if (filtersOpen) { filtersOpen = false; $refs.filtersTrigger.focus() }"
            aria-label="{{ __('products.filters.aria_label') }}"
            class="relative space-y-2"
        >
            <div data-product-filter-toolbar class="grid min-w-0 grid-cols-[minmax(0,1fr)_7.5rem_auto] items-center gap-2 sm:grid-cols-[minmax(0,1fr)_10rem_auto] sm:gap-3">
                <label class="flex h-11 min-w-0 items-center gap-2 rounded-lg border border-[var(--color-field-outline)] bg-[var(--color-field)] px-3 text-sm focus-within:border-[var(--color-accent)] focus-within:ring-1 focus-within:ring-[var(--color-accent)]">
                    <x-heroicon-o-magnifying-glass class="size-4 shrink-0 text-[var(--color-ink-soft)]" aria-hidden="true" />
                    <span class="sr-only">{{ __('products.filters.search.aria_label') }}</span>
                    <input
                        autocomplete="off"
                        wire:model.live.debounce.250ms="search"
                        type="search"
                        placeholder="{{ __('products.filters.search_placeholder') }}"
                        class="sk-field-control w-full"
                    />
                </label>
                <label class="min-w-0">
                    <span class="sr-only">{{ __('products.filters.status.label') }}</span>
                    <select wire:model.live="archivedFilter" class="flex h-11 w-full min-w-0 items-center rounded-lg border border-[var(--color-field-outline)] bg-[var(--color-field)] pl-3 text-sm text-[var(--color-ink-strong)]" title="{{ __('products.filters.status.label') }}">
                        <option value="active">{{ __('products.filters.status.active') }}</option>
                        <option value="archived">{{ __('products.filters.status.archived') }}</option>
                        <option value="">{{ __('products.filters.status.all') }}</option>
                    </select>
                </label>
                <button
                    data-product-filter-toggle
                    x-ref="filtersTrigger"
                    type="button"
                    x-on:click="filtersOpen = ! filtersOpen"
                    x-bind:aria-expanded="filtersOpen.toString()"
                    aria-controls="product-classification-filters"
                    aria-haspopup="dialog"
                    title="{{ __('products.filters.more') }}"
                    class="flex h-11 min-w-11 shrink-0 items-center justify-center gap-2 rounded-lg border border-[var(--color-field-outline)] bg-[var(--color-field)] px-3 text-sm font-medium text-[var(--color-ink-strong)] hover:bg-[var(--color-field-muted)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)]"
                >
                    <x-heroicon-o-adjustments-horizontal class="size-4 shrink-0" aria-hidden="true" />
                    <span class="sr-only sm:not-sr-only">{{ __('products.filters.more') }}</span>
                    @if ($classificationFilters->isNotEmpty())
                        <span data-product-filter-count class="text-xs font-semibold tabular-nums text-[var(--color-accent-strong)]">{{ $classificationFilters->count() }}</span>
                    @endif
                </button>
            </div>

            <div
                x-cloak
                x-show="filtersOpen"
                x-on:click="filtersOpen = false"
                class="fixed inset-0 z-40 bg-[var(--color-ink-strong)]/25 sm:bg-transparent"
                aria-hidden="true"
            ></div>
            <div
                data-product-filter-panel
                id="product-classification-filters"
                x-cloak
                x-show="filtersOpen"
                x-trap.inert.noscroll="filtersOpen"
                role="dialog"
                aria-modal="true"
                aria-labelledby="product-filter-heading"
                class="fixed inset-x-0 bottom-0 z-50 max-h-[85dvh] overflow-y-auto rounded-t-2xl border border-[var(--color-line)] bg-[var(--color-panel)] p-5 shadow-xl sm:absolute sm:inset-x-auto sm:bottom-auto sm:right-0 sm:top-12 sm:w-96 sm:rounded-xl"
            >
                <div class="mb-4 flex items-center justify-between gap-3">
                    <h3 id="product-filter-heading" class="text-sm font-semibold text-[var(--color-ink-strong)]">{{ __('products.filters.more') }}</h3>
                    <button type="button" x-on:click="filtersOpen = false" aria-label="{{ __('products.filters.close') }}" class="-mr-2 grid size-11 place-items-center rounded-lg text-[var(--color-ink-soft)] hover:bg-[var(--color-field-muted)] focus-visible:outline-2 focus-visible:outline-[var(--color-accent)]">
                        <x-heroicon-m-x-mark class="size-4" aria-hidden="true" />
                    </button>
                </div>
                <div class="space-y-4">
                    <label class="block space-y-1.5">
                        <span class="block text-xs font-medium text-[var(--color-ink-soft)]">{{ __('products.filters.area.label') }}</span>
                        <select wire:model.live="productAreaFilter" class="flex h-11 w-full min-w-0 items-center rounded-lg border border-[var(--color-field-outline)] bg-[var(--color-field)] pl-3 text-sm text-[var(--color-ink-strong)]">
                            <option value="">{{ __('products.filters.area.all') }}</option>
                            @foreach ($productAreaOptions as $productAreaSlug => $productAreaName)
                                <option value="{{ $productAreaSlug }}">{{ $productAreaName }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block space-y-1.5">
                        <span class="block text-xs font-medium text-[var(--color-ink-soft)]">{{ __('products.filters.category.label') }}</span>
                        <select wire:model.live="productCategoryFilter" class="flex h-11 w-full min-w-0 items-center rounded-lg border border-[var(--color-field-outline)] bg-[var(--color-field)] pl-3 text-sm text-[var(--color-ink-strong)]">
                            <option value="">{{ __('products.filters.category.all') }}</option>
                            @foreach ($productCategoryOptions as $productCategorySlug => $productCategoryName)
                                <option value="{{ $productCategorySlug }}">{{ $productCategoryName }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block space-y-1.5">
                        <span class="block text-xs font-medium text-[var(--color-ink-soft)]">{{ __('products.filters.type.label') }}</span>
                        <select wire:model.live="productTypeFilter" class="flex h-11 w-full min-w-0 items-center rounded-lg border border-[var(--color-field-outline)] bg-[var(--color-field)] pl-3 text-sm text-[var(--color-ink-strong)]">
                            <option value="">{{ __('products.filters.type.all') }}</option>
                            @foreach ($productTypeOptions as $productTypeSlug => $productTypeName)
                                <option value="{{ $productTypeSlug }}">{{ $productTypeName }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>
                <div class="mt-5 flex items-center justify-end border-t border-[var(--color-line)] pt-4">
                    <button type="button" x-on:click="filtersOpen = false" class="sk-btn sk-btn-primary min-h-11">{{ __('products.filters.done') }}</button>
                </div>
            </div>

            <div data-product-filter-summary class="flex min-h-8 flex-wrap items-center gap-x-3 gap-y-1 text-xs text-[var(--color-ink-soft)]">
                <p role="status" aria-live="polite" aria-atomic="true">
                    {{ trans_choice($hasFilters ? 'products.count.matching' : 'products.count.all', $recipeCount, ['count' => $recipeCount]) }}
                </p>
                @if ($classificationFilters->isNotEmpty() || $archivedFilter !== 'active')
                    <p data-product-active-filters class="min-w-0 break-words">
                        {{ $classificationFilters->concat($archivedFilter !== 'active' ? [__('products.filters.status.'.($archivedFilter === 'archived' ? 'archived' : 'all'))] : [])->implode(' · ') }}
                    </p>
                @endif
                @if ($hasFilters)
                    <button data-product-clear-filters type="button" wire:click="clearFilters" class="min-h-8 font-medium text-[var(--color-accent-strong)] underline underline-offset-2 focus-visible:rounded-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)]">{{ __('products.actions.clear_filters') }}</button>
                @endif
                <span wire:loading.delay wire:target="search,archivedFilter,productAreaFilter,productCategoryFilter,productTypeFilter,clearFilters">{{ __('products.filters.updating') }}</span>
            </div>
        </section>
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
                {{ $hasFilters ? __('products.empty.adjust_filters') : __('products.empty.description') }}
            </p>
            @if (! $hasFilters)
                <div class="mt-5 flex flex-wrap justify-center gap-2">
                    <a href="{{ route('recipes.start') }}" wire:navigate class="sk-btn sk-btn-primary">{{ __('products.actions.new_product') }}</a>
                </div>
            @endif
        </section>
    @else
        <div class="grid gap-6 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-4">
            @foreach ($recipes as $recipe)
                @php
                    $productFamilySlug = $recipe->productFamily?->slug ?? 'product';
                    $categoryLabel = $recipe->productType?->localizedName() ?? __('products.card.unclassified');
                    $thumbnailUrl = $recipe->indexImageUrl() ?? $recipe->productType?->fallbackImageUrl();
                    $isLocked = $recipe->isLocked();
                    $hasProductionHistory = $recipe->production_runs_count > 0;
                    $fallbackThumbnailClasses = match ($productFamilySlug) {
                        'soap' => 'bg-[var(--color-accent-soft)] text-[var(--color-accent-strong)]',
                        default => 'bg-[var(--color-panel-strong)] text-[var(--color-ink-soft)]',
                    };
                    $fallbackLabel = \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($categoryLabel, 0, 4));
                @endphp

                <article
                    class="sk-card relative overflow-hidden transition hover:shadow-lg"
                    x-data="{ menuOpen: false, deleteOpen: false, archiveOpen: false, confirmText: '', productName: @js($recipe->name) }"
                >
                    <a
                        href="{{ route('recipes.edit', $recipe) }}"
                        wire:navigate
                        data-product-card-link
                        class="absolute inset-0 z-10 rounded-[inherit] focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-[var(--color-active)]"
                        aria-label="{{ __('products.actions.open_workbench') }}: {{ $recipe->name }}"
                    ></a>
                    <div class="relative aspect-[4/3] {{ $thumbnailUrl ? '' : $fallbackThumbnailClasses }}">
                        @if ($thumbnailUrl)
                            <img src="{{ $thumbnailUrl }}" alt="{{ $recipe->name }}" class="h-full w-full object-contain" />
                        @else
                            <div class="grid h-full w-full place-items-center">
                                <div class="text-center">
                                    <p class="text-lg font-semibold tracking-[0.18em]">{{ $fallbackLabel }}</p>
                                    <p class="mt-1 text-sm font-medium">{{ $categoryLabel }}</p>
                                </div>
                            </div>
                        @endif

                        <div class="absolute top-3 right-3 z-20" data-product-card-actions>
                            <button
                                type="button"
                                @click="menuOpen = !menuOpen"
                                :aria-expanded="menuOpen.toString()"
                                class="grid size-10 place-items-center rounded-lg border border-[var(--color-line)]/60 bg-[var(--color-panel)] text-[var(--color-ink-strong)] transition-colors duration-150 hover:border-[var(--color-line)] hover:bg-[color-mix(in_oklab,var(--color-panel)_80%,var(--color-panel-strong))] aria-expanded:border-[var(--color-line)] aria-expanded:bg-[color-mix(in_oklab,var(--color-panel)_80%,var(--color-panel-strong))] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)] motion-reduce:transition-none sm:size-8"
                                aria-label="{{ __('products.accessibility.actions', ['product' => $recipe->name]) }}"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
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
                                    @if ($hasProductionHistory && $recipe->archived_at === null)
                                        <button type="button" @click="archiveOpen = true; menuOpen = false" class="w-full rounded-lg px-3 py-3 text-left text-sm text-[var(--color-ink)] hover:bg-[var(--color-panel-strong)]">
                                            {{ __('products.actions.archive') }}
                                        </button>
                                    @elseif ($recipe->archived_at !== null)
                                        <form method="POST" action="{{ route('recipes.restore', $recipe) }}">
                                            @csrf
                                            <button type="submit" class="w-full rounded-lg px-3 py-3 text-left text-sm text-[var(--color-ink)] hover:bg-[var(--color-panel-strong)]">
                                                {{ __('products.actions.restore') }}
                                            </button>
                                        </form>
                                        <button type="button" @click="deleteOpen = true; menuOpen = false" class="w-full rounded-lg px-3 py-3 text-left text-sm text-[var(--color-danger-strong)] hover:bg-[var(--color-danger-soft)]">
                                            {{ __('products.actions.delete') }}
                                        </button>
                                    @else
                                        <button type="button" @click="deleteOpen = true; menuOpen = false" class="w-full rounded-lg px-3 py-3 text-left text-sm text-[var(--color-danger-strong)] hover:bg-[var(--color-danger-soft)]">
                                            {{ __('products.actions.delete') }}
                                        </button>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="p-4">
                        <span class="sk-badge sk-badge-neutral">{{ $categoryLabel }}</span>
                        @if ($isLocked)
                            <span class="sk-badge sk-badge-neutral mt-2">{{ __('products.card.locked') }}</span>
                        @endif
                        @if ($recipe->archived_at !== null)
                            <span class="sk-badge sk-badge-neutral mt-2">{{ __('products.filters.status.archived') }}</span>
                        @endif
                        <h4 class="mt-2 line-clamp-2 text-lg font-semibold leading-snug text-[var(--color-ink-strong)]">{{ $recipe->name }}</h4>
                        <p class="mt-1.5 text-xs text-[var(--color-ink-soft)]">
                            {{ __('products.card.updated', ['time' => $recipe->updated_at?->diffForHumans() ?? __('products.card.just_now')]) }}
                        </p>
                        @if ($hasProductionHistory)
                            <a href="{{ route('production-bench.production.index', ['recipe' => $recipe->public_id]) }}" wire:navigate class="relative z-20 mt-2 inline-block text-xs font-medium text-[var(--color-accent-strong)] hover:underline">
                                {{ trans_choice('products.card.production_count', $recipe->production_runs_count, ['count' => $recipe->production_runs_count]) }}
                            </a>
                        @endif
                    </div>

                    <div x-show="archiveOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="archiveOpen = false" role="dialog" aria-modal="true" aria-labelledby="product-archive-heading-{{ $recipe->id }}">
                        <div class="sk-card w-full max-w-md p-6" @click.stop>
                            <h3 id="product-archive-heading-{{ $recipe->id }}" class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('products.archiving.heading', ['product' => $recipe->name]) }}</h3>
                            <p class="mt-2 text-sm text-[var(--color-ink-soft)]">{{ __('products.archiving.warning') }}</p>
                            <form method="POST" action="{{ route('recipes.archive', $recipe) }}" class="mt-4">
                                @csrf
                                <button type="submit" class="sk-btn w-full bg-[var(--color-danger-strong)] text-white hover:bg-[var(--color-danger)]">
                                    {{ __('products.actions.archive') }}
                                </button>
                            </form>
                            <button type="button" @click="archiveOpen = false" class="sk-btn sk-btn-outline mt-3 w-full">
                                {{ __('products.actions.cancel') }}
                            </button>
                        </div>
                    </div>

                    <div x-show="deleteOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="deleteOpen = false" role="dialog" aria-modal="true" aria-labelledby="product-delete-heading-{{ $recipe->id }}">
                        <div class="sk-card w-full max-w-md p-6" @click.stop>
                            <h3 id="product-delete-heading-{{ $recipe->id }}" class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('products.deletion.heading', ['product' => $recipe->name]) }}</h3>
                            <p class="mt-2 text-sm text-[var(--color-ink-soft)]">{{ __('products.deletion.warning') }}</p>
                            @if ($hasProductionHistory)
                                <p class="mt-2 text-sm text-[var(--color-ink-soft)]">{{ __('products.deletion.history_note') }}</p>
                            @endif

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
        @if ($recipes->hasPages())
            <x-table-pagination :paginator="$recipes" :show-per-page="false" />
        @endif
    @endif
</div>
