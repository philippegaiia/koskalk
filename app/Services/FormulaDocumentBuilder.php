<?php

namespace App\Services;

use Illuminate\Support\Arr;

class FormulaDocumentBuilder
{
    public function __construct(
        private readonly SoapCuredOutputBuilder $soapCuredOutputBuilder,
    ) {}

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $identity
     * @return array<string, mixed>
     */
    public function build(array $snapshot, array $identity = []): array
    {
        $isCosmetic = ($identity['calculation_basis'] ?? null) === 'total_formula';
        $basisWeight = round((float) data_get($snapshot, 'draft.oilWeight', 0), 4);
        $labeling = is_array($snapshot['labeling'] ?? null) ? $snapshot['labeling'] : [];

        return [
            'identity' => $identity,
            'family' => $isCosmetic ? 'cosmetic' : 'soap',
            'percentage_basis' => $isCosmetic ? 'formula' : 'oils',
            'unit' => (string) data_get($snapshot, 'draft.oilUnit', 'g'),
            'basis_weight' => $basisWeight,
            'settings' => $this->settings($snapshot, $isCosmetic),
            'sections' => $this->sections($snapshot, $isCosmetic, $basisWeight),
            'results' => $this->results($snapshot, $isCosmetic, $basisWeight),
            'soap_output' => $isCosmetic ? null : $this->soapCuredOutputBuilder->build(
                $labeling,
                $this->curedWeight($snapshot, $basisWeight),
            ),
            'soap_analysis' => $isCosmetic ? null : [
                'qualities' => $this->qualityRows($snapshot),
                'fatty_acids' => $this->fattyAcidRows($snapshot),
            ],
            'label_text' => (string) data_get($snapshot, 'labeling.print_ingredient_list_text', ''),
            'warnings' => array_values(Arr::wrap(data_get($snapshot, 'labeling.warnings', []))),
        ];
    }

    /** @return array<int, array{label: string, value: string}> */
    private function settings(array $snapshot, bool $isCosmetic): array
    {
        $draft = is_array($snapshot['draft'] ?? null) ? $snapshot['draft'] : [];
        $common = [
            [
                'label' => __('formula_documents.settings.exposure'),
                'value' => ($draft['exposureMode'] ?? 'rinse_off') === 'leave_on'
                    ? __('formula_documents.settings.leave_on')
                    : __('formula_documents.settings.rinse_off'),
            ],
            [
                'label' => __('formula_documents.settings.regime'),
                'value' => mb_strtoupper((string) ($draft['regulatoryRegime'] ?? 'eu')),
            ],
        ];

        if ($isCosmetic) {
            return [
                [
                    'label' => __('formula_documents.settings.entry_mode'),
                    'value' => ($draft['editMode'] ?? 'percentage') === 'weight'
                        ? __('formula_documents.settings.weight')
                        : __('formula_documents.settings.percentage'),
                ],
                ...$common,
            ];
        }

        $waterSetting = match ($draft['waterMode'] ?? 'percent_of_oils') {
            'lye_ratio' => __('formula_documents.settings.lye_ratio_value', ['value' => round((float) ($draft['waterValue'] ?? 0), 2)]),
            'lye_concentration' => __('formula_documents.settings.lye_concentration_value', ['value' => round((float) ($draft['waterValue'] ?? 0), 2)]),
            default => __('formula_documents.settings.oil_water_value', ['value' => round((float) ($draft['waterValue'] ?? 0), 2)]),
        };

        return [
            ['label' => __('formula_documents.settings.lye_system'), 'value' => mb_strtoupper((string) ($draft['lyeType'] ?? 'naoh'))],
            ['label' => __('formula_documents.settings.superfat'), 'value' => round((float) data_get($snapshot, 'calculation.lye.superfat_percentage', $draft['superfat'] ?? 0), 2).'%'],
            ['label' => __('formula_documents.settings.water'), 'value' => $waterSetting],
            ...$common,
        ];
    }

    /** @return array<int, array{key: string, label: string, rows: array<int, array<string, mixed>>}> */
    private function sections(array $snapshot, bool $isCosmetic, float $basisWeight): array
    {
        return $isCosmetic
            ? $this->cosmeticSections($snapshot, $basisWeight)
            : $this->soapSections($snapshot, $basisWeight);
    }

