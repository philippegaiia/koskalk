<?php

namespace App\Livewire\ProductionBench\Production;

use App\Models\ProductionRun;
use App\Models\ProductionTask;
use App\Models\User;
use App\Models\Workspace;
use App\ProductionRunStatus;
use App\Services\ProductionBenchAccess;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class ProductionCalendar extends Component
{
    public bool $showProductions = true;

    public bool $showTasks = true;

    public bool $showCompleted = false;

    public string $rangeStart;

    public string $rangeEnd;

    public function mount(): void
    {
        $today = CarbonImmutable::today();
        $this->rangeStart = $today->startOfMonth()->toDateString();
        $this->rangeEnd = $today->endOfMonth()->addDay()->toDateString();
    }

    public function updatedShowProductions(): void
    {
        $this->dispatchCalendarUpdate();
    }

    public function updatedShowTasks(): void
    {
        $this->dispatchCalendarUpdate();
    }

    public function updatedShowCompleted(): void
    {
        $this->dispatchCalendarUpdate();
    }

    public function setRange(string $start, string $end): void
    {
        try {
            $startDate = CarbonImmutable::createFromFormat('!Y-m-d', $start);
            $endDate = CarbonImmutable::createFromFormat('!Y-m-d', $end);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'range' => __('production_bench.calendar.invalid_range'),
            ]);
        }

        if (! $startDate instanceof CarbonImmutable || ! $endDate instanceof CarbonImmutable || $endDate->lessThanOrEqualTo($startDate) || $startDate->diffInDays($endDate) > 366) {
            throw ValidationException::withMessages([
                'range' => __('production_bench.calendar.invalid_range'),
            ]);
        }

        $this->rangeStart = $startDate->toDateString();
        $this->rangeEnd = $endDate->toDateString();
        $this->dispatchCalendarUpdate();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function events(): array
    {
        $workspace = $this->workspace();
        $events = [];

        if ($this->showProductions) {
            $productions = ProductionRun::query()
                ->where('workspace_id', $workspace->id)
                ->whereNotNull('planned_for')
                ->whereDate('planned_for', '>=', $this->rangeStart)
                ->whereDate('planned_for', '<', $this->rangeEnd)
                ->whereNotIn('status', [ProductionRunStatus::Draft, ProductionRunStatus::Cancelled])
                ->when(! $this->showCompleted, fn (Builder $query): Builder => $query->where('status', '!=', ProductionRunStatus::Completed))
                ->with('recipe')
                ->orderBy('planned_for')
                ->orderBy('id')
                ->get();

            foreach ($productions as $production) {
                $events[] = [
                    'id' => 'production-'.$production->id,
                    'title' => $production->recipe?->name ?? __('production_bench.production.unknown_product'),
                    'start' => $production->planned_for->toDateString(),
                    'allDay' => true,
                    'url' => route('production-bench.production.show', ['productionRun' => $production->public_id]),
                    'classNames' => ['production-calendar-production', $production->status === ProductionRunStatus::Completed ? 'production-calendar-completed' : ''],
                    'extendedProps' => [
                        'eventType' => 'production',
                        'status' => $production->status->value,
                        'publicId' => $production->public_id,
                    ],
                ];
            }
        }

        if ($this->showTasks) {
            $tasks = ProductionTask::query()
                ->where('workspace_id', $workspace->id)
                ->whereDate('scheduled_for', '>=', $this->rangeStart)
                ->whereDate('scheduled_for', '<', $this->rangeEnd)
                ->when(! $this->showCompleted, fn (Builder $query): Builder => $query->whereNull('completed_at'))
                ->with('productionRun.recipe')
                ->orderBy('scheduled_for')
                ->orderBy('id')
                ->get();

            foreach ($tasks as $task) {
                $events[] = [
                    'id' => 'task-'.$task->id,
                    'title' => $task->name_snapshot,
                    'start' => $task->scheduled_for->toDateString(),
                    'allDay' => true,
                    'url' => route('production-bench.production.show', ['productionRun' => $task->productionRun?->public_id]),
                    'classNames' => ['production-calendar-task', $task->completed_at !== null ? 'production-calendar-completed' : ''],
                    'extendedProps' => [
                        'eventType' => 'task',
                        'completed' => $task->completed_at !== null,
                        'production' => $task->productionRun?->recipe?->name,
                    ],
                ];
            }
        }

        return array_values(array_map(
            fn (array $event): array => [
                ...$event,
                'classNames' => array_values(array_filter($event['classNames'])),
            ],
            $events,
        ));
    }

    public function render(ProductionBenchAccess $access): View
    {
        $workspace = $this->workspace();

        return view('livewire.production-bench.production.production-calendar', [
            'workspace' => $workspace,
            'events' => $this->events(),
            'isBenchActive' => $access->isActive($workspace),
            'isReadOnly' => $access->isReadOnly($workspace),
        ]);
    }

    private function dispatchCalendarUpdate(): void
    {
        $this->dispatch('production-calendar-updated', events: $this->events());
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
