<x-production-bench.page compact>
    @if (! $isBenchActive && ! $isReadOnly)
        <section class="sk-card p-8 text-center">
            <h1 class="text-3xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.common.inactive') }}</h1>
            <a href="{{ route('production-bench.home') }}" wire:navigate class="mt-4 inline-block text-sm font-medium text-[var(--color-accent)]">{{ __('production_bench.title') }}</a>
        </section>
    @else
        @php $mutationLocked = $isReadOnly || ! $canMutate; @endphp
        <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="sk-eyebrow">{{ __('production_bench.navigation.production_workflow') }}</p>
                <h1 class="mt-2 text-3xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.production.index_title') }}</h1>
                <p class="mt-2 max-w-2xl text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.production.index_intro') }}</p>
            </div>
            @if ($isBenchActive)
                <div class="flex flex-wrap gap-2">
                    <button type="button" wire:click="assignSelectedBatchNumbers" wire:confirm="{{ __('production_bench.production.assign_batch_numbers_confirm') }}" wire:loading.attr="disabled" wire:target="assignSelectedBatchNumbers" class="sk-btn sk-btn-secondary" @disabled(! $canMutate)>{{ __('production_bench.production.assign_batch_numbers') }}</button>
                    <button type="button" wire:click="prepareSelected" class="sk-btn sk-btn-secondary" @disabled(! $canMutate)>{{ __('production_bench.production.prepare_stock') }}</button>
                    <a href="{{ route('production-bench.production.create') }}" wire:navigate class="sk-btn sk-btn-primary">{{ __('production_bench.production.new') }}</a>
                </div>
            @endif
        </header>

        <section aria-label="{{ __('production_bench.common.search') }}" class="sk-card grid gap-4 p-5 sm:grid-cols-2 lg:grid-cols-4">
            <label class="space-y-2 sm:col-span-2 lg:col-span-2">
                <span class="text-sm font-medium">{{ __('production_bench.common.search') }}</span>
                <input wire:model.live.debounce.300ms="search" class="sk-input w-full" placeholder="{{ __('production_bench.production.search_placeholder') }}">
            </label>
            <label class="space-y-2">
                <span class="text-sm font-medium">{{ __('production_bench.production.status_filter') }}</span>
                <select wire:model.live="status" class="sk-input w-full">
                    <option value="">{{ __('production_bench.production.all_statuses') }}</option>
                    @foreach (\App\ProductionRunStatus::cases() as $statusOption)
                        <option value="{{ $statusOption->value }}">{{ $statusOption->label() }}</option>
                    @endforeach
                </select>
            </label>
            <label class="space-y-2">
                <span class="text-sm font-medium">{{ __('production_bench.production.from_date') }}</span>
                <input wire:model.live="dateFrom" type="date" class="sk-input w-full">
            </label>
            <label class="space-y-2">
                <span class="text-sm font-medium">{{ __('production_bench.production.to_date') }}</span>
                <input wire:model.live="dateTo" type="date" class="sk-input w-full">
            </label>
        </section>

        @error('selectedProductionIds')
            <p role="alert" class="rounded-xl bg-[var(--color-danger-soft)] px-4 py-3 text-sm text-[var(--color-danger-strong)]">{{ $message }}</p>
        @enderror
        @error('scheduleDate')
            <p role="alert" class="rounded-xl bg-[var(--color-danger-soft)] px-4 py-3 text-sm text-[var(--color-danger-strong)]">{{ $message }}</p>
        @enderror

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

                {{-- Desktop table (lg+) --}}
                <div class="hidden lg:block overflow-x-auto">
                    <table class="min-w-full">
                        <thead>
                            <tr class="border-b border-[var(--color-line)] text-left text-xs uppercase tracking-wide text-[var(--color-ink-muted)]">
                                <th class="w-10 px-5 py-4"><span class="sr-only">Select</span></th>
                                <th class="px-5 py-4">{{ __('production_bench.navigation.production_workflow') }}</th>
                                <th class="px-5 py-4">{{ __('production_bench.production.status_filter') }}</th>
                                <th class="px-5 py-4">{{ __('production_bench.production.production_date') }}</th>
                                <th class="px-5 py-4">{{ __('production_bench.settings.batch_size') }}</th>
                                <th class="px-5 py-4">{{ __('production_bench.settings.expected_units') }}</th>
                                <th class="px-5 py-4">{{ __('production_bench.production.tasks') }}</th>
                                <th class="px-5 py-4"><span class="sr-only">{{ __('production_bench.common.actions') }}</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--color-line)]">
                            @foreach ($productions as $production)
                                @php
                                    $partialShortage = '0';
                                    if ($production->status->value === 'scheduled') {
                                        foreach ($production->requirements as $requirement) {
                                            $reserved = '0';
                                            foreach ($requirement->reservations->where('status', \App\StockReservationStatus::Active) as $r) {
                                                $reserved = bcadd($reserved, (string) $r->quantity, 9);
                                            }
                                            $required = $requirement->ingredient_id !== null
                                                ? (string) $requirement->required_mass_grams
                                                : (string) $requirement->required_units;
                                            if (bccomp($reserved, '0', 9) > 0 && bccomp($reserved, $required, 9) < 0) {
                                                $partialShortage = bcadd($partialShortage, bcsub($required, $reserved, 9), 9);
                                            }
                                        }
                                    }
                                @endphp
                                <tr class="transition hover:bg-[var(--color-panel-muted)]">
                                    <td class="px-5 py-4">
                                        <input type="checkbox" wire:model.live="selectedProductionIds" value="{{ $production->id }}" @disabled(! $canMutate || ! in_array($production->status->value, ['scheduled', 'reserved'], true)) aria-label="{{ __('production_bench.production.select_production', ['name' => $production->displayRecipeName()]) }}" style="accent-color: var(--color-accent);" class="h-5 w-5 rounded border-[var(--color-line-strong)]">
                                    </td>
                                    <td class="px-5 py-4 min-w-0">
                                        <a href="{{ route('production-bench.production.show', $production) }}" wire:navigate class="block">
                                            <p class="font-semibold text-[var(--color-ink-strong)]">{{ $production->displayIdentifier() }} · {{ $production->displayRecipeName() }}</p>
                                            <p class="mt-1 text-xs text-[var(--color-ink-soft)]">
                                                <span class="font-medium text-[var(--color-ink-muted)]">{{ __('production_bench.production.planning_reference') }}:</span>
                                                <span class="font-mono">{{ $production->planning_batch_number }}</span>
                                                @if ($production->batch_number)
                                                    · <span class="font-medium text-[var(--color-ink-muted)]">{{ __('production_bench.production.batch_number') }}:</span>
                                                    <span class="font-mono font-semibold text-[var(--color-ink-strong)]">{{ $production->batch_number }}</span>
                                                @endif
                                            </p>
                                        </a>
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="flex flex-wrap items-center gap-1.5">
                                            <span class="inline-block rounded-full px-2.5 py-1 text-xs font-medium {{ $statusColorMap[$production->status->value] ?? 'bg-[var(--color-ink-muted)]/10 text-[var(--color-ink-muted)]' }}">{{ $production->status->label() }}</span>
                                            @if ($partialShortage !== '0')
                                                <span class="inline-block rounded-full bg-[var(--color-warning-soft)] px-2.5 py-1 text-xs font-medium text-[var(--color-warning-strong)]">{{ __('production_bench.production.partially_reserved_short', ['short' => \App\Support\NumberLocale::formatAdaptiveDecimal($partialShortage, 0, 3, auth()->user()?->number_locale)]) }}</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="px-5 py-4 font-mono tabular-nums text-sm text-[var(--color-ink-strong)] whitespace-nowrap">{{ $production->planned_for?->format('Y-m-d') ?? '—' }}</td>
                                    <td class="px-5 py-4 font-mono tabular-nums text-sm text-[var(--color-ink-strong)] whitespace-nowrap">{{ \App\Support\NumberLocale::formatAdaptiveDecimal($production->basis_input_value, 0, 3, auth()->user()?->number_locale) }} {{ $production->basis_input_unit->value }}</td>
                                    <td class="px-5 py-4 font-mono tabular-nums text-sm text-[var(--color-ink-strong)] whitespace-nowrap">{{ \App\Support\NumberLocale::formatDecimal($production->expected_units, 0, auth()->user()?->number_locale) }}</td>
                                    <td class="px-5 py-4 font-mono tabular-nums text-sm text-[var(--color-ink-strong)] whitespace-nowrap">{{ $production->tasks->count() }}</td>
                                    <td class="px-5 py-4">
                                        <div class="flex flex-wrap items-center gap-1.5">
                                            @if ($canMutate && $production->status->value === 'draft')
                                                <input type="date" wire:model="scheduleDates.{{ $production->id }}" class="sk-input w-28 py-1 text-xs" @disabled($mutationLocked)>
                                                <button type="button" wire:click="scheduleProduction({{ $production->id }})" wire:loading.attr="disabled" @disabled($mutationLocked) class="sk-btn sk-btn-primary text-xs">{{ __('production_bench.production.schedule_draft') }}</button>
                                            @endif
                                            @if ($canMutate && in_array($production->status->value, ['draft', 'scheduled'], true))
                                                <button type="button" wire:click.stop="deleteProduction({{ $production->id }})" wire:confirm="{{ __('production_bench.production.delete_confirm') }}" wire:loading.attr="disabled" class="sk-btn sk-btn-danger text-xs">
                                                    {{ __('production_bench.production.delete') }}
                                                </button>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{-- Mobile card stack (<lg) --}}
                <div class="lg:hidden divide-y divide-[var(--color-line)]">
                    @foreach ($productions as $production)
                        @php
                            $partialShortage = '0';
                            if ($production->status->value === 'scheduled') {
                                foreach ($production->requirements as $requirement) {
                                    $reserved = '0';
                                    foreach ($requirement->reservations->where('status', \App\StockReservationStatus::Active) as $r) {
                                        $reserved = bcadd($reserved, (string) $r->quantity, 9);
                                    }
                                    $required = $requirement->ingredient_id !== null
                                        ? (string) $requirement->required_mass_grams
                                        : (string) $requirement->required_units;
                                    if (bccomp($reserved, '0', 9) > 0 && bccomp($reserved, $required, 9) < 0) {
                                        $partialShortage = bcadd($partialShortage, bcsub($required, $reserved, 9), 9);
                                    }
                                }
                            }
                        @endphp
                        <div class="flex gap-4 px-5 py-5 transition hover:bg-[var(--color-panel-muted)] sm:px-6">
                            <div class="pt-1">
                                <input type="checkbox" wire:model.live="selectedProductionIds" value="{{ $production->id }}" @disabled(! $canMutate || ! in_array($production->status->value, ['scheduled', 'reserved'], true)) aria-label="{{ __('production_bench.production.select_production', ['name' => $production->displayRecipeName()]) }}" style="accent-color: var(--color-accent);" class="h-5 w-5 rounded border-[var(--color-line-strong)]">
                            </div>
                            <div class="min-w-0 flex-1">
                                <a href="{{ route('production-bench.production.show', $production) }}" wire:navigate class="block">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h3 class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ $production->displayIdentifier() }} · {{ $production->displayRecipeName() }}</h3>
                                        <span class="inline-block rounded-full px-2.5 py-1 text-xs font-medium {{ $statusColorMap[$production->status->value] ?? 'bg-[var(--color-ink-muted)]/10 text-[var(--color-ink-muted)]' }}">{{ $production->status->label() }}</span>
                                        @if ($partialShortage !== '0')
                                            <span class="inline-block rounded-full bg-[var(--color-warning-soft)] px-2.5 py-1 text-xs font-medium text-[var(--color-warning-strong)]">{{ __('production_bench.production.partially_reserved_short', ['short' => \App\Support\NumberLocale::formatAdaptiveDecimal($partialShortage, 0, 3, auth()->user()?->number_locale)]) }}</span>
                                        @endif
                                    </div>
                                    <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs">
                                        <span class="text-[var(--color-ink-soft)]"><span class="font-medium text-[var(--color-ink-muted)]">{{ __('production_bench.production.planning_reference') }}:</span> <span class="font-mono">{{ $production->planning_batch_number }}</span></span>
                                        @if ($production->batch_number)
                                            <span class="text-[var(--color-ink-soft)]"><span class="font-medium text-[var(--color-ink-muted)]">{{ __('production_bench.production.batch_number') }}:</span> <span class="font-mono font-semibold text-[var(--color-ink-strong)]">{{ $production->batch_number }}</span></span>
                                        @endif
                                    </div>
                                    <dl class="mt-3 grid grid-cols-2 gap-x-8 gap-y-2 text-sm sm:grid-cols-4">
                                        <div><dt class="text-xs uppercase tracking-wide text-[var(--color-ink-muted)]">{{ __('production_bench.production.production_date') }}</dt><dd class="mt-1 font-mono tabular-nums text-[var(--color-ink-strong)]">{{ $production->planned_for?->format('Y-m-d') ?? '—' }}</dd></div>
                                        <div><dt class="text-xs uppercase tracking-wide text-[var(--color-ink-muted)]">{{ __('production_bench.settings.batch_size') }}</dt><dd class="mt-1 font-mono tabular-nums text-[var(--color-ink-strong)]">{{ \App\Support\NumberLocale::formatAdaptiveDecimal($production->basis_input_value, 0, 3, auth()->user()?->number_locale) }} {{ $production->basis_input_unit->value }}</dd></div>
                                        <div><dt class="text-xs uppercase tracking-wide text-[var(--color-ink-muted)]">{{ __('production_bench.settings.expected_units') }}</dt><dd class="mt-1 font-mono tabular-nums text-[var(--color-ink-strong)]">{{ \App\Support\NumberLocale::formatDecimal($production->expected_units, 0, auth()->user()?->number_locale) }}</dd></div>
                                        <div><dt class="text-xs uppercase tracking-wide text-[var(--color-ink-muted)]">{{ __('production_bench.production.tasks') }}</dt><dd class="mt-1 font-mono tabular-nums text-[var(--color-ink-strong)]">{{ $production->tasks->count() }}</dd></div>
                                    </dl>
                                </a>
                                <div class="mt-3 flex flex-wrap items-center gap-2">
                                    @if ($canMutate && $production->status->value === 'draft')
                                        <input type="date" wire:model="scheduleDates.{{ $production->id }}" class="sk-input w-28 py-1 text-xs" @disabled($mutationLocked)>
                                        <button type="button" wire:click="scheduleProduction({{ $production->id }})" wire:loading.attr="disabled" @disabled($mutationLocked) class="sk-btn sk-btn-primary text-xs">{{ __('production_bench.production.schedule_draft') }}</button>
                                    @endif
                                    @if ($canMutate && in_array($production->status->value, ['draft', 'scheduled'], true))
                                        <button type="button" wire:click.stop="deleteProduction({{ $production->id }})" wire:confirm="{{ __('production_bench.production.delete_confirm') }}" wire:loading.attr="disabled" class="sk-btn sk-btn-danger text-xs">
                                            {{ __('production_bench.production.delete') }}
                                        </button>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            @if ($productions->hasPages())
                <div class="border-t border-[var(--color-line)] px-5 py-4 sm:px-6">{{ $productions->links() }}</div>
            @endif
        </section>
    @endif
</x-production-bench.page>
