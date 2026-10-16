<?php

namespace App\Services\Production;

use App\Enums\LiquidSoapNeutralizer;
use App\Enums\ProductionFormulaComponent;
use App\Enums\ProductionRequirementKind;
use App\Enums\ProductionRunStatus;
use App\Enums\StockReservationStatus;
use App\Models\ProductionConsumption;
use App\Models\ProductionFormulaLine;
use App\Models\ProductionRequirement;
use App\Models\ProductionRun;
use App\Models\StockReservation;
use App\Support\NumberLocale;
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

            // Calculated alkali and lye water print in the lye water section;
            // liquid soap dilution water keeps its own process phase.
            $isLyeWater = $line->component !== ProductionFormulaComponent::Ingredient
                && in_array($line->phase_key_snapshot, [null, 'lye_water'], true);

            return [
                'requirement_id' => $requirement?->id,
                'group_key' => $isLyeWater ? 'lye_water' : ($line->phase_key_snapshot ?? 'formula'),
                'group_label' => $isLyeWater
                    ? __('formula_documents.sections.lye_water')
                    : ($line->phase_name_snapshot ?? __('production_bench.run_print.formula')),
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
            // Process order; the liquid soap phases slot in around the soap ones.
            $order = ['saponified_oils' => 0, 'lye_water' => 1, 'first_dilution' => 2, 'neutralization' => 3, 'additives' => 4, 'fragrance' => 5, 'final_dilution' => 6];
            $sections = $sections->sortBy(fn (Collection $rows, string $key): int => $order[$key] ?? 7);
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
            'liquid_process' => is_array($context['liquid_process'] ?? null)
                ? $this->liquidProcess($context['liquid_process'], (string) $production->basis_quantity_grams, $formulaMaterials)
                : null,
        ];
    }

    /**
     * The captured liquid soap process sized to this batch, shaped for the
     * formula document's liquid process section.
     *
     * @param  array<string, mixed>  $process  Shares of the basis captured with the formula snapshot.
     * @param  Collection<int, array<string, mixed>>  $materials
     * @return array<string, mixed>
     */
    private function liquidProcess(array $process, string $basisQuantityGrams, Collection $materials): array
    {
        $locale = app()->getLocale();
        $grams = fn (?string $share): ?float => $share === null
            ? null
            : (float) bcdiv(bcmul($basisQuantityGrams, $share, 18), '100', 9);
        $neutralizer = is_array($process['neutralizer'] ?? null) ? $process['neutralizer'] : null;
        $stage = fn (string $key): array => $process['stages'][$key];
        $target = fn (string $key): string => NumberLocale::formatDecimal((float) $stage($key)['target_percentage'], 1, $locale);
        $finalWeight = $grams($process['final_percentage']);
        $neutralizationInstruction = match (true) {
            $neutralizer === null && bccomp((string) $process['free_koh_percentage'], '0', 9) > 0 => __('formula_documents.liquid.instructions.neutralization_missing'),
            $neutralizer === null => __('formula_documents.liquid.instructions.no_free_koh'),
            LiquidSoapNeutralizer::tryFrom((string) $neutralizer['type'])?->formsSoap() === true => __('formula_documents.liquid.instructions.neutralization_neat', ['acid' => $neutralizer['name']]),
            default => __('formula_documents.liquid.instructions.neutralization_solution', [
                'acid' => $neutralizer['name'],
                'strength' => NumberLocale::formatDecimal((float) $neutralizer['solution_percentage'], 0, $locale),
            ]),
        };
        $step = fn (string $key, string $instruction): array => [
            'key' => $key,
            'label' => __('formula_documents.liquid.steps.'.$key),
            'instruction' => $instruction,
            'add' => $grams($stage($key)['add_percentage']),
            'mass' => $grams($stage($key)['mass_percentage']),
            'anhydrous_percentage' => (float) $stage($key)['anhydrous_percentage'],
        ];

        return [
            'anhydrous_soap_weight' => $grams($process['anhydrous_soap_percentage']),
            'final_anhydrous_soap_weight' => $grams($process['final_anhydrous_soap_percentage'] ?? null),
            'final_weight' => $finalWeight,
            'paste' => ['theoretical_weight' => $grams($stage('paste')['mass_percentage']), 'weight' => $grams($stage('paste')['mass_percentage']), 'is_weighed' => false],
            'steps' => [
                $step('paste', __('formula_documents.liquid.instructions.paste')),
                $step('first_dilution', __('formula_documents.liquid.instructions.first_dilution', ['target' => $target('first_dilution')])),
                $step('neutralization', $neutralizationInstruction),
                $step('final_dilution', __('formula_documents.liquid.instructions.final_dilution', ['target' => $target('final_dilution')])),
            ],
            'neutralizer' => $neutralizer === null ? null : [
                'name' => $neutralizer['name'],
                'weight' => $grams($neutralizer['percentage']),
                'solution_weight' => $grams($neutralizer['solution_percentage_of_basis']),
                'recommended_weight' => $grams($neutralizer['recommended_percentage']),
            ],
            'additions' => $materials
                ->filter(fn (array $row): bool => in_array($row['group_key'], ['additives', 'fragrance'], true))
                ->map(fn (array $row): array => [
                    'name' => $row['name'],
                    'weight' => (float) $row['planned'],
                    'final_percentage' => $finalWeight === null || $finalWeight <= 0.0 ? null : (float) $row['planned'] * 100 / $finalWeight,
                ])
                ->values()
                ->all(),
            'warnings' => collect($process['warnings'] ?? [])
                ->map(fn (string $warning): string => __('workbench.liquid.warnings.'.$warning))
                ->values()
                ->all(),
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
