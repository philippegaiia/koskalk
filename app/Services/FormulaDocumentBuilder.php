<?php

namespace App\Services;

use App\Models\RegulatoryRegime;
use App\SoapSap;
use App\Support\NumberLocale;
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
        $useExplicitRowWeights = ($identity['state'] ?? null) === 'current_working_copy';
        $basisWeight = round((float) data_get($snapshot, 'draft.oilWeight', 0), 4);
        $labeling = is_array($snapshot['labeling'] ?? null) ? $snapshot['labeling'] : [];
        $liquidProcess = $isCosmetic ? null : $this->liquidProcess($snapshot);

        return [
            'identity' => $identity,
            'family' => $isCosmetic ? 'cosmetic' : 'soap',
            'percentage_basis' => $isCosmetic ? 'formula' : 'oils',
            'unit' => (string) data_get($snapshot, 'draft.oilUnit', 'g'),
            'basis_weight' => $basisWeight,
            'settings' => $this->settings($snapshot, $isCosmetic),
            'sections' => $this->sections($snapshot, $isCosmetic, $basisWeight, $useExplicitRowWeights),
            'results' => $liquidProcess === null
                ? $this->results($snapshot, $isCosmetic, $basisWeight)
                : $this->liquidResults($snapshot, $liquidProcess),
            'liquid_process' => $liquidProcess === null ? null : $this->liquidProcessDocument($snapshot, $liquidProcess),
            // A liquid soap is not cured, so it has no cured-soap output.
            'soap_output' => $isCosmetic || $liquidProcess !== null ? null : $this->soapCuredOutputBuilder->build(
                $labeling,
                $this->curedWeight($snapshot, $basisWeight),
                $this->curedWaterPercentage($snapshot),
            ),
            'soap_analysis' => $isCosmetic ? null : [
                'qualities' => $this->qualityRows($snapshot),
                'fatty_acids' => $this->fattyAcidRows($snapshot),
            ],
            'label_text' => (string) data_get($snapshot, 'labeling.print_ingredient_list_text', ''),
            'warnings' => array_values(Arr::wrap(data_get($snapshot, 'labeling.warnings', []))),
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>|null
     */
    private function liquidProcess(array $snapshot): ?array
    {
        $process = data_get($snapshot, 'calculation.liquid_process');

        return is_array($process) && ($process['status'] ?? null) === 'ok' ? $process : null;
    }

    /**
     * Batch sheet steps in process order, with what to add at each step, the
     * running batch weight and its anhydrous soap concentration.
     *
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $process
     * @return array<string, mixed>
     */
    private function liquidProcessDocument(array $snapshot, array $process): array
    {
        $locale = app()->getLocale();
        $neutralizer = is_array($process['neutralizer'] ?? null) ? $process['neutralizer'] : null;
        $neutralizerName = (string) (collect(Arr::wrap(data_get($snapshot, 'draft.phaseItems.neutralization', [])))
            ->first(fn (mixed $row): bool => is_array($row) && filled($row['ingredient_id'] ?? null))['name'] ?? '');
        $freeKoh = (float) data_get($process, 'paste_composition.free_koh_weight', 0);
        $target = fn (string $stage): string => NumberLocale::formatDecimal((float) data_get($process, "stages.{$stage}.target_percentage", 0), 1, $locale);

        $neutralizationInstruction = match (true) {
            $neutralizer === null && $freeKoh > 0 => __('formula_documents.liquid.instructions.neutralization_missing'),
            $neutralizer === null => __('formula_documents.liquid.instructions.no_free_koh'),
            $neutralizer['type'] === 'fatty_acid' => __('formula_documents.liquid.instructions.neutralization_neat', ['acid' => $neutralizerName]),
            default => __('formula_documents.liquid.instructions.neutralization_solution', [
                'acid' => $neutralizerName,
                'strength' => NumberLocale::formatDecimal((float) $neutralizer['solution_percentage'], 0, $locale),
            ]),
        };

        $draftAdditions = collect(['additives', 'fragrance'])
            ->flatMap(fn (string $phaseKey): array => collect(Arr::wrap(data_get($snapshot, "draft.phaseItems.{$phaseKey}", [])))
                ->filter(fn (mixed $row): bool => is_array($row) && filled($row['ingredient_id'] ?? null))
                ->values()
                ->all())
            ->values();

        return [
            'anhydrous_soap_weight' => (float) $process['anhydrous_soap_weight'],
            'final_anhydrous_soap_weight' => (float) $process['final_anhydrous_soap_weight'],
            'final_weight' => (float) $process['final_weight'],
            'paste' => [
                'theoretical_weight' => (float) $process['paste']['theoretical_weight'],
                'weight' => (float) $process['paste']['weight'],
                'is_weighed' => (float) $process['paste']['evaporated_weight'] !== 0.0,
            ],
            'steps' => [
                [
                    'key' => 'paste',
                    'label' => __('formula_documents.liquid.steps.paste'),
                    'instruction' => __('formula_documents.liquid.instructions.paste'),
                    'add' => null,
                    'mass' => (float) $process['paste']['weight'],
                    'anhydrous_percentage' => (float) $process['paste']['anhydrous_percentage'],
                ],
                [
                    'key' => 'first_dilution',
                    'label' => __('formula_documents.liquid.steps.first_dilution'),
                    'instruction' => __('formula_documents.liquid.instructions.first_dilution', ['target' => $target('first_dilution')]),
                    'add' => (float) $process['stages']['first_dilution']['add_weight'],
                    'mass' => (float) $process['stages']['first_dilution']['mass'],
                    'anhydrous_percentage' => (float) $process['stages']['first_dilution']['anhydrous_percentage'],
                ],
                [
                    'key' => 'neutralization',
                    'label' => __('formula_documents.liquid.steps.neutralization'),
                    'instruction' => $neutralizationInstruction,
                    'add' => (float) $process['stages']['neutralization']['add_weight'],
                    'mass' => (float) $process['stages']['neutralization']['mass'],
                    'anhydrous_percentage' => (float) $process['stages']['neutralization']['anhydrous_percentage'],
                ],
                [
                    'key' => 'final_dilution',
                    'label' => __('formula_documents.liquid.steps.final_dilution'),
                    'instruction' => __('formula_documents.liquid.instructions.final_dilution', ['target' => $target('final_dilution')]),
                    'add' => (float) $process['stages']['final_dilution']['add_weight'],
                    'mass' => (float) $process['stages']['final_dilution']['mass'],
                    'anhydrous_percentage' => (float) $process['stages']['final_dilution']['anhydrous_percentage'],
                ],
            ],
            'neutralizer' => $neutralizer === null ? null : [
                'name' => $neutralizerName,
                'weight' => (float) $neutralizer['weight'],
                'solution_weight' => (float) $neutralizer['solution_weight'],
                'recommended_weight' => (float) $neutralizer['recommended_weight'],
            ],
            'additions' => collect($process['additions'] ?? [])
                ->values()
                ->map(fn (array $addition, int $index): array => [
                    'name' => (string) ($draftAdditions->get($index)['name'] ?? ''),
                    'weight' => (float) $addition['weight'],
                    'final_percentage' => $addition['final_percentage'] === null ? null : (float) $addition['final_percentage'],
                ])
                ->all(),
            'warnings' => collect($process['warnings'] ?? [])
                ->map(fn (string $warning): string => __('workbench.liquid.warnings.'.$warning))
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $process
     * @return array<int, array{label: string, value: float, unit: string}>
     */
    private function liquidResults(array $snapshot, array $process): array
    {
        $unit = (string) data_get($snapshot, 'draft.oilUnit', 'g');
        $pasteAnhydrousSoapWeight = round((float) $process['anhydrous_soap_weight'], 4);
        $finalAnhydrousSoapWeight = round((float) $process['final_anhydrous_soap_weight'], 4);

        return [
            ['label' => __('formula_documents.results.final_batch'), 'value' => round((float) $process['final_weight'], 4), 'unit' => $unit],
            ...($finalAnhydrousSoapWeight === $pasteAnhydrousSoapWeight ? [] : [
                ['label' => __('formula_documents.results.paste_anhydrous_soap_weight'), 'value' => $pasteAnhydrousSoapWeight, 'unit' => $unit],
            ]),
            ['label' => __('formula_documents.results.anhydrous_soap_weight'), 'value' => $finalAnhydrousSoapWeight, 'unit' => $unit],
            ['label' => __('formula_documents.results.anhydrous_soap'), 'value' => round((float) $process['final_anhydrous_percentage'], 4), 'unit' => '%'],
            ['label' => __('formula_documents.results.glycerine'), 'value' => round((float) data_get($process, 'composition.glycerine_weight', 0), 4), 'unit' => $unit],
            ['label' => __('formula_documents.results.additions'), 'value' => round((float) data_get($process, 'composition.additions_weight', 0), 4), 'unit' => $unit],
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
                'value' => ($draft['regulatoryRegime'] ?? RegulatoryRegime::NONE_CODE) === RegulatoryRegime::NONE_CODE
                    ? __('formula_documents.settings.no_market')
                    : mb_strtoupper((string) ($draft['regulatoryRegime'] ?? RegulatoryRegime::NONE_CODE)),
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
    private function sections(array $snapshot, bool $isCosmetic, float $basisWeight, bool $useExplicitRowWeights): array
    {
        return $isCosmetic
            ? $this->cosmeticSections($snapshot, $basisWeight, $useExplicitRowWeights)
            : $this->soapSections($snapshot, $basisWeight, $useExplicitRowWeights);
    }

    /** @return array<int, array{key: string, label: string, rows: array<int, array<string, mixed>>}> */
    private function soapSections(array $snapshot, float $basisWeight, bool $useExplicitRowWeights): array
    {
        $sections = [
            $this->section(
                'saponified_oils',
                __('formula_documents.sections.saponified_oils'),
                $this->ingredientRows(data_get($snapshot, 'draft.phaseItems.saponified_oils', []), $basisWeight, $useExplicitRowWeights),
            ),
            $this->section(
                'lye_water',
                __('formula_documents.sections.lye_water'),
                $this->lyeAndWaterRows($snapshot, data_get($snapshot, 'draft.oilWeight', 0)),
            ),
            $this->section(
                'formula_additions',
                __('formula_documents.sections.formula_additions'),
                [
                    ...$this->ingredientRows(data_get($snapshot, 'draft.phaseItems.additives', []), $basisWeight, $useExplicitRowWeights),
                    ...$this->ingredientRows(data_get($snapshot, 'draft.phaseItems.fragrance', []), $basisWeight, $useExplicitRowWeights),
                ],
            ),
        ];

        return array_values(array_filter(
            $sections,
            fn (array $section): bool => $section['rows'] !== [],
        ));
    }

    /** @return array<int, array{key: string, label: string, rows: array<int, array<string, mixed>>}> */
    private function cosmeticSections(array $snapshot, float $basisWeight, bool $useExplicitRowWeights): array
    {
        $phaseItems = data_get($snapshot, 'draft.phaseItems', []);

        if (! is_array($phaseItems)) {
            return [];
        }

        return collect(data_get($snapshot, 'draft.phases', []))
            ->filter(fn (mixed $phase): bool => is_array($phase) && filled($phase['key'] ?? null))
            ->map(function (array $phase) use ($phaseItems, $basisWeight, $useExplicitRowWeights): array {
                $key = (string) $phase['key'];

                return $this->section(
                    $key,
                    (string) ($phase['name'] ?? str($key)->headline()),
                    $this->ingredientRows($phaseItems[$key] ?? [], $basisWeight, $useExplicitRowWeights),
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
    private function ingredientRows(mixed $rows, float $basisWeight, bool $useExplicitWeight): array
    {
        if (! is_array($rows)) {
            return [];
        }

        return collect($rows)
            ->filter(fn (mixed $row): bool => is_array($row) && (float) ($row['percentage'] ?? 0) > 0)
            ->map(function (array $row) use ($basisWeight, $useExplicitWeight): array {
                $percentage = round((float) ($row['percentage'] ?? 0), 4);
                $weight = $useExplicitWeight && is_numeric($row['weight'] ?? null)
                    ? round((float) $row['weight'], 4)
                    : round($basisWeight * ($percentage / 100), 4);

                return [
                    'name' => (string) ($row['name'] ?? __('formula_documents.ingredients.unnamed')),
                    'percentage' => $percentage,
                    'weight' => $weight,
                    'note' => filled($row['note'] ?? null) ? (string) $row['note'] : null,
                    'is_user_owned' => (bool) ($row['is_user_owned'] ?? false),
                ];
            })
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function lyeAndWaterRows(array $snapshot, mixed $basisWeight): array
    {
        $selected = data_get($snapshot, 'calculation.lye.selected', []);
        $normalizedBasisWeight = $this->decimal($basisWeight);

        if (! is_array($selected) || bccomp($normalizedBasisWeight, '0', 18) <= 0) {
            return [];
        }

        $rows = collect([
            [__('formula_documents.ingredients.naoh'), $this->decimal($selected['naoh_weight'] ?? 0)],
            [__('formula_documents.ingredients.koh'), $this->decimal($selected['koh_to_weigh'] ?? 0)],
            [
                __('formula_documents.ingredients.water'),
                $this->decimal(data_get(
                    $snapshot,
                    'calculation.lye.liquid_composition.water_weight',
                    data_get($snapshot, 'calculation.lye.water.weight', 0),
                )),
            ],
        ])
            ->filter(fn (array $row): bool => bccomp($row[1], '0', 18) > 0)
            ->map(fn (array $row): array => [
                'name' => $row[0],
                'percentage' => $this->percentageOfBasis($row[1], $normalizedBasisWeight),
                'weight' => (float) $this->roundMass($row[1]),
                'note' => null,
                'is_user_owned' => false,
            ]);

        $draftRows = collect(Arr::wrap(data_get($snapshot, 'draft.phaseItems.lye_water', [])))
            ->filter(fn (mixed $row): bool => is_array($row) && (float) ($row['percentage'] ?? 0) > 0)
            ->values();
        $substitutionRows = collect(Arr::wrap(data_get($snapshot, 'calculation.lye.liquid_composition.substitutions', [])))
            ->map(function (mixed $substitution, int $index) use ($normalizedBasisWeight, $draftRows): ?array {
                $weight = is_array($substitution) ? $this->decimal($substitution['weight'] ?? 0) : '0';

                if (! is_array($substitution) || bccomp($weight, '0', 18) <= 0) {
                    return null;
                }

                $draftRow = $draftRows->get($index, []);

                return [
                    'name' => (string) ($draftRow['name'] ?? __('formula_documents.ingredients.unnamed')),
                    'percentage' => $this->percentageOfBasis($weight, $normalizedBasisWeight),
                    'weight' => (float) $this->roundMass($weight),
                    'note' => filled($draftRow['note'] ?? null) ? (string) $draftRow['note'] : null,
                    'is_user_owned' => (bool) ($draftRow['is_user_owned'] ?? false),
                ];
            })
            ->filter();

        return $rows->merge($substitutionRows)->values()->all();
    }

    private function percentageOfBasis(string $weight, string $basisWeight): float
    {
        return (float) $this->roundMass(bcmul(bcdiv($weight, $basisWeight, 18), '100', 18));
    }

    private function decimal(mixed $value): string
    {
        return NumberLocale::normalizeDecimalString($value) ?? '0';
    }

    private function roundMass(string $value): string
    {
        return bcadd(bcadd($value, '0.00005', 5), '0', 4);
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

        return $wetWeight > 0
            ? round(SoapSap::curedWeight($nonWaterWeight, $this->curedWaterPercentage($snapshot)), 4)
            : 0.0;
    }

    /** @param array<string, mixed> $snapshot */
    private function curedWaterPercentage(array $snapshot): float
    {
        return SoapSap::curedWaterPercentage(data_get($snapshot, 'draft.curedWaterPercentage'));
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