    /** @return array<int, array{key: string, label: string, rows: array<int, array<string, mixed>>}> */
    private function soapSections(array $snapshot, float $basisWeight): array
    {
        $sections = [
            $this->section(
                'saponified_oils',
                __('formula_documents.sections.saponified_oils'),
                $this->ingredientRows(data_get($snapshot, 'draft.phaseItems.saponified_oils', []), $basisWeight),
            ),
            $this->section(
                'lye_water',
                __('formula_documents.sections.lye_water'),
                $this->lyeAndWaterRows($snapshot, $basisWeight),
            ),
            $this->section(
                'formula_additions',
                __('formula_documents.sections.formula_additions'),
                [
                    ...$this->ingredientRows(data_get($snapshot, 'draft.phaseItems.additives', []), $basisWeight),
                    ...$this->ingredientRows(data_get($snapshot, 'draft.phaseItems.fragrance', []), $basisWeight),
                ],
            ),
        ];

        return array_values(array_filter(
            $sections,
            fn (array $section): bool => $section['rows'] !== [],
        ));
    }

    /** @return array<int, array{key: string, label: string, rows: array<int, array<string, mixed>>}> */
    private function cosmeticSections(array $snapshot, float $basisWeight): array
    {
        $phaseItems = data_get($snapshot, 'draft.phaseItems', []);

        if (! is_array($phaseItems)) {
            return [];
        }

        return collect(data_get($snapshot, 'draft.phases', []))
            ->filter(fn (mixed $phase): bool => is_array($phase) && filled($phase['key'] ?? null))
            ->map(function (array $phase) use ($phaseItems, $basisWeight): array {
                $key = (string) $phase['key'];

                return $this->section(
                    $key,
                    (string) ($phase['name'] ?? str($key)->headline()),
                    $this->ingredientRows($phaseItems[$key] ?? [], $basisWeight),
                );
            })
            ->filter(fn (array $section): bool => $section['rows'] !== [])
            ->values()
            ->all();
    }

    /** @return array{key: string, label: string, rows: array<int, array<string, mixed>>} */
    private function section(string $key, string $label, array $rows): array
    {
        return ['key' => $key, 'label' => $label, 'rows' => $rows];
    }

