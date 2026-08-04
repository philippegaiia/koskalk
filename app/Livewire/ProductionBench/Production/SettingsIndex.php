<?php

namespace App\Livewire\ProductionBench\Production;

use App\Actions\Production\SaveEmployee;
use App\Actions\Production\SaveProductionBatchPreset;
use App\Actions\Production\SaveProductionHoliday;
use App\Actions\Production\SaveProductionTaskSet;
use App\Actions\Production\SaveProductionTaskType;
use App\Actions\Production\UpdateProductionWorkingCalendar;
use App\MassUnit;
use App\Models\Employee;
use App\Models\ProductionBatchPreset;
use App\Models\ProductionHoliday;
use App\Models\ProductionTaskSet;
use App\Models\ProductionTaskType;
use App\Models\Recipe;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class SettingsIndex extends Component
{
    public string $employeeFirstName = '';

    public string $employeeLastName = '';

    public bool $employeeIsActive = true;

    public ?int $editingEmployeeId = null;

    public string $taskTypeName = '';

    public string $taskTypeDuration = '';

    public string $taskTypeColour = '';

    public bool $taskTypeIsActive = true;

    public ?int $editingTaskTypeId = null;

    public string $taskSetName = '';

    public string $taskSetRecipeId = '';

    public bool $taskSetIsDefault = false;

    public bool $taskSetIsActive = true;

    /** @var array<int, array{task_type_id: string|int, days_after_production: string|int, duration_minutes: string|int}> */
    public array $taskSetItems = [];

    public ?int $editingTaskSetId = null;

    public string $presetRecipeId = '';

    public string $presetName = '';

    public string $presetBasisInputValue = '';

    public string $presetBasisInputUnit = 'kg';

    public string $presetExpectedUnits = '';

    public bool $presetIsDefault = false;

    public bool $presetIsActive = true;

    public ?int $editingPresetId = null;

    public string $holidayName = '';

    public string $holidayDate = '';

    public bool $holidayIsRecurring = false;

    public bool $worksOnWeekends = false;

    public function mount(): void
    {
        $this->worksOnWeekends = (bool) $this->workspace()->production_works_on_weekends;
        $this->taskSetItems = [$this->emptyTaskSetItem()];
        $this->holidayDate = now()->toDateString();
    }

    public function saveEmployee(SaveEmployee $saveEmployee): void
    {
        $this->validate([
            'employeeFirstName' => ['required', 'string', 'max:80'],
            'employeeLastName' => ['required', 'string', 'max:80'],
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
            );
        } catch (ValidationException $exception) {
            $this->surfaceErrors('employee', $exception);

            return;
        }

        $this->resetEmployeeForm();
        $this->dispatch('production-settings-saved');
    }

    public function editEmployee(int $employeeId): void
    {
        $employee = Employee::query()->where('workspace_id', $this->workspace()->id)->findOrFail($employeeId);
        $this->editingEmployeeId = $employee->id;
        $this->employeeFirstName = $employee->first_name;
        $this->employeeLastName = $employee->last_name;
        $this->employeeIsActive = $employee->is_active;
    }

    public function saveTaskType(SaveProductionTaskType $saveTaskType): void
    {
        $this->validate([
            'taskTypeName' => ['required', 'string', 'max:120'],
            'taskTypeDuration' => ['nullable', 'integer', 'min:0'],
            'taskTypeColour' => ['nullable', 'string', 'max:16'],
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
            );
        } catch (ValidationException $exception) {
            $this->surfaceErrors('taskType', $exception);

            return;
        }

        $this->resetTaskTypeForm();
        $this->dispatch('production-settings-saved');
    }

    public function editTaskType(int $taskTypeId): void
    {
        $taskType = ProductionTaskType::query()->where('workspace_id', $this->workspace()->id)->findOrFail($taskTypeId);
        $this->editingTaskTypeId = $taskType->id;
        $this->taskTypeName = $taskType->name;
        $this->taskTypeDuration = $taskType->default_duration_minutes === null ? '' : (string) $taskType->default_duration_minutes;
        $this->taskTypeColour = (string) ($taskType->colour ?? '');
        $this->taskTypeIsActive = $taskType->is_active;
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

    public function saveTaskSet(SaveProductionTaskSet $saveTaskSet): void
    {
        $this->validate([
            'taskSetName' => ['required', 'string', 'max:120'],
            'taskSetItems' => ['required', 'array', 'min:1'],
            'taskSetItems.*.task_type_id' => ['required', 'integer'],
            'taskSetItems.*.days_after_production' => ['required', 'integer', 'min:0'],
            'taskSetItems.*.duration_minutes' => ['nullable', 'integer', 'min:0'],
        ]);

        try {
            $taskSet = $this->editingTaskSetId === null
                ? null
                : ProductionTaskSet::query()->where('workspace_id', $this->workspace()->id)->findOrFail($this->editingTaskSetId);
            $recipe = $this->taskSetRecipeId === ''
                ? null
                : Recipe::query()->where('workspace_id', $this->workspace()->id)->findOrFail((int) $this->taskSetRecipeId);
            $items = array_map(
                fn (array $item): array => [
                    ...$item,
                    'duration_minutes' => ($item['duration_minutes'] ?? '') === ''
                        ? null
                        : $item['duration_minutes'],
                ],
                $this->taskSetItems,
            );

            $saveTaskSet->handle(
                actor: $this->user(),
                workspace: $this->workspace(),
                name: $this->taskSetName,
                items: $items,
                recipe: $recipe,
                isDefault: $this->taskSetIsDefault,
                isActive: $this->taskSetIsActive,
                taskSet: $taskSet,
            );
        } catch (ValidationException $exception) {
            $this->surfaceErrors('taskSet', $exception);

            return;
        }

        $this->resetTaskSetForm();
        $this->dispatch('production-settings-saved');
    }

    public function editTaskSet(int $taskSetId): void
    {
        $taskSet = ProductionTaskSet::query()
            ->where('workspace_id', $this->workspace()->id)
            ->with('items')
            ->findOrFail($taskSetId);
        $this->editingTaskSetId = $taskSet->id;
        $this->taskSetName = $taskSet->name;
        $this->taskSetIsActive = $taskSet->is_active;
        $this->taskSetItems = $taskSet->items->map(fn ($item): array => [
            'task_type_id' => $item->production_task_type_id,
            'days_after_production' => $item->days_after_production,
            'duration_minutes' => $item->duration_minutes ?? '',
        ])->all();
        $this->taskSetRecipeId = (string) Recipe::query()
            ->where('workspace_id', $this->workspace()->id)
            ->where('default_production_task_set_id', $taskSet->id)
            ->value('id');
    }

    public function savePreset(SaveProductionBatchPreset $savePreset): void
    {
        $this->validate([
            'presetRecipeId' => ['required', 'integer'],
            'presetName' => ['required', 'string', 'max:120'],
            'presetBasisInputValue' => ['required', 'numeric', 'gt:0'],
            'presetBasisInputUnit' => ['required', 'in:g,kg,oz,lb'],
            'presetExpectedUnits' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $recipe = Recipe::query()->where('workspace_id', $this->workspace()->id)->findOrFail((int) $this->presetRecipeId);
            $preset = $this->editingPresetId === null
                ? null
                : ProductionBatchPreset::query()->where('workspace_id', $this->workspace()->id)->findOrFail($this->editingPresetId);

            $savePreset->handle(
                actor: $this->user(),
                workspace: $this->workspace(),
                recipe: $recipe,
                name: $this->presetName,
                basisInputValue: $this->presetBasisInputValue,
                basisInputUnit: $this->presetBasisInputUnit,
                expectedUnits: $this->presetExpectedUnits,
                isDefault: $this->presetIsDefault,
                isActive: $this->presetIsActive,
                preset: $preset,
            );
        } catch (ValidationException $exception) {
            $this->surfaceErrors('preset', $exception);

            return;
        }

        $this->resetPresetForm();
        $this->dispatch('production-settings-saved');
    }

    public function editPreset(int $presetId): void
    {
        $preset = ProductionBatchPreset::query()->where('workspace_id', $this->workspace()->id)->findOrFail($presetId);
        $this->editingPresetId = $preset->id;
        $this->presetRecipeId = (string) $preset->recipe_id;
        $this->presetName = $preset->name;
        $this->presetBasisInputValue = (string) $preset->basis_input_value;
        $this->presetBasisInputUnit = $preset->basis_input_unit->value;
        $this->presetExpectedUnits = (string) $preset->expected_units;
        $this->presetIsDefault = $preset->is_default;
        $this->presetIsActive = $preset->is_active;
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
        $this->dispatch('production-settings-saved');
    }

    public function saveCalendar(UpdateProductionWorkingCalendar $updateCalendar): void
    {
        try {
            $workspace = $updateCalendar->handle($this->user(), $this->workspace(), $this->worksOnWeekends);
            $this->worksOnWeekends = (bool) $workspace->production_works_on_weekends;
        } catch (ValidationException $exception) {
            $this->surfaceErrors('worksOnWeekends', $exception);
        }
    }

    public function render(ProductionBenchAccess $access): View
    {
        $workspace = $this->workspace();

        return view('livewire.production-bench.production.settings-index', [
            'workspace' => $workspace,
            'isBenchActive' => $access->isActive($workspace),
            'isReadOnly' => $access->isReadOnly($workspace),
            'employees' => Employee::query()->where('workspace_id', $workspace->id)->orderBy('last_name')->orderBy('first_name')->get(),
            'taskTypes' => ProductionTaskType::query()->where('workspace_id', $workspace->id)->orderBy('name')->get(),
            'taskSets' => ProductionTaskSet::query()->where('workspace_id', $workspace->id)->with('items.taskType')->orderBy('name')->get(),
            'recipes' => Recipe::query()->where('workspace_id', $workspace->id)->whereHas('publishedVersions')->orderBy('name')->get(),
            'presets' => ProductionBatchPreset::query()->where('workspace_id', $workspace->id)->with('recipe')->orderBy('recipe_id')->orderBy('name')->get(),
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
        $this->reset(['employeeFirstName', 'employeeLastName', 'editingEmployeeId']);
        $this->employeeIsActive = true;
    }

    public function resetTaskTypeForm(): void
    {
        $this->reset(['taskTypeName', 'taskTypeDuration', 'taskTypeColour', 'editingTaskTypeId']);
        $this->taskTypeIsActive = true;
    }

    public function resetTaskSetForm(): void
    {
        $this->reset(['taskSetName', 'taskSetRecipeId', 'editingTaskSetId']);
        $this->taskSetItems = [$this->emptyTaskSetItem()];
        $this->taskSetIsDefault = false;
        $this->taskSetIsActive = true;
    }

    public function resetPresetForm(): void
    {
        $this->reset(['presetRecipeId', 'presetName', 'presetBasisInputValue', 'presetExpectedUnits', 'editingPresetId']);
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
                    $field === 'production_bench' => $prefix.'Name',
                    $field === 'items' => $prefix.'Items',
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
