<?php

namespace App\Livewire\ProductionBench\Production;

use App\Enums\ProductionRunStatus;
use App\Models\ProductionLocation;
use App\Models\ProductionRun;
use App\Models\ProductionTask;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ContextualHelp\ProductionHelpTopics;
use App\Services\ProductionBenchAccess;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;

class ProductionCalendar extends Component
{
    public bool $showProductions = true;

    public bool $showTasks = true;

    public bool $showCompleted = false;

    public string $rangeStart;

    public string $rangeEnd;

    public string $today;

    #[Url(as: 'location')]
    public string $locationFilter = '';

    public function mount(): void
    {
        $today = CarbonImmutable::today();
        $this->today = $today->toDateString();
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

    public function updatedLocationFilter(): void
    {
        $this->dispatchCalendarUpdate();
    }

    public function refreshEvents(): void
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
        $locationFilterId = $this->locationFilterId($workspace);
        $events = [];

        if ($this->showProductions) {
            $productions = ProductionRun::query()
                ->where('workspace_id', $workspace->id)
                ->whereNotNull('planned_for')
                ->whereDate('planned_for', '>=', $this->rangeStart)
                ->whereDate('planned_for', '<', $this->rangeEnd)
                ->whereNotIn('status', [ProductionRunStatus::Draft, ProductionRunStatus::Cancelled])
                ->when(! $this->showCompleted, fn (Builder $query): Builder => $query->where('status', '!=', ProductionRunStatus::Completed))
                ->when(
                    $workspace->uses_production_locations && $locationFilterId !== null,
                    fn (Builder $query): Builder => $query->where('production_location_id', $locationFilterId),
                )
                ->when(
                    $workspace->uses_production_locations,
                    fn (Builder $query): Builder => $query->with('productionLocation'),
                )
                ->orderBy('planned_for')
                ->orderBy('id')
                ->get();

            foreach ($productions as $production) {
                $plannedFor = $production->planned_for->toDateString();

                $events[] = [
                    'id' => 'production-'.$production->id,
                    'title' => $this->productionEventTitle($production, $workspace),
                    'start' => $plannedFor,
                    'end' => $production->planned_for->copy()->addDay()->toDateString(),
                    'allDay' => true,
                    'classNames' => ['production-calendar-production', $production->status === ProductionRunStatus::Completed ? 'production-calendar-completed' : ''],
                    'extendedProps' => [
                        'eventType' => 'production',
                        'status' => $production->status->value,
                        'publicId' => $production->public_id,
                        'productionLocation' => $workspace->uses_production_locations
                            ? $production->productionLocation?->name
                            : null,
                        'url' => route('production-bench.production.show', ['productionRun' => $production->public_id]),
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
                ->when(
                    $workspace->uses_production_locations && $locationFilterId !== null,
                    fn (Builder $query): Builder => $query->whereHas(
                        'productionRun',
                        fn (Builder $productionQuery): Builder => $productionQuery->where('production_location_id', $locationFilterId),
                    ),
                )
                ->with($workspace->uses_production_locations ? 'productionRun.productionLocation' : 'productionRun')
                ->orderBy('scheduled_for')
                ->orderBy('id')
                ->get();

            foreach ($tasks as $task) {
                $colour = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $task->colour_snapshot) === 1
                    ? strtoupper((string) $task->colour_snapshot)
                    : null;

                $events[] = [
                    'id' => 'task-'.$task->id,
                    'title' => $task->name_snapshot,
                    'start' => $task->scheduled_for->toDateString(),
                    'end' => $task->scheduled_for->copy()->addDay()->toDateString(),
                    'allDay' => true,
                    'backgroundColor' => $colour,
                    'borderColor' => $colour,
                    'classNames' => ['production-calendar-task', $task->completed_at !== null ? 'production-calendar-completed' : ''],
                    'extendedProps' => [
                        'eventType' => 'task',
                        'completed' => $task->completed_at !== null,
                        'production' => $task->productionRun?->displayRecipeName(),
                        'productionLocation' => $workspace->uses_production_locations
                            ? $task->productionRun?->productionLocation?->name
                            : null,
                        'colour' => $colour,
                        'url' => route('production-bench.production.show', ['productionRun' => $task->productionRun?->public_id]),
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

    public function render(ProductionHelpTopics $helpTopics, ProductionBenchAccess $access): View
    {
        $workspace = $this->workspace();
        $productionLocations = $workspace->uses_production_locations
            ? ProductionLocation::query()
                ->where('workspace_id', $workspace->id)
                ->orderBy('name')
                ->get()
            : collect();

        return view('livewire.production-bench.production.production-calendar', [
            'contextualHelp' => $helpTopics->resolve('calendar', app()->getLocale()),
            'workspace' => $workspace,
            'productionLocations' => $productionLocations,
            'events' => $this->events(),
            'isBenchActive' => $access->isActive($workspace),
            'isReadOnly' => $access->isReadOnly($workspace),
        ]);
    }

    private function dispatchCalendarUpdate(): void
    {
        $this->dispatch(
            'production-calendar-updated',
            events: $this->events(),
            showProductions: $this->showProductions,
            showTasks: $this->showTasks,
            showCompleted: $this->showCompleted,
            rangeStart: $this->rangeStart,
            rangeEnd: $this->rangeEnd,
        );
    }

    private function productionEventTitle(ProductionRun $production, Workspace $workspace): string
    {
        $title = trim($production->displayIdentifier().' · '.$production->displayRecipeName());

        if (! $workspace->uses_production_locations) {
            return $title;
        }

        return trim($title.' · '.($production->productionLocation?->name ?? __('locations.unassigned')));
    }

    private function locationFilterId(Workspace $workspace): ?int
    {
        if (! $workspace->uses_production_locations) {
            $this->locationFilter = '';

            return null;
        }

        $value = trim($this->locationFilter);
        if ($value === '') {
            return null;
        }

        $location = ProductionLocation::query()
            ->where('workspace_id', $workspace->id)
            ->when(ctype_digit($value), fn (Builder $query): Builder => $query->whereKey((int) $value))
            ->when(! ctype_digit($value), fn (Builder $query): Builder => $query->where('public_id', $value))
            ->first(['id']);

        if (! $location instanceof ProductionLocation) {
            $this->locationFilter = '';

            return null;
        }

        return (int) $location->id;
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
