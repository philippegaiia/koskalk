<?php

namespace App\Services;

use App\Support\NumberLocale;

class RecipeCompositionExportDataBuilder
{
    public function __construct(
        private readonly LyeLiquidAllocationService $lyeLiquidAllocationService,
        private readonly RecipeCompositionIngredientIdentityResolver $ingredientIdentityResolver,
    ) {}

    /**
     * Build a composition projection from the captured workbench snapshot and its formula document.
     * The projection keeps authored raw inputs separate from calculated soap INCI/output rows.
     *
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public function build(array $snapshot, array $document): array
    {
        $draft = is_array($snapshot['draft'] ?? null) ? $snapshot['draft'] : [];
        $labeling = is_array($snapshot['labeling'] ?? null) ? $snapshot['labeling'] : [];
        $family = (string) ($document['family'] ?? 'soap');
        $isSoap = $family === 'soap';

        if ($isSoap) {
            $snapshot = $this->ingredientIdentityResolver->withAvailableIdentities($snapshot);
        }

        $unit = (string) ($document['unit'] ?? $draft['oilUnit'] ?? 'g');
        $phaseNames = $this->phaseNames($draft);
        $rawRows = $isSoap
            ? $this->soapRawMaterialRows(
                $snapshot,
                $phaseNames,
                $unit,
                ($document['identity']['state'] ?? null) === 'current_working_copy',
            )
            : $this->cosmeticRawMaterialRows($draft, $phaseNames, $unit);
        $rawBasisWeight = $this->totalWeight($rawRows);
        $rawRows = $this->withFreshMixturePercentages($rawRows, $rawBasisWeight, $isSoap);
        $basisWeight = $isSoap
            ? $rawBasisWeight
            : $this->decimal(data_get($draft, 'oilWeight', $document['basis_weight'] ?? 0));
        $candidateVariant = $this->incorporatedVariant($labeling);
        $incorporatedVariant = $isSoap || $this->hasDistinctInciBreakdown($candidateVariant, $rawRows)
            ? $candidateVariant
            : null;
        $inciRows = $this->inciRows($incorporatedVariant);
        $inciRows = $this->withAccountedInciPercentages($inciRows, $basisWeight);
        $accountedInciWeight = $this->totalNamedWeight($inciRows);
        $accountedInciPercentage = bccomp($basisWeight, '0', 18) > 0
            ? bcmul(bcdiv($accountedInciWeight, $basisWeight, 18), '100', 18)
            : '0';
        $warnings = array_values(array_filter(
            is_array($labeling['warnings'] ?? null) ? $labeling['warnings'] : [],
            'is_string',
        ));

        if (collect($rawRows)->contains(fn (array $row): bool => $row['recorded_inci_missing'])) {
            $warnings[] = 'composition_recorded_inci_missing';
        }

        if (collect($rawRows)->contains(fn (array $row): bool => ($row['is_derived'] ?? false)
            && ! is_numeric($row['ingredient_id'] ?? null))) {
            $warnings[] = 'composition_derived_identity_unavailable';
        }

        if (
            $isSoap
            && (float) data_get($draft, 'kohPurity', 100) < 100
            && bccomp($this->decimal(data_get($snapshot, 'calculation.lye.selected.koh_to_weigh', 0)), '0', 18) > 0
        ) {
            $warnings[] = 'composition_koh_purity_remainder_unrepresented';
        }

        $warnings = array_values(array_unique($warnings));
        $generatedLabel = (string) ($incorporatedVariant['final_label_text'] ?? '');
        $defaultVariantKey = $labeling['default_variant_key'] ?? null;
        $defaultVariant = collect($labeling['list_variants'] ?? [])
            ->firstWhere('key', $defaultVariantKey);
        $defaultVariantLabel = match ($defaultVariantKey) {
            'saponified_with_superfat' => __('workbench.output.lists.saponified'),
            'incorporated_ingredients' => __('workbench.output.lists.as_added'),
            default => (string) ($defaultVariant['label'] ?? ''),
        };
        $authoredLabel = (string) data_get(
            $labeling,
            'final_ingredient_list.final_text',
            data_get($labeling, 'print_ingredient_list_text', $document['label_text'] ?? ''),
        );

        return [
            'family' => $family,
            'unit' => $unit,
            'identity' => is_array($document['identity'] ?? null) ? $document['identity'] : [],
            'metadata' => is_array($document['metadata'] ?? null) ? $document['metadata'] : [],
            'formula_basis_weight' => $document['basis_weight'] ?? data_get($draft, 'oilWeight', 0),
            'formula_percentage_basis' => (string) ($document['percentage_basis'] ?? 'formula'),
            'incorporated' => [
                'basis_weight' => (float) $basisWeight,
                'percentage_basis_weight' => (float) $basisWeight,
                'basis_label' => $isSoap
                    ? __('formula_documents.composition.fresh_mixture_basis')
                    : __('formula_documents.composition.authored_formula_basis'),
                'actual_mass' => (float) $rawBasisWeight,
                'actual_mass_label' => __('formula_documents.composition.actual_incorporated_mass'),
                'raw_material_rows' => $rawRows,
                'inci_rows' => $inciRows,
                'accounted_inci_weight' => (float) $accountedInciWeight,
                'accounted_inci_percentage' => (float) $accountedInciPercentage,
            ],
            'calculated_output' => $isSoap && is_array($document['soap_output'] ?? null)
                ? $document['soap_output']
                : null,
            'settings' => is_array($document['settings'] ?? null) ? $document['settings'] : [],
            'label_context' => [
                'default_variant_key' => $defaultVariantKey,
                'default_variant_label' => $defaultVariantLabel,
                'incorporated_variant_key' => 'incorporated_ingredients',
                'generated_inci_text' => $generatedLabel,
                'authored_label_text' => $authoredLabel,
                'regulatory_regime' => data_get($draft, 'regulatoryRegime'),
                'exposure_mode' => data_get($draft, 'exposureMode'),
            ],
            'warnings' => $warnings,
            'assumption_note' => $isSoap
                ? __('formula_documents.composition.retained_liquid_assumption', [
                    'percentage' => LyeLiquidAllocationService::CURED_RESIDUAL_PERCENTAGE,
                ])
                : null,
            'review_note' => __('formula_documents.composition.review_note'),
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, string>  $phaseNames
     * @return list<array<string, mixed>>
     */
    private function soapRawMaterialRows(array $snapshot, array $phaseNames, string $unit, bool $useExplicitRowWeights): array
    {
        $draft = $snapshot['draft'] ?? [];
        $phaseItems = is_array($draft['phaseItems'] ?? null) ? $draft['phaseItems'] : [];
        $rows = [];

        foreach (['saponified_oils' => 'oil', 'additives' => 'additive', 'fragrance' => 'fragrance'] as $phaseKey => $role) {
            foreach (($phaseItems[$phaseKey] ?? []) as $row) {
                if (is_array($row)) {
                    $weight = $useExplicitRowWeights
                        ? $this->decimal($row['weight'] ?? 0)
                        : bcmul(
                            $this->decimal($draft['oilWeight'] ?? 0),
                            bcdiv($this->decimal($row['percentage'] ?? 0), '100', 18),
                            18,
                        );

                    if (bccomp($weight, '0', 18) > 0) {
                        $rows[] = $this->phaseRow(
                            [...$row, 'weight' => $weight],
                            $phaseKey,
                            $phaseNames[$phaseKey] ?? $phaseKey,
                            $role,
                            $unit,
                        );
                    }
                }
            }
        }

        $selectedLye = data_get($snapshot, 'calculation.lye.selected', []);
        $naohWeight = $this->decimal($selectedLye['naoh_weight'] ?? 0);
        $kohWeight = $this->decimal($selectedLye['koh_to_weigh'] ?? 0);

        if (bccomp($naohWeight, '0', 18) > 0) {
            $rows[] = $this->derivedRow(
                __('formula_documents.ingredients.naoh'),
                null,
                $naohWeight,
                $unit,
                'lye_alkali',
                __('formula_documents.composition.phase_lye_alkali'),
                'alkali',
                is_array(data_get($snapshot, 'calculation.lye.identities.naoh'))
                    ? data_get($snapshot, 'calculation.lye.identities.naoh')
                    : [],
                null,
            );
        }

        if (bccomp($kohWeight, '0', 18) > 0) {
            $purity = (string) data_get($draft, 'kohPurity', 100);
            $kohIdentity = is_array(data_get($snapshot, 'calculation.lye.identities.koh'))
                ? data_get($snapshot, 'calculation.lye.identities.koh')
                : [];
            $kohName = trim((string) ($kohIdentity['name'] ?? ''));
            $kohIdentity['name'] = null;
            $rows[] = $this->derivedRow(
                __('formula_documents.composition.koh_to_weigh', [
                    'name' => $kohName !== '' ? $kohName : __('formula_documents.ingredients.koh'),
                    'purity' => $purity,
                ]),
                null,
                $kohWeight,
                $unit,
                'lye_alkali',
                __('formula_documents.composition.phase_lye_alkali'),
                'alkali',
                $kohIdentity,
                null,
            );
        }

        $totalLiquidWeight = data_get($snapshot, 'calculation.lye.water.weight', 0);
        $substitutionRows = collect($phaseItems['lye_water'] ?? [])
            ->filter(fn (mixed $row): bool => is_array($row))
            ->values();
        $allocation = $this->lyeLiquidAllocationService->allocateFresh(
            $totalLiquidWeight,
            $substitutionRows->map(fn (array $row): array => [
                'percentage' => $row['percentage'] ?? 0,
            ])->all(),
        );
        $plainWaterWeight = $this->decimal($allocation['water_weight'] ?? 0);

        if (bccomp($plainWaterWeight, '0', 18) > 0) {
            $waterIdentity = is_array(data_get($snapshot, 'calculation.lye.identities.water'))
                ? data_get($snapshot, 'calculation.lye.identities.water')
                : [];
            $rows[] = $this->derivedRow(
                __('formula_documents.ingredients.water'),
                null,
                $plainWaterWeight,
                $unit,
                'lye_water',
                __('formula_documents.composition.phase_dilution_liquid'),
                'dilution_liquid',
                $waterIdentity,
                null,
            );
        }

        foreach ($allocation['substitutions'] ?? [] as $index => $substitution) {
            $source = $substitutionRows->get($index, []);
            $weight = $this->decimal($substitution['weight'] ?? 0);

            if (bccomp($weight, '0', 18) <= 0) {
                continue;
            }

            $rows[] = $this->phaseRow(
                [
                    ...$source,
                    'weight' => $weight,
                ],
                'lye_water',
                __('formula_documents.composition.phase_dilution_liquid'),
                'dilution_substitution',
                $unit,
            );
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  array<string, string>  $phaseNames
     * @return list<array<string, mixed>>
     */
    private function cosmeticRawMaterialRows(array $draft, array $phaseNames, string $unit): array
    {
        $rows = [];

        foreach (($draft['phaseItems'] ?? []) as $phaseKey => $phaseRows) {
            if (! is_string($phaseKey) || ! is_array($phaseRows)) {
                continue;
            }

            foreach ($phaseRows as $row) {
                if (is_array($row) && bccomp($this->decimal($row['weight'] ?? 0), '0', 18) > 0) {
                    $rows[] = $this->phaseRow(
                        $row,
                        $phaseKey,
                        $phaseNames[$phaseKey] ?? $phaseKey,
                        'ingredient',
                        $unit,
                        preserveAuthoredPercentage: true,
                    );
                }
            }
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function phaseRow(
        array $row,
        string $phaseKey,
        string $phase,
        string $role,
        string $unit,
        bool $preserveAuthoredPercentage = false,
    ): array {
        $inci = trim((string) ($row['inci_name'] ?? ''));

        return [
            'phase_key' => $phaseKey,
            'phase' => $phase,
            'row_id' => is_numeric($row['id'] ?? null) ? (int) $row['id'] : null,
            'ingredient_id' => is_numeric($row['ingredient_id'] ?? null) ? (int) $row['ingredient_id'] : null,
            'name' => (string) ($row['name'] ?? ''),
            'recorded_inci' => $inci,
            'recorded_inci_missing' => $inci === '',
            'role' => $role,
            'quantity' => (float) $this->decimal($row['weight'] ?? 0),
            'percentage' => $preserveAuthoredPercentage ? (float) $this->decimal($row['percentage'] ?? 0) : 0.0,
            'percentage_is_authored' => $preserveAuthoredPercentage,
            'unit' => $unit,
            'note' => filled($row['note'] ?? null) ? (string) $row['note'] : '',
            'is_derived' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function derivedRow(
        string $name,
        ?string $inci,
        string $weight,
        string $unit,
        string $phaseKey,
        string $phase,
        string $role,
        array $identity,
        ?string $note,
    ): array {
        $ingredientId = is_numeric($identity['ingredient_id'] ?? null) ? (int) $identity['ingredient_id'] : null;
        $ingredientName = trim((string) ($identity['name'] ?? ''));
        $recordedInci = trim((string) ($identity['inci_name'] ?? $inci ?? ''));

        return [
            'phase_key' => $phaseKey,
            'phase' => $phase,
            'row_id' => null,
            'ingredient_id' => $ingredientId,
            'name' => $ingredientName !== '' ? $ingredientName : $name,
            'recorded_inci' => $recordedInci,
            'recorded_inci_missing' => $recordedInci === '',
            'role' => $role,
            'quantity' => (float) $weight,
            'percentage' => 0.0,
            'percentage_is_authored' => false,
            'unit' => $unit,
            'note' => $note ?? '',
            'is_derived' => true,
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function withFreshMixturePercentages(array $rows, string $basisWeight, bool $isSoap): array
    {
        foreach ($rows as &$row) {
            if (! $isSoap || $row['percentage_is_authored']) {
                continue;
            }

            $row['percentage'] = bccomp($basisWeight, '0', 18) > 0
                ? (float) bcmul(bcdiv($this->decimal($row['quantity']), $basisWeight, 18), '100', 18)
                : 0.0;
        }

        unset($row);

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function inciRows(?array $variant): array
    {
        return collect($variant['ingredient_rows'] ?? [])
            ->filter(fn (mixed $row): bool => is_array($row))
            ->map(fn (array $row): array => [
                'name' => (string) ($row['label'] ?? ''),
                'quantity' => (float) $this->decimal($row['weight'] ?? 0),
                'percentage' => 0.0,
                'kind' => (string) ($row['kind'] ?? ''),
                'sources' => collect($row['source_ingredients'] ?? [])->filter(fn (mixed $source): bool => is_string($source))->values()->all(),
            ])
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $labeling
     * @return array<string, mixed>|null
     */
    private function incorporatedVariant(array $labeling): ?array
    {
        foreach (($labeling['list_variants'] ?? []) as $variant) {
            if (is_array($variant) && ($variant['key'] ?? null) === 'incorporated_ingredients') {
                return $variant;
            }
        }

        return null;
    }

    /** @param list<array<string, mixed>> $rawRows */
    private function hasDistinctInciBreakdown(?array $variant, array $rawRows): bool
    {
        $ingredientRows = is_array($variant['ingredient_rows'] ?? null) ? $variant['ingredient_rows'] : [];

        if ($ingredientRows === []) {
            return false;
        }

        $rawBreakdown = [];

        foreach ($rawRows as $row) {
            $name = $this->normalizedName((string) ($row['recorded_inci'] ?? ''));

            if ($name === '') {
                return true;
            }

            $rawBreakdown[$name] = bcadd(
                $rawBreakdown[$name] ?? '0',
                $this->decimal($row['quantity'] ?? 0),
                18,
            );
        }

        $inciBreakdown = [];

        foreach ($ingredientRows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = $this->normalizedName((string) ($row['label'] ?? ''));

            if ($name === '') {
                continue;
            }

            $inciBreakdown[$name] = bcadd(
                $inciBreakdown[$name] ?? '0',
                $this->decimal($row['weight'] ?? 0),
                18,
            );
        }

        ksort($rawBreakdown);
        ksort($inciBreakdown);

        if (array_keys($rawBreakdown) !== array_keys($inciBreakdown)) {
            return true;
        }

        foreach ($rawBreakdown as $name => $weight) {
            if (bccomp($weight, $inciBreakdown[$name], 18) !== 0) {
                return true;
            }
        }

        return false;
    }

    private function normalizedName(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $name)));
    }

    /** @param array<string, mixed> $draft
     * @return array<string, string>
     */
    private function phaseNames(array $draft): array
    {
        $names = [];

        foreach (($draft['phases'] ?? []) as $phase) {
            if (is_array($phase) && is_string($phase['key'] ?? null)) {
                $names[$phase['key']] = (string) ($phase['name'] ?? $phase['key']);
            }
        }

        $names['saponified_oils'] ??= __('formula_documents.sections.saponified_oils');
        $names['additives'] ??= __('formula_documents.composition.phase_additives');
        $names['fragrance'] ??= __('formula_documents.composition.phase_fragrance');
        $names['lye_water'] = __('formula_documents.composition.phase_dilution_liquid');

        return $names;
    }

    /** @param list<array<string, mixed>> $rows */
    private function totalWeight(array $rows): string
    {
        $total = '0';

        foreach ($rows as $row) {
            $total = bcadd($total, $this->decimal($row['quantity'] ?? 0), 18);
        }

        return $total;
    }

    /** @param list<array<string, mixed>> $rows */
    private function totalNamedWeight(array $rows): string
    {
        $total = '0';

        foreach ($rows as $row) {
            $total = bcadd($total, $this->decimal($row['quantity'] ?? 0), 18);
        }

        return $total;
    }

    /** @param list<array<string, mixed>> $rows */
    private function withAccountedInciPercentages(array $rows, string $basisWeight): array
    {
        foreach ($rows as &$row) {
            $row['percentage'] = bccomp($basisWeight, '0', 18) > 0
                ? (float) bcmul(bcdiv($this->decimal($row['quantity']), $basisWeight, 18), '100', 18)
                : 0.0;
        }

        unset($row);

        return $rows;
    }

    private function decimal(mixed $value): string
    {
        if (is_float($value)) {
            if (! is_finite($value)) {
                return '0';
            }

            $normalized = rtrim(rtrim(sprintf('%.18F', $value), '0'), '.');

            return $normalized !== '' ? $normalized : '0';
        }

        return NumberLocale::normalizeDecimalString($value) ?? '0';
    }
}
