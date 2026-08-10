<x-production-bench.page productionSetup>
    @php($numberLocale = auth()->user()?->number_locale)
    <header class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="sk-eyebrow">{{ __('production_bench.navigation.production') }}</p>
            <h1 class="mt-2 text-3xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.settings.title') }}</h1>
            <p class="mt-2 max-w-2xl text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.settings.intro') }}</p>
        </div>
        @if ($isReadOnly)
            <p role="status" class="rounded-xl bg-[var(--color-warning-soft)] px-4 py-3 text-sm text-[var(--color-warning-strong)]">{{ __('production_bench.common.read_only') }}</p>
        @elseif (! $isBenchActive)
            <p role="status" class="rounded-xl bg-[var(--color-panel-muted)] px-4 py-3 text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.common.inactive') }}</p>
        @endif
    </header>

    @if (in_array($section, ['all', 'presets'], true))
    <section aria-labelledby="preset-heading" class="space-y-4">
        <div class="flex flex-col gap-4 rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] p-5 shadow-sm sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 id="preset-heading" class="text-xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.settings.presets') }}</h2>
                <p class="mt-1 text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.settings.presets_help') }}</p>
            </div>
            <a href="{{ route('production-bench.production.settings.presets') }}" wire:navigate class="sk-btn sk-btn-primary">{{ __('production_bench.settings.manage_batch_sizes') }}</a>
        </div>
    </section>
    @endif

    @if ($section === 'all')
    <section aria-labelledby="ready-date-heading" class="space-y-4">
        <div>
            <h2 id="ready-date-heading" class="text-xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.settings.ready_dates') }}</h2>
            <p class="mt-1 text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.settings.ready_dates_help') }}</p>
        </div>
        <form wire:submit="saveOutputSettings" class="sk-card grid gap-4 p-5 sm:grid-cols-2 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] lg:items-end">
            <label class="text-sm">
                <span class="font-medium">{{ __('production_bench.settings.soap_ready_delay_days') }}</span>
                <input wire:model="soapReadyDelayDays" type="number" min="0" step="1" class="sk-input mt-1 w-full" @disabled(! $isBenchActive || $isReadOnly)>
                @error('soapReadyDelayDays')<span class="mt-1 block text-xs text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror
            </label>
            <label class="text-sm">
                <span class="font-medium">{{ __('production_bench.settings.cosmetic_ready_delay_days') }}</span>
                <input wire:model="cosmeticReadyDelayDays" type="number" min="0" step="1" class="sk-input mt-1 w-full" @disabled(! $isBenchActive || $isReadOnly)>
                @error('cosmeticReadyDelayDays')<span class="mt-1 block text-xs text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror
            </label>
            <button type="submit" class="sk-btn sk-btn-primary" @disabled(! $isBenchActive || $isReadOnly)>{{ __('production_bench.common.save_changes') }}</button>
        </form>
    </section>
    @endif

    @if (in_array($section, ['all', 'departments'], true))
    <section aria-labelledby="department-heading" class="space-y-4">
        <div>
            <h2 id="department-heading" class="text-xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.settings.departments') }}</h2>
            <p class="mt-1 text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.settings.departments_help') }}</p>
        </div>
        <form wire:submit="saveDepartment" class="sk-card grid gap-4 p-5 sm:grid-cols-[minmax(0,1fr)_auto_auto] sm:items-end">
            <label class="text-sm">
                <span class="font-medium">{{ __('production_bench.settings.department_name') }}</span>
                <input wire:model="departmentName" class="sk-input mt-1 w-full" placeholder="Production" @disabled(! $isBenchActive || $isReadOnly)>
                @error('departmentName')<span class="mt-1 block text-xs text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror
            </label>
            <label class="flex items-center gap-2 text-sm sm:pb-3">
                <input wire:model="departmentIsActive" type="checkbox" class="size-4 rounded accent-[var(--color-accent)]" @disabled(! $isBenchActive || $isReadOnly)>
                {{ __('production_bench.common.active') }}
            </label>
            <div class="flex gap-2 sm:justify-self-end">
                <button type="button" wire:click="resetDepartmentForm" class="sk-btn sk-btn-ghost" @disabled(! $isBenchActive || $isReadOnly)>{{ __('production_bench.common.clear') }}</button>
                <button type="submit" class="sk-btn sk-btn-primary" @disabled(! $isBenchActive || $isReadOnly)>{{ $editingDepartmentId ? __('production_bench.common.save_changes') : __('production_bench.settings.add_department') }}</button>
            </div>
        </form>
        <div class="sk-card divide-y divide-[var(--color-line)] p-1">
            @forelse ($departments as $department)
                <div wire:key="department-{{ $department->id }}" class="grid items-center gap-3 p-4 sm:grid-cols-[minmax(0,1fr)_auto_auto]">
                    <span>
                        <span class="block font-medium text-[var(--color-ink-strong)]">{{ $department->name }}</span>
                        <span class="text-xs text-[var(--color-ink-soft)]">{{ trans_choice('production_bench.settings.department_usage', $department->employees_count + $department->production_task_types_count + $department->production_tasks_count, ['employees' => $department->employees_count, 'tasks' => $department->production_tasks_count]) }}</span>
                    </span>
                    <span class="text-sm text-[var(--color-ink-soft)]">{{ $department->is_active ? __('production_bench.common.active') : __('production_bench.common.inactive') }}</span>
                    <span class="flex items-center gap-3">
                        <button type="button" wire:click="editDepartment({{ $department->id }})" class="text-sm text-[var(--color-accent-strong)] hover:underline" @disabled(! $isBenchActive || $isReadOnly)>{{ __('production_bench.common.edit') }}</button>
                        <button type="button" wire:click="deleteDepartment({{ $department->id }})" wire:confirm="{{ __('production_bench.settings.delete_department_confirm') }}" class="text-sm text-[var(--color-danger-strong)] hover:underline" @disabled(! $isBenchActive || $isReadOnly)>{{ __('production_bench.common.delete') }}</button>
                    </span>
                </div>
            @empty
                <p class="p-5 text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.settings.no_departments') }}</p>
            @endforelse
        </div>
    </section>
    @endif

    @if (in_array($section, ['all', 'employees', 'task-types'], true))
    <div @class([
        'grid gap-8',
        'lg:grid-cols-2' => $section === 'all',
    ])>
        @if (in_array($section, ['all', 'employees'], true))
        <section aria-labelledby="employee-heading" class="space-y-4">
            <div><h2 id="employee-heading" class="text-xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.settings.employees') }}</h2><p class="mt-1 text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.settings.employees_help') }}</p></div>
            <form wire:submit="saveEmployee" class="sk-card grid gap-4 p-5 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(13rem,1fr)_auto_auto] lg:items-end">
                <label class="text-sm"><span class="font-medium">{{ __('production_bench.settings.first_name') }}</span><input wire:model="employeeFirstName" class="sk-input mt-1 w-full" autocomplete="given-name" @disabled(! $isBenchActive || $isReadOnly)>@error('employeeFirstName')<span class="mt-1 block text-xs text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror</label>
                <label class="text-sm"><span class="font-medium">{{ __('production_bench.settings.last_name') }}</span><input wire:model="employeeLastName" class="sk-input mt-1 w-full" autocomplete="family-name" @disabled(! $isBenchActive || $isReadOnly)>@error('employeeLastName')<span class="mt-1 block text-xs text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror</label>
                <label class="text-sm"><span class="font-medium">{{ __('production_bench.settings.employee_title') }}</span><input wire:model="employeeTitle" class="sk-input mt-1 w-full" autocomplete="organization-title" @disabled(! $isBenchActive || $isReadOnly)>@error('employeeTitle')<span class="mt-1 block text-xs text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror</label>
                <label class="text-sm"><span class="font-medium">{{ __('production_bench.settings.employee_departments') }}</span><select wire:model="employeeDepartmentIds" multiple size="3" class="sk-input mt-1 w-full" @disabled(! $isBenchActive || $isReadOnly)>@foreach ($departments->where('is_active', true) as $department)<option value="{{ $department->id }}">{{ $department->name }}</option>@endforeach</select>@error('employeeDepartmentIds')<span class="mt-1 block text-xs text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror</label>
                <label class="flex items-center gap-2 text-sm lg:self-end lg:whitespace-nowrap lg:pb-5"><input wire:model="employeeIsActive" type="checkbox" class="size-4 rounded accent-[var(--color-accent)]" @disabled(! $isBenchActive || $isReadOnly)>{{ __('production_bench.common.active') }}</label>
                <div class="flex gap-2 lg:justify-self-end"><button type="button" wire:click="resetEmployeeForm" class="sk-btn sk-btn-ghost" @disabled(! $isBenchActive || $isReadOnly)>{{ __('production_bench.common.clear') }}</button><button type="submit" class="sk-btn sk-btn-primary" @disabled(! $isBenchActive || $isReadOnly)>{{ $editingEmployeeId ? __('production_bench.common.save_changes') : __('production_bench.settings.add_employee') }}</button></div>
            </form>
            <div class="sk-card divide-y divide-[var(--color-line)] p-1">@forelse ($employees as $employee)<div wire:key="employee-{{ $employee->id }}" class="grid items-center gap-3 p-4 sm:grid-cols-[minmax(0,1fr)_minmax(10rem,1fr)_auto_auto]"><span><span class="block font-medium text-[var(--color-ink-strong)]">{{ $employee->first_name }} {{ $employee->last_name }}</span><span class="text-xs text-[var(--color-ink-soft)]">{{ $employee->title ?: __('production_bench.settings.no_title') }}</span></span><span class="text-sm text-[var(--color-ink-soft)]">{{ $employee->departments->pluck('name')->join(', ') ?: __('production_bench.settings.no_departments_assigned') }}</span><span class="text-sm text-[var(--color-ink-soft)]">{{ $employee->is_active ? __('production_bench.common.active') : __('production_bench.common.inactive') }}</span><span class="flex items-center gap-3"><button type="button" wire:click="editEmployee({{ $employee->id }})" class="text-sm text-[var(--color-accent-strong)] hover:underline" @disabled(! $isBenchActive || $isReadOnly)>{{ __('production_bench.common.edit') }}</button><button type="button" wire:click="deleteEmployee({{ $employee->id }})" wire:confirm="{{ __('production_bench.settings.delete_employee_confirm') }}" class="text-sm text-[var(--color-danger-strong)] hover:underline" @disabled(! $isBenchActive || $isReadOnly)>{{ __('production_bench.common.delete') }}</button></span></div>@empty<p class="p-5 text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.settings.no_employees') }}</p>@endforelse</div>
        </section>
        @endif

        @if (in_array($section, ['all', 'task-types'], true))
        <section aria-labelledby="task-type-heading" class="space-y-4">
            <div><h2 id="task-type-heading" class="text-xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.settings.task_types') }}</h2><p class="mt-1 text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.settings.task_types_help') }}</p></div>
            <form wire:submit="saveTaskType" class="sk-card grid gap-4 p-5">
                <label class="text-sm"><span class="font-medium">{{ __('production_bench.settings.task_name') }}</span><input wire:model="taskTypeName" class="sk-input mt-1 w-full" placeholder="Pour and cut" @disabled(! $isBenchActive || $isReadOnly)>@error('taskTypeName')<span class="mt-1 block text-xs text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror</label>
                <label class="text-sm"><span class="font-medium">{{ __('production_bench.settings.duration_minutes') }}</span><input wire:model="taskTypeDuration" type="number" min="0" step="1" class="sk-input mt-1 w-full" @disabled(! $isBenchActive || $isReadOnly)></label>
                <label class="text-sm"><span class="font-medium">{{ __('production_bench.settings.colour') }}</span><span class="mt-1 flex items-center gap-3"><input wire:model="taskTypeColour" type="color" class="h-11 w-16 cursor-pointer rounded-lg border border-[var(--color-line)] bg-[var(--color-panel)] p-1" @disabled(! $isBenchActive || $isReadOnly)><button type="button" wire:click="$set('taskTypeColour', '')" class="text-xs text-[var(--color-accent-strong)] hover:underline" @disabled(! $isBenchActive || $isReadOnly)>{{ __('production_bench.settings.no_colour') }}</button></span></label>
                <label class="text-sm"><span class="font-medium">{{ __('production_bench.settings.default_department') }}</span><select wire:model="taskTypeDepartmentId" class="sk-input mt-1 w-full" @disabled(! $isBenchActive || $isReadOnly)><option value="">{{ __('production_bench.settings.no_default_department') }}</option>@foreach ($departments->where('is_active', true) as $department)<option value="{{ $department->id }}">{{ $department->name }}</option>@endforeach</select>@error('taskTypeDepartmentId')<span class="mt-1 block text-xs text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror</label>
                <div class="flex items-center gap-2 text-sm"><input wire:model="taskTypeIsActive" type="checkbox" class="size-4 rounded accent-[var(--color-accent)]" @disabled(! $isBenchActive || $isReadOnly)>{{ __('production_bench.common.active') }}</div>
                <div class="flex gap-2"><button type="button" wire:click="resetTaskTypeForm" class="sk-btn sk-btn-ghost" @disabled(! $isBenchActive || $isReadOnly)>{{ __('production_bench.common.clear') }}</button><button type="submit" class="sk-btn sk-btn-primary" @disabled(! $isBenchActive || $isReadOnly)>{{ $editingTaskTypeId ? __('production_bench.common.save_changes') : __('production_bench.settings.add_task_type') }}</button></div>
            </form>
            <div class="sk-card divide-y divide-[var(--color-line)] p-1">@forelse ($taskTypes as $taskType)<div wire:key="task-type-{{ $taskType->id }}" class="flex items-center justify-between gap-3 p-4"><span><span class="block font-medium text-[var(--color-ink-strong)]">{{ $taskType->name }}</span><span class="text-xs text-[var(--color-ink-soft)]">{{ $taskType->default_duration_minutes ? $taskType->default_duration_minutes.' min · ' : '' }}{{ $taskType->department?->name ? $taskType->department->name.' · ' : '' }}{{ $taskType->is_active ? __('production_bench.common.active') : __('production_bench.common.inactive') }}</span></span><span class="flex items-center gap-3"><button type="button" wire:click="editTaskType({{ $taskType->id }})" class="text-sm text-[var(--color-accent-strong)] hover:underline" @disabled(! $isBenchActive || $isReadOnly)>{{ __('production_bench.common.edit') }}</button><button type="button" wire:click="deleteTaskType({{ $taskType->id }})" wire:confirm="{{ __('production_bench.settings.delete_task_type_confirm') }}" class="text-sm text-[var(--color-danger-strong)] hover:underline" @disabled(! $isBenchActive || $isReadOnly)>{{ __('production_bench.common.delete') }}</button></span></div>@empty<p class="p-5 text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.settings.no_task_types') }}</p>@endforelse</div>
        </section>
        @endif
    </div>
    @endif

    @if (in_array($section, ['all', 'task-sets'], true))
    <section aria-labelledby="task-set-heading" class="space-y-4">
        <div class="flex flex-col gap-4 rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] p-5 shadow-sm sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 id="task-set-heading" class="text-xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.settings.task_sets') }}</h2>
                <p class="mt-1 text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.settings.task_sets_help') }}</p>
            </div>
            <span class="flex flex-wrap gap-2">
                <a href="{{ route('production-bench.production.settings.task-sets') }}" wire:navigate class="sk-btn sk-btn-ghost">{{ __('production_bench.settings.manage_task_sets') }}</a>
                @if ($isBenchActive && ! $isReadOnly)
                    <a href="{{ route('production-bench.production.settings.task-sets.create') }}" wire:navigate class="sk-btn sk-btn-primary">{{ __('production_bench.settings.new_task_set') }}</a>
                @endif
            </span>
        </div>
    </section>
    @endif

    @if (in_array($section, ['all', 'calendar'], true))
    <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_minmax(18rem,.65fr)]">
        <section aria-labelledby="holiday-heading" class="space-y-4">
            <div><h2 id="holiday-heading" class="text-xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.settings.working_calendar') }}</h2><p class="mt-1 text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.settings.working_calendar_help') }}</p></div>
            <form wire:submit="saveHoliday" class="sk-card grid gap-4 p-5 sm:grid-cols-[minmax(0,1fr)_12rem_auto]"><label class="text-sm"><span class="font-medium">{{ __('production_bench.settings.holiday_name') }}</span><input wire:model="holidayName" class="sk-input mt-1 w-full" placeholder="Summer closure" @disabled(! $isBenchActive || $isReadOnly)>@error('holidayName')<span class="mt-1 block text-xs text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror</label><label class="text-sm"><span class="font-medium">{{ __('production_bench.settings.holiday_date') }}</span><input wire:model="holidayDate" type="date" class="sk-input mt-1 w-full" @disabled(! $isBenchActive || $isReadOnly)>@error('holidayDate')<span class="mt-1 block text-xs text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror</label><label class="flex items-center gap-2 self-end pb-2 text-sm"><input wire:model="holidayIsRecurring" type="checkbox" class="size-4 rounded accent-[var(--color-accent)]" @disabled(! $isBenchActive || $isReadOnly)>{{ __('production_bench.settings.recurring') }}</label><button type="submit" class="sk-btn sk-btn-primary sm:col-span-3 sm:justify-self-end" @disabled(! $isBenchActive || $isReadOnly)>{{ __('production_bench.settings.add_holiday') }}</button></form>
            <div class="sk-card divide-y divide-[var(--color-line)] p-1">@forelse ($holidays as $holiday)<div wire:key="holiday-{{ $holiday->id }}" class="flex items-center justify-between gap-3 p-4"><span><span class="block font-medium text-[var(--color-ink-strong)]">{{ $holiday->name }}</span><span class="text-xs text-[var(--color-ink-soft)]">{{ $holiday->date->format('d/m/Y') }}{{ $holiday->is_recurring ? ' · '.__('production_bench.settings.recurring') : '' }}</span></span></div>@empty<p class="p-5 text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.settings.no_holidays') }}</p>@endforelse</div>
        </section>
        <section aria-labelledby="weekend-heading" class="space-y-4"><div><h2 id="weekend-heading" class="text-xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.settings.weekends') }}</h2><p class="mt-1 text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.settings.weekends_help') }}</p></div><div class="sk-card p-5"><label class="flex items-start gap-3 text-sm"><input wire:model="worksOnWeekends" wire:change="saveCalendar" type="checkbox" class="mt-0.5 size-4 rounded accent-[var(--color-accent)]" @disabled(! $isBenchActive || $isReadOnly)><span><span class="font-medium text-[var(--color-ink-strong)]">{{ __('production_bench.settings.works_on_weekends') }}</span><span class="mt-1 block text-[var(--color-ink-soft)]">{{ __('production_bench.settings.works_on_weekends_help') }}</span></span></label></div></section>
    </div>
    @endif
</x-production-bench.page>
