<?php

namespace App\Services;

use App\Support\NumberLocale;

class RecipeCompositionExportDataBuilder
{
    public function __construct(
        private readonly LyeLiquidAllocationService $lyeLiquidAllocationService,
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
        $unit = (string) ($document['unit'] ?? $draft['oilUnit'] ?? 'g');
        $phaseNames = $this->phaseNames($draft);
        $rawRows = $isSoap
            ? $this->soapRawMaterialRows($snapshot, $phaseNames, $unit)
            : $this->cosmeticRawMaterialRows($draft, $phaseNames, $unit);
        $rawBasisWeight = $this->totalWeight($rawRows);
        $rawRows = $this->withFreshMixturePercentages($rawRows, $rawBasisWeight, $isSoap);
        $incorporatedVariant = $isSoap ? $this->incorporatedVariant($labeling) : null;
        $inciRows = $this->inciRows($incorporatedVariant);
        $inciRows = $this->withAccountedInciPercentages($inciRows, $rawBasisWeight);
        $accountedInciWeight = $this->totalNamedWeight($inciRows);
        $accountedInciPercentage = bccomp($rawBasisWeight, '0', 18) > 0
            ? bcmul(bcdiv($accountedInciWeight, $rawBasisWeight, 18), '100', 18)
            : '0';
        $warnings = array_values(array_filter(
            is_array($labeling['warnings'] ?? null) ? $labeling['warnings'] : [],
            'is_string',
        ));

        if (collect($rawRows)->contains(fn (array $row): bool => $row['recorded_inci_missing'])) {
            $warnings[] = 'composition_recorded_inci_missing';
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
                'basis_weight' => (float) $rawBasisWeight,
                'basis_label' => $isSoap
                    ? __('formula_documents.composition.fresh_mixture_basis')
                    : __('formula_documents.composition.authored_formula_basis'),
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
                'default_variant_key' => $labeling['default_variant_key'] ?? null,
                'incorporated_variant_key' => 'incorporated_ingredients',
                'generated_inci_text' => $generatedLabel,
                'authored_label_text' => $authoredLabel,
                'regulatory_regime' => data_get($draft, 'regulatoryRegime'),
                'exposure_mode' => data_get($draft, 'exposureMode'),
            ],
            'warnings' => $warnings,
            'assumption_note' => $isSoap
                ? __('formula_documents.composition.retained_liquid_assumption')
                : null,
            'review_note' => __('formula_documents.composition.review_note'),
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, string>  $phaseNames
     * @return list<array<string, mixed>>
     */
    private function soapRawMaterialRows(array $snapshot, array $phaseNames, string $unit): array
    {
        $draft = $snapshot['draft'] ?? [];
        $phaseItems = is_array($draft['phaseItems'] ?? null) ? $draft['phaseItems'] : [];
        $rows = [];

        foreach (['saponified_oils' => 'oil', 'additives' => 'additive', 'fragrance' => 'fragrance'] as $phaseKey => $role) {
            foreach (($phaseItems[$phaseKey] ?? []) as $row) {
                if (is_array($row) && bccomp($this->decimal($row['weight'] ?? 0), '0', 18) > 0) {
                    $rows[] = $this->phaseRow($row, $phaseKey, $phaseNames[$phaseKey] ?? $phaseKey, $role, $unit);
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
            $rows[] = $this->derivedRow(
                __('formula_documents.composition.koh_to_weigh', ['purity' => $purity]),
                null,
                $kohWeight,
                $unit,
                'lye_alkali',
                __('formula_documents.composition.phase_lye_alkali'),
                'alkali',
                is_array(data_get($snapshot, 'calculation.lye.identities.koh'))
                    ? data_get($snapshot, 'calculation.lye.identities.koh')
                    : [],
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
            $rows[] = $this->derivedRow(
                __('formula_documents.ingredients.water'),
                null,
                $plainWaterWeight,
                $unit,
                'lye_water',
                __('formula_documents.composition.phase_lye_liquid'),
                'lye_liquid',
                [],
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
                $phaseNames['lye_water'] ?? __('formula_documents.composition.phase_lye_liquid'),
                'lye_liquid_substitution',
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
            'recorded_inci_missing' => false,
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
        $names['lye_water'] ??= __('formula_documents.composition.phase_lye_liquid');

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
        return NumberLocale::normalizeDecimalString($value) ?? '0';
    }
}
