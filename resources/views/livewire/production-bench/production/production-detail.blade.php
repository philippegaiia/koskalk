<x-production-bench.page compact>
    @if (! $isBenchActive && ! $isReadOnly)
        <section class="sk-card p-8 text-center">
            <h1 class="text-3xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.common.inactive') }}</h1>
            <a href="{{ route('production-bench.home') }}" wire:navigate class="mt-4 inline-block text-sm font-medium text-[var(--color-accent)]">{{ __('production_bench.title') }}</a>
        </section>
    @endif

    @if ($isBenchActive || $isReadOnly)
        @if ($isReadOnly)
            <p role="status" class="rounded-xl bg-[var(--color-warning-soft)] px-4 py-3 text-sm text-[var(--color-warning-strong)]">{{ __('production_bench.common.read_only') }}</p>
        @endif

    <header class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <a href="{{ route('production-bench.production.index') }}" wire:navigate class="text-sm font-medium text-[var(--color-accent-strong)] hover:underline">← {{ __('production_bench.production.back_to_list') }}</a>
            <div class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-1">
                <p class="sk-eyebrow">{{ $production->displayIdentifier() }}</p>
                <p class="font-mono text-xs text-[var(--color-ink-soft)]">{{ $production->public_id }}</p>
            </div>
            <h1 class="mt-2 text-3xl font-semibold text-[var(--color-ink-strong)]">{{ $production->recipe?->name ?? __('production_bench.production.unknown_product') }}</h1>
            <p class="mt-2 text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.production.detail_title') }}</p>
        </div>
        <span class="rounded-full bg-[var(--color-accent-soft)] px-3 py-1.5 text-sm font-medium text-[var(--color-accent-strong)]">{{ $production->status->label() }}</span>
    </header>

    <section class="sk-card grid gap-5 p-5 sm:grid-cols-2 lg:grid-cols-4" aria-labelledby="batch-identity-heading">
        <h2 id="batch-identity-heading" class="sr-only">{{ __('production_bench.production.batch_identity') }}</h2>
        <div>
            <p class="text-xs uppercase tracking-wide text-[var(--color-ink-muted)]">{{ __('production_bench.production.batch_number') }}</p>
            <p class="mt-1 font-mono tabular-nums text-[var(--color-ink-strong)]">{{ $production->batch_number ?? '—' }}</p>
        </div>
        <div>
            <p class="text-xs uppercase tracking-wide text-[var(--color-ink-muted)]">{{ __('production_bench.production.planning_reference') }}</p>
            <p class="mt-1 font-mono tabular-nums text-[var(--color-ink-strong)]">{{ $production->planning_batch_number }}</p>
        </div>
        @if ($production->batch_number_assigned_at)
            <div>
                <p class="text-xs uppercase tracking-wide text-[var(--color-ink-muted)]">{{ __('production_bench.production.batch_number_assigned_at') }}</p>
                <p class="mt-1 font-mono tabular-nums text-[var(--color-ink-strong)]">{{ $production->batch_number_assigned_at->format('Y-m-d H:i') }}</p>
            </div>
            <div>
                <p class="text-xs uppercase tracking-wide text-[var(--color-ink-muted)]">{{ __('production_bench.production.batch_number_assigned_by') }}</p>
                <p class="mt-1 text-[var(--color-ink-strong)]">{{ $production->batchNumberAssignedBy?->name ?? '—' }}</p>
            </div>
        @endif
    </section>

    @if ($canMutate && $production->batch_number === null && in_array($production->status->value, ['scheduled', 'reserved'], true) && $production->planned_for)
        <section class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-[var(--color-accent)] bg-[var(--color-accent-soft)] p-4" aria-labelledby="assign-batch-heading">
            <div>
                <h2 id="assign-batch-heading" class="font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.production.assign_batch_number') }}</h2>
                <p class="mt-1 text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.production.assign_batch_number_help') }}</p>
            </div>
            <button type="button" wire:click="assignBatchNumber" wire:confirm="{{ __('production_bench.production.assign_batch_number_confirm') }}" wire:loading.attr="disabled" wire:target="assignBatchNumber" class="sk-btn sk-btn-primary">{{ __('production_bench.production.assign_batch_number') }}</button>
        </section>
    @endif

    @error('production_bench')
        <p role="alert" class="rounded-xl bg-[var(--color-danger-soft)] px-4 py-3 text-sm text-[var(--color-danger-strong)]">{{ $message }}</p>
    @enderror

    @if (in_array($production->status->value, ['scheduled', 'reserved'], true))
        <section class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-[var(--color-accent)] bg-[var(--color-accent-soft)] p-4">
            <div>
                <p class="font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.production.prepare_stock') }}</p>
                <p class="mt-1 text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.production.prepare_stock_help_short') }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @if ($production->status->value === 'reserved')
                    <button type="button" wire:click="releaseStock" wire:loading.attr="disabled" @disabled($isReadOnly) class="sk-btn sk-btn-ghost">{{ __('production_bench.production.release_stock') }}</button>
                @endif
                <a href="{{ route('production-bench.production.prepare', $production) }}" wire:navigate class="sk-btn sk-btn-primary">{{ __('production_bench.production.prepare_stock') }}</a>
            </div>
        </section>
        @error('production')
            <p role="alert" class="rounded-xl bg-[var(--color-danger-soft)] px-4 py-3 text-sm text-[var(--color-danger-strong)]">{{ $message }}</p>
        @enderror
    @endif

    <section class="sk-card grid gap-5 p-5 sm:grid-cols-2 lg:grid-cols-4">
        <div><p class="text-xs uppercase tracking-wide text-[var(--color-ink-muted)]">{{ __('production_bench.production.production_date') }}</p><p class="mt-1 font-mono tabular-nums text-[var(--color-ink-strong)]">{{ $production->planned_for?->format('Y-m-d') ?? '—' }}</p></div>
        <div><p class="text-xs uppercase tracking-wide text-[var(--color-ink-muted)]">{{ __('production_bench.settings.batch_size') }}</p><p class="mt-1 font-mono tabular-nums text-[var(--color-ink-strong)]">{{ \App\Support\NumberLocale::formatAdaptiveDecimal($production->basis_input_value, 0, 3, auth()->user()?->number_locale) }} {{ $production->basis_input_unit->value }}</p></div>
        <div><p class="text-xs uppercase tracking-wide text-[var(--color-ink-muted)]">{{ __('production_bench.settings.expected_units') }}</p><p class="mt-1 font-mono tabular-nums text-[var(--color-ink-strong)]">{{ $production->expected_units }}</p></div>
        <div><p class="text-xs uppercase tracking-wide text-[var(--color-ink-muted)]">{{ __('production_bench.production.version') }}</p><p class="mt-1 font-mono tabular-nums text-[var(--color-ink-strong)]">{{ $production->recipeVersion?->version_number ?? '—' }}</p></div>
    </section>

    @if ($production->notes)
        <p class="sk-card p-5 text-sm text-[var(--color-ink-soft)]">{{ $production->notes }}</p>
    @endif

    <section aria-labelledby="requirements-detail-heading" class="sk-card overflow-hidden">
        <div class="border-b border-[var(--color-line)] p-5 sm:p-6"><h2 id="requirements-detail-heading" class="text-xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.production.requirements') }}</h2></div>
        <div class="divide-y divide-[var(--color-line)]">
            @forelse ($production->requirements as $requirement)
                <div class="flex flex-col gap-2 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                    <div><p class="font-medium text-[var(--color-ink-strong)]">{{ $requirement->subject_name_snapshot }}</p><p class="text-xs text-[var(--color-ink-soft)]">{{ $requirement->percentage_snapshot ? $requirement->percentage_snapshot.'%' : __('production_bench.production.packaging_requirement') }}</p></div>
                    <p class="font-mono tabular-nums text-[var(--color-ink-strong)]">{{ $requirement->kind->value === 'ingredient' ? $requirement->required_mass_grams.' g' : $requirement->required_units.' '.__('production_bench.inventory.units') }}</p>
                </div>
            @empty
                <p class="p-8 text-center text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.production.no_requirements') }}</p>
            @endforelse
        </div>
    </section>

    <section aria-labelledby="tasks-detail-heading" class="sk-card overflow-hidden">
        <div class="border-b border-[var(--color-line)] p-5 sm:p-6"><h2 id="tasks-detail-heading" class="text-xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.production.tasks') }}</h2></div>
        <div class="divide-y divide-[var(--color-line)]">
            @forelse ($production->tasks as $task)
                <div class="flex flex-col gap-2 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                    <div class="min-w-0">
                        <p class="font-medium text-[var(--color-ink-strong)]">{{ $task->name_snapshot }}</p>
                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <select wire:change="assignTask({{ $task->id }}, $event.target.value)" class="sk-input min-w-48 py-1.5 text-sm" @disabled($isReadOnly || in_array($production->status->value, ['in_production', 'completed', 'cancelled', 'aborted'], true))>
                                <option value="">{{ __('production_bench.production.choose_employee') }}</option>
                                @foreach ($employees as $employee)
                                    <option value="{{ $employee->id }}" @selected($task->employee_id === $employee->id)>{{ $employee->first_name }} {{ $employee->last_name }}</option>
                                @endforeach
                            </select>
                            @if ($task->employee)
                                <span class="text-xs text-[var(--color-ink-soft)]">{{ $task->employee->first_name.' '.$task->employee->last_name }}</span>
                            @endif
                            @error('task_employee') <span class="text-xs text-[var(--color-danger-strong)]">{{ $message }}</span> @enderror
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-3 sm:justify-end">
                        <p class="font-mono tabular-nums text-[var(--color-ink-strong)]">{{ $task->scheduled_for->format('Y-m-d') }} @if($task->completed_at) · {{ __('production_bench.production.completed_task') }} @endif</p>
                        <button type="button" wire:click="toggleTask({{ $task->id }})" wire:loading.attr="disabled" @disabled($isReadOnly || in_array($production->status->value, ['completed', 'cancelled', 'aborted'], true)) class="sk-btn sk-btn-ghost py-1.5 text-sm">
                            {{ $task->completed_at ? __('production_bench.production.reopen_task') : __('production_bench.production.mark_complete') }}
                        </button>
                    </div>
                </div>
            @empty
                <p class="p-8 text-center text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.production.no_tasks') }}</p>
            @endforelse
        </div>
    </section>

    @if (in_array($production->status->value, ['draft', 'scheduled', 'reserved'], true))
        <section aria-labelledby="cancel-production-heading" class="sk-card space-y-4 p-5 sm:p-6">
            <div><h2 id="cancel-production-heading" class="text-xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.production.cancel') }}</h2><p class="mt-1 text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.production.cancel_help') }}</p></div>
            <form wire:submit="cancel" class="space-y-3">
                <label class="block text-sm"><span class="font-medium">{{ __('production_bench.production.cancel_reason') }}</span><textarea wire:model="cancellationReason" rows="2" required @disabled($isReadOnly) class="sk-input mt-1 w-full"></textarea>@error('cancellationReason')<span class="mt-1 block text-xs text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror</label>
                <button type="submit" wire:loading.attr="disabled" @disabled($isReadOnly) class="sk-btn sk-btn-ghost">{{ __('production_bench.production.cancel') }}</button>
            </form>
        </section>
    @endif
    @endif
</x-production-bench.page>
