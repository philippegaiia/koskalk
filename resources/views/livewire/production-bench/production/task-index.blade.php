<x-production-bench.page>
    <header class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="sk-eyebrow">{{ __('production_bench.navigation.production_workflow') }}</p>
            <h1 class="mt-2 text-3xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.navigation.tasks') }}</h1>
            <p class="mt-2 max-w-2xl text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.production.task_list_help') }}</p>
        </div>
        <a href="{{ route('production-bench.production.calendar') }}" wire:navigate class="sk-btn sk-btn-ghost">{{ __('production_bench.navigation.calendar') }}</a>
    </header>

    @if ($isReadOnly)
        <p role="status" class="rounded-xl bg-[var(--color-warning-soft)] px-4 py-3 text-sm text-[var(--color-warning-strong)]">{{ __('production_bench.common.read_only') }}</p>
    @elseif (! $isBenchActive)
        <p role="status" class="rounded-xl bg-[var(--color-panel-muted)] px-4 py-3 text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.common.inactive') }}</p>
    @endif

    <section aria-label="{{ __('production_bench.common.filters') }}" class="sk-card space-y-4 p-5">
        <div class="flex flex-wrap gap-2" role="tablist" aria-label="{{ __('production_bench.production.task_scopes') }}">
            @foreach (['today' => __('production_bench.production.today'), 'upcoming' => __('production_bench.production.upcoming'), 'overdue' => __('production_bench.production.overdue'), 'completed' => __('production_bench.production.completed_task'), 'all' => __('production_bench.common.all')] as $value => $label)
                <button type="button" wire:click="$set('scope', '{{ $value }}')" role="tab" aria-selected="{{ $scope === $value ? 'true' : 'false' }}" class="sk-btn {{ $scope === $value ? 'sk-btn-primary' : 'sk-btn-ghost' }}">{{ $label }}</button>
            @endforeach
        </div>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
            <label class="text-sm lg:col-span-2"><span class="font-medium">{{ __('production_bench.common.search') }}</span><input wire:model.live.debounce.300ms="search" class="sk-input mt-1 w-full" placeholder="{{ __('production_bench.production.task_search_placeholder') }}"></label>
            <label class="text-sm"><span class="font-medium">{{ __('production_bench.common.status') }}</span><select wire:model.live="status" class="sk-input mt-1 w-full"><option value="open">{{ __('production_bench.production.open_tasks') }}</option><option value="completed">{{ __('production_bench.production.completed_task') }}</option><option value="all">{{ __('production_bench.common.all') }}</option></select></label>
            <label class="text-sm"><span class="font-medium">{{ __('production_bench.production.choose_department') }}</span><select wire:model.live="departmentId" class="sk-input mt-1 w-full"><option value="">{{ __('production_bench.common.all') }}</option>@foreach ($departments as $department)<option value="{{ $department->id }}">{{ $department->name }}{{ $department->is_active ? '' : ' · '.__('production_bench.common.inactive') }}</option>@endforeach</select></label>
            <label class="text-sm"><span class="font-medium">{{ __('production_bench.production.choose_employee') }}</span><select wire:model.live="employeeId" class="sk-input mt-1 w-full"><option value="">{{ __('production_bench.common.all') }}</option>@foreach ($employees as $employee)<option value="{{ $employee->id }}">{{ $employee->first_name }} {{ $employee->last_name }}{{ $employee->is_active ? '' : ' · '.__('production_bench.common.inactive') }}</option>@endforeach</select></label>
        </div>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-[12rem_12rem_auto] lg:items-end">
            <label class="text-sm"><span class="font-medium">{{ __('production_bench.production.from_date') }}</span><input wire:model.live="fromDate" type="date" class="sk-input mt-1 w-full"></label>
            <label class="text-sm"><span class="font-medium">{{ __('production_bench.production.to_date') }}</span><input wire:model.live="toDate" type="date" class="sk-input mt-1 w-full"></label>
            <button type="button" wire:click="clearFilters" class="sk-btn sk-btn-ghost lg:justify-self-end">{{ __('production_bench.common.clear') }}</button>
        </div>
    </section>

    <section aria-labelledby="production-task-list-heading" class="sk-card overflow-hidden">
        <div class="border-b border-[var(--color-line)] p-5 sm:p-6"><h2 id="production-task-list-heading" class="text-xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.production.tasks') }}</h2></div>
        @error('task_task') <p role="alert" class="border-b border-[var(--color-line)] px-5 py-3 text-sm text-[var(--color-danger-strong)] sm:px-6">{{ $message }}</p> @enderror
        <div class="divide-y divide-[var(--color-line)]">
            @forelse ($tasks as $task)
                @php($terminal = in_array($task->productionRun?->status?->value, ['completed', 'cancelled', 'aborted'], true))
                <article wire:key="production-task-{{ $task->id }}" class="grid gap-4 p-5 lg:grid-cols-[9rem_minmax(12rem,1.3fr)_minmax(10rem,1fr)_minmax(10rem,1fr)_minmax(10rem,1fr)_auto] lg:items-center">
                    <div><p class="font-mono tabular-nums text-sm text-[var(--color-ink-strong)]">{{ $task->scheduled_for->format('Y-m-d') }}</p><p class="mt-1 text-xs text-[var(--color-ink-soft)]">{{ $task->completed_at ? __('production_bench.production.completed_task') : $task->productionRun?->status?->label() }}</p></div>
                    <div><p class="font-medium text-[var(--color-ink-strong)]">{{ $task->name_snapshot }}</p><a href="{{ route('production-bench.production.show', $task->productionRun) }}" wire:navigate class="mt-1 inline-block text-xs text-[var(--color-accent-strong)] hover:underline">{{ $task->productionRun?->public_id }}</a></div>
                    <div><p class="text-xs uppercase tracking-wide text-[var(--color-ink-muted)]">{{ __('production_bench.production.product') }}</p><p class="mt-1 text-sm text-[var(--color-ink-soft)]">{{ $task->productionRun?->recipe?->name ?? __('production_bench.production.unknown_product') }}</p></div>
                    <label class="text-sm"><span class="sr-only">{{ __('production_bench.production.choose_department') }}</span><select wire:change="assignDepartment({{ $task->id }}, $event.target.value)" class="sk-input w-full py-1.5 text-sm" @disabled($isReadOnly || $terminal)><option value="">{{ __('production_bench.production.unassigned') }}</option>@foreach ($departments->where('is_active', true) as $department)<option value="{{ $department->id }}" @selected($task->department_id === $department->id)>{{ $department->name }}</option>@endforeach</select></label>
                    <label class="text-sm"><span class="sr-only">{{ __('production_bench.production.choose_employee') }}</span><select wire:change="assignEmployee({{ $task->id }}, $event.target.value)" class="sk-input w-full py-1.5 text-sm" @disabled($isReadOnly || $terminal)><option value="">{{ __('production_bench.production.unassigned') }}</option>@foreach ($employees->where('is_active', true) as $employee)<option value="{{ $employee->id }}" @selected($task->employee_id === $employee->id)>{{ $employee->first_name }} {{ $employee->last_name }}</option>@endforeach</select></label>
                    <button type="button" wire:click="toggleTask({{ $task->id }})" wire:loading.attr="disabled" class="sk-btn sk-btn-ghost whitespace-nowrap" @disabled($isReadOnly || $terminal)>{{ $task->completed_at ? __('production_bench.production.reopen_task') : __('production_bench.production.mark_complete') }}</button>
                </article>
            @empty
                <p class="p-8 text-center text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.production.no_tasks_match') }}</p>
            @endforelse
        </div>
        @if ($tasks->hasPages())
            <div class="border-t border-[var(--color-line)] px-5 py-4">{{ $tasks->links() }}</div>
        @endif
    </section>
</x-production-bench.page>
