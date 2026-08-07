<x-production-bench.page compact>
    @if (! $isBenchActive && ! $isReadOnly)
        <section class="sk-card p-8 text-center">
            <h1 class="text-3xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.common.inactive') }}</h1>
            <a href="{{ route('production-bench.home') }}" wire:navigate class="mt-4 inline-block text-sm font-medium text-[var(--color-accent)]">{{ __('production_bench.title') }}</a>
        </section>
    @else
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

        <section aria-labelledby="production-list-heading" class="sk-card overflow-hidden">
            <h2 id="production-list-heading" class="sr-only">{{ __('production_bench.production.index_title') }}</h2>
            <div class="divide-y divide-[var(--color-line)]">
                @forelse ($productions as $production)
                    <div class="flex gap-4 px-5 py-5 transition hover:bg-[var(--color-panel-muted)] sm:px-6">
                        <div class="pt-1">
                            <input type="checkbox" wire:model.live="selectedProductionIds" value="{{ $production->id }}" @disabled(! $canMutate || ! in_array($production->status->value, ['scheduled', 'reserved'], true)) aria-label="{{ __('production_bench.production.select_production', ['name' => $production->displayRecipeName()]) }}" style="accent-color: var(--color-accent);" class="h-5 w-5 rounded border-[var(--color-line-strong)]">
                        </div>
                        <a href="{{ route('production-bench.production.show', $production) }}" wire:navigate class="min-w-0 flex-1">
                            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ $production->displayIdentifier() }} · {{ $production->displayRecipeName() }}</h3>
                                    <span class="rounded-full bg-[var(--color-accent-soft)] px-2.5 py-1 text-xs font-medium text-[var(--color-accent-strong)]">{{ $production->status->label() }}</span>
                                </div>
                                <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs">
                                    <span class="text-[var(--color-ink-soft)]"><span class="font-medium text-[var(--color-ink-muted)]">{{ __('production_bench.production.planning_reference') }}:</span> <span class="font-mono">{{ $production->planning_batch_number }}</span></span>
                                    @if ($production->batch_number)
                                        <span class="text-[var(--color-ink-soft)]"><span class="font-medium text-[var(--color-ink-muted)]">{{ __('production_bench.production.batch_number') }}:</span> <span class="font-mono font-semibold text-[var(--color-ink-strong)]">{{ $production->batch_number }}</span></span>
                                    @endif
                                    @if ($canMutate && in_array($production->status->value, ['draft', 'scheduled'], true))
                                        <button type="button" wire:click="deleteProduction({{ $production->id }})" wire:confirm="{{ __('production_bench.production.delete_confirm') }}" wire:loading.attr="disabled" class="text-[var(--color-danger-strong)] hover:underline">
                                            {{ __('production_bench.production.delete') }}
                                        </button>
                                    @endif
                                </div>
                            </div>
                            <dl class="grid grid-cols-2 gap-x-8 gap-y-2 text-sm sm:grid-cols-4">
                                <div><dt class="text-xs uppercase tracking-wide text-[var(--color-ink-muted)]">{{ __('production_bench.production.production_date') }}</dt><dd class="mt-1 font-mono tabular-nums text-[var(--color-ink-strong)]">{{ $production->planned_for?->format('Y-m-d') ?? '—' }}</dd></div>
                                <div><dt class="text-xs uppercase tracking-wide text-[var(--color-ink-muted)]">{{ __('production_bench.settings.batch_size') }}</dt><dd class="mt-1 font-mono tabular-nums text-[var(--color-ink-strong)]">{{ \App\Support\NumberLocale::formatAdaptiveDecimal($production->basis_input_value, 0, 3, auth()->user()?->number_locale) }} {{ $production->basis_input_unit->value }}</dd></div>
                                <div><dt class="text-xs uppercase tracking-wide text-[var(--color-ink-muted)]">{{ __('production_bench.settings.expected_units') }}</dt><dd class="mt-1 font-mono tabular-nums text-[var(--color-ink-strong)]">{{ $production->expected_units }}</dd></div>
                                <div><dt class="text-xs uppercase tracking-wide text-[var(--color-ink-muted)]">{{ __('production_bench.production.tasks') }}</dt><dd class="mt-1 font-mono tabular-nums text-[var(--color-ink-strong)]">{{ $production->tasks->count() }}</dd></div>
                            </dl>
                            </div>
                        </a>
                    </div>
                @empty
                    <p class="p-10 text-center text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.production.no_productions') }}</p>
                @endforelse
            </div>
            @if ($productions->hasPages())
                <div class="border-t border-[var(--color-line)] px-5 py-4 sm:px-6">{{ $productions->links() }}</div>
            @endif
        </section>
    @endif
</x-production-bench.page>
