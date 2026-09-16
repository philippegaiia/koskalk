<?php

namespace App\Livewire\ProductionBench\Production;

use App\Actions\Production\AssignProductionBatchNumbers;
use App\Actions\Production\DeleteProductionRun;
use App\Actions\Production\ScheduleProduction;
use App\Enums\ProductionRunStatus;
use App\Enums\WorkspaceMemberRole;
use App\Livewire\Concerns\InteractsWithAppNotifications;
use App\Models\ProductionLocation;
use App\Models\ProductionRun;
use App\Models\Recipe;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Production\ProductionDailyOccupancy;
use App\Services\ProductionBenchAccess;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class ProductionIndex extends Component implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithAppNotifications;
    use InteractsWithForms;
    use WithPagination;

    private const array ALLOWED_PER_PAGE = [25, 50, 100];

    public string $search = '';

    public string $status = '';

    public string $dateFrom = '';

    public string $dateTo = '';

    public int $perPage = 25;

    #[Url(as: 'recipe')]
    public string $recipeFilter = '';

    #[Url(as: 'location')]
    public string $locationFilter = '';

    /** @var list<int> */
    public array $selectedProductionIds = [];

    public ?string $statusMessage = null;

    public string $statusType = 'idle';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedDateFrom(): void
    {
        $this->resetPage();
    }

    public function updatedDateTo(): void
    {
        $this->resetPage();
    }

    public function updatedLocationFilter(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->perPage = $this->normalizedPerPage();
        $this->resetPage();
    }

    public function deleteProduction(int $productionId, DeleteProductionRun $deleteProductionRun): void
    {
        try {
            $production = ProductionRun::query()
                ->where('workspace_id', $this->workspace()->id)
                ->findOrFail($productionId);

            $deleteProductionRun->handle($this->user(), $production);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError('selectedProductionIds', $message);
                }
            }

            return;
        }

        $this->showAppNotification(__('production_bench.production.deleted'));
        $this->dispatch('production-deleted');
    }

    /** @var array<int, string> */
    public array $scheduleDates = [];

    public function scheduleProduction(int $productionId, ScheduleProduction $scheduleProduction): void
    {
        try {
            $this->validate([
                'scheduleDates.'.$productionId => ['required', 'date_format:Y-m-d'],
            ]);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->addError('scheduleDate', $message);
                }
            }

            return;
        }

        $date = $this->scheduleDates[$productionId] ?? '';

        try {
            $production = ProductionRun::query()
                ->where('workspace_id', $this->workspace()->id)
                ->findOrFail($productionId);

            $scheduleProduction->handle(
                actor: $this->user(),
                production: $production,
                plannedFor: $date,
            );
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError('scheduleDate', $message);
                }
            }

            return;
        }

        unset($this->scheduleDates[$productionId]);
        $this->showAppNotification(__('production_bench.production.planned_success'));
        $this->dispatch('production-scheduled');
    }

    public function prepareSelected(): void
    {
        $this->selectedProductionIds = array_values(array_filter(
            array_map('intval', $this->selectedProductionIds),
            fn (int $id): bool => $id > 0,
        ));

        if ($this->selectedProductionIds === []) {
            $this->addError('selectedProductionIds', __('production_bench.production.select_production_to_prepare'));

            return;
        }

        $this->redirectRoute('production-bench.production.prepare', [
            'ids' => implode(',', $this->selectedProductionIds),
        ]);
    }

    public function assignSelectedBatchNumbers(AssignProductionBatchNumbers $assignProductionBatchNumbers): void
    {
        try {
            $result = $assignProductionBatchNumbers->handle(
                actor: $this->user(),
                workspace: $this->workspace(),
                productionIds: $this->selectedProductionIds,
            );
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError(
                        in_array($field, ['production_ids', 'batch_number', 'next_permanent_serial', 'production_bench'], true)
                            ? 'selectedProductionIds'
                            : $field,
                        $message,
                    );
                }
            }

            return;
        }

        $this->selectedProductionIds = [];
        $this->showAppNotification(__('production_bench.production.batch_numbers_assigned', [
            'assigned' => $result['assigned'],
            'already' => $result['already_assigned'],
        ]));
        $this->dispatch('production-batch-numbers-updated');
    }

    public function render(ProductionBenchAccess $access, ProductionDailyOccupancy $occupancy): View
    {
        $workspace = $this->workspace();
        $locationFilterId = $this->locationFilterId($workspace);
        $productionLocations = $workspace->uses_production_locations
            ? ProductionLocation::query()
                ->where('workspace_id', $workspace->id)
                ->orderBy('name')
                ->get()
            : collect();
        $canMutate = $access->isActive($workspace)
            && ! $access->isReadOnly($workspace)
            && in_array($workspace->roleFor($this->user()), [
                WorkspaceMemberRole::Owner,
                WorkspaceMemberRole::Admin,
                WorkspaceMemberRole::Editor,
            ], true);
        $productions = ProductionRun::query()
            ->where('workspace_id', $workspace->id)
            ->with(['tasks', 'requirements.reservations'])
            ->when(
                $workspace->uses_production_locations,
                fn (Builder $query): Builder => $query->with('productionLocation'),
            )
            ->when($this->search !== '', function (Builder $query): void {
                $search = trim($this->search);
                $query->where(function (Builder $nested) use ($search): void {
                    $nested
                        ->where('recipe_name_snapshot', 'like', "%{$search}%")
                        ->orWhere('public_id', 'like', "%{$search}%")
                        ->orWhere('planning_batch_number', 'like', "%{$search}%")
                        ->orWhere('batch_number', 'like', "%{$search}%");
                });
            })
            ->when($this->status !== '', fn (Builder $query): Builder => $query->where('status', $this->status))
            ->when($this->recipeFilter !== '', function (Builder $query): void {
                $recipeId = Recipe::withoutGlobalScopes()
                    ->where('public_id', $this->recipeFilter)
                    ->value('id');

                if ($recipeId === null) {
                    $query->whereRaw('0 = 1');

                    return;
                }

                $query->where('recipe_id', $recipeId);
            })
            ->when($locationFilterId !== null, fn (Builder $query): Builder => $query->where('production_location_id', $locationFilterId))
            ->when($this->dateFrom !== '', fn (Builder $query): Builder => $query->whereDate('planned_for', '>=', $this->dateFrom))
            ->when($this->dateTo !== '', fn (Builder $query): Builder => $query->whereDate('planned_for', '<=', $this->dateTo))
            ->orderByRaw('planned_for is null')
            ->orderBy('planned_for')
            ->orderByDesc('id')
            ->paginate($this->normalizedPerPage());

        return view('livewire.production-bench.production.production-index', [
            'workspace' => $workspace,
            'isBenchActive' => $access->isActive($workspace),
            'isReadOnly' => $access->isReadOnly($workspace),
            'canMutate' => $canMutate,
            'productions' => $productions,
            'productionLocations' => $productionLocations,
            'scheduleWarnings' => $this->scheduleWarnings(
                workspace: $workspace,
                productions: $productions->getCollection(),
                productionLocations: $productionLocations,
                occupancy: $occupancy,
            ),
        ]);
    }

    /**
     * @return array<int, list<array{label: string, count: int, limit: int}>>
     */
    private function scheduleWarnings(
        Workspace $workspace,
        Collection $productions,
        Collection $productionLocations,
        ProductionDailyOccupancy $occupancy,
        ?array $datesByProduction = null,
    ): array {
        $datesByProduction ??= $this->scheduleDates;
        $dates = $productions
            ->map(fn (ProductionRun $production): ?string => $datesByProduction[$production->id] ?? null)
            ->filter(fn (?string $date): bool => $date !== null && $this->isDate($date))
            ->values();

        if ($dates->isEmpty()) {
            return [];
        }

        $occupied = $occupancy->between(
            workspace: $workspace,
            from: $dates->min(),
            through: $dates->max().' 23:59:59',
        );
        $warnings = [];

        foreach ($productions as $production) {
            $date = $datesByProduction[$production->id] ?? '';
            if (! $this->isDate($date)) {
                continue;
            }

            $productionWarnings = [];
            $overallCount = ($occupied['overall'][$date] ?? 0) + 1;

            if ($overallCount > (int) $workspace->production_daily_limit) {
                $productionWarnings[] = [
                    'label' => __('locations.daily_production_limit'),
                    'count' => $overallCount,
                    'limit' => (int) $workspace->production_daily_limit,
                ];
            }

            if ($workspace->uses_production_locations && $production->production_location_id !== null) {
                $location = $productionLocations->firstWhere('id', $production->production_location_id);
                $location ??= $production->productionLocation;

                if ($location instanceof ProductionLocation) {
                    $locationCount = ($occupied['locations'][$location->id][$date] ?? 0) + 1;

                    if ($locationCount > $location->daily_production_limit) {
                        $productionWarnings[] = [
                            'label' => $location->name,
                            'count' => $locationCount,
                            'limit' => (int) $location->daily_production_limit,
                        ];
                    }
                }
            }

            if ($productionWarnings !== []) {
                $warnings[$production->id] = $productionWarnings;
            }
        }

        return $warnings;
    }

    private function locationFilterId(Workspace $workspace): ?int
    {
        if (! $workspace->uses_production_locations) {
            if ($this->locationFilter !== '') {
                $this->locationFilter = '';
                $this->resetPage();
            }

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
            $this->resetPage();

            return null;
        }

        return (int) $location->id;
    }

    private function isDate(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();

        return $date !== false
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $date->format('Y-m-d') === $value;
    }

    public function updated(string $property): void
    {
        if (! str_starts_with($property, 'scheduleDates.')) {
            return;
        }

        $key = substr($property, strlen('scheduleDates.'));
        $value = (string) ($this->scheduleDates[$key] ?? '');
        $date = preg_match('/^\d{4}-\d{2}-\d{2}(?: 00:00:00)?$/', $value) === 1 ? substr($value, 0, 10) : '';
        $this->scheduleDates[$key] = validator(['date' => $date], ['date' => 'required|date_format:Y-m-d'])->passes() ? $date : '';
    }

    public function scheduleDraftAction(): Action
    {
        return Action::make('scheduleDraft')
            ->label(__('production_bench.production.schedule_draft'))
            ->modalHeading(__('production_bench.production.schedule_draft'))
            ->visible(app(ProductionBenchAccess::class)->canWrite($this->user(), $this->workspace()))
            ->fillForm(fn (array $arguments): array => [
                'planned_for' => $this->scheduleDates[$this->draftForScheduling($arguments)->id] ?? null,
            ])
            ->schema(fn (array $arguments): array => [
                DatePicker::make('planned_for')
                    ->label(__('production_bench.production.production_date'))
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->required()
                    ->live()
                    ->helperText(function (mixed $state) use ($arguments): ?string {
                        $date = substr((string) $state, 0, 10);
                        if (! $this->isDate($date)) {
                            return null;
                        }
                        $production = $this->draftForScheduling($arguments);
                        $warnings = $this->scheduleWarnings($this->workspace(), collect([$production]), collect(), app(ProductionDailyOccupancy::class), [$production->id => $date]);
                        $warnings = $warnings[$production->id] ?? [];

                        return $warnings === [] ? null : __('locations.capacity_warning').' '.collect($warnings)
                            ->map(fn (array $warning): string => $warning['label'].': '.$warning['count'].' / '.$warning['limit'])->implode(' · ');
                    }),
            ])
            ->action(function (array $data, array $arguments, ScheduleProduction $scheduleProduction): void {
                $production = $this->draftForScheduling($arguments);
                try {
                    $scheduleProduction->handle($this->user(), $production, $data['planned_for']);
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages([
                        $this->getMountedActionSchema()->getStatePath().'.planned_for' => collect($exception->errors())->flatten()->all(),
                    ]);
                }
                unset($this->scheduleDates[$production->id]);
                $this->showAppNotification(__('production_bench.production.planned_success'));
                $this->dispatch('production-scheduled');
            });
    }

    /** @param array<string, mixed> $arguments */
    private function draftForScheduling(array $arguments): ProductionRun
    {
        return ProductionRun::query()->where('workspace_id', $this->workspace()->id)
            ->where('status', ProductionRunStatus::Draft)
            ->findOrFail($arguments['productionId'] ?? null);
    }

    private function user(): User
    {
        return auth()->user() ?? abort(401);
    }

    private function normalizedPerPage(): int
    {
        return in_array($this->perPage, self::ALLOWED_PER_PAGE, true)
            ? $this->perPage
            : self::ALLOWED_PER_PAGE[0];
    }

    private function workspace(): Workspace
    {
        return $this->user()->company() ?? abort(404);
    }
}
