<?php

namespace App\Services;

use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\RecipeVersionCosting;
use App\Models\RecipeVersionPackagingItem;
use App\Models\User;

class RecipeVersionViewDataBuilder
{
    public function __construct(
        private readonly RecipeWorkbenchService $recipeWorkbenchService,
        private readonly RecipeVersionCostPreviewBuilder $costPreviewBuilder,
    ) {}

    /**
     * @return array{
     *     recipe: Recipe,
     *     version: RecipeVersion,
     *     snapshot: array<string, mixed>,
     *     phaseSections: array<int, array<string, mixed>>,
     *     summaryCards: array<int, array<string, scalar|null>>,
     *     contextRows: array<int, array<string, scalar|null>>,
     *     lyeRows: array<int, array<string, scalar|null>>,
     *     recoverySnapshots: array<int, array<string, mixed>>,
     *     selectedOilWeight: float
     * }
     */
    public function build(Recipe $recipe, RecipeVersion $version, mixed $requestedOilWeight = null, array $batchContext = []): array
    {
        $snapshot = $this->recipeWorkbenchService->versionSnapshot($recipe, $version->id);

        abort_if($snapshot === null, 404);

        $selectedOilWeight = $this->requestedOilWeight(
            $requestedOilWeight,
            $snapshot['draft']['oilWeight'] ?? $version->batch_size,
        );

        if (abs($selectedOilWeight - (float) ($snapshot['draft']['oilWeight'] ?? 0)) > 0.0001) {
            $draft = $snapshot['draft'];
            $draft['oilWeight'] = $selectedOilWeight;
            $snapshot = $this->recipeWorkbenchService->snapshotFromWorkbenchDraft($draft);
        }

        $isCosmetic = $recipe->productFamily?->calculation_basis === 'total_formula';
        $normalizedBatchContext = $this->batchContext($batchContext, $selectedOilWeight);

        $phaseSections = $this->phaseSections($snapshot['draft'], $isCosmetic);
        $costingData = $this->costingData($recipe, $version, $selectedOilWeight, $normalizedBatchContext);

        return [
            'recipe' => $recipe,
            'version' => $version,
            'snapshot' => $snapshot,
            'phaseSections' => $phaseSections,
            'summaryCards' => $this->summaryCards($snapshot['draft'], $snapshot['calculation'], $isCosmetic),
            'contextRows' => $this->contextRows($snapshot['draft'], $snapshot['calculation'], $version, $isCosmetic),
            'lyeRows' => $isCosmetic ? [] : $this->lyeRows($snapshot['draft'], $snapshot['calculation']),
            'recoverySnapshots' => $this->recoverySnapshots($recipe, $version),
            'packagingPlanRows' => $this->packagingPlanRows($version),
            'costingSummary' => $costingData['summary'],
            'costingIngredientRows' => $costingData['ingredientRows'],
            'costingPackagingRows' => $costingData['packagingRows'],
            'costingCurrency' => $costingData['currency'],
            'hasCostingData' => $costingData['hasCostingData'],
            'hasUnpricedRows' => $costingData['hasUnpricedRows'],
            'batchContext' => $normalizedBatchContext,
            'selectedOilWeight' => $selectedOilWeight,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function packagingPlanRows(RecipeVersion $version): array
    {
        return $version->packagingItems()
            ->with('packagingItem')
            ->get()
            ->map(fn (RecipeVersionPackagingItem $item): array => [
                'name' => $item->name,
                'components_per_unit' => (float) $item->components_per_unit,
                'notes' => $item->notes,
                'catalog_price' => $item->packagingItem?->unit_cost === null ? null : (float) $item->packagingItem->unit_cost,
                'currency' => $item->packagingItem?->currency,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{batch_number: string, manufacture_date: string, batch_basis: string, units_produced: string}
     */
    private function batchContext(array $context, float $selectedOilWeight): array
    {
        $batchBasis = $this->stringValue($context['batch_basis'] ?? $context['oil_weight'] ?? '');

        return [
            'batch_number' => $this->stringValue($context['batch_number'] ?? ''),
            'manufacture_date' => filled($this->stringValue($context['manufacture_date'] ?? ''))
                ? $this->stringValue($context['manufacture_date'])
                : now()->toDateString(),
            'batch_basis' => filled($batchBasis)
                ? $batchBasis
                : rtrim(rtrim(number_format($selectedOilWeight, 2, '.', ''), '0'), '.'),
            'units_produced' => $this->stringValue($context['units_produced'] ?? ''),
        ];
    }

    private function stringValue(mixed $value): string
    {
        return trim((string) $value);
    }

    private function requestedOilWeight(mixed $candidate, mixed $defaultWeight): float
    {
        if (! is_numeric($candidate)) {
            return round((float) $defaultWeight, 4);
        }

        $normalized = round((float) $candidate, 4);

        return $normalized > 0 ? $normalized : round((float) $defaultWeight, 4);
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<int, array<string, mixed>>
     */
    private function phaseSections(array $draft, bool $isCosmetic): array
    {
        if ($isCosmetic) {
            return $this->cosmeticPhaseSections($draft);
        }

        return $this->soapPhaseSections($draft);
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<int, array<string, mixed>>
     */
    private function soapPhaseSections(array $draft): array
    {
        $sectionLabels = [
            'saponified_oils' => 'Saponified oils',
            'additives' => 'Additives',
            'fragrance' => 'Fragrance and aromatics',
        ];

        return collect($sectionLabels)
            ->map(function (string $label, string $key) use ($draft): ?array {
                $rows = collect($draft['phaseItems'][$key] ?? [])
                    ->filter(fn (mixed $row): bool => is_array($row) && ((float) ($row['percentage'] ?? 0) > 0))
                    ->map(function (array $row) use ($draft): array {
                        $percentage = (float) ($row['percentage'] ?? 0);
                        $weight = round(((float) ($draft['oilWeight'] ?? 0)) * ($percentage / 100), 4);

                        return [
                            'name' => $row['name'] ?? 'Unnamed ingredient',
                            'inci_name' => $row['inci_name'] ?? null,
                            'percentage' => $percentage,
                            'weight' => $weight,
                            'note' => $row['note'] ?? null,
                        ];
                    })
                    ->values();

                if ($rows->isEmpty()) {
                    return null;
                }

                return [
                    'key' => $key,
                    'label' => $label,
                    'rows' => $rows->all(),
                    'total_percentage' => round((float) $rows->sum('percentage'), 4),
                    'total_weight' => round((float) $rows->sum('weight'), 4),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<int, array<string, mixed>>
     */
    private function cosmeticPhaseSections(array $draft): array
    {
        $phaseDefinitions = collect(is_array($draft['phases'] ?? null) ? $draft['phases'] : [])
            ->filter(fn (mixed $phase): bool => is_array($phase))
            ->mapWithKeys(fn (array $phase): array => [
                (string) ($phase['key'] ?? '') => (string) ($phase['name'] ?? ''),
            ])
            ->filter(fn (string $name, string $key): bool => $key !== '');

        return collect($draft['phaseItems'] ?? [])
            ->filter(fn (mixed $rows, mixed $key): bool => is_string($key) && is_array($rows))
            ->map(function (array $rows, string $key) use ($draft, $phaseDefinitions): ?array {
                $phaseRows = collect($rows)
                    ->filter(fn (mixed $row): bool => is_array($row) && ((float) ($row['percentage'] ?? 0) > 0))
                    ->map(function (array $row) use ($draft): array {
                        $percentage = (float) ($row['percentage'] ?? 0);
                        $weight = round(((float) ($draft['oilWeight'] ?? 0)) * ($percentage / 100), 4);

                        return [
                            'name' => $row['name'] ?? 'Unnamed ingredient',
                            'inci_name' => $row['inci_name'] ?? null,
                            'percentage' => $percentage,
                            'weight' => $weight,
                            'note' => $row['note'] ?? null,
                        ];
                    })
                    ->values();

                if ($phaseRows->isEmpty()) {
                    return null;
                }

                return [
                    'key' => $key,
                    'label' => $phaseDefinitions->get($key) ?: str($key)->replace('_', ' ')->title()->toString(),
                    'basis_label' => 'formula',
                    'rows' => $phaseRows->all(),
                    'total_percentage' => round((float) $phaseRows->sum('percentage'), 4),
                    'total_weight' => round((float) $phaseRows->sum('weight'), 4),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>|null  $calculation
     * @return array<int, array<string, scalar|null>>
     */
    private function summaryCards(array $draft, ?array $calculation, bool $isCosmetic): array
    {
        if ($isCosmetic) {
            $oilWeight = round((float) ($draft['oilWeight'] ?? 0), 4);
            $formulaTotal = round((float) ($draft['formulaTotalPercentage'] ?? collect($draft['phaseItems'] ?? [])
                ->flatMap(fn (mixed $rows): array => is_array($rows) ? $rows : [])
                ->sum(fn (mixed $row): float => is_array($row) ? (float) ($row['percentage'] ?? 0) : 0)), 4);

            return [
                [
                    'label' => 'Batch weight',
                    'value' => round($oilWeight, 2),
                    'unit' => $draft['oilUnit'] ?? 'g',
                ],
                [
                    'label' => 'Formula total',
                    'value' => round($formulaTotal, 2),
                    'unit' => '%',
                ],
                [
                    'label' => 'Ingredients',
                    'value' => collect($draft['phaseItems'] ?? [])
                        ->flatMap(fn (mixed $rows): array => is_array($rows) ? $rows : [])
                        ->filter(fn (mixed $row): bool => is_array($row) && filled($row['ingredient_id'] ?? null))
                        ->count(),
                    'unit' => null,
                ],
                [
                    'label' => 'Phases',
                    'value' => collect($draft['phaseItems'] ?? [])->filter(fn (mixed $rows): bool => is_array($rows) && $rows !== [])->count(),
                    'unit' => null,
                ],
            ];
        }

        $oilWeight = round((float) ($draft['oilWeight'] ?? 0), 4);
        $additionWeight = collect([
            ...($draft['phaseItems']['additives'] ?? []),
            ...($draft['phaseItems']['fragrance'] ?? []),
        ])->sum(fn (mixed $row): float => round($oilWeight * (((float) ($row['percentage'] ?? 0)) / 100), 4));

        $selectedLye = $calculation['lye']['selected'] ?? [];
        $waterWeight = (float) ($calculation['lye']['water']['weight'] ?? 0);
        $lyeToWeigh = match ($draft['lyeType'] ?? 'naoh') {
            'koh' => (float) ($selectedLye['koh_to_weigh'] ?? 0),
            'dual' => (float) ($selectedLye['naoh_weight'] ?? 0) + (float) ($selectedLye['koh_to_weigh'] ?? 0),
            default => (float) ($selectedLye['naoh_weight'] ?? 0),
        };
        $wetWeight = $oilWeight + $additionWeight + $waterWeight + $lyeToWeigh;
        $nonWaterWeight = max(0, $wetWeight - $waterWeight);
        $curedWeight = $wetWeight > 0 ? $nonWaterWeight / (1 - 0.11) : 0.0;

        return [
            [
                'label' => 'Oil quantity',
                'value' => round($oilWeight, 2),
                'unit' => $draft['oilUnit'] ?? 'g',
            ],
            [
                'label' => 'Wet batch weight',
                'value' => round($wetWeight, 2),
                'unit' => $draft['oilUnit'] ?? 'g',
            ],
            [
                'label' => 'Weight after cure',
                'value' => round($curedWeight, 2),
                'unit' => $draft['oilUnit'] ?? 'g',
            ],
            [
                'label' => 'Produced glycerine',
                'value' => round((float) ($selectedLye['glycerine_weight'] ?? 0), 2),
                'unit' => $draft['oilUnit'] ?? 'g',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>|null  $calculation
     * @return array<int, array<string, scalar|null>>
     */
    private function contextRows(array $draft, ?array $calculation, RecipeVersion $version, bool $isCosmetic): array
    {
        $rows = [
            [
                'label' => 'Reference formula',
                'value' => $version->saved_at !== null ? 'Current saved recipe' : 'Not saved yet',
            ],
            [
                'label' => 'Saved at',
                'value' => $version->saved_at?->format('Y-m-d H:i') ?? 'Not saved yet',
            ],
            [
                'label' => 'Exposure mode',
                'value' => $draft['exposureMode'] === 'leave_on' ? 'Leave-on' : 'Rinse-off',
            ],
            [
                'label' => 'Regime',
                'value' => strtoupper((string) ($draft['regulatoryRegime'] ?? 'eu')),
            ],
            [
                'label' => 'Manufacturing mode',
                'value' => ($draft['manufacturingMode'] ?? 'saponify_in_formula') === 'blend_only'
                    ? 'Blend only'
                    : 'Saponify in formula',
            ],
        ];

        if ($isCosmetic) {
            return [
                ...$rows,
                [
                    'label' => 'Entry mode',
                    'value' => ($draft['editMode'] ?? 'percentage') === 'weight' ? 'Weight' : 'Percentage',
                ],
            ];
        }

        return [
            ...$rows,
            [
                'label' => 'Superfat',
                'value' => round((float) ($calculation['lye']['superfat_percentage'] ?? $draft['superfat'] ?? 0), 2).'%',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>|null  $calculation
     * @return array<int, array<string, scalar|null>>
     */
    private function lyeRows(array $draft, ?array $calculation): array
    {
        if ($calculation === null) {
            return [];
        }

        $selectedLye = $calculation['lye']['selected'] ?? [];

        return [
            [
                'label' => 'Lye system',
                'value' => strtoupper((string) ($draft['lyeType'] ?? 'naoh')),
                'unit' => null,
            ],
            [
                'label' => 'NaOH to weigh',
                'value' => round((float) ($selectedLye['naoh_weight'] ?? 0), 2),
                'unit' => $draft['oilUnit'] ?? 'g',
            ],
            [
                'label' => 'KOH to weigh',
                'value' => round((float) ($selectedLye['koh_to_weigh'] ?? 0), 2),
                'unit' => $draft['oilUnit'] ?? 'g',
            ],
            [
                'label' => 'Water',
                'value' => round((float) ($calculation['lye']['water']['weight'] ?? 0), 2),
                'unit' => $draft['oilUnit'] ?? 'g',
            ],
            [
                'label' => 'Water setting',
                'value' => match ($draft['waterMode'] ?? 'percent_of_oils') {
                    'lye_ratio' => 'Lye ratio: '.round((float) ($draft['waterValue'] ?? 0), 2),
                    'lye_concentration' => 'Lye concentration: '.round((float) ($draft['waterValue'] ?? 0), 2).'%',
                    default => '% of oils: '.round((float) ($draft['waterValue'] ?? 0), 2).'%',
                },
                'unit' => null,
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recoverySnapshots(Recipe $recipe, RecipeVersion $currentVersion): array
    {
        return collect($this->recipeWorkbenchService->versionOptions($recipe))
            ->map(function (array $option) use ($currentVersion): array {
                return [
                    ...$option,
                    'is_current' => (int) $option['id'] === $currentVersion->id,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array{
     *     summary: array<int, array{label: string, value: string}>,
     *     ingredientRows: array<int, array<string, mixed>>,
     *     packagingRows: array<int, array<string, mixed>>,
     *     currency: string,
     *     hasCostingData: bool,
     *     hasUnpricedRows: bool
     * }
     */
    private function costingData(Recipe $recipe, RecipeVersion $version, float $selectedOilWeight, array $batchContext): array
    {
        $user = $recipe->ownerUser();

        if (! $user instanceof User) {
            return [
                'summary' => [],
                'ingredientRows' => [],
                'packagingRows' => [],
                'currency' => 'EUR',
                'hasCostingData' => false,
                'hasUnpricedRows' => false,
            ];
        }

        $existingCosting = RecipeVersionCosting::query()
            ->where('recipe_version_id', $version->id)
            ->where('user_id', $user->id)
            ->first();

        if (! $existingCosting instanceof RecipeVersionCosting) {
            return [
                'summary' => [],
                'ingredientRows' => [],
                'packagingRows' => [],
                'currency' => $user->defaultCurrency(),
                'hasCostingData' => false,
                'hasUnpricedRows' => false,
            ];
        }

        $unitsProduced = $this->positiveInt($batchContext['units_produced'] ?? null) ?? $existingCosting?->units_produced;
        $preview = $this->costPreviewBuilder->build(
            recipe: $recipe,
            version: $version,
            user: $user,
            batchBasisValue: $this->positiveFloat($batchContext['batch_basis'] ?? null) ?? $selectedOilWeight,
            unitsProduced: $unitsProduced,
        );

        $ingredientRows = collect($preview['ingredient_rows'])
            ->map(fn (array $row): array => [
                ...$row,
                'phase' => $row['phase_name'],
                'name' => $row['ingredient_name'],
                'weight' => $row['quantity'],
                'line_cost' => $row['is_unpriced'] ? null : $row['line_cost'],
            ])
            ->values()
            ->all();

        $packagingRows = collect($preview['packaging_rows'])
            ->map(fn (array $row): array => [
                ...$row,
                'quantity' => $row['components_per_unit'],
                'line_cost' => $unitsProduced !== null && $unitsProduced > 0 ? $row['line_cost'] : null,
            ])
            ->values()
            ->all();

        $packagingTotal = $unitsProduced !== null && $unitsProduced > 0 ? $preview['packaging_total'] : null;

        return [
            'summary' => [
                ['label' => 'Ingredient total', 'value' => $this->money($preview['ingredient_total'], $preview['currency'])],
                ['label' => 'Packaging total', 'value' => $packagingTotal !== null ? $this->money($packagingTotal, $preview['currency']) : 'Set units produced'],
                ['label' => 'Total batch cost', 'value' => $this->money($preview['total_cost'], $preview['currency'])],
                ['label' => 'Cost per unit', 'value' => $preview['cost_per_unit'] !== null ? $this->money($preview['cost_per_unit'], $preview['currency']) : 'Not set'],
            ],
            'ingredientRows' => $ingredientRows,
            'packagingRows' => $packagingRows,
            'currency' => $preview['currency'],
            'hasCostingData' => $ingredientRows !== [] || $packagingRows !== [],
            'hasUnpricedRows' => $preview['has_unpriced_rows'],
        ];
    }

    private function money(float $value, string $currency): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.').' '.$currency;
    }

    private function positiveFloat(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $normalized = (float) $value;

        return $normalized > 0 ? $normalized : null;
    }

    private function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $normalized = (int) $value;

        return $normalized > 0 ? $normalized : null;
    }
}
