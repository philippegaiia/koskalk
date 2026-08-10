<?php

namespace App\Livewire\ProductionBench\Production;

use App\Actions\Production\DeleteDepartment;
use App\Actions\Production\DeleteEmployee;
use App\Actions\Production\SaveDepartment;
use App\Actions\Production\SaveEmployee;
use App\Actions\Production\SaveProductionBatchPreset;
use App\Actions\Production\SaveProductionHoliday;
use App\Actions\Production\SaveProductionOutputSettings;
use App\Actions\Production\SaveProductionTaskSet;
use App\Actions\Production\SaveProductionTaskType;
use App\Actions\Production\SyncProductionBatchPresetProducts;
use App\Actions\Production\SyncProductionTaskSetProducts;
use App\Actions\Production\UpdateProductionWorkingCalendar;
use App\Enums\MassUnit;
use App\Livewire\Concerns\InteractsWithAppNotifications;
use App\Models\Department;
use App\Models\Employee;
use App\Models\ProductionBatchPreset;
use App\Models\ProductionHoliday;
use App\Models\ProductionTaskSet;
use App\Models\ProductionTaskType;
use App\Models\Recipe;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use App\Support\NumberLocale;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class SettingsIndex extends Component
{
    use InteractsWithAppNotifications;

    public string $section = 'all';

    public ?string $statusMessage = null;

    public string $statusType = 'idle';

    public string $employeeFirstName = '';

    public string $employeeLastName = '';

    public string $employeeTitle = '';

    /** @var list<string|int> */
    public array $employeeDepartmentIds = [];

    public bool $employeeIsActive = true;

    public ?int $editingEmployeeId = null;

    public string $departmentName = '';

    public bool $departmentIsActive = true;

    public ?int $editingDepartmentId = null;

    public string $taskTypeName = '';

    public string $taskTypeDuration = '';

    public string $taskTypeColour = '';

    public string|int|null $taskTypeDepartmentId = null;

    public bool $taskTypeIsActive = true;

    public ?int $editingTaskTypeId = null;

    public string $taskSetName = '';

    /** @var list<string|int> */
    public array $taskSetRecipeIds = [];

    /** @var list<string|int> */
    public array $taskSetDefaultRecipeIds = [];

    /** @deprecated Kept as a compatibility alias for older Livewire clients. */
    public string $taskSetDefaultRecipeId = '';

    public bool $taskSetIsActive = true;

    /** @var array<int, array{task_type_id: string|int, days_after_production: string|int, duration_minutes: string|int}> */
    public array $taskSetItems = [];

    public ?int $editingTaskSetId = null;

    /** @var list<string|int> */
    public array $presetRecipeIds = [];

    /** @var list<string|int> */
    public array $presetDefaultRecipeIds = [];

    /** @deprecated Kept as a compatibility alias for older Livewire clients. */
    public string $presetRecipeId = '';

    public string $presetName = '';

    public string $presetBasisInputValue = '';

    public string $presetBasisInputUnit = 'kg';

    public string $presetExpectedUnits = '';

    /** @deprecated Kept as a compatibility alias for older Livewire clients. */
    public bool $presetIsDefault = false;

    public bool $presetIsActive = true;

    public ?int $editingPresetId = null;

    public string $holidayName = '';

    public string $holidayDate = '';

    public bool $holidayIsRecurring = false;

    public bool $worksOnWeekends = false;

    public string $soapReadyDelayDays = '21';

    public string $cosmeticReadyDelayDays = '3';

    public function mount(): void
    {
        $this->section = match (true) {
            request()->routeIs('production-bench.production.settings.presets') => 'presets',
            request()->routeIs('production-bench.production.settings.departments') => 'departments',
            request()->routeIs('production-bench.production.settings.employees') => 'employees',
            request()->routeIs('production-bench.production.settings.task-types') => 'task-types',
            request()->routeIs('production-bench.production.settings.task-sets') => 'task-sets',
            request()->routeIs('production-bench.production.settings.calendar') => 'calendar',
            default => 'all',
        };

        $this->worksOnWeekends = (bool) $this->workspace()->production_works_on_weekends;
        $outputSettings = $this->workspace()->productionOutputSetting;
        $this->soapReadyDelayDays = (string) ($outputSettings?->soap_ready_delay_days ?? 21);
        $this->cosmeticReadyDelayDays = (string) ($outputSettings?->cosmetic_ready_delay_days ?? 3);
        $this->taskSetItems = [$this->emptyTaskSetItem()];
        $this->holidayDate = now()->toDateString();
    }

    public function saveOutputSettings(SaveProductionOutputSettings $saveOutputSettings): void
    {
        try {
            $settings = $saveOutputSettings->handle(
                actor: $this->user(),
                workspace: $this->workspace(),
                soapReadyDelayDays: $this->soapReadyDelayDays,
                cosmeticReadyDelayDays: $this->cosmeticReadyDelayDays,
            );
        } catch (ValidationException $exception) {
            $this->surfaceErrors('outputSettings', $exception);

            return;
        }

        $this->soapReadyDelayDays = (string) $settings->soap_ready_delay_days;
        $this->cosmeticReadyDelayDays = (string) $settings->cosmetic_ready_delay_days;
        $this->showAppNotification(__('production_bench.settings.saved'));
        $this->dispatch('production-settings-saved');
    }

    public function saveEmployee(SaveEmployee $saveEmployee): void
    {
        $this->validate([
            'employeeFirstName' => ['required', 'string', 'max:80'],
            'employeeLastName' => ['required', 'string', 'max:80'],
            'employeeTitle' => ['nullable', 'string', 'max:120'],
            'employeeDepartmentIds' => ['array'],
            'employeeDepartmentIds.*' => ['integer'],
        ]);

        try {
            $employee = $this->editingEmployeeId === null
                ? null
                : Employee::query()->where('workspace_id', $this->workspace()->id)->findOrFail($this->editingEmployeeId);

            $saveEmployee->handle(
                actor: $this->user(),
                workspace: $this->workspace(),
                firstName: $this->employeeFirstName,
                lastName: $this->employeeLastName,
                isActive: $this->employeeIsActive,
                employee: $employee,
                title: $this->employeeTitle,
                departmentIds: $this->employeeDepartmentIds,
            );
        } catch (ValidationException $exception) {
            $this->surfaceErrors('employee', $exception);

            return;
        }

        $this->resetEmployeeForm();
        $this->showAppNotification(__('production_bench.settings.saved'));
        $this->dispatch('production-settings-saved');
    }

    public function editEmployee(int $employeeId): void
    {
        $employee = Employee::query()->where('workspace_id', $this->workspace()->id)->findOrFail($employeeId);
        $this->editingEmployeeId = $employee->id;
        $this->employeeFirstName = $employee->first_name;
        $this->employeeLastName = $employee->last_name;
        $this->employeeTitle = (string) ($employee->title ?? '');
        $this->employeeDepartmentIds = $employee->departments->pluck('id')->all();
        $this->employeeIsActive = $employee->is_active;
    }

    public function deleteEmployee(int $employeeId, DeleteEmployee $deleteEmployee): void
    {
        $workspace = $this->workspace();
        $employee = Employee::query()
            ->where('workspace_id', $workspace->id)
            ->findOrFail($employeeId);

        try {
            $deleteEmployee->handle(
                actor: $this->user(),
                workspace: $workspace,
                employee: $employee,
            );
        } catch (ValidationException $exception) {
            $this->surfaceErrors('employee', $exception);

            return;
        }

        $this->resetEmployeeForm();
        $this->showAppNotification(__('production_bench.settings.saved'));
        $this->dispatch('production-settings-saved');
    }

    public function saveDepartment(SaveDepartment $saveDepartment): void
    {
        $this->validate([
            'departmentName' => ['required', 'string', 'max:120'],
        ]);

        try {
            $department = $this->editingDepartmentId === null
                ? null
                : Department::query()->where('workspace_id', $this->workspace()->id)->findOrFail($this->editingDepartmentId);

            $saveDepartment->handle(
                actor: $this->user(),
                workspace: $this->workspace(),
                name: $this->departmentName,
                isActive: $this->departmentIsActive,
                department: $department,
            );
        } catch (ValidationException $exception) {
            $this->surfaceErrors('department', $exception);

            return;
        }

        $this->resetDepartmentForm();
        $this->showAppNotification(__('production_bench.settings.saved'));
        $this->dispatch('production-settings-saved');
    }

    public function editDepartment(int $departmentId): void
    {
        $department = Department::query()->where('workspace_id', $this->workspace()->id)->findOrFail($departmentId);
        $this->editingDepartmentId = $department->id;
        $this->departmentName = $department->name;
        $this->departmentIsActive = $department->is_active;
    }

    public function deleteDepartment(int $departmentId, DeleteDepartment $deleteDepartment): void
    {
        $workspace = $this->workspace();
        $department = Department::query()
            ->where('workspace_id', $workspace->id)
            ->findOrFail($departmentId);

        try {
            $deleteDepartment->handle(
                actor: $this->user(),
                workspace: $workspace,
                department: $department,
            );
        } catch (ValidationException $exception) {
            $this->surfaceErrors('department', $exception);

            return;
        }

        $this->resetDepartmentForm();
        $this->showAppNotification(__('production_bench.settings.saved'));
        $this->dispatch('production-settings-saved');
    }

    public function saveTaskType(SaveProductionTaskType $saveTaskType): void
    {
        $this->validate([
            'taskTypeName' => ['required', 'string', 'max:120'],
            'taskTypeDuration' => ['nullable', 'integer', 'min:0'],
            'taskTypeColour' => ['nullable', 'string', 'max:16'],
            'taskTypeDepartmentId' => ['nullable', 'integer'],
        ]);

        try {
            $taskType = $this->editingTaskTypeId === null
                ? null
                : ProductionTaskType::query()->where('workspace_id', $this->workspace()->id)->findOrFail($this->editingTaskTypeId);

            $saveTaskType->handle(
                actor: $this->user(),
                workspace: $this->workspace(),
                name: $this->taskTypeName,
                defaultDurationMinutes: $this->taskTypeDuration === '' ? null : (int) $this->taskTypeDuration,
                colour: $this->taskTypeColour === '' ? null : $this->taskTypeColour,
                isActive: $this->taskTypeIsActive,
                taskType: $taskType,
                departmentId: $this->taskTypeDepartmentId === null || $this->taskTypeDepartmentId === '' ? null : (int) $this->taskTypeDepartmentId,
            );
        } catch (ValidationException $exception) {
            $this->surfaceErrors('taskType', $exception);

            return;
        }

        $this->resetTaskTypeForm();
        $this->showAppNotification(__('production_bench.settings.saved'));
        $this->dispatch('production-settings-saved');
    }

    public function editTaskType(int $taskTypeId): void
    {
        $taskType = ProductionTaskType::query()->where('workspace_id', $this->workspace()->id)->findOrFail($taskTypeId);
        $this->editingTaskTypeId = $taskType->id;
        $this->taskTypeName = $taskType->name;
        $this->taskTypeDuration = $taskType->default_duration_minutes === null ? '' : (string) $taskType->default_duration_minutes;
        $this->taskTypeColour = (string) ($taskType->colour ?? '');
        $this->taskTypeDepartmentId = $taskType->department_id;
        $this->taskTypeIsActive = $taskType->is_active;
    }

    public function deleteTaskType(int $taskTypeId, ProductionBenchAccess $access): void
    {
        $workspace = $this->workspace();
        $access->assertWritable($this->user(), $workspace);
        $taskType = ProductionTaskType::query()
            ->where('workspace_id', $workspace->id)
            ->findOrFail($taskTypeId);

        if ($taskType->taskSetItems()->exists()) {
            $this->addError('taskTypeName', __('production_bench.settings.task_type_in_use'));

            return;
        }

        $taskType->delete();
        $this->resetTaskTypeForm();
        $this->showAppNotification(__('production_bench.settings.saved'));
        $this->dispatch('production-settings-saved');
    }

    public function addTaskSetItem(): void
    {
        $this->taskSetItems[] = $this->emptyTaskSetItem();
    }

    public function removeTaskSetItem(int $index): void
    {
        if (count($this->taskSetItems) <= 1) {
            return;
        }

        unset($this->taskSetItems[$index]);
        $this->taskSetItems = array_values($this->taskSetItems);
    }

    public function saveTaskSet(
        SaveProductionTaskSet $saveTaskSet,
        SyncProductionTaskSetProducts $syncProducts,
    ): void {
        $this->validate([
            'taskSetName' => ['required', 'string', 'max:120'],
            'taskSetItems' => ['required', 'array', 'min:1'],
            'taskSetItems.*.task_type_id' => ['required', 'integer'],
            'taskSetItems.*.days_after_production' => ['required', 'integer'],
            'taskSetItems.*.duration_minutes' => ['nullable', 'integer', 'min:0'],
            'taskSetRecipeIds' => ['array'],
            'taskSetRecipeIds.*' => ['integer'],
            'taskSetDefaultRecipeIds' => ['array'],
            'taskSetDefaultRecipeIds.*' => ['integer'],
        ]);

        try {
            $taskSet = $this->editingTaskSetId === null
                ? null
                : ProductionTaskSet::query()->where('workspace_id', $this->workspace()->id)->findOrFail($this->editingTaskSetId);
            $items = array_map(
                fn (array $item): array => [
                    ...$item,
                    'duration_minutes' => ($item['duration_minutes'] ?? '') === ''
                        ? null
                        : $item['duration_minutes'],
                ],
                $this->taskSetItems,
            );

            $savedTaskSet = $saveTaskSet->handle(
                actor: $this->user(),
                workspace: $this->workspace(),
                name: $this->taskSetName,
                items: $items,
                isActive: $this->taskSetIsActive,
                taskSet: $taskSet,
            );

            $taskSetRecipeIds = collect($this->taskSetRecipeIds)
                ->merge($this->taskSetDefaultRecipeIds)
                ->when($this->taskSetDefaultRecipeId !== '', fn ($ids) => $ids->push($this->taskSetDefaultRecipeId))
                ->map(fn (int|string $id): int => (int) $id)
                ->filter(fn (int $id): bool => $id > 0)
                ->unique()
                ->values();
            $taskSetDefaultRecipeIds = collect($this->taskSetDefaultRecipeIds)
                ->when($this->taskSetDefaultRecipeId !== '', fn ($ids) => $ids->push($this->taskSetDefaultRecipeId))
                ->map(fn (int|string $id): int => (int) $id)
                ->filter(fn (int $id): bool => $id > 0)
                ->unique()
                ->values();

            $syncProducts->handle(
                actor: $this->user(),
                workspace: $this->workspace(),
                taskSet: $savedTaskSet,
                assignments: $taskSetRecipeIds->map(fn (int $recipeId): array => [
                    'recipe_id' => $recipeId,
                    'is_default' => $taskSetDefaultRecipeIds->contains($recipeId),
                ])->all(),
            );
        } catch (ValidationException $exception) {
            $this->surfaceErrors('taskSet', $exception);

            return;
        }

        $this->resetTaskSetForm();
        $this->showAppNotification(__('production_bench.settings.saved'));
        $this->dispatch('production-settings-saved');
    }

    public function editTaskSet(int $taskSetId): void
    {
        $taskSet = ProductionTaskSet::query()
            ->where('workspace_id', $this->workspace()->id)
            ->with(['items', 'recipes', 'defaultRecipes'])
            ->findOrFail($taskSetId);
        $this->editingTaskSetId = $taskSet->id;
        $this->taskSetName = $taskSet->name;
        $this->taskSetIsActive = $taskSet->is_active;
        $this->taskSetItems = $taskSet->items->map(fn ($item): array => [
            'task_type_id' => $item->production_task_type_id,
            'days_after_production' => $item->days_after_production,
            'duration_minutes' => $item->duration_minutes ?? '',
        ])->all();
        $this->taskSetRecipeIds = $taskSet->recipes->pluck('id')->map(fn (int $id): string => (string) $id)->all();
        $this->taskSetDefaultRecipeIds = $taskSet->recipes
            ->filter(fn (Recipe $recipe): bool => (bool) $recipe->pivot->is_default)
            ->pluck('id')
            ->map(fn (int $id): string => (string) $id)
            ->values()
            ->all();
        $this->taskSetDefaultRecipeId = (string) ($this->taskSetDefaultRecipeIds[0] ?? '');
    }

    public function deleteTaskSet(int $taskSetId, ProductionBenchAccess $access): void
    {
        $workspace = $this->workspace();
        $access->assertWritable($this->user(), $workspace);
        $taskSet = ProductionTaskSet::query()
            ->where('workspace_id', $workspace->id)
            ->findOrFail($taskSetId);
        $taskSet->delete();
        $this->resetTaskSetForm();
        $this->showAppNotification(__('production_bench.settings.saved'));
        $this->dispatch('production-settings-saved');
    }

    public function savePreset(
        SaveProductionBatchPreset $savePreset,
        SyncProductionBatchPresetProducts $syncProducts,
    ): void {
        $this->presetBasisInputValue = NumberLocale::normalizeDecimalString($this->presetBasisInputValue) ?? $this->presetBasisInputValue;

        $this->validate([
            'presetRecipeIds' => ['array'],
            'presetRecipeIds.*' => ['integer'],
            'presetDefaultRecipeIds' => ['array'],
            'presetDefaultRecipeIds.*' => ['integer'],
            'presetName' => ['required', 'string', 'max:120'],
            'presetBasisInputValue' => ['required', 'numeric', 'gt:0'],
            'presetBasisInputUnit' => ['required', 'in:g,kg,oz,lb'],
            'presetExpectedUnits' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $preset = $this->editingPresetId === null
                ? null
                : ProductionBatchPreset::query()->where('workspace_id', $this->workspace()->id)->findOrFail($this->editingPresetId);

            $savedPreset = $savePreset->handle(
                actor: $this->user(),
                workspace: $this->workspace(),
                name: $this->presetName,
                basisInputValue: $this->presetBasisInputValue,
                basisInputUnit: $this->presetBasisInputUnit,
                expectedUnits: $this->presetExpectedUnits,
                isActive: $this->presetIsActive,
                preset: $preset,
            );

            $presetRecipeIds = collect($this->presetRecipeIds)
                ->merge($this->presetDefaultRecipeIds)
                ->when($this->presetRecipeId !== '', fn ($ids) => $ids->push($this->presetRecipeId))
                ->map(fn (int|string $id): int => (int) $id)
                ->filter(fn (int $id): bool => $id > 0)
                ->unique()
                ->values();
            $presetDefaultRecipeIds = collect($this->presetDefaultRecipeIds)
                ->when($this->presetIsDefault && $this->presetRecipeId !== '', fn ($ids) => $ids->push($this->presetRecipeId))
                ->map(fn (int|string $id): int => (int) $id)
                ->filter(fn (int $id): bool => $id > 0)
                ->unique();

            $syncProducts->handle(
                actor: $this->user(),
                workspace: $this->workspace(),
                preset: $savedPreset,
                assignments: $presetRecipeIds->map(fn (int $recipeId): array => [
                    'recipe_id' => $recipeId,
                    'is_default' => $presetDefaultRecipeIds->contains($recipeId),
                ])->all(),
            );
        } catch (ValidationException $exception) {
            $this->surfaceErrors('preset', $exception);

            return;
        }

        $this->resetPresetForm();
        $this->showAppNotification(__('production_bench.settings.saved'));
        $this->dispatch('production-settings-saved');
    }

    public function editPreset(int $presetId): void
    {
        $preset = ProductionBatchPreset::query()->where('workspace_id', $this->workspace()->id)->findOrFail($presetId);
        $this->editingPresetId = $preset->id;
        $preset->load('recipes');
        $this->presetRecipeIds = $preset->recipes->pluck('id')->map(fn (int $id): string => (string) $id)->all();
        $this->presetDefaultRecipeIds = $preset->recipes
            ->filter(fn (Recipe $recipe): bool => (bool) $recipe->pivot->is_default)
            ->pluck('id')
            ->map(fn (int $id): string => (string) $id)
            ->values()
            ->all();
        $this->presetRecipeId = (string) ($this->presetRecipeIds[0] ?? '');
        $this->presetName = $preset->name;
        $this->presetBasisInputValue = NumberLocale::formatAdaptiveDecimal(
            $preset->basis_input_value,
            0,
            3,
            $this->user()->number_locale,
        );
        $this->presetBasisInputUnit = $preset->basis_input_unit->value;
        $this->presetExpectedUnits = (string) $preset->expected_units;
        $this->presetIsDefault = false;
        $this->presetIsActive = $preset->is_active;
    }

    public function deletePreset(int $presetId, ProductionBenchAccess $access): void
    {
        $workspace = $this->workspace();
        $access->assertWritable($this->user(), $workspace);
        $preset = ProductionBatchPreset::query()
            ->where('workspace_id', $workspace->id)
            ->findOrFail($presetId);
        $preset->delete();
        $this->resetPresetForm();
        $this->showAppNotification(__('production_bench.settings.saved'));
        $this->dispatch('production-settings-saved');
    }

    public function saveHoliday(SaveProductionHoliday $saveHoliday): void
    {
        $this->validate([
            'holidayName' => ['required', 'string', 'max:120'],
            'holidayDate' => ['required', 'date_format:Y-m-d'],
        ]);

        try {
            $saveHoliday->handle(
                actor: $this->user(),
                workspace: $this->workspace(),
                name: $this->holidayName,
                date: $this->holidayDate,
                isRecurring: $this->holidayIsRecurring,
            );
        } catch (ValidationException $exception) {
            $this->surfaceErrors('holiday', $exception);

            return;
        }

        $this->holidayName = '';
        $this->holidayDate = now()->toDateString();
        $this->holidayIsRecurring = false;
        $this->showAppNotification(__('production_bench.settings.saved'));
        $this->dispatch('production-settings-saved');
    }

    public function saveCalendar(UpdateProductionWorkingCalendar $updateCalendar): void
    {
        try {
            $workspace = $updateCalendar->handle($this->user(), $this->workspace(), $this->worksOnWeekends);
            $this->worksOnWeekends = (bool) $workspace->production_works_on_weekends;
            $this->showAppNotification(__('production_bench.settings.saved'));
            $this->dispatch('production-settings-saved');
        } catch (ValidationException $exception) {
            $this->surfaceErrors('worksOnWeekends', $exception);
        }
    }

    public function render(ProductionBenchAccess $access): View
    {
        $workspace = $this->workspace();

        return view('livewire.production-bench.production.settings-index', [
            'section' => $this->section,
            'workspace' => $workspace,
            'isBenchActive' => $access->isActive($workspace),
            'isReadOnly' => $access->isReadOnly($workspace),
            'employees' => Employee::query()->where('workspace_id', $workspace->id)->with('departments')->orderBy('last_name')->orderBy('first_name')->get(),
            'departments' => Department::query()->where('workspace_id', $workspace->id)->withCount(['employees', 'productionTaskTypes', 'productionTasks'])->orderByDesc('is_active')->orderBy('name')->get(),
            'taskTypes' => ProductionTaskType::query()->where('workspace_id', $workspace->id)->with('department')->orderBy('name')->get(),
            'taskSets' => ProductionTaskSet::query()->where('workspace_id', $workspace->id)->with(['items.taskType', 'recipes'])->orderBy('name')->get(),
            'recipes' => Recipe::query()->where('workspace_id', $workspace->id)->whereNull('archived_at')->whereHas('publishedVersions')->orderBy('name')->get(),
            'presets' => ProductionBatchPreset::query()->where('workspace_id', $workspace->id)->with('recipes')->orderBy('name')->get(),
            'holidays' => ProductionHoliday::query()->where('workspace_id', $workspace->id)->orderBy('date')->get(),
            'massUnits' => MassUnit::cases(),
        ]);
    }

    /** @return array{task_type_id: string, days_after_production: int, duration_minutes: string} */
    private function emptyTaskSetItem(): array
    {
        return ['task_type_id' => '', 'days_after_production' => 0, 'duration_minutes' => ''];
    }

    public function resetEmployeeForm(): void
    {
        $this->reset(['employeeFirstName', 'employeeLastName', 'employeeTitle', 'employeeDepartmentIds', 'editingEmployeeId']);
        $this->employeeIsActive = true;
    }

    public function resetDepartmentForm(): void
    {
        $this->reset(['departmentName', 'editingDepartmentId']);
        $this->departmentIsActive = true;
    }

    public function resetTaskTypeForm(): void
    {
        $this->reset(['taskTypeName', 'taskTypeDuration', 'taskTypeColour', 'taskTypeDepartmentId', 'editingTaskTypeId']);
        $this->taskTypeIsActive = true;
    }

    public function resetTaskSetForm(): void
    {
        $this->reset(['taskSetName', 'taskSetRecipeIds', 'taskSetDefaultRecipeIds', 'taskSetDefaultRecipeId', 'editingTaskSetId']);
        $this->taskSetItems = [$this->emptyTaskSetItem()];
        $this->taskSetIsActive = true;
    }

    public function resetPresetForm(): void
    {
        $this->reset(['presetRecipeIds', 'presetDefaultRecipeIds', 'presetRecipeId', 'presetName', 'presetBasisInputValue', 'presetExpectedUnits', 'editingPresetId']);
        $this->presetBasisInputUnit = 'kg';
        $this->presetIsDefault = false;
        $this->presetIsActive = true;
    }

    private function surfaceErrors(string $prefix, ValidationException $exception): void
    {
        foreach ($exception->errors() as $field => $messages) {
            foreach ($messages as $message) {
                $errorKey = match (true) {
                    $field === 'production_bench' && $prefix === 'employee' => 'employeeFirstName',
                    $field === 'production_bench' && $prefix === 'outputSettings' => 'soapReadyDelayDays',
                    $field === 'production_bench' => $prefix.'Name',
                    $field === 'department_id' && $prefix === 'taskType' => 'taskTypeDepartmentId',
                    $field === 'departments' && $prefix === 'employee' => 'employeeDepartmentIds',
                    $field === 'items' => $prefix.'Items',
                    $field === 'soap_ready_delay_days' && $prefix === 'outputSettings' => 'soapReadyDelayDays',
                    $field === 'cosmetic_ready_delay_days' && $prefix === 'outputSettings' => 'cosmeticReadyDelayDays',
                    default => $prefix.ucfirst($field),
                };

                $this->addError($errorKey, $message);
            }
        }
    }

    private function user(): User
    {
        return auth()->user() ?? abort(401);
    }

    private function workspace(): Workspace
    {
        return $this->user()->company() ?? abort(404);
    }
}
