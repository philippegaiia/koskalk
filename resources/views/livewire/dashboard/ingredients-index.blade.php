<div
    class="mx-auto w-full max-w-7xl space-y-6"
    x-on:ingredient-removal-closed.window="$nextTick(() => (document.getElementById($event.detail.triggerId) ?? document.getElementById('ingredient-catalog-heading'))?.focus())"
>
    @if ($statusMessage)
        <div
            role="{{ $statusType === 'error' ? 'alert' : 'status' }}"
            class="{{ $statusType === 'error' ? 'border-[var(--color-danger-soft)] bg-[var(--color-danger-soft)] text-[var(--color-danger-strong)]' : 'border-[var(--color-success-soft)] bg-[var(--color-success-soft)] text-[var(--color-success-strong)]' }} rounded-xl border px-5 py-3 text-sm"
        >
            {{ $statusMessage }}
        </div>
    @endif

    <section class="sk-card p-5 sm:p-6">
        <div class="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
            <div class="min-w-0">
                <p class="sk-eyebrow">{{ __('ingredients.page.eyebrow') }}</p>
                <h3 class="mt-2 max-w-4xl text-xl font-semibold text-[var(--color-ink-strong)] sm:text-2xl">{{ __('ingredients.page.heading') }}</h3>
                <p class="mt-2 max-w-3xl text-sm leading-7 text-[var(--color-ink-soft)]">
                    {{ __('ingredients.page.intro') }}
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-3 lg:justify-end">
                @include('livewire.dashboard.partials.duplicate-ingredient-modal')

                <a href="{{ route('dashboard') }}" wire:navigate class="inline-flex min-h-11 items-center justify-center whitespace-nowrap rounded-full border border-[var(--color-line)] px-5 py-2.5 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel)]">
                    {{ __('ingredients.actions.back_to_dashboard') }}
                </a>
            </div>
        </div>
    </section>

    @if (! $currentUser)
        <section class="sk-card p-8 text-center">
            <h4 class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('ingredients.auth.heading') }}</h4>
            <p class="mt-3 text-sm leading-7 text-[var(--color-ink-soft)]">{{ __('ingredients.auth.description') }}</p>
        </section>
    @else
        <section class="overflow-hidden sk-card p-0">
            <div class="flex flex-col gap-4 border-b border-[var(--color-line)] bg-[var(--color-field-muted)] px-5 py-4">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <p id="ingredient-catalog-heading" tabindex="-1" class="text-sm font-medium text-[var(--color-ink-strong)] focus:outline-none">{{ __('ingredients.catalog.heading') }}</p>
                        <p class="mt-1 text-xs text-[var(--color-ink-soft)]">{{ __('ingredients.catalog.description') }}</p>
                    </div>

                    <a href="{{ route('ingredients.create') }}" wire:navigate class="sk-btn sk-btn-primary justify-center">{{ __('ingredients.actions.add') }}</a>
                </div>

                <div class="flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between" aria-label="{{ __('ingredients.catalog.filters_label') }}">
                    <div class="flex flex-col gap-2">
                        <div class="flex flex-wrap gap-2" role="radiogroup" aria-label="{{ __('ingredients.catalog.filter_label') }}">
                            @foreach ($this->ownershipFilterOptions() as $filterValue => $filterLabel)
                                <button
                                    type="button"
                                    role="radio"
                                    aria-checked="{{ $ownershipFilter === $filterValue ? 'true' : 'false' }}"
                                    wire:click="setOwnershipFilter('{{ $filterValue }}')"
                                    class="{{ $ownershipFilter === $filterValue ? 'border-[var(--color-accent)] bg-[var(--color-accent-soft)] text-[var(--color-accent-strong)]' : 'border-[var(--color-line)] bg-white text-[var(--color-ink-soft)] hover:bg-[var(--color-field-muted)] hover:text-[var(--color-ink-strong)]' }} rounded-full border px-4 py-2 text-sm font-medium transition"
                                >
                                    {{ $filterValue === 'mine' ? $filterLabel.' ('.$privateIngredientUsage['used'].')' : $filterLabel }}
                                </button>
                            @endforeach
                        </div>

                        <p class="text-xs text-[var(--color-ink-soft)]">
                            @if ($privateIngredientUsage['limit'] === null)
                                {{ trans_choice('ingredients.limits.unlimited', $privateIngredientUsage['used'], ['used' => $privateIngredientUsage['used']]) }}
                            @else
                                {{ trans_choice('ingredients.limits.limited', $privateIngredientUsage['limit'], ['used' => $privateIngredientUsage['used'], 'limit' => $privateIngredientUsage['limit']]) }}
                            @endif
                        </p>
                    </div>

                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                        <label class="sk-field min-w-64">
                            <span class="shrink-0 text-[var(--color-ink-soft)]">{{ __('ingredients.search.label') }}</span>
                            <input wire:model.live.debounce.250ms="search" type="text" placeholder="{{ __('ingredients.search.placeholder') }}" class="sk-field-control" aria-label="{{ __('ingredients.search.aria_label') }}" />
                        </label>
                    </div>
                </div>
            </div>

            @if ($ingredients->isEmpty())
                <div class="px-5 py-10 text-center">
                    <h4 class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ $search !== '' ? __('ingredients.empty.no_matches') : __('ingredients.empty.no_ingredients') }}</h4>
                    <p class="mt-2 text-sm text-[var(--color-ink-soft)]">{{ __('ingredients.empty.description') }}</p>
                    <div class="mt-5">
                        <a href="{{ route('ingredients.create') }}" wire:navigate class="sk-btn sk-btn-primary">{{ __('ingredients.actions.add') }}</a>
                    </div>
                </div>
            @else
                <div class="sk-table-wrapper">
                    <table class="sk-table min-w-[68rem] table-auto">
                        <colgroup>
                            <col class="w-20" />
                            <col />
                            <col />
                            <col class="w-36" />
                            <col class="w-24" />
                            <col class="w-40" />
                            <col class="w-32" />
                        </colgroup>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('ingredients.table.picture') }}</th>
                                <th scope="col">
                                    <button type="button" wire:click="sortBy('display_name')" class="inline-flex items-center gap-1 font-semibold">
                                        {{ __('ingredients.table.name') }}
                                        <span class="text-xs text-[var(--color-ink-soft)]">{{ $sortField === 'display_name' ? ($sortDirection === 'asc' ? __('ingredients.sort.ascending') : __('ingredients.sort.descending')) : '' }}</span>
                                    </button>
                                </th>
                                <th scope="col">{{ __('ingredients.table.inci') }}</th>
                                <th scope="col">
                                    <button type="button" wire:click="sortBy('category')" class="inline-flex items-center gap-1 font-semibold">
                                        {{ __('ingredients.table.category') }}
                                        <span class="text-xs text-[var(--color-ink-soft)]">{{ $sortField === 'category' ? ($sortDirection === 'asc' ? __('ingredients.sort.ascending') : __('ingredients.sort.descending')) : '' }}</span>
                                    </button>
                                </th>
                                <th scope="col">{{ __('ingredients.table.source.label') }}</th>
                                <th scope="col">{{ $priceLabel }}</th>
                                <th scope="col" class="text-right">{{ __('ingredients.table.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($ingredients as $ingredient)
                                @php
                                    $imageUrl = $this->catalogImageUrl($ingredient);
                                    $displayName = $ingredient->localizedDisplayName();
                                    $isMine = $ingredient->owner_type !== null;
                                    $canEdit = $ingredient->isEditableBy($currentUser);
                                    $formulaUsage = $formulaUsageByIngredient[$ingredient->id] ?? [];
                                    $formulaUsageCount = count($formulaUsage);
                                    $currentFormulaUsageCount = count(array_filter($formulaUsage, fn (array $usage): bool => $usage['is_current']));
                                    $historyOnlyFormulaUsageCount = $formulaUsageCount - $currentFormulaUsageCount;
                                    $hasFormulaUsage = $canEdit && $formulaUsageCount > 0;
                                    $usageDisclosureId = 'ingredient-usage-'.$ingredient->id;
                                @endphp
                                <tr wire:key="ingredient-{{ $ingredient->id }}">
                                    <td>
                                        @if ($imageUrl)
                                            <img src="{{ $imageUrl }}" alt="" class="size-13 rounded-lg object-cover" />
                                        @else
                                            <div class="grid size-13 place-items-center rounded-lg bg-[var(--color-panel-strong)] text-xs font-semibold text-[var(--color-ink-soft)]">
                                                {{ mb_substr((string) $displayName, 0, 1) }}
                                            </div>
                                        @endif
                                    </td>
                                    <td>
                                        <div class="min-w-48">
                                            <p class="flex items-center gap-1.5 font-semibold text-[var(--color-ink-strong)]">
                                                <span>{{ $displayName }}</span>
                                                <x-ingredient-source-marker :is-user-owned="$isMine" />
                                            </p>
                                            @if ($ingredient->supplier_name)
                                                <p class="mt-1 text-xs text-[var(--color-ink-soft)]">{{ $ingredient->supplier_name }}</p>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="min-w-56 max-w-72 whitespace-normal text-[var(--color-ink-soft)]">{{ $ingredient->inci_name ?: '—' }}</td>
                                    <td>
                                        <span class="inline-flex whitespace-nowrap rounded-full bg-[var(--color-panel-strong)] px-3 py-1.5 text-sm font-medium text-[var(--color-ink-soft)]">
                                            {{ $ingredient->category?->getLabel() ?? __('ingredients.table.uncategorized') }}
                                        </span>
                                    </td>
                                    <td>
                                        <span class="{{ $isMine ? 'bg-[var(--color-warning-soft)] text-[var(--color-warning-strong)]' : 'bg-[var(--color-panel-strong)] text-[var(--color-ink-soft)]' }} inline-flex whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-medium">
                                            {{ $isMine ? __('ingredients.table.source.yours') : __('ingredients.table.source.soapkraft') }}
                                        </span>
                                    </td>
                                    <td>
                                        <div class="min-w-36">
                                            <input
                                                value="{{ $this->formattedPrice($ingredient->user_price_per_kg) }}"
                                                wire:change="updateIngredientPrice({{ $ingredient->id }}, $event.target.value)"
                                                type="text"
                                                inputmode="decimal"
                                                class="sk-input numeric"
                                                aria-label="{{ __('ingredients.accessibility.price', ['ingredient' => $displayName]) }}"
                                            />
                                            @error('price_'.$ingredient->id)
                                                <p class="mt-1 text-xs text-[var(--color-danger-strong)]">{{ $message }}</p>
                                            @enderror
                                        </div>
                                    </td>
                                    <td>
                                        <div class="flex items-center justify-end gap-1">
                                            @if ($canEdit)
                                                <a href="{{ route('ingredients.edit', $ingredient) }}" wire:navigate class="grid size-9 place-items-center rounded-lg text-[var(--color-ink-soft)] hover:bg-[var(--color-panel-strong)]" aria-label="{{ __('ingredients.accessibility.edit', ['ingredient' => $displayName]) }}" title="{{ __('ingredients.actions.edit') }}">
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125" />
                                                    </svg>
                                                </a>
                                                @if ($hasFormulaUsage)
                                                    <div class="flex flex-col items-end">
                                                        <div class="flex items-center gap-1">
                                                            <button
                                                                type="button"
                                                                wire:click="toggleUsage({{ $ingredient->id }})"
                                                                aria-expanded="{{ $expandedUsageIngredientId === $ingredient->id ? 'true' : 'false' }}"
                                                                aria-controls="{{ $usageDisclosureId }}"
                                                                class="{{ $currentFormulaUsageCount > 0 ? 'text-[var(--color-danger-strong)] hover:bg-[var(--color-danger-soft)]' : 'text-[var(--color-ink-soft)] hover:bg-[var(--color-panel-strong)]' }} inline-flex min-h-9 items-center justify-center rounded-lg px-2.5 text-xs font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)]"
                                                            >
                                                                @if ($currentFormulaUsageCount > 0 && $historyOnlyFormulaUsageCount > 0)
                                                                    {{ trans_choice('ingredients.usage.current_and_history', $currentFormulaUsageCount, ['current' => $currentFormulaUsageCount, 'history' => $historyOnlyFormulaUsageCount]) }}
                                                                @elseif ($currentFormulaUsageCount > 0)
                                                                    {{ trans_choice('ingredients.usage.current', $currentFormulaUsageCount, ['count' => $currentFormulaUsageCount]) }}
                                                                @else
                                                                    {{ trans_choice('ingredients.usage.history', $historyOnlyFormulaUsageCount, ['count' => $historyOnlyFormulaUsageCount]) }}
                                                                @endif
                                                            </button>
                                                            <button
                                                                type="button"
                                                                id="ingredient-delete-trigger-{{ $ingredient->id }}"
                                                                wire:click="confirmDelete({{ $ingredient->id }})"
                                                                wire:loading.attr="disabled"
                                                                wire:target="confirmDelete({{ $ingredient->id }})"
                                                                class="grid size-9 place-items-center rounded-lg text-[var(--color-danger-strong)] hover:bg-[var(--color-danger-soft)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)] disabled:cursor-wait disabled:opacity-50"
                                                                aria-label="{{ __('ingredients.accessibility.manage_removal', ['ingredient' => $displayName]) }}"
                                                                title="{{ __('ingredients.actions.manage_removal') }}"
                                                            >
                                                                <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                                                </svg>
                                                            </button>
                                                        </div>

                                                        <div id="{{ $usageDisclosureId }}" @if ($expandedUsageIngredientId !== $ingredient->id) hidden @endif class="mt-2 w-72 rounded-xl border border-[var(--color-line)] bg-[var(--color-panel)] p-3 text-left shadow-sm">
                                                            <ul class="space-y-2">
                                                                @foreach ($formulaUsage as $usage)
                                                                    <li class="flex items-baseline justify-between gap-3">
                                                                        <a href="{{ $usage['url'] }}" wire:navigate class="text-sm font-medium text-[var(--color-accent-strong)] underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2">
                                                                            {{ $usage['name'] }}
                                                                        </a>
                                                                        @if (! $usage['is_current'])
                                                                            <span class="shrink-0 text-xs text-[var(--color-ink-soft)]">{{ __('ingredients.usage.history_only') }}</span>
                                                                        @endif
                                                                    </li>
                                                                @endforeach
                                                            </ul>
                                                        </div>
                                                    </div>
                                                @else
                                                    <button
                                                        type="button"
                                                        id="ingredient-delete-trigger-{{ $ingredient->id }}"
                                                        wire:click="confirmDelete({{ $ingredient->id }})"
                                                        wire:loading.attr="disabled"
                                                        wire:target="confirmDelete({{ $ingredient->id }})"
                                                        class="grid size-9 place-items-center rounded-lg text-[var(--color-danger-strong)] hover:bg-[var(--color-danger-soft)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)] disabled:cursor-wait disabled:opacity-50"
                                                        aria-label="{{ __('ingredients.accessibility.delete', ['ingredient' => $displayName]) }}"
                                                        title="{{ __('ingredients.actions.delete') }}"
                                                    >
                                                        <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                                        </svg>
                                                    </button>
                                                @endif
                                            @else
                                                <a href="{{ route('ingredients.edit', $ingredient) }}" wire:navigate class="grid size-9 place-items-center rounded-lg text-[var(--color-ink-soft)] hover:bg-[var(--color-panel-strong)]" aria-label="{{ __('ingredients.accessibility.view', ['ingredient' => $displayName]) }}" title="{{ __('ingredients.actions.view_reference') }}">
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12s3.75-6.75 9.75-6.75S21.75 12 21.75 12 18 18.75 12 18.75 2.25 12 2.25 12Z" />
                                                        <circle cx="12" cy="12" r="2.25" />
                                                    </svg>
                                                </a>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="border-t border-[var(--color-line)] px-5 py-2.5">
                    <x-ingredient-source-legend :show="$ingredients->contains(fn ($ingredient): bool => $ingredient->owner_type !== null)" />
                </div>

                <x-table-pagination :paginator="$ingredients" :per-page-label="__('ingredients.table.per_page')" />
            @endif
        </section>

        @if ($pendingDeleteIngredient && $pendingDeleteImpact)
            @php
                $usedFormulaCount = $pendingDeleteImpact['formula_count'];
                $usedCompositeCount = $pendingDeleteImpact['composite_count'];
                $hasBlockedFormulas = $pendingDeleteImpact['blocked_recipes']->isNotEmpty() || $pendingDeleteImpact['inaccessible_blocked_count'] > 0;
                $hasBlockedComposites = $pendingDeleteImpact['blocked_composites']->isNotEmpty() || $pendingDeleteImpact['inaccessible_blocked_composite_count'] > 0;
                $hasBlockedDependencies = $hasBlockedFormulas || $hasBlockedComposites;
                $hasReplacementCandidates = $replacementCandidates->isNotEmpty();
                $isCarrierOil = $pendingDeleteIngredient->category === \App\IngredientCategory::CarrierOil;
            @endphp
            <div
                class="fixed inset-0 z-50 flex items-center justify-center bg-[color:oklch(20.3%_0.026_149_/_0.46)] p-4"
                x-data
                x-init="$nextTick(() => $refs.initialFocus?.focus())"
                wire:loading.attr="data-mutating"
                wire:target="deleteIngredient, replaceEverywhereAndDelete, removeEverywhereAndDelete"
                @click.self="if (! $el.hasAttribute('data-mutating')) $wire.cancelDelete()"
                @keydown.escape.window="if (! $el.hasAttribute('data-mutating')) $wire.cancelDelete()"
                role="dialog"
                aria-modal="true"
                aria-labelledby="ingredient-delete-heading"
                aria-describedby="ingredient-delete-description"
            >
                @if ($usedFormulaCount === 0 && $usedCompositeCount === 0)
                    <div x-trap.noscroll="true" class="w-full max-w-md rounded-xl border border-[var(--color-line)] bg-[var(--color-panel)] p-6 shadow-xl">
                        <h3 id="ingredient-delete-heading" class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('ingredients.removal.delete_heading', ['ingredient' => $pendingDeleteIngredient->localizedDisplayName()]) }}</h3>
                        <p id="ingredient-delete-description" class="mt-2 text-sm leading-6 text-[var(--color-ink-soft)]">{{ __('ingredients.removal.delete_description') }}</p>

                        @error('ingredient')
                            <p role="alert" class="mt-4 rounded-lg bg-[var(--color-danger-soft)] px-3 py-2 text-sm text-[var(--color-danger-strong)]">{{ $message }}</p>
                        @enderror

                        <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                            <button x-ref="initialFocus" type="button" wire:click="cancelDelete" wire:loading.attr="disabled" wire:target="deleteIngredient, replaceEverywhereAndDelete, removeEverywhereAndDelete" class="min-h-11 rounded-lg border border-[var(--color-line)] px-4 py-2 text-sm font-medium text-[var(--color-ink-soft)] hover:bg-[var(--color-panel-strong)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)] disabled:cursor-wait disabled:opacity-50">{{ __('ingredients.actions.cancel') }}</button>
                            <button type="button" wire:click="deleteIngredient" wire:loading.attr="disabled" wire:target="deleteIngredient, replaceEverywhereAndDelete, removeEverywhereAndDelete" class="min-h-11 rounded-lg bg-[var(--color-danger-strong)] px-4 py-2 text-sm font-medium text-[var(--color-cream)] hover:opacity-90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-danger-strong)] disabled:cursor-wait disabled:opacity-50">{{ __('ingredients.actions.delete') }}</button>
                        </div>
                    </div>
                @else
                    <div x-trap.noscroll="true" class="max-h-[calc(100dvh-2rem)] w-full max-w-2xl overflow-y-auto rounded-xl border border-[var(--color-line)] bg-[var(--color-panel)] p-5 shadow-xl sm:p-6">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <p class="sk-eyebrow">{{ __('ingredients.removal.eyebrow') }}</p>
                                <h3 id="ingredient-delete-heading" class="mt-2 text-xl font-semibold text-[var(--color-ink-strong)]">{{ __('ingredients.removal.manage_heading', ['ingredient' => $pendingDeleteIngredient->localizedDisplayName()]) }}</h3>
                                <p id="ingredient-delete-description" class="mt-2 text-sm leading-6 text-[var(--color-ink-soft)]">
                                    @if ($usedFormulaCount > 0)
                                        {{ trans_choice('ingredients.removal.formula_usage', $usedFormulaCount, ['count' => $usedFormulaCount]) }}
                                    @endif
                                    @if ($usedCompositeCount > 0)
                                        {{ trans_choice($usedFormulaCount > 0 ? 'ingredients.removal.additional_composite_usage' : 'ingredients.removal.composite_usage', $usedCompositeCount, ['count' => $usedCompositeCount]) }}
                                    @endif
                                    {{ __('ingredients.removal.choose_change') }}
                                </p>
                            </div>
                            <button x-ref="initialFocus" type="button" wire:click="cancelDelete" wire:loading.attr="disabled" wire:target="deleteIngredient, replaceEverywhereAndDelete, removeEverywhereAndDelete" class="grid size-10 shrink-0 place-items-center rounded-lg text-[var(--color-ink-soft)] hover:bg-[var(--color-panel-strong)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)] disabled:cursor-wait disabled:opacity-50" aria-label="{{ __('ingredients.accessibility.close_removal') }}">
                                <svg xmlns="http://www.w3.org/2000/svg" class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>

                        @if ($hasBlockedFormulas)
                            <div class="mt-5 rounded-xl border border-[var(--color-warning-soft)] bg-[var(--color-warning-soft)] p-4 text-sm text-[var(--color-warning-strong)]">
                                <p class="font-semibold">{{ __('ingredients.removal.automatic_unavailable') }}</p>
                                <p class="mt-1 leading-6">{{ __('ingredients.removal.edit_formulas') }}</p>
                                @if ($pendingDeleteImpact['blocked_recipes']->isNotEmpty())
                                    <ul class="mt-3 space-y-1.5">
                                        @foreach ($pendingDeleteImpact['blocked_recipes'] as $blockedRecipe)
                                            <li>
                                                <a href="{{ route('recipes.edit', $blockedRecipe) }}" wire:navigate class="font-medium underline underline-offset-2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)]">{{ $blockedRecipe->name }}</a>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                                @if ($pendingDeleteImpact['inaccessible_blocked_count'] > 0)
                                    <p class="mt-3">{{ trans_choice('ingredients.removal.additional_formula_blocked', $pendingDeleteImpact['inaccessible_blocked_count'], ['count' => $pendingDeleteImpact['inaccessible_blocked_count']]) }}</p>
                                @endif
                            </div>
                        @endif

                        @if ($hasBlockedComposites)
                            <div class="mt-5 rounded-xl border border-[var(--color-warning-soft)] bg-[var(--color-warning-soft)] p-4 text-sm text-[var(--color-warning-strong)]">
                                <p class="font-semibold">{{ __('ingredients.removal.automatic_unavailable') }}</p>
                                <p class="mt-1 leading-6">{{ __('ingredients.removal.edit_composites') }}</p>
                                @if ($pendingDeleteImpact['blocked_composites']->isNotEmpty())
                                    <ul class="mt-3 space-y-1.5">
                                        @foreach ($pendingDeleteImpact['blocked_composites'] as $blockedComposite)
                                            <li class="font-medium">{{ $blockedComposite->localizedDisplayName() }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                                @if ($pendingDeleteImpact['inaccessible_blocked_composite_count'] > 0)
                                    <p class="mt-3">{{ trans_choice('ingredients.removal.additional_composite_blocked', $pendingDeleteImpact['inaccessible_blocked_composite_count'], ['count' => $pendingDeleteImpact['inaccessible_blocked_composite_count']]) }}</p>
                                @endif
                            </div>
                        @endif

                        @error('ingredient')
                            <p role="alert" class="mt-5 rounded-lg bg-[var(--color-danger-soft)] px-3 py-2 text-sm text-[var(--color-danger-strong)]">{{ $message }}</p>
                        @enderror

                        <div class="mt-6 border-t border-[var(--color-line)] pt-5">
                            @php
                                $replacementOptions = $replacementCandidates
                                    ->map(fn ($candidate): array => [
                                        'id' => $candidate->id,
                                        'label' => $candidate->localizedDisplayName().' ('.$candidate->category?->getLabel().')',
                                        'description' => $candidate->inci_name,
                                        'searchText' => implode(' ', [
                                            $candidate->localizedDisplayName(),
                                            $candidate->inci_name,
                                            $candidate->category?->getLabel(),
                                        ]),
                                    ])
                                    ->values()
                                    ->all();
                                $selectedReplacement = $replacementCandidates->firstWhere('id', $replacementIngredientId);
                                $selectedReplacementLabel = $selectedReplacement
                                    ? $selectedReplacement->localizedDisplayName().' ('.$selectedReplacement->category?->getLabel().')'
                                    : null;
                            @endphp

                            <p class="text-sm font-semibold text-[var(--color-ink-strong)]">{{ __('ingredients.removal.replacement.heading') }}</p>
                            <p class="mt-1 text-xs leading-5 text-[var(--color-ink-soft)]">{{ __('ingredients.removal.replacement.description') }}</p>
                            <div
                                class="mt-3"
                                wire:key="replacement-combobox-{{ $pendingDeleteIngredient->id }}"
                                x-on:search-combobox-selected="$wire.selectReplacementIngredient($event.detail.id)"
                                x-on:search-combobox-cleared="$wire.clearReplacementIngredient()"
                            >
                                <x-search-combobox
                                    id="replacement-ingredient"
                                    :label="__('ingredients.removal.replacement.search_label')"
                                    :options="$replacementOptions"
                                    :placeholder="__('ingredients.removal.replacement.search_placeholder')"
                                    :action-label="__('ingredients.actions.select')"
                                    :empty-message="__('ingredients.removal.replacement.no_match')"
                                    :selected-id="$replacementIngredientId"
                                    :selected-label="$selectedReplacementLabel"
                                    :disabled="$hasBlockedDependencies || ! $hasReplacementCandidates"
                                />
                            </div>
                            @error('replacementIngredientId')
                                <p role="alert" class="mt-2 text-xs text-[var(--color-danger-strong)]">{{ $message }}</p>
                            @enderror
                            @if (! $hasReplacementCandidates)
                                <p class="mt-2 text-xs leading-5 text-[var(--color-ink-soft)]">{{ __('ingredients.removal.replacement.unavailable') }}</p>
                            @endif

                            <button
                                type="button"
                                wire:click="replaceEverywhereAndDelete"
                                wire:loading.attr="disabled"
                                wire:target="deleteIngredient, replaceEverywhereAndDelete, removeEverywhereAndDelete"
                                @disabled($hasBlockedDependencies || ! $hasReplacementCandidates || $replacementIngredientId === null)
                                class="mt-4 inline-flex min-h-11 w-full items-center justify-center rounded-lg bg-[var(--color-accent)] px-4 py-2.5 text-sm font-semibold text-[var(--color-cream)] hover:bg-[var(--color-accent-hover)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)] disabled:cursor-not-allowed disabled:opacity-50 sm:w-auto"
                            >
                                {{ __('ingredients.removal.replacement.action') }}
                            </button>
                        </div>

                        <div class="mt-6 border-t border-[var(--color-line)] pt-5">
                            <p class="text-sm font-semibold text-[var(--color-ink-strong)]">{{ __('ingredients.removal.without_replacement.heading') }}</p>
                            <p class="mt-1 text-xs leading-5 text-[var(--color-ink-soft)]">{{ __('ingredients.removal.without_replacement.description') }}</p>
                            @if ($isCarrierOil)
                                <p class="mt-2 rounded-lg bg-[var(--color-warning-soft)] px-3 py-2 text-xs leading-5 text-[var(--color-warning-strong)]">{{ __('ingredients.removal.without_replacement.soap_warning') }}</p>
                            @endif
                            <button
                                type="button"
                                wire:click="removeEverywhereAndDelete"
                                wire:loading.attr="disabled"
                                wire:target="deleteIngredient, replaceEverywhereAndDelete, removeEverywhereAndDelete"
                                @disabled($hasBlockedDependencies)
                                class="mt-4 inline-flex min-h-11 w-full items-center justify-center rounded-lg bg-[var(--color-danger-strong)] px-4 py-2.5 text-sm font-semibold text-[var(--color-cream)] hover:opacity-90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-danger-strong)] disabled:cursor-not-allowed disabled:opacity-50 sm:w-auto"
                            >
                                {{ __('ingredients.removal.without_replacement.action') }}
                            </button>
                        </div>

                        <div class="mt-6 flex justify-end border-t border-[var(--color-line)] pt-4">
                            <button type="button" wire:click="cancelDelete" wire:loading.attr="disabled" wire:target="deleteIngredient, replaceEverywhereAndDelete, removeEverywhereAndDelete" class="min-h-11 rounded-lg border border-[var(--color-line)] px-4 py-2 text-sm font-medium text-[var(--color-ink-soft)] hover:bg-[var(--color-panel-strong)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)] disabled:cursor-wait disabled:opacity-50">{{ __('ingredients.actions.cancel') }}</button>
                        </div>
                    </div>
                @endif
            </div>
        @endif
    @endif
</div>
