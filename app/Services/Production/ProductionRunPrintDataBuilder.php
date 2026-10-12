<?php

namespace App\Services\Production;

use App\Enums\ProductionFormulaComponent;
use App\Enums\ProductionRequirementKind;
use App\Enums\ProductionRunStatus;
use App\Enums\StockReservationStatus;
use App\Models\ProductionConsumption;
use App\Models\ProductionFormulaLine;
use App\Models\ProductionRequirement;
use App\Models\ProductionRun;
use App\Models\StockReservation;
use Illuminate\Support\Collection;

final class ProductionRunPrintDataBuilder
{
    /** @return array<string, mixed> */
    public function build(ProductionRun $production): array
    {
        $production->load(['formulaLines', 'requirements', 'consumption']);
        $context = $production->formula_context_snapshot ?? [];
        $record = in_array($production->status, [ProductionRunStatus::Completed, ProductionRunStatus::Aborted], true);
        $remaining = $production->requirements->keyBy('id');
        $materials = $production->formulaLines->map(function (ProductionFormulaLine $line) use ($production, &$remaining): array {
            $requirement = $this->requirementForLine($line, $remaining);
            if ($requirement !== null) {
                $remaining->forget($requirement->id);
            }

            return [
                'requirement_id' => $requirement?->id,
                'group_key' => $line->component === ProductionFormulaComponent::Ingredient ? ($line->phase_key_snapshot ?? 'formula') : 'lye_water',
                'group_label' => $line->component === ProductionFormulaComponent::Ingredient
                    ? ($line->phase_name_snapshot ?? __('production_bench.run_print.formula'))
                    : __('formula_documents.sections.lye_water'),
                'is_packaging' => false,
                'name' => $line->subject_name_snapshot,
                'code' => $requirement?->material_code_snapshot,
                'phase' => $line->phase_name_snapshot,
                'percentage' => $line->basis_percentage_snapshot,
                'planned' => $line->planned_mass_grams,
                'actual' => ($requirement !== null ? $this->actual($production, $requirement) : null) ?? $line->actual_mass_grams,
                'unit' => 'g',
                'note' => $line->note_snapshot,
            ];
        })->merge($remaining->map(fn (ProductionRequirement $requirement): array => [
            'requirement_id' => $requirement->id,
            'group_key' => $requirement->phase_key_snapshot ?? 'formula',
            'group_label' => $requirement->phase_name_snapshot ?? __('production_bench.run_print.formula'),
            'is_packaging' => $requirement->kind === ProductionRequirementKind::Packaging,
            'name' => $requirement->subject_name_snapshot,
            'code' => $requirement->material_code_snapshot,
            'phase' => $requirement->phase_name_snapshot,
            'percentage' => $requirement->percentage_snapshot,
            'planned' => $requirement->required_mass_grams ?? $requirement->required_units,
            'actual' => $this->actual($production, $requirement),
            'unit' => $requirement->unit_snapshot,
            'note' => $requirement->note_snapshot,
        ]))->values()->all();

        if (! $record) {
            $production->consumption->load('stockLot');
        }

        $consumption = $production->consumption->map(function (ProductionConsumption $row) use ($record, $context): array {
            $identity = $context['execution_lots'][(string) $row->id] ?? null;
            if (! $record) {
                $lot = $row->stockLot;
                $identity = $lot === null ? null : [
                    'internal_lot_code' => $lot->internal_lot_code,
                    'supplier_batch_number' => $lot->supplier_batch_number,
                    'expires_at' => $lot->expires_at?->toDateString(),
                ];
            }

            return [
                'requirement_id' => $row->production_requirement_id,
                'name' => $row->subject_name_snapshot,
                'lot_id' => $identity['stock_lot_id'] ?? $row->stock_lot_id,
                'lot' => $identity['internal_lot_code'] ?? null,
                'supplier_batch' => $identity['supplier_batch_number'] ?? null,
                'expiry' => $identity['expires_at'] ?? null,
                'identity_available' => $identity !== null,
                'quantity' => $row->quantity,
                'unit' => $row->unit_snapshot,
                'note' => $row->note,
            ];
        })->all();

        $reservations = $record ? [] : StockReservation::query()
            ->where('production_run_id', $production->id)
            ->where('status', StockReservationStatus::Active)
            ->with(['stockLot', 'productionRequirement'])
            ->orderBy('id')->get()
            ->map(fn (StockReservation $reservation): array => [
                'requirement_id' => $reservation->production_requirement_id,
                'name' => $reservation->productionRequirement?->subject_name_snapshot,
                'lot_id' => $reservation->stock_lot_id,
                'lot' => $reservation->stockLot?->internal_lot_code,
                'supplier_batch' => $reservation->stockLot?->supplier_batch_number,
                'expiry' => $reservation->stockLot?->expires_at?->toDateString(),
                'identity_available' => $reservation->stockLot !== null,
                'quantity' => $reservation->quantity,
                'unit' => $reservation->productionRequirement?->unit_snapshot,
                'note' => null,
            ])->all();

        $lots = collect($record ? $consumption : $reservations)->groupBy('requirement_id');
        $materials = collect($materials)->map(fn (array $row): array => [
            ...$row,
            'lots' => $row['requirement_id'] === null ? [] : $lots->get($row['requirement_id'], collect())->values()->all(),
        ]);
        $formulaMaterials = $materials->where('is_packaging', false)->values();
        $sections = $formulaMaterials->groupBy('group_key');
        if (($context['calculation_basis'] ?? null) === 'initial_oils') {
            $order = ['saponified_oils' => 0, 'lye_water' => 1, 'additives' => 2, 'fragrance' => 3];
            $sections = $sections->sortBy(fn (Collection $rows, string $key): int => $order[$key] ?? 4);
        }

        return [
            'record' => $record,
            'aborted' => $production->status === ProductionRunStatus::Aborted,
            'identifier' => $production->displayIdentifier(),
            'batch_number' => $production->batch_number,
            'planning_reference' => $production->planning_batch_number,
            'formula' => $production->recipe_name_snapshot,
            'version' => $production->source_formula_version_number,
            'calculation_basis' => $context['calculation_basis'] ?? null,
            'basis_quantity_grams' => $production->basis_quantity_grams,
            'status' => $production->status->value,
            'planned_for' => $production->planned_for?->toDateString(),
            'started_at' => $production->started_at?->toIso8601String(),
            'manufacture_date' => $production->manufacture_date?->toDateString(),
            'completed_at' => $production->completed_at?->toIso8601String(),
            'aborted_at' => $production->aborted_at?->toIso8601String(),
            'abort_reason' => $production->abort_reason,
            'notes' => $production->notes,
            'expected_units' => $production->expected_units,
            'actual_output_units' => $production->actual_output_units,
            'actual_output_mass_grams' => $production->actual_output_mass_grams,
            'procedure_available' => array_key_exists('manufacturing_procedure_html', $context),
            'procedure' => $context['manufacturing_procedure_html'] ?? '',
            'illustrations_omitted' => $context['manufacturing_procedure_illustrations_omitted'] ?? false,
            'materials' => $formulaMaterials->all(),
            'sections' => $sections->map(fn (Collection $rows, string $key): array => [
                'key' => $key,
                'label' => $rows->first()['group_label'],
                'rows' => $rows->values()->all(),
            ])->values()->all(),
            'packaging' => $materials->where('is_packaging', true)->values()->all(),
            'consumption' => $consumption,
            'unmatched_consumption' => collect($consumption)
                ->filter(fn (array $row): bool => $row['requirement_id'] === null || ! $materials->contains('requirement_id', $row['requirement_id']))
                ->values()->all(),
            'reservations' => $reservations,
        ];
    }

    /** @param Collection<int, ProductionRequirement> $requirements */
    private function requirementForLine(ProductionFormulaLine $line, Collection $requirements): ?ProductionRequirement
    {
        if ($line->component === ProductionFormulaComponent::Water) {
            return null;
        }

        $requirements = $requirements->where('kind', ProductionRequirementKind::Ingredient);

        if ($line->recipe_item_id !== null) {
            $match = $requirements->firstWhere('recipe_item_id', $line->recipe_item_id);
            if ($match !== null) {
                return $match;
            }
        }

        if ($line->ingredient_id !== null) {
            return $requirements->firstWhere('ingredient_id', $line->ingredient_id);
        }

        return $requirements->first(fn (ProductionRequirement $requirement): bool => $requirement->subject_name_snapshot === $line->subject_name_snapshot
            && $requirement->phase_key_snapshot === $line->phase_key_snapshot
        );
    }

    private function actual(ProductionRun $production, ProductionRequirement $requirement): ?string
    {
        $rows = $production->consumption->where('production_requirement_id', $requirement->id);

        return $rows->isEmpty() ? null : $rows->reduce(
            fn (string $sum, ProductionConsumption $row): string => bcadd($sum, $row->quantity, 9),
            '0',
        );
    }
}
