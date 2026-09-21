<x-production-bench.page active="production">
    @if (! $isBenchActive && ! $isReadOnly)
        <section class="sk-card p-8 text-center">
            <h1 class="text-3xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.common.inactive') }}</h1>
            <a href="{{ route('production-bench.home') }}" wire:navigate class="mt-4 inline-block text-sm font-medium text-[var(--color-accent)]">{{ __('production_bench.title') }}</a>
        </section>
    @else
        @php
            $numberLocale = auth()->user()?->number_locale;
        @endphp
        <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="sk-eyebrow">{{ __('production_bench.navigation.production_workflow') }}</p>
                <h1 class="mt-2 text-3xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.production.index_title') }}</h1>
                <p class="mt-2 max-w-2xl text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.production.index_intro') }}</p>
            </div>
            @if ($isBenchActive)
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('production-bench.production.create') }}" wire:navigate class="sk-btn sk-btn-primary">{{ __('production_bench.production.new') }}</a>
                </div>
            @endif
        </header>

        <section aria-label="{{ __('production_bench.common.search') }}" class="sk-card grid gap-4 p-5 sm:grid-cols-2 lg:grid-cols-4">
            <label class="space-y-2 sm:col-span-2 lg:col-span-2">
                <span class="text-sm font-medium">{{ __('production_bench.common.search') }}</span>
                <input autocomplete="off" wire:model.live.debounce.300ms="search" class="sk-input w-full" placeholder="{{ __('production_bench.production.search_placeholder') }}">
            </label>
            <label class="space-y-2">
                <span class="text-sm font-medium">{{ __('production_bench.production.status_filter') }}</span>
                <select wire:model.live="status" class="sk-input w-full">
                    <option value="">{{ __('production_bench.production.all_statuses') }}</option>
                    @foreach (\App\Enums\ProductionRunStatus::cases() as $statusOption)
                        <option value="{{ $statusOption->value }}">{{ $statusOption->label() }}</option>
                    @endforeach
                </select>
            </label>
            {{ $this->filterDatesForm->getComponent('dateFrom') }}
            {{ $this->filterDatesForm->getComponent('dateTo') }}
            @if ($workspace->uses_production_locations)
                <label class="space-y-2">
                    <span class="text-sm font-medium">{{ __('locations.production_location') }}</span>
                    <select wire:model.live="locationFilter" class="sk-input w-full">
                        <option value="">{{ __('locations.all_production_locations') }}</option>
                        @foreach ($productionLocations as $location)
                            <option value="{{ $location->public_id }}">{{ $location->name }}@if (! $location->is_active) ({{ __('locations.archived') }}) @endif</option>
                        @endforeach
                    </select>
                </label>
            @endif
        </section>

        @error('selectedProductionIds')
            <p role="alert" class="rounded-xl bg-[var(--color-danger-soft)] px-4 py-3 text-sm text-[var(--color-danger-strong)]">{{ $message }}</p>
        @enderror

        @if ($filteredRecipeName !== null)
            <div class="flex flex-wrap items-center gap-2">
                <span class="inline-flex items-center gap-2 rounded-full bg-[var(--color-accent-soft)] px-3 py-1.5 text-sm font-medium text-[var(--color-accent-strong)]">
                    {{ __('production_bench.production.product_filter', ['product' => $filteredRecipeName]) }}
                    <button
                        type="button"
                        wire:click="clearRecipeFilter"
                        aria-label="{{ __('production_bench.production.clear_product_filter') }}"
                        class="grid h-5 w-5 place-items-center rounded-full transition hover:bg-[var(--color-accent)]/15 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)]"
                    >
                        <x-action-icon name="close" />
                    </button>
                </span>
            </div>
        @endif

        <section aria-labelledby="production-list-heading" class="sk-card overflow-hidden">
            <h2 id="production-list-heading" class="sr-only">{{ __('production_bench.production.index_title') }}</h2>

            @if ($productions->isEmpty())
                <p class="p-10 text-center text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.production.no_productions') }}</p>
            @else
                @php
                    $statusColorMap = [
                        'draft' => 'bg-[var(--color-ink-soft)]/10 text-[var(--color-ink-soft)]',
                        'scheduled' => 'bg-[var(--color-accent-soft)] text-[var(--color-accent-strong)]',
                        'reserved' => 'bg-[var(--color-warning-soft)] text-[var(--color-warning-strong)]',
                        'in_production' => 'bg-[var(--color-success-soft)] text-[var(--color-success-strong)]',
                        'completed' => 'bg-[var(--color-ink-strong)]/10 text-[var(--color-ink-strong)]',
                    ];
                @endphp

                @if ($canMutate && $visibleSelectedProductionIds !== [])
                    <div role="region" aria-label="{{ __('production_bench.production.bulk_actions') }}" class="flex flex-wrap items-center gap-2 border-b border-[var(--color-line)] bg-[var(--color-panel-muted)] px-5 py-3">
                        <p class="mr-1 text-sm font-medium text-[var(--color-ink-strong)]">{{ __('production_bench.production.selected_count', ['count' => count($visibleSelectedProductionIds)]) }}</p>
                        <button type="button" wire:click="prepareSelected" wire:loading.attr="disabled" wire:target="prepareSelected" class="sk-btn sk-btn-secondary text-xs">{{ __('production_bench.production.prepare_stock') }}</button>
                        <button type="button" wire:click="assignSelectedBatchNumbers" wire:confirm="{{ __('production_bench.production.assign_batch_numbers_confirm') }}" wire:loading.attr="disabled" wire:target="assignSelectedBatchNumbers" class="sk-btn sk-btn-secondary text-xs">{{ __('production_bench.production.assign_batch_numbers') }}</button>
                        <button type="button" wire:click="clearSelection" class="sk-btn sk-btn-ghost text-xs">{{ __('production_bench.production.clear_selection') }}</button>
                    </div>
                @endif

                {{-- Desktop table (lg+) --}}
                <x-sticky-table-scroll class="hidden lg:block">
                    <table data-production-table class="w-full table-fixed {{ $workspace->uses_production_locations ? 'min-w-[1120px]' : 'min-w-[920px]' }}">
                        <colgroup>
                            <col class="w-16" />
                            <col data-production-product-column />
                            <col class="w-40" />
                            @if ($workspace->uses_production_locations)
                                <col class="w-48" />
                            @endif
                            <col class="w-40" />
                            <col class="w-36" />
                            <col data-production-actions-column class="w-36" />
                        </colgroup>
                        <thead wire:ignore.self data-sticky-table-header class="relative z-20 whitespace-nowrap bg-[var(--color-panel-muted)] text-left text-xs uppercase tracking-wide text-[var(--color-ink-muted)] shadow-[0_1px_0_0_var(--color-line)]">
                            <tr>
                                <th class="w-10 px-5 py-4"><span class="sr-only">{{ __('production_bench.common.select') }}</span></th>
                                <th class="px-5 py-4">{{ __('production_bench.production.product') }}</th>
                                <th class="px-5 py-4">{{ __('production_bench.production.production_date') }}</th>
                                @if ($workspace->uses_production_locations)
                                    <th data-production-place-header class="px-5 py-4">{{ __('production_bench.production.production_place') }}</th>
                                @endif
                                <th class="px-5 py-4">{{ __('production_bench.production.quantity') }}</th>
                                <th class="px-5 py-4">{{ __('production_bench.production.status_filter') }}</th>
                                <th class="px-5 py-4"><span class="sr-only">{{ __('production_bench.common.actions') }}</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--color-line)]">
                            @foreach ($productions as $production)
                                @php
                                    $identifierLabel = $production->batch_number !== null
                                        ? __('production_bench.production.batch_number')
                                        : __('production_bench.production.planning_reference');
                                    $canSchedule = $canMutate && $production->status->value === 'draft';
                                    $canDelete = $canMutate && in_array($production->status->value, ['draft', 'scheduled'], true);
                                    $partiallyReserved = in_array($production->id, $partiallyReservedIds, true);
                                @endphp
                                <tr class="cursor-pointer transition hover:bg-[var(--color-panel-muted)]" x-data x-on:click="if (! event.target.closest('a, button, input, select, label')) window.Livewire?.navigate($el.querySelector('a[data-row-link]').href)">
                                    <td data-production-cell class="px-5 py-3 align-top">
                                        <input type="checkbox" wire:model.live="selectedProductionIds" value="{{ $production->id }}" @disabled(! $canMutate || ! in_array($production->status->value, ['scheduled', 'reserved'], true)) aria-label="{{ __('production_bench.production.select_production', ['name' => $production->displayRecipeName()]) }}" style="accent-color: var(--color-accent);" class="h-5 w-5 rounded border-[var(--color-line-strong)]">
                                    </td>
                                    <td data-production-cell class="min-w-0 px-5 py-3 align-top">
                                        <a href="{{ route('production-bench.production.show', $production) }}" wire:navigate data-row-link class="block">
                                            <p class="line-clamp-2 font-semibold text-[var(--color-ink-strong)]">{{ $production->displayRecipeName() }}</p>
                                            <p class="mt-1 font-mono text-xs text-[var(--color-ink-soft)]">{{ $identifierLabel }} {{ $production->displayIdentifier() }}</p>
                                        </a>
                                    </td>
                                    <td data-production-cell class="px-5 py-3 align-top">
                                        @if ($production->planned_for)
                                            <p class="whitespace-nowrap font-mono tabular-nums text-sm text-[var(--color-ink-strong)]">{{ $production->planned_for->format('Y-m-d') }}</p>
                                        @else
                                            <p class="text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.production.not_scheduled') }}</p>
                                        @endif
                                    </td>
                                    @if ($workspace->uses_production_locations)
                                        <td data-production-cell data-production-place-cell class="px-5 py-3 align-top">
                                            <p class="line-clamp-2 text-sm text-[var(--color-ink-strong)]">
                                                @if ($production->productionLocation)
                                                    {{ $production->productionLocation->name }}
                                                    @if (! $production->productionLocation->is_active)
                                                        <span class="text-xs text-[var(--color-ink-muted)]">({{ __('locations.archived') }})</span>
                                                    @endif
                                                @else
                                                    <span class="text-[var(--color-ink-soft)]">{{ __('production_bench.production.unassigned') }}</span>
                                                @endif
                                            </p>
                                        </td>
                                    @endif
                                    <td data-production-cell class="px-5 py-3 align-top">
                                        <p class="whitespace-nowrap font-mono tabular-nums text-sm text-[var(--color-ink-strong)]">{{ \App\Support\NumberLocale::formatAdaptiveDecimal($production->basis_input_value, 0, 3, $numberLocale) }} {{ $production->basis_input_unit->value }}</p>
                                        <p class="mt-1 text-xs text-[var(--color-ink-soft)]">{{ __('production_bench.production.expected_units_short', ['count' => \App\Support\NumberLocale::formatDecimal($production->expected_units, 0, $numberLocale)]) }}</p>
                                    </td>
                                    <td data-production-cell class="px-5 py-3 align-top">
                                        <span class="inline-block rounded-full px-2.5 py-1 text-xs font-medium {{ $statusColorMap[$production->status->value] ?? 'bg-[var(--color-ink-muted)]/10 text-[var(--color-ink-muted)]' }}">{{ $production->status->label() }}</span>
                                        @if ($partiallyReserved)
                                            <p class="mt-1 text-xs text-[var(--color-warning-strong)]">{{ __('production_bench.production.partially_reserved') }}</p>
                                        @endif
                                    </td>
                                    <td data-production-cell class="px-5 py-3 align-top">
                                        <div class="flex min-h-11 items-start justify-end gap-1">
                                            @if ($canSchedule)
                                                {{ ($this->scheduleDraftAction)(['productionId' => $production->id]) }}
                                            @endif

                                            @if ($canDelete)
                                                <x-table-row-action icon="trash" label="{{ __('production_bench.production.delete') }}"
                                                    data-production-delete-action
                                                    wire:click.stop="deleteProduction({{ $production->id }})"
                                                    wire:confirm="{{ __('production_bench.production.delete_confirm') }}"
                                                    wire:loading.attr="disabled"
                                                    aria-label="{{ __('production_bench.production.delete') }}: {{ $production->displayRecipeName() }}"
                                                />
                                            @endif

                                            <span data-production-open-indicator aria-hidden="true" class="grid size-11 place-items-center text-[var(--color-ink-muted)]">
                                                <x-action-icon name="chevron-right" />
                                            </span>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-sticky-table-scroll>

                {{-- Mobile stack (<lg) --}}
                <div class="lg:hidden divide-y divide-[var(--color-line)]">
                    @foreach ($productions as $production)
                        @php
                            $identifierLabel = $production->batch_number !== null
                                ? __('production_bench.production.batch_number')
                                : __('production_bench.production.planning_reference');
                            $canSchedule = $canMutate && $production->status->value === 'draft';
                            $canDelete = $canMutate && in_array($production->status->value, ['draft', 'scheduled'], true);
                            $partiallyReserved = in_array($production->id, $partiallyReservedIds, true);
                        @endphp
                        <div class="flex gap-4 px-5 py-5 transition hover:bg-[var(--color-panel-muted)] sm:px-6">
                            <div class="pt-1">
                                <input type="checkbox" wire:model.live="selectedProductionIds" value="{{ $production->id }}" @disabled(! $canMutate || ! in_array($production->status->value, ['scheduled', 'reserved'], true)) aria-label="{{ __('production_bench.production.select_production', ['name' => $production->displayRecipeName()]) }}" style="accent-color: var(--color-accent);" class="h-5 w-5 rounded border-[var(--color-line-strong)]">
                            </div>
                            <div class="min-w-0 flex-1">
                                <a href="{{ route('production-bench.production.show', $production) }}" wire:navigate class="block">
                                    <div class="flex flex-wrap items-start justify-between gap-2">
                                        <div class="min-w-0">
                                            <h3 class="line-clamp-2 text-base font-semibold text-[var(--color-ink-strong)]">{{ $production->displayRecipeName() }}</h3>
                                            <p class="mt-1 font-mono text-xs text-[var(--color-ink-soft)]">{{ $identifierLabel }} {{ $production->displayIdentifier() }}</p>
                                        </div>
                                        <span class="inline-block rounded-full px-2.5 py-1 text-xs font-medium {{ $statusColorMap[$production->status->value] ?? 'bg-[var(--color-ink-muted)]/10 text-[var(--color-ink-muted)]' }}">{{ $production->status->label() }}</span>
                                    </div>
                                    @if ($partiallyReserved)
                                        <p class="mt-2 text-xs text-[var(--color-warning-strong)]">{{ __('production_bench.production.partially_reserved') }}</p>
                                    @endif
                                    <dl class="mt-3 grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
                                        <div>
                                            <dt class="text-xs uppercase tracking-wide text-[var(--color-ink-muted)]">{{ __('production_bench.production.production_date') }}</dt>
                                            <dd class="mt-1">
                                                @if ($production->planned_for)
                                                    <span class="font-mono tabular-nums text-[var(--color-ink-strong)]">{{ $production->planned_for->format('Y-m-d') }}</span>
                                                @else
                                                    <span class="text-[var(--color-ink-soft)]">{{ __('production_bench.production.not_scheduled') }}</span>
                                                @endif
                                            </dd>
                                        </div>
                                        @if ($workspace->uses_production_locations)
                                            <div>
                                                <dt class="text-xs uppercase tracking-wide text-[var(--color-ink-muted)]">{{ __('production_bench.production.production_place') }}</dt>
                                                <dd class="mt-1 text-[var(--color-ink-strong)]">
                                                    @if ($production->productionLocation)
                                                        {{ $production->productionLocation->name }}
                                                        @if (! $production->productionLocation->is_active)
                                                            <span class="text-xs text-[var(--color-ink-muted)]">({{ __('locations.archived') }})</span>
                                                        @endif
                                                    @else
                                                        <span class="text-[var(--color-ink-soft)]">{{ __('production_bench.production.unassigned') }}</span>
                                                    @endif
                                                </dd>
                                            </div>
                                        @endif
                                        <div>
                                            <dt class="text-xs uppercase tracking-wide text-[var(--color-ink-muted)]">{{ __('production_bench.production.quantity') }}</dt>
                                            <dd class="mt-1">
                                                <span class="font-mono tabular-nums text-[var(--color-ink-strong)]">{{ \App\Support\NumberLocale::formatAdaptiveDecimal($production->basis_input_value, 0, 3, $numberLocale) }} {{ $production->basis_input_unit->value }}</span>
                                                <span class="mt-1 block text-xs text-[var(--color-ink-soft)]">{{ __('production_bench.production.expected_units_short', ['count' => \App\Support\NumberLocale::formatDecimal($production->expected_units, 0, $numberLocale)]) }}</span>
                                            </dd>
                                        </div>
                                    </dl>
                                </a>
                                @if ($canSchedule || $canDelete)
                                    <div class="mt-3 flex flex-wrap items-center gap-2">
                                        @if ($canSchedule)
                                            {{ ($this->scheduleDraftAction)(['productionId' => $production->id]) }}
                                        @endif

                                        @if ($canDelete)
                                            <x-table-row-action icon="trash" label="{{ __('production_bench.production.delete') }}"
                                                    data-production-delete-action
                                                wire:click.stop="deleteProduction({{ $production->id }})"
                                                wire:confirm="{{ __('production_bench.production.delete_confirm') }}"
                                                wire:loading.attr="disabled"
                                                aria-label="{{ __('production_bench.production.delete') }}: {{ $production->displayRecipeName() }}"
                                                />
                                        @endif
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            <x-table-pagination :paginator="$productions" :per-page-label="__('production_bench.production.per_page')" />
        </section>
    @endif
    <x-filament-actions::modals />
</x-production-bench.page>