    /** @return array<int, array<string, mixed>> */
    private function ingredientRows(mixed $rows, float $basisWeight): array
    {
        if (! is_array($rows)) {
            return [];
        }

        return collect($rows)
            ->filter(fn (mixed $row): bool => is_array($row) && (float) ($row['percentage'] ?? 0) > 0)
            ->map(function (array $row) use ($basisWeight): array {
                $percentage = round((float) ($row['percentage'] ?? 0), 4);

                return [
                    'name' => (string) ($row['name'] ?? __('formula_documents.ingredients.unnamed')),
                    'percentage' => $percentage,
                    'weight' => round($basisWeight * ($percentage / 100), 4),
                    'note' => filled($row['note'] ?? null) ? (string) $row['note'] : null,
                    'is_user_owned' => (bool) ($row['is_user_owned'] ?? false),
                ];
            })
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function lyeAndWaterRows(array $snapshot, float $basisWeight): array
    {
        $selected = data_get($snapshot, 'calculation.lye.selected', []);

        if (! is_array($selected) || $basisWeight <= 0) {
            return [];
        }

        return collect([
            [__('formula_documents.ingredients.naoh'), (float) ($selected['naoh_weight'] ?? 0)],
            [__('formula_documents.ingredients.koh'), (float) ($selected['koh_to_weigh'] ?? 0)],
            [__('formula_documents.ingredients.water'), (float) data_get($snapshot, 'calculation.lye.water.weight', 0)],
        ])
            ->filter(fn (array $row): bool => $row[1] > 0)
            ->map(fn (array $row): array => [
                'name' => $row[0],
                'percentage' => round(($row[1] / $basisWeight) * 100, 4),
                'weight' => round($row[1], 4),
                'note' => null,
                'is_user_owned' => false,
            ])
            ->values()
            ->all();
    }

    /** @return array<int, array{label: string, value: float, unit: string}> */
    private function results(array $snapshot, bool $isCosmetic, float $basisWeight): array
    {
        $unit = (string) data_get($snapshot, 'draft.oilUnit', 'g');

        if ($isCosmetic) {
            $formulaTotal = collect(data_get($snapshot, 'draft.phaseItems', []))
                ->flatMap(fn (mixed $rows): array => is_array($rows) ? $rows : [])
                ->sum(fn (mixed $row): float => is_array($row) ? (float) ($row['percentage'] ?? 0) : 0.0);

            return [
                ['label' => __('formula_documents.results.batch_quantity'), 'value' => round($basisWeight, 4), 'unit' => $unit],
                ['label' => __('formula_documents.results.formula_total'), 'value' => round((float) $formulaTotal, 4), 'unit' => '%'],
            ];
        }

        $additionWeight = collect([
            ...Arr::wrap(data_get($snapshot, 'draft.phaseItems.additives', [])),
            ...Arr::wrap(data_get($snapshot, 'draft.phaseItems.fragrance', [])),
        ])->sum(fn (mixed $row): float => is_array($row)
            ? round($basisWeight * ((float) ($row['percentage'] ?? 0) / 100), 4)
            : 0.0);
        $selected = data_get($snapshot, 'calculation.lye.selected', []);
        $selected = is_array($selected) ? $selected : [];
        $waterWeight = (float) data_get($snapshot, 'calculation.lye.water.weight', 0);
        $lyeWeight = (float) ($selected['naoh_weight'] ?? 0) + (float) ($selected['koh_to_weigh'] ?? 0);
        $wetWeight = $basisWeight + $additionWeight + $waterWeight + $lyeWeight;

        return [
            ['label' => __('formula_documents.results.wet_batch'), 'value' => round($wetWeight, 4), 'unit' => $unit],
            ['label' => __('formula_documents.results.cured_weight'), 'value' => $this->curedWeight($snapshot, $basisWeight), 'unit' => $unit],
            ['label' => __('formula_documents.results.glycerine'), 'value' => round((float) ($selected['glycerine_weight'] ?? 0), 4), 'unit' => $unit],
            ['label' => __('formula_documents.results.additions'), 'value' => round((float) $additionWeight, 4), 'unit' => $unit],
        ];
    }

    private function curedWeight(array $snapshot, float $basisWeight): float
    {
        $selected = data_get($snapshot, 'calculation.lye.selected', []);
        $selected = is_array($selected) ? $selected : [];
        $waterWeight = (float) data_get($snapshot, 'calculation.lye.water.weight', 0);
        $lyeWeight = (float) ($selected['naoh_weight'] ?? 0) + (float) ($selected['koh_to_weigh'] ?? 0);
        $additionWeight = collect([
            ...Arr::wrap(data_get($snapshot, 'draft.phaseItems.additives', [])),
            ...Arr::wrap(data_get($snapshot, 'draft.phaseItems.fragrance', [])),
        ])->sum(fn (mixed $row): float => is_array($row)
            ? round($basisWeight * ((float) ($row['percentage'] ?? 0) / 100), 4)
            : 0.0);
        $wetWeight = $basisWeight + $additionWeight + $waterWeight + $lyeWeight;
        $nonWaterWeight = max(0, $wetWeight - $waterWeight);

        return $wetWeight > 0 ? round($nonWaterWeight / 0.89, 4) : 0.0;
    }

    /** @return array<int, array{key: string, label: string, value: float, range: string, status: string}> */
    private function qualityRows(array $snapshot): array
    {
        $ranges = [
            'unmolding_firmness' => [45, 70],
            'cured_hardness' => [45, 70],
            'longevity' => [40, 70],
            'cleansing_strength' => [18, 40],
            'mildness' => [50, 75],
            'bubble_volume' => [25, 55],
            'creamy_lather' => [25, 55],
            'lather_stability' => [25, 55],
            'conditioning_feel' => [35, 65],
            'dos_risk' => [0, 20],
            'slime_risk' => [0, 20],
            'cure_speed' => [35, 60],
            'iodine' => [41, 70],
            'ins' => [136, 165],
        ];

        return collect(data_get($snapshot, 'calculation.properties.qualities', []))
            ->map(function (mixed $rawValue, string $key) use ($ranges): array {
                $value = (float) $rawValue;
                $range = $ranges[$key] ?? null;
                $status = match (true) {
                    $range === null => __('formula_documents.analysis.reference_only'),
                    $value < $range[0] => __('formula_documents.analysis.below_range'),
                    $value > $range[1] => __('formula_documents.analysis.above_range'),
                    default => __('formula_documents.analysis.in_range'),
                };

                return [
                    'key' => $key,
                    'label' => __("formula_documents.analysis.quality_metrics.{$key}"),
                    'value' => $value,
                    'range' => $range === null ? '—' : $range[0].'–'.$range[1],
                    'status' => $status,
                ];
            })
            ->values()
            ->all();
    }

    /** @return array<int, array{key: string, label: string, value: float, contribution: string}> */
    private function fattyAcidRows(array $snapshot): array
    {
        $knownContributions = [
            'caprylic' => 'quick_lather',
            'capric' => 'quick_lather',
            'lauric' => 'cleansing_bubbles',
            'myristic' => 'cleansing_bubbles',
            'palmitic' => 'hardness_lasting',
            'stearic' => 'hardness_creamy',
            'ricinoleic' => 'lather_support',
            'oleic' => 'mildness_conditioning',
            'linoleic' => 'conditioning_softness',
            'linolenic' => 'conditioning_softness',
        ];

        return collect(data_get($snapshot, 'calculation.properties.fatty_acid_profile', []))
            ->filter(fn (mixed $value): bool => (float) $value > 0)
            ->map(fn (mixed $value, string $key): array => [
                'key' => $key,
                'label' => str($key)->headline()->toString(),
                'value' => (float) $value,
                'contribution' => __('formula_documents.analysis.contributions.'.($knownContributions[$key] ?? 'other')),
            ])
            ->values()
            ->all();
    }
}
