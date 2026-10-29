<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Support\NumberLocale;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class PublicCalculatorService
{
    public function __construct(
        private readonly PublicCalculatorCatalog $catalog,
        private readonly RecipeWorkbenchPreviewService $previewService,
        private readonly FormulaDocumentBuilder $documentBuilder,
        private readonly IngredientFormulaContextResolver $contextResolver,
        private readonly SoapCuredOutputBuilder $curedOutputBuilder,
    ) {}

    /** @param array<string, mixed> $draft
     * @return array<string, mixed>
     */
    public function calculate(string $key, array $draft): array
    {
        $draft = ['name' => '', 'notes' => '', ...$draft];
        $family = $this->catalog->family($key);
        $draft['cured_water_percentage'] ??= $family->curedWaterPercentage();
        $cosmetic = $key === 'cosmetics';
        $liquid = $key === 'liquid-soap';
        $ingredientIds = collect($draft['phase_items'])->flatten(1)->pluck('ingredient_id')->filter()->unique()->map(fn (mixed $id): int => (int) $id)->values()->all();
        $ingredients = $this->catalog->ingredients($ingredientIds);
        $warnings = [];
        $references = [];
        $basis = $this->decimal($draft['oil_weight']);
        if (bccomp($basis, '0', 18) <= 0) {
            throw ValidationException::withMessages(['draft.oil_weight' => __('calculators.incomplete')]);
        }
        $phaseItems = $this->rows($draft, $ingredients, $cosmetic, $liquid, $basis, $warnings, $references);
        $basisRows = $cosmetic ? collect($phaseItems)->flatten(1) : collect($phaseItems['saponified_oils'] ?? []);
        $total = $basisRows->reduce(fn (string $sum, array $row): string => bcadd($sum, $this->decimal($row['percentage']), 18), '0');
        if (bccomp($total, '100', 4) !== 0) {
            $this->invalid(__('calculators.validation_total'));
        }
        if ($liquid && ($draft['lye_type'] !== 'koh' || ! is_array($draft['liquid_process'] ?? null))) {
            $this->invalid(__('calculators.validation_role'));
        }
        $payload = [
            'manufacturing_mode' => $cosmetic ? 'blend_only' : 'saponify_in_formula',
            'exposure_mode' => 'rinse_off', 'regulatory_regime' => 'none',
            'oil_unit' => $draft['oil_unit'], 'oil_weight' => (float) $basis,
            'lye_type' => $draft['lye_type'], 'koh_purity_percentage' => $draft['koh_purity_percentage'],
            'dual_lye_koh_percentage' => $draft['dual_lye_koh_percentage'],
            'water_mode' => $draft['water_mode'], 'water_value' => $draft['water_value'],
            'superfat' => $draft['superfat'], 'cured_water_percentage' => $draft['cured_water_percentage'] ?? $family->curedWaterPercentage(),
            'phase_items' => $phaseItems,
            ...($liquid ? ['liquid_process' => $draft['liquid_process']] : []),
        ];
        try {
            $calculation = $cosmetic ? null : $this->previewService->previewSoapCalculation($payload, includePostReactionOils: ! $liquid);
            if ($liquid && is_array($calculation)) {
                $calculation['liquid_process'] = $this->previewService->previewLiquidSoapProcess($payload, $calculation, includeTemporaryAdditions: true);
                foreach ($calculation['liquid_process']['additions'] as $addition) {
                    foreach ($phaseItems[$addition['phase_key']] as $index => $row) {
                        if ($row['id'] === $addition['id']) {
                            $phaseItems[$addition['phase_key']][$index]['weight'] = (float) $addition['weight'];
                            $phaseItems[$addition['phase_key']][$index]['percentage'] = (float) bcdiv(bcmul($addition['weight'], '100', 18), $basis, 18);
                        }
                    }
                }
                $payload['phase_items'] = $phaseItems;
            }
            $labeling = $this->previewService->previewInci($payload, $calculation);
        } catch (InvalidArgumentException $exception) {
            $this->invalid($exception->getMessage());
        }
        if (! $cosmetic && ! is_array($calculation)) {
            $this->invalid(__('calculators.incomplete'));
        }
        $label = $this->ingredientList($payload, $labeling, $calculation);
        $labelWarnings = collect($labeling['warnings'] ?? [])
            ->reject(fn (string $warning): bool => str_contains($warning, 'missing soap-specific INCI'))
            ->all();
        $warnings = [...$warnings, ...$labelWarnings, __('calculators.allergens_unchecked')];
        if ($label['incomplete']) {
            $warnings[] = __('calculators.label_incomplete');
        }
        if (collect($phaseItems)->flatten(1)->contains(fn (array $row): bool => $row['ingredient_id'] === null)) {
            $warnings[] = __('calculators.temporary_chemistry');
        }
        foreach (data_get($calculation, 'liquid_process.warnings', []) as $warning) {
            $warnings[] = $warning === 'neutralizer_amount_is_theoretical'
                ? __('workbench.liquid.estimate')
                : __('workbench.liquid.warnings.'.$warning);
        }
        $warningKeys = [
            'high_koh_context_process_dependent' => 'high_koh_help',
            'effective_superfat_leaves_excess_lye' => 'excess_lye_help',
            'negative_superfat_requires_neutralization_and_ph_control' => 'negative_superfat_help',
            'positive_liquid_superfat_may_cloud_or_separate' => 'liquid_superfat_help',
            'high_polyunsaturated_dos_risk' => 'high_polyunsaturated_dos_help',
            'very_high_polyunsaturated_dos_risk' => 'very_high_dos_help',
        ];
        foreach (data_get($calculation, 'properties.warnings', []) as $warning) {
            if (isset($warningKeys[$warning])) {
                $warnings[] = __('workbench.qualities.'.$warningKeys[$warning]);
            }
        }
        $normalizedDraft = [...$draft, 'phase_items' => $phaseItems];
        $snapshotDraft = $this->snapshotDraft($normalizedDraft, $family->calculation_basis, $liquid);
        $snapshot = ['draft' => $snapshotDraft, 'calculation' => $calculation, 'labeling' => [...$labeling, 'print_ingredient_list_text' => $label['text']]];
        $document = $this->documentBuilder->build($snapshot, [
            'name' => filled($draft['name'] ?? null) ? $draft['name'] : __('calculators.formula_name'),
            'calculation_basis' => $family->calculation_basis, 'state' => 'current_working_copy',
        ]);
        $document = $this->completeDocument($document, $snapshotDraft, $cosmetic, $liquid, $draft);
        $document['settings'] = collect($document['settings'])->reject(fn (array $setting): bool => in_array($setting['label'], [
            __('formula_documents.settings.exposure'), __('formula_documents.settings.regime'),
        ], true))->values()->all();
        foreach ($document['soap_analysis']['qualities'] ?? [] as $index => $quality) {
            if ($quality['key'] === 'shrinkage_risk') {
                $document['soap_analysis']['qualities'][$index]['label'] = __('workbench.qualities.shrinkage_label');
            }
        }
        if (! $cosmetic && collect($phaseItems['saponified_oils'] ?? [])->contains(fn (array $row): bool => $row['weight'] > 0
            && array_sum($ingredients->get($row['ingredient_id'])->normalizedFattyAcidProfile()) <= 0)) {
            $document['soap_analysis'] = null;
            $warnings[] = __('calculators.missing_fatty_acids');
        }
        if ($label['incomplete']) {
            $document['soap_output'] = null;
        }
        $warnings = array_values(array_unique($warnings));
        $document['warnings'] = $warnings;

        return ['valid' => true, 'document' => $document, 'draft' => $normalizedDraft,
            'warnings' => $warnings, 'references' => (object) $references, 'labeling' => $label,
            'calculation' => $calculation, 'workbenchLabeling' => $labeling,
            'calculated_at' => now()->toIso8601String()];
    }

    /** @param array<string, mixed> $draft
     * @param  Collection<int, Ingredient>  $ingredients
     * @param  list<string>  $warnings
     * @param  array<int, array{name:string,fingerprint:string}>  $references
     * @return array<string, list<array<string, mixed>>>
     */
    private function rows(array $draft, Collection $ingredients, bool $cosmetic, bool $liquid, string $basis, array &$warnings, array &$references): array
    {
        $phases = collect($draft['phases'])->pluck('key')->all();
        $allowed = $cosmetic ? $phases : ['saponified_oils', 'additives', 'fragrance', ...($liquid ? ['neutralization'] : [])];
        $count = collect($draft['phase_items'])->sum(fn (array $rows): int => count($rows));
        if ($count > 100) {
            $this->invalid(__('calculators.number_of_rows'));
        }
        $normalized = [];
        foreach ($draft['phase_items'] as $phase => $rows) {
            if (! in_array($phase, $allowed, true)) {
                if ($rows !== []) {
                    $this->invalid(__('calculators.validation_phase'));
                }

                $normalized[$phase] = [];

                continue;
            }
            if ($phase === 'neutralization' && count($rows) > 1) {
                $this->invalid(__('calculators.validation_role'));
            }
            $normalized[$phase] = [];
            foreach ($rows as $row) {
                $ingredient = isset($row['ingredient_id']) ? $ingredients->get((int) $row['ingredient_id']) : null;
                $fingerprint = null;
                if (isset($row['ingredient_id'])) {
                    if (! $ingredient instanceof Ingredient || ($fingerprint = $this->catalog->fingerprint($ingredient, $ingredients)) === null) {
                        $this->invalid(__('calculators.validation_reference'));
                    }
                    if (filled($row['fingerprint'] ?? null) && ! hash_equals($fingerprint, $row['fingerprint'])) {
                        $warnings[] = __('calculators.reference_changed');
                    }
                    $references[$ingredient->id] = ['name' => $ingredient->localizedDisplayName(), 'fingerprint' => $fingerprint];
                }
                if (! $cosmetic) {
                    $validRole = match ($phase) {
                        'saponified_oils' => $ingredient?->canDriveSoapSaponification() ?? false,
                        'neutralization' => $ingredient?->liquidSoapNeutralizer() !== null,
                        default => $ingredient === null || in_array($phase, $ingredient->availableWorkbenchPhases(), true),
                    };
                    if (! $validRole) {
                        $this->invalid(__('calculators.validation_role'));
                    }
                }
                $name = $ingredient?->localizedDisplayName() ?? trim((string) ($row['name'] ?? ''));
                if ($name === '') {
                    $this->invalid(__('calculators.temporary_name'));
                }
                $percentage = $draft['editing_mode'] === 'weight'
                    ? bcdiv(bcmul($this->decimal($row['weight']), '100', 18), $basis, 18)
                    : $this->decimal($row['percentage']);
                $weight = bcdiv(bcmul($basis, $percentage, 18), '100', 18);
                $normalized[$phase][] = [
                    'id' => $row['id'], 'ingredient_id' => $ingredient?->id, 'name' => $name,
                    'percentage' => (float) $percentage, 'weight' => (float) $weight,
                    'note' => $row['note'] ?? null, 'fingerprint' => $fingerprint,
                    ...($liquid && in_array($phase, ['additives', 'fragrance'], true) && isset($row['final_percentage']) ? ['final_percentage' => $row['final_percentage']] : []),
                ];
            }
        }

        return $normalized;
    }

    /** @param array<string, mixed> $payload
     * @param  array<string, mixed>  $labeling
     * @param  array<string, mixed>|null  $calculation
     * @return array{text:string,incomplete:bool}
     */
    private function ingredientList(array $payload, array $labeling, ?array $calculation): array
    {
        $rows = collect($labeling['ingredient_rows'] ?? []);
        $incomplete = false;
        $contexts = $this->contextResolver->resolve($payload);
        foreach ($contexts as $context) {
            $ingredient = $context['ingredient'];
            if ($ingredient === null) {
                $incomplete = true;
                $rows->push(['label' => __('calculators.temporary_label', ['name' => $context['ingredient_name']]), 'weight' => $context['weight']]);
            } else {
                $missingSoapName = $context['phase_key'] === 'saponified_oils' && match ($payload['lye_type']) {
                    'koh' => blank($ingredient->soap_inci_koh_name),
                    'dual' => ((float) $payload['dual_lye_koh_percentage'] < 100 && blank($ingredient->soap_inci_naoh_name))
                        || ((float) $payload['dual_lye_koh_percentage'] > 0 && blank($ingredient->soap_inci_koh_name)),
                    default => blank($ingredient->soap_inci_naoh_name),
                };
                $missingRawName = blank($ingredient->inci_name);
                $rows = $rows->map(function (array $row) use ($ingredient, $missingRawName, $missingSoapName, &$incomplete): array {
                    $missing = match ($row['kind'] ?? null) {
                        'saponified_oil' => $missingSoapName,
                        'mixed_saponified_superfat' => $missingSoapName || $missingRawName,
                        default => $missingRawName,
                    };
                    if ($missing && in_array($ingredient->display_name, $row['source_ingredients'] ?? [], true)) {
                        $incomplete = true;
                        $row['label'] = __('calculators.missing_inci_label', ['name' => $ingredient->localizedDisplayName()]);
                    }

                    return $row;
                });
            }
        }
        if (collect($labeling['warnings'] ?? [])->contains(fn (string $warning): bool => str_contains($warning, 'missing soap-specific'))) {
            $incomplete = true;
        }

        if (($payload['manufacturing_mode'] ?? null) === 'saponify_in_formula' && ! isset($calculation['liquid_process'])) {
            $cured = $this->curedOutputBuilder->build([
                'default_variant_key' => 'guest',
                'list_variants' => [['key' => 'guest', 'ingredient_rows' => $rows->all()]],
            ], data_get($labeling, 'basis.cured_weight', 0), (float) $payload['cured_water_percentage']);

            return ['text' => collect($cured['rows'])->pluck('name')->implode(', '), 'incomplete' => $incomplete];
        }

        return ['text' => $rows->sortByDesc('weight')->pluck('label')->implode(', '), 'incomplete' => $incomplete];
    }

    /** @param array<string, mixed> $draft
     * @return array<string, mixed>
     */
    private function snapshotDraft(array $draft, string $calculationBasis, bool $liquid): array
    {
        $phaseItems = collect($draft['phase_items'])->map(fn (array $rows): array => collect($rows)->map(fn (array $row): array => [
            ...$row, 'name' => $row['name'].($row['ingredient_id'] === null ? ' ('.__('calculators.temporary').')' : ''), 'is_user_owned' => false,
        ])->all())->all();

        return [
            'oilWeight' => $draft['oil_weight'], 'oilUnit' => $draft['oil_unit'], 'editMode' => $draft['editing_mode'],
            'lyeType' => $draft['lye_type'], 'kohPurity' => $draft['koh_purity_percentage'],
            'dualKohPercentage' => $draft['dual_lye_koh_percentage'], 'waterMode' => $draft['water_mode'],
            'waterValue' => $draft['water_value'], 'superfat' => $draft['superfat'],
            'regulatoryRegime' => 'none', 'exposureMode' => 'rinse_off',
            'curedWaterPercentage' => $draft['cured_water_percentage'] ?? 11,
            'manufacturingMode' => $calculationBasis === 'total_formula' ? 'blend_only' : 'saponify_in_formula',
            'phaseItems' => $phaseItems, 'phases' => $draft['phases'],
            ...($liquid ? ['liquidProcess' => ['firstDilutionPercentage' => $draft['liquid_process']['first_dilution_percentage'], 'finalDilutionPercentage' => $draft['liquid_process']['final_dilution_percentage'], 'neutralizerSolutionPercentage' => $draft['liquid_process']['neutralizer_solution_percentage']]] : []),
        ];
    }

    /** @param array<string, mixed> $document
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    private function completeDocument(array $document, array $snapshot, bool $cosmetic, bool $liquid, array $draft): array
    {
        $rows = fn (array $items): array => collect($items)->map(fn (array $row): array => [
            'name' => $row['name'], 'percentage' => $row['percentage'], 'weight' => $row['weight'],
            'note' => $row['note'], 'is_user_owned' => false,
        ])->all();
        if ($cosmetic) {
            $document['sections'] = collect($draft['phases'])->map(fn (array $phase): array => [
                'key' => $phase['key'], 'label' => $phase['name'], 'rows' => $rows($snapshot['phaseItems'][$phase['key']] ?? []),
            ])->all();
        } else {
            $generated = collect($document['sections'])->firstWhere('key', 'lye_water');
            $document['sections'] = [
                ['key' => 'saponified_oils', 'label' => __('calculators.phase_oils'), 'rows' => $rows($snapshot['phaseItems']['saponified_oils'] ?? [])],
                ...($generated ? [$generated] : []),
                ...($liquid ? [['key' => 'neutralization', 'label' => __('calculators.phase_neutralization'), 'rows' => $rows($snapshot['phaseItems']['neutralization'] ?? [])]] : []),
                ['key' => 'formula_additions', 'label' => __('calculators.phase_additives'), 'rows' => $rows([...($snapshot['phaseItems']['additives'] ?? []), ...($snapshot['phaseItems']['fragrance'] ?? [])])],
            ];
            if ($liquid || $draft['lye_type'] !== 'naoh') {
                $document['settings'][] = ['label' => __('calculators.koh_purity'), 'value' => $draft['koh_purity_percentage'].'%'];
            }
            if ($draft['lye_type'] === 'dual') {
                $document['settings'][] = ['label' => __('calculators.dual_koh'), 'value' => $draft['dual_lye_koh_percentage'].'%'];
            }
            if (! $liquid) {
                $document['settings'][] = ['label' => __('calculators.cured_water'), 'value' => ($draft['cured_water_percentage'] ?? 11).'%'];
            }
            if ($liquid) {
                foreach (['first_dilution_percentage' => 'first_dilution', 'final_dilution_percentage' => 'final_dilution', 'neutralizer_solution_percentage' => 'neutralizer_solution'] as $setting => $label) {
                    $document['settings'][] = ['label' => __('calculators.'.$label), 'value' => $draft['liquid_process'][$setting].'%'];
                }
                $document['settings'][] = ['label' => __('calculators.measured_paste'), 'value' => filled($draft['liquid_process']['measured_paste_weight'] ?? null)
                    ? $draft['liquid_process']['measured_paste_weight'].' '.$draft['oil_unit']
                    : '—'];
                $additions = collect([...($snapshot['phaseItems']['additives'] ?? []), ...($snapshot['phaseItems']['fragrance'] ?? [])])->values();
                foreach ($document['liquid_process']['additions'] ?? [] as $index => $addition) {
                    $document['liquid_process']['additions'][$index]['name'] = $additions->get($index)['name'];
                }
            }
        }

        return $document;
    }

    private function decimal(mixed $value): string
    {
        $decimal = NumberLocale::normalizeDecimalString(is_float($value) && is_finite($value) ? sprintf('%.18F', $value) : $value);
        if ($decimal === null) {
            $this->invalid(__('calculators.validation_payload'));
        }

        return $decimal;
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['draft.phase_items' => $message]);
    }
}
