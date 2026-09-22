<?php

namespace App\Livewire\ProductionBench\Production;

use App\Actions\Production\AssignProductionBatchNumbers;
use App\Actions\Production\DeleteProductionRun;
use App\Actions\Production\ScheduleProduction;
use App\Enums\ProductionRunStatus;
use App\Enums\StockReservationStatus;
use App\Enums\WorkspaceMemberRole;
use App\Livewire\Concerns\InteractsWithAppNotifications;
use App\Livewire\Concerns\NormalizesDatePickerState;
use App\Models\ProductionLocation;
use App\Models\ProductionRun;
use App\Models\Recipe;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ContextualHelp\ProductionHelpTopics;
use App\Services\Production\ProductionDailyOccupancy;
use App\Services\ProductionBenchAccess;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Schema;
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
    use NormalizesDatePickerState;
    use WithPagination;

    private const array ALLOWED_PER_PAGE = [10, 25, 50, 100];

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
        $this->clearSelection();
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->clearSelection();
        $this->resetPage();
    }

    public function updatedDateFrom(): void
    {
        $this->dateFrom = $this->normalizeDatePickerState($this->dateFrom);
        $this->clearSelection();
        $this->resetPage();
    }

    public function updatedDateTo(): void
    {
        $this->dateTo = $this->normalizeDatePickerState($this->dateTo);
        $this->clearSelection();
        $this->resetPage();
    }

    public function updatedRecipeFilter(): void
    {
        $this->clearSelection();
        $this->resetPage();
    }

    public function filterDatesForm(Schema $schema): Schema
    {
        return $schema->components([
            DatePicker::make('dateFrom')
                ->label(__('production_bench.production.from_date'))
                ->native(false)
                ->displayFormat('d/m/Y')
                ->live(),
            DatePicker::make('dateTo')
                ->label(__('production_bench.production.to_date'))
                ->native(false)
                ->displayFormat('d/m/Y')
                ->live(),
        ]);
    }

    public function updatedLocationFilter(): void
    {
        $this->clearSelection();
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->perPage = $this->normalizedPerPage();
        $this->clearSelection();
        $this->resetPage();
    }

    public function updatedPaginators(): void
    {
        $this->clearSelection();
    }

    public function clearSelection(): void
    {
        $this->selectedProductionIds = [];
    }

    public function clearRecipeFilter(): void
    {
        $this->recipeFilter = '';
        $this->clearSelection();
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

    public function prepareSelected(): void
    {
        $this->constrainSelectionToCurrentPage();

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
        $this->constrainSelectionToCurrentPage();

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

    public function render(ProductionHelpTopics $helpTopics, ProductionBenchAccess $access): View
    {
        $workspace = $this->workspace();
        $locationFilterId = $this->locationFilterId($workspace);
        $filteredRecipe = $this->filteredRecipe($workspace);
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
        $productions = $this->productionQuery($workspace, $locationFilterId, $filteredRecipe?->id)
            ->paginate($this->normalizedPerPage());

        return view('livewire.production-bench.production.production-index', [
            'contextualHelp' => $helpTopics->resolve('index', app()->getLocale()),
            'workspace' => $workspace,
            'isBenchActive' => $access->isActive($workspace),
            'isReadOnly' => $access->isReadOnly($workspace),
            'canMutate' => $canMutate,
            'productions' => $productions,
            'productionLocations' => $productionLocations,
            'partiallyReservedIds' => $this->partiallyReservedIds($productions->getCollection()),
            'visibleSelectedProductionIds' => $this->visibleSelectedProductionIds($productions->getCollection()),
            'filteredRecipeName' => $this->recipeFilter === ''
                ? null
                : ($filteredRecipe?->name ?? __('production_bench.production.unknown_product')),
        ]);
    }

    private function productionQuery(Workspace $workspace, ?int $locationFilterId, ?int $recipeId): Builder
    {
        return ProductionRun::query()
            ->where('workspace_id', $workspace->id)
            ->with(['requirements.reservations'])
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
            ->when($this->recipeFilter !== '', function (Builder $query) use ($recipeId): void {
                $recipeId === null
                    ? $query->whereRaw('0 = 1')
                    : $query->where('recipe_id', $recipeId);
            })
            ->when($locationFilterId !== null, fn (Builder $query): Builder => $query->where('production_location_id', $locationFilterId))
            ->when($this->dateFrom !== '', fn (Builder $query): Builder => $query->whereDate('planned_for', '>=', $this->dateFrom))
            ->when($this->dateTo !== '', fn (Builder $query): Builder => $query->whereDate('planned_for', '<=', $this->dateTo))
            ->orderByRaw('planned_for is null')
            ->orderBy('planned_for')
            ->orderByDesc('id');
    }

    private function filteredRecipe(Workspace $workspace): ?Recipe
    {
        if ($this->recipeFilter === '') {
            return null;
        }

        return Recipe::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('public_id', $this->recipeFilter)
            ->first(['id', 'name']);
    }

    /** @param Collection<int, ProductionRun> $productions */
    private function visibleSelectedProductionIds(Collection $productions): array
    {
        $selectableIds = $productions
            ->filter(fn (ProductionRun $production): bool => in_array(
                $production->status,
                [ProductionRunStatus::Scheduled, ProductionRunStatus::Reserved],
                true,
            ))
            ->pluck('id');

        return collect($this->selectedProductionIdsOnCurrentPage($productions))
            ->intersect($selectableIds)
            ->values()
            ->all();
    }

    /** @param Collection<int, ProductionRun> $productions */
    private function selectedProductionIdsOnCurrentPage(Collection $productions): array
    {
        return collect($this->normalizedSelectedProductionIds())
            ->intersect($productions->pluck('id'))
            ->values()
            ->all();
    }

    private function constrainSelectionToCurrentPage(): void
    {
        $workspace = $this->workspace();
        $locationFilterId = $this->locationFilterId($workspace);
        $filteredRecipe = $this->filteredRecipe($workspace);
        $productions = $this->productionQuery($workspace, $locationFilterId, $filteredRecipe?->id)
            ->forPage($this->getPage(), $this->normalizedPerPage())
            ->get();

        $this->selectedProductionIds = $this->selectedProductionIdsOnCurrentPage($productions);
    }

    /** @return list<int> */
    private function normalizedSelectedProductionIds(): array
    {
        return collect($this->selectedProductionIds)
            ->filter(fn (mixed $id): bool => is_int($id) || is_string($id))
            ->map(fn (int|string $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Productions whose active reservations cover part of a requirement but not all of it.
     *
     * Only the fact of a shortfall belongs in the list; a single combined figure would add
     * mass requirements to piece requirements and mean nothing.
     *
     * @param  Collection<int, ProductionRun>  $productions
     * @return list<int>
     */
    private function partiallyReservedIds(Collection $productions): array
    {
        $ids = [];

        foreach ($productions as $production) {
            if ($production->status !== ProductionRunStatus::Scheduled) {
                continue;
            }

            foreach ($production->requirements as $requirement) {
                $reserved = '0';

                foreach ($requirement->reservations->where('status', StockReservationStatus::Active) as $reservation) {
                    $reserved = bcadd($reserved, (string) $reservation->quantity, 9);
                }

                $required = $requirement->ingredient_id !== null
                    ? (string) $requirement->required_mass_grams
                    : (string) $requirement->required_units;

                if (bccomp($reserved, '0', 9) > 0 && bccomp($reserved, $required, 9) < 0) {
                    $ids[] = $production->id;

                    continue 2;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return array<int, list<array{label: string, count: int, limit: int}>>
     */
    private function scheduleWarnings(
        Workspace $workspace,
        Collection $productions,
        Collection $productionLocations,
        ProductionDailyOccupancy $occupancy,
        array $datesByProduction,
    ): array {
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

    public function scheduleDraftAction(): Action
    {
        return Action::make('scheduleDraft')
            ->label(__('production_bench.production.schedule_draft'))
            ->modalHeading(__('production_bench.production.schedule_draft'))
            ->visible(app(ProductionBenchAccess::class)->canWrite($this->user(), $this->workspace()))
            ->fillForm(function (array $arguments): array {
                $this->draftForScheduling($arguments);

                return ['planned_for' => null];
            })
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
            : 25;
    }

    private function workspace(): Workspace
    {
        return $this->user()->company() ?? abort(404);
    }
}
