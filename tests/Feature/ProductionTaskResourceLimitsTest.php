<?php

use App\Actions\Production\SaveProductionTaskSet;
use App\Actions\Production\SaveProductionTaskType;
use App\Livewire\ProductionBench\Production\SettingsIndex;
use App\Livewire\ProductionBench\Production\TaskSetForm;
use App\Models\ProductionHoliday;
use App\Models\ProductionTaskSet;
use App\Models\ProductionTaskSetItem;
use App\Models\ProductionTaskType;
use App\Models\Workspace;
use App\Models\WorkspaceProductionEntitlement;
use App\Services\Production\FlashDateProposalService;
use App\Services\Production\ProductionTaskLimits;
use App\Services\Production\ProductionWorkingCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function boundedTasksWorkspace(): Workspace
{
    $workspace = Workspace::factory()->create(['production_works_on_weekends' => true]);
    WorkspaceProductionEntitlement::factory()->for($workspace)->create();

    return $workspace;
}

it('accepts one hundred task items but rejects a larger replacement without changing the stored set', function (): void {
    $workspace = boundedTasksWorkspace();
    $type = ProductionTaskType::factory()->for($workspace)->create();
    $items = array_fill(0, 100, ['task_type_id' => $type->id, 'days_after_production' => 0]);
    $action = app(SaveProductionTaskSet::class);
    $set = $action->handle($workspace->owner, $workspace, 'Bounded workflow', $items);
    expect($set->items()->count())->toBe(100);
    expect(fn () => $action->handle($workspace->owner, $workspace, 'Oversized replacement', [...$items, $items[0]], taskSet: $set))->toThrow(ValidationException::class);
    expect($set->fresh()->name)->toBe('Bounded workflow');
    expect($set->items()->count())->toBe(100);
});

it('rejects task offsets and durations outside technical bounds before persistence', function (string $field, int $value): void {
    $workspace = boundedTasksWorkspace();
    $type = ProductionTaskType::factory()->for($workspace)->create();
    $items = [['task_type_id' => $type->id, 'days_after_production' => 0], ['task_type_id' => $type->id, 'days_after_production' => 0, $field => $value]];
    expect(fn () => app(SaveProductionTaskSet::class)->handle($workspace->owner, $workspace, 'Invalid workflow', $items))->toThrow(ValidationException::class);
    expect(ProductionTaskSet::query()->count())->toBe(0);
})->with([
    ['days_after_production', 1828],
    ['days_after_production', -1828],
    ['days_after_production', 2147483648],
    ['duration_minutes', 2630881],
    ['duration_minutes', 2147483648],
]);

it('accepts safe task timing boundaries and rejects overflowing task type duration', function (): void {
    $workspace = boundedTasksWorkspace();
    $type = app(SaveProductionTaskType::class)->handle($workspace->owner, $workspace, 'Long task', 2630880);
    $set = app(SaveProductionTaskSet::class)->handle($workspace->owner, $workspace, 'Boundary workflow', [
        ['task_type_id' => $type->id, 'days_after_production' => -1827, 'duration_minutes' => 0],
        ['task_type_id' => $type->id, 'days_after_production' => 0],
        ['task_type_id' => $type->id, 'days_after_production' => 1827, 'duration_minutes' => 2630880],
    ]);
    expect($set->items()->count())->toBe(3);
    expect(fn () => app(SaveProductionTaskType::class)->handle($workspace->owner, $workspace, 'Overflow', 2147483648))->toThrow(ValidationException::class);
    expect(ProductionTaskType::query()->count())->toBe(1);
});

it('preserves an oversized legacy task set and rejects it before loading its items for generation', function (): void {
    $workspace = boundedTasksWorkspace();
    $type = ProductionTaskType::factory()->for($workspace)->create();
    $set = ProductionTaskSet::factory()->for($workspace)->create();
    ProductionTaskSetItem::factory()->count(101)->for($set, 'taskSet')->for($type, 'taskType')->sequence(fn (Sequence $sequence): array => ['position' => $sequence->index + 1])->create();
    expect(fn () => app(ProductionTaskLimits::class)->assertUsableTaskSet($set))->toThrow(ValidationException::class);
    expect($set->relationLoaded('items'))->toBeFalse();
    expect($set->items()->count())->toBe(101);
});

it('checks flash task expansion before constructing date previews', function (): void {
    $workspace = boundedTasksWorkspace();
    $items = array_fill(0, 100, ['name' => 'Task', 'days_after_production' => 0, 'duration_minutes' => 1]);
    $line = ['line_index' => 0, 'recipe_id' => 1, 'recipe_name' => 'Product', 'whole_batches' => 101, 'task_items' => $items, 'output_ready_delay_days' => 0];
    expect(fn () => app(FlashDateProposalService::class)->propose($workspace, [$line], '2026-09-28', 1000))->toThrow(ValidationException::class);
    $line['whole_batches'] = 100;
    $proposals = app(FlashDateProposalService::class)->propose($workspace, [$line], '2026-09-28', 1000);
    expect($proposals)->toHaveCount(100);
    expect($proposals[0]['tasks'])->toHaveCount(100);
});

it('returns a validation error when no working date exists within the five-year search horizon', function (string $method): void {
    $workspace = boundedTasksWorkspace();
    $start = CarbonImmutable::parse('2024-01-01');
    ProductionHoliday::factory()->count(366)->for($workspace)->sequence(fn (Sequence $sequence): array => [
        'date' => $start->addDays($sequence->index)->toDateString(), 'is_recurring' => true,
    ])->create();
    expect(fn () => app(ProductionWorkingCalendar::class)->{$method}($workspace, '2026-09-28'))->toThrow(ValidationException::class);
})->with(['nextWorkingDate', 'previousWorkingDate']);

it('preserves normal directional holiday snapping and refreshes cached holiday lookup', function (): void {
    $workspace = boundedTasksWorkspace();
    $calendar = app(ProductionWorkingCalendar::class);
    expect($calendar->nextWorkingDate($workspace, '2026-09-28')->toDateString())->toBe('2026-09-28');
    ProductionHoliday::factory()->for($workspace)->create(['date' => '2026-09-28']);
    $calendar->refresh($workspace);
    expect($calendar->nextWorkingDate($workspace, '2026-09-28')->toDateString())->toBe('2026-09-29');
    expect($calendar->previousWorkingDate($workspace, '2026-09-28')->toDateString())->toBe('2026-09-27');
});

it('applies task item bounds on the dedicated task set form', function (): void {
    $workspace = boundedTasksWorkspace();
    $type = ProductionTaskType::factory()->for($workspace)->create();
    $this->actingAs($workspace->owner);
    Livewire::test(TaskSetForm::class)->set('name', 'Oversized workflow')
        ->set('taskSetItems', array_fill(0, 101, ['task_type_id' => $type->id, 'days_after_production' => 0, 'duration_minutes' => '']))
        ->call('save')->assertHasErrors(['taskSetItems' => 'max']);
    expect(ProductionTaskSet::query()->count())->toBe(0);
});

it('applies duration bounds on the production settings form', function (): void {
    $workspace = boundedTasksWorkspace();
    $this->actingAs($workspace->owner);
    Livewire::test(SettingsIndex::class, ['section' => 'task-types'])->set('taskTypeName', 'Overflow')
        ->set('taskTypeDuration', '2147483648')->call('saveTaskType')->assertHasErrors(['taskTypeDuration' => 'max']);
    expect(ProductionTaskType::query()->count())->toBe(0);
});
