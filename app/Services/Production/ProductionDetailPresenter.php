<?php

namespace App\Services\Production;

use App\Enums\ProductionFormulaComponent;
use App\Enums\ProductionRequirementKind;
use App\Enums\ProductionRunStatus;
use App\Enums\StockLotStatus;
use App\Enums\StockReservationStatus;
use App\Models\ProductionFormulaLine;
use App\Models\ProductionRequirement;
use App\Models\ProductionRun;
use App\Support\NumberLocale;

class ProductionDetailPresenter
{
    public function __construct(
        private readonly ProductionOutputReconciliation $outputReconciliation,
    ) {}

    /**
     * @param  array<string, array<string, mixed>>  $actualRows
     * @param  array<string, array{actual_mass_grams: string}>  $calculatedActualRows
     * @return array{
     *   identity: array<string, string|null>,
     *   lifecycle: list<array{key: string, label: string, state: string}>,
     *   primary_action: string|null,
     *   materials: list<array<string, mixed>>,
     *   has_active_reservations: bool,
     *   output: array{unit: string, planned: string, actual: string|null, variance: string|null, variance_percentage: string|null},
     *   release: array{has_output: bool, quarantined: bool, ready_date_reached: bool, tasks_complete: bool, ready: bool, available_from: string|null, incomplete_tasks: list<string>},
     * }
     */
    public function present(
        ProductionRun $production,
        array $actualRows = [],
        array $calculatedActualRows = [],
        ?string $locale = null,
    ): array {
        $locale ??= auth()->user()?->number_locale;

        return [
            'identity' => $this->identity($production, $locale),
            'lifecycle' => $this->lifecycle($production),
            'primary_action' => $this->primaryAction($production),
            'materials' => $this->materials($production, $actualRows, $calculatedActualRows, $locale),
            'has_active_reservations' => $production->requirements
                ->flatMap->reservations
                ->contains(fn ($reservation): bool => $reservation->status === StockReservationStatus::Active),
            'output' => $this->outputReconciliation->forProduction($production),
            'release' => $this->releaseReadiness($production),
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function identity(ProductionRun $production, ?string $locale): array
    {
        return [
            'identifier' => $production->displayIdentifier(),
            'product_name' => $production->displayRecipeName(),
            'planned_for' => $production->planned_for?->format('Y-m-d'),
            'basis' => NumberLocale::formatAdaptiveDecimal($production->basis_input_value, 0, 3, $locale)
                .' '.$production->basis_input_unit->value,
            'expected_units' => NumberLocale::formatDecimal($production->expected_units, 0, $locale),
            'formula_version' => $production->source_formula_version_number === null
                ? null
                : (string) $production->source_formula_version_number,
        ];
    }

    /**
     * @return list<array{key: string, label: string, state: string}>
     */
    private function lifecycle(ProductionRun $production): array
    {
        $steps = [
            ProductionRunStatus::Draft,
            ProductionRunStatus::Scheduled,
            ProductionRunStatus::Reserved,
            ProductionRunStatus::InProduction,
            ProductionRunStatus::Completed,
        ];
        $terminalStatuses = [ProductionRunStatus::Cancelled, ProductionRunStatus::Aborted];

        if (in_array($production->status, $terminalStatuses, true)) {
            return [
                ...array_map(
                    fn (ProductionRunStatus $status): array => [
                        'key' => $status->value,
                        'label' => $status->label(),
                        'state' => 'upcoming',
                    ],
                    $steps,
                ),
                [
                    'key' => $production->status->value,
                    'label' => $production->status->label(),
                    'state' => 'terminal',
                ],
            ];
        }

        $currentIndex = array_search($production->status, $steps, true);

        return array_map(
            fn (ProductionRunStatus $status, int $index): array => [
                'key' => $status->value,
                'label' => $status->label(),
                'state' => $index < $currentIndex ? 'completed' : ($index === $currentIndex ? 'current' : 'upcoming'),
            ],
            $steps,
            array_keys($steps),
        );
    }

    private function primaryAction(ProductionRun $production): ?string
    {
        return match ($production->status) {
            ProductionRunStatus::Draft => 'schedule',
            ProductionRunStatus::Scheduled => 'prepare_stock',
            ProductionRunStatus::Reserved => $production->batch_number === null ? 'assign_batch_number' : 'start',
            ProductionRunStatus::InProduction => 'complete',
            ProductionRunStatus::Completed => $production->outputLot?->status === StockLotStatus::Quarantined
                ? 'release_batch'
                : null,
            default => null,
        };
    }

    /**
     * @param  array<string, array<string, mixed>>  $actualRows
     * @param  array<string, array{actual_mass_grams: string}>  $calculatedActualRows
     * @return list<array<string, mixed>>
     */
    private function materials(
        ProductionRun $production,
        array $actualRows,
        array $calculatedActualRows,
        ?string $locale,
    ): array {
        $requirements = $production->requirements;
        $usedRequirementIds = [];
        $materials = [];

        foreach ($production->formulaLines as $line) {
            $requirement = $line->component === ProductionFormulaComponent::Water
                ? null
                : $this->requirementForLine($line, $requirements, $usedRequirementIds);

            $materials[] = [
                'key' => 'formula-'.$line->id,
                'group_key' => (string) ($line->phase_key_snapshot ?? 'formula'),
                'group_name' => (string) ($line->phase_name_snapshot ?? __('production_bench.production.formula.title')),
                'material_name' => $line->subject_name_snapshot,
                'percentage' => $line->basis_percentage_snapshot === null
                    ? null
                    : $this->formatPercentage($line->basis_percentage_snapshot, $locale),
                'note' => $line->note_snapshot,
                'planned' => [
                    'quantity' => $this->formatMass($line->planned_mass_grams, $locale),
                    'unit' => 'g',
                ],
                'reservation' => $this->reservationSummary($requirement, $locale),
                'actual' => $line->component === ProductionFormulaComponent::Water
                    ? $this->waterActual($production, $line, $calculatedActualRows)
                    : $this->materialActual($production, $requirement, $actualRows),
            ];
        }

        // Production runs created before formula snapshots were introduced
        // still have valid requirement snapshots. Keep those records visible
        // while the new page converges on formula lines as its primary source.
        foreach ($requirements->where('kind', ProductionRequirementKind::Ingredient) as $requirement) {
            if (isset($usedRequirementIds[$requirement->id])) {
                continue;
            }

            $usedRequirementIds[$requirement->id] = true;
            $materials[] = [
                'key' => 'requirement-'.$requirement->id,
                'group_key' => (string) ($requirement->phase_key_snapshot ?? 'formula'),
                'group_name' => (string) ($requirement->phase_name_snapshot ?? __('production_bench.production.formula.title')),
                'material_name' => $requirement->subject_name_snapshot,
                'percentage' => $requirement->percentage_snapshot === null
                    ? null
                    : $this->formatPercentage($requirement->percentage_snapshot, $locale),
                'note' => $requirement->note_snapshot,
                'planned' => [
                    'quantity' => $this->formatQuantity((string) $requirement->required_mass_grams, false, $locale),
                    'unit' => 'g',
                ],
                'reservation' => $this->reservationSummary($requirement, $locale),
                'actual' => $this->materialActual($production, $requirement, $actualRows),
            ];
        }

        foreach ($requirements->where('kind', ProductionRequirementKind::Packaging) as $requirement) {
            $materials[] = [
                'key' => 'packaging-'.$requirement->id,
                'group_key' => 'packaging',
                'group_name' => __('production_bench.production.packaging_requirement'),
                'material_name' => $requirement->subject_name_snapshot,
                'percentage' => null,
                'note' => $requirement->note_snapshot,
                'planned' => [
                    'quantity' => NumberLocale::formatDecimal($requirement->required_units, 0, $locale),
                    'unit' => 'units',
                ],
                'reservation' => $this->reservationSummary($requirement, $locale),
                'actual' => $this->materialActual($production, $requirement, $actualRows),
            ];
        }

        return $materials;
    }

    /**
     * @param  iterable<ProductionRequirement>  $requirements
     * @param  array<int, bool>  $usedRequirementIds
     */
    private function requirementForLine(
        ProductionFormulaLine $line,
        iterable $requirements,
        array &$usedRequirementIds,
    ): ?ProductionRequirement {
        $candidates = collect($requirements)
            ->where('kind', ProductionRequirementKind::Ingredient)
            ->reject(fn (ProductionRequirement $requirement): bool => isset($usedRequirementIds[$requirement->id]));

        if ($line->recipe_item_id !== null) {
            $requirement = $candidates->firstWhere('recipe_item_id', $line->recipe_item_id);

            if ($requirement instanceof ProductionRequirement) {
                $usedRequirementIds[$requirement->id] = true;

                return $requirement;
            }
        }

        if ($line->ingredient_id !== null) {
            $requirement = $candidates->firstWhere('ingredient_id', $line->ingredient_id);

            if ($requirement instanceof ProductionRequirement) {
                $usedRequirementIds[$requirement->id] = true;

                return $requirement;
            }
        }

        return null;
    }

    /**
     * @return array{tracked: bool, total: string|null, lots: list<array{id: int, code: string, quantity: string}>}
     */
    private function reservationSummary(?ProductionRequirement $requirement, ?string $locale): array
    {
        if (! $requirement instanceof ProductionRequirement) {
            return [
                'tracked' => false,
                'total' => null,
                'lots' => [],
            ];
        }

        $activeReservations = $requirement->reservations
            ->filter(fn ($reservation): bool => $reservation->status === StockReservationStatus::Active);
        $total = $activeReservations->reduce(
            fn (string $sum, $reservation): string => bcadd($sum, (string) $reservation->quantity, 9),
            '0',
        );

        return [
            'tracked' => true,
            'total' => $this->formatQuantity($total, $requirement->kind === ProductionRequirementKind::Packaging, $locale),
            'lots' => $activeReservations
                ->map(fn ($reservation): array => [
                    'id' => (int) $reservation->stock_lot_id,
                    'code' => (string) ($reservation->stockLot?->internal_lot_code ?? '—'),
                    'quantity' => $this->formatQuantity(
                        (string) $reservation->quantity,
                        $requirement->kind === ProductionRequirementKind::Packaging,
                        $locale,
                    ),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $actualRows
     * @return array{mode: string, rows: list<array<string, mixed>>}
     */
    private function materialActual(
        ProductionRun $production,
        ?ProductionRequirement $requirement,
        array $actualRows,
    ): array {
        $mode = $this->actualMode($production);

        if (! $requirement instanceof ProductionRequirement || $mode === 'hidden') {
            return ['mode' => $mode, 'rows' => []];
        }

        $rows = [];

        foreach ($actualRows as $key => $row) {
            [$requirementId, $lotId] = array_pad(explode('-', (string) $key, 2), 2, '');

            if ((int) $requirementId !== $requirement->id || ! is_array($row)) {
                continue;
            }

            $resolvedLotId = $lotId !== ''
                ? (int) $lotId
                : ($row['stock_lot_id'] ?? null);
            $rows[] = $this->actualRow(
                stateKey: 'actualRows.'.(string) $key,
                lotId: $resolvedLotId !== null ? (int) $resolvedLotId : null,
                quantity: (string) ($row['quantity'] ?? ''),
                note: isset($row['note']) && $row['note'] !== '' ? (string) $row['note'] : null,
            );
        }

        if ($rows === []) {
            foreach ($production->consumption->where('production_requirement_id', $requirement->id) as $consumption) {
                $rows[] = $this->actualRow(
                    stateKey: 'actualRows.'.$requirement->id.'-'.($consumption->stock_lot_id ?? ''),
                    lotId: $consumption->stock_lot_id,
                    quantity: (string) $consumption->quantity,
                    note: $consumption->note,
                );
            }
        }

        if ($rows === [] && $mode === 'editable') {
            foreach ($requirement->reservations->where('status', StockReservationStatus::Active) as $reservation) {
                $rows[] = $this->actualRow(
                    stateKey: 'actualRows.'.$requirement->id.'-'.$reservation->stock_lot_id,
                    lotId: $reservation->stock_lot_id,
                    quantity: (string) $reservation->quantity,
                    note: null,
                );
            }
        }

        return ['mode' => $mode, 'rows' => $rows];
    }

    /**
     * @param  array<string, array{actual_mass_grams: string}>  $calculatedActualRows
     * @return array{mode: string, rows: list<array<string, mixed>>}
     */
    private function waterActual(
        ProductionRun $production,
        ProductionFormulaLine $line,
        array $calculatedActualRows,
    ): array {
        $mode = $this->actualMode($production);

        if ($mode === 'hidden') {
            return ['mode' => $mode, 'rows' => []];
        }

        $value = $calculatedActualRows[(string) $line->id]['actual_mass_grams']
            ?? $line->actual_mass_grams
            ?? $line->planned_mass_grams;

        return [
            'mode' => $mode,
            'rows' => [$this->actualRow(
                stateKey: 'calculatedActualRows.'.$line->id,
                lotId: null,
                quantity: (string) $value,
                note: null,
            )],
        ];
    }

    /**
     * @return array{state_key: string, lot_id: int|null, quantity: string, note: string|null}
     */
    private function actualRow(string $stateKey, ?int $lotId, string $quantity, ?string $note): array
    {
        return [
            'state_key' => $stateKey,
            'lot_id' => $lotId,
            'quantity' => $quantity,
            'note' => $note,
        ];
    }

    private function actualMode(ProductionRun $production): string
    {
        return match ($production->status) {
            ProductionRunStatus::InProduction => 'editable',
            ProductionRunStatus::Completed, ProductionRunStatus::Aborted => 'readonly',
            default => 'hidden',
        };
    }

    /**
     * @return array{has_output: bool, quarantined: bool, ready_date_reached: bool, tasks_complete: bool, ready: bool, available_from: string|null, incomplete_tasks: list<string>}
     */
    private function releaseReadiness(ProductionRun $production): array
    {
        $outputLot = $production->outputLot;
        $incompleteTasks = $production->tasks
            ->filter(fn ($task): bool => $task->completed_at === null)
            ->pluck('name_snapshot')
            ->values()
            ->all();
        $availableFrom = $outputLot?->available_from?->format('Y-m-d');
        $readyDateReached = $availableFrom === null || $availableFrom <= today()->format('Y-m-d');
        $quarantined = $outputLot?->status === StockLotStatus::Quarantined;
        $tasksComplete = $incompleteTasks === [];

        return [
            'has_output' => $outputLot !== null,
            'quarantined' => $quarantined,
            'ready_date_reached' => $readyDateReached,
            'tasks_complete' => $tasksComplete,
            'ready' => $production->status === ProductionRunStatus::Completed
                && $quarantined
                && $readyDateReached
                && $tasksComplete,
            'available_from' => $availableFrom,
            'incomplete_tasks' => $incompleteTasks,
        ];
    }

    private function formatMass(mixed $quantity, ?string $locale): string
    {
        return NumberLocale::formatAdaptiveDecimal($quantity, 0, 3, $locale);
    }

    private function formatPercentage(mixed $percentage, ?string $locale): string
    {
        return $this->formatMass($percentage, $locale).'%';
    }

    private function formatQuantity(string $quantity, bool $whole, ?string $locale): string
    {
        return $whole
            ? NumberLocale::formatDecimal($quantity, 0, $locale)
            : $this->formatMass($quantity, $locale);
    }
}
