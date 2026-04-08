<?php

namespace App\Services;

use App\Models\Ingredient;
use Illuminate\Support\Str;

class InciGenerationService
{
    private const DEFAULT_LIST_VARIANT_KEY = 'saponified_with_superfat';

    private const INCORPORATED_LIST_VARIANT_KEY = 'incorporated_ingredients';

    /**
     * @var array<int, Ingredient|null>
     */
    private array $ingredientCache = [];

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $soapCalculation
     * @return array{
     *     basis: array{
     *         label: string,
     *         note: string,
     *         formula_weight: float,
     *         threshold_percent: float
     *     },
     *     ingredient_rows: array<int, array{
     *         label: string,
     *         weight: float,
     *         percent_of_formula: float,
     *         kind: string,
     *         source_ingredients: array<int, string>
     *     }>,
     *     declaration_rows: array<int, array{
     *         label: string,
     *         percent_of_formula: float,
     *         threshold_percent: float,
     *         exceeds_threshold: bool,
     *         included_in_inci: bool,
     *         suppressed_by_existing_label: bool,
     *         status_label: string,
     *         source_ingredients: array<int, string>,
     *         notes: string|null
     *     }>,
     *     list_variants: array<int, array{
     *         key: string,
     *         label: string,
     *         note: string,
     *         ingredient_rows: array<int, array{
     *             label: string,
     *             weight: float,
     *             percent_of_formula: float,
     *             kind: string,
     *             source_ingredients: array<int, string>
     *         }>,
     *         declaration_rows: array<int, array{
     *             label: string,
     *             percent_of_formula: float,
     *             threshold_percent: float,
     *             exceeds_threshold: bool,
     *             included_in_inci: bool,
     *             suppressed_by_existing_label: bool,
     *             status_label: string,
     *             source_ingredients: array<int, string>,
     *             notes: string|null
     *         }>,
     *         final_labels: array<int, string>,
     *         final_label_text: string
     *     }>,
     *     default_variant_key: string,
     *     final_labels: array<int, string>,
     *     final_label_text: string,
     *     warnings: array<int, string>
     * }
     */
    public function generate(array $payload, ?array $soapCalculation = null): array
    {
        $this->ingredientCache = [];
        $this->preloadIngredientsForPayload($payload);

        $rowContexts = $this->resolveRowContexts($payload);
        $basis = $this->basisState($payload, $rowContexts, $soapCalculation);
        $listVariants = $this->listVariants($payload, $rowContexts, $basis, $soapCalculation);
        $defaultVariant = $this->defaultListVariant($listVariants);

        return [
            'basis' => $basis,
            'ingredient_rows' => $defaultVariant['ingredient_rows'],
            'declaration_rows' => $defaultVariant['declaration_rows'],
            'list_variants' => $listVariants,
            'default_variant_key' => $defaultVariant['key'],
            'final_labels' => $defaultVariant['final_labels'],
            'final_label_text' => $defaultVariant['final_label_text'],
            'warnings' => $this->warnings($payload, $rowContexts, $this->variantWarnings($listVariants), $soapCalculation),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, array{
     *     phase_key: string,
     *     weight: float,
     *     ingredient: Ingredient|null,
     *     ingredient_name: string
     * }>  $rowContexts
     * @param  array{
     *     label: string,
     *     note: string,
     *     formula_weight: float,
     *     threshold_percent: float
     * }  $basis
     * @param  array<string, mixed>|null  $soapCalculation
     * @return array<int, array{
     *     key: string,
     *     label: string,
     *     note: string,
     *     ingredient_rows: array<int, array{
     *         label: string,
     *         weight: float,
     *         percent_of_formula: float,
     *         kind: string,
     *         source_ingredients: array<int, string>
     *     }>,
     *     declaration_rows: array<int, array{
     *         label: string,
     *         percent_of_formula: float,
     *         threshold_percent: float,
     *         exceeds_threshold: bool,
     *         included_in_inci: bool,
     *         suppressed_by_existing_label: bool,
     *         status_label: string,
     *         source_ingredients: array<int, string>,
     *         notes: string|null
     *     }>,
     *     final_labels: array<int, string>,
     *     final_label_text: string,
     *     warnings: array<int, string>
     * }>
     */
    private function listVariants(
        array $payload,
        array $rowContexts,
        array $basis,
        ?array $soapCalculation,
    ): array {
        $variantDefinitions = [
            [
                'key' => self::DEFAULT_LIST_VARIANT_KEY,
                'label' => 'Saponified + superfat oils',
                'note' => 'Uses saponified oil names while also listing the theoretical unsaponified share from the selected superfat percentage.',
            ],
            [
                'key' => self::INCORPORATED_LIST_VARIANT_KEY,
                'label' => 'Incorporated ingredients',
                'note' => 'Lists ingredients as incorporated before saponification, then appends qualifying allergens.',
            ],
        ];

        return array_map(function (array $definition) use ($payload, $rowContexts, $basis, $soapCalculation): array {
            $ingredientRowsState = $this->ingredientRowsState(
                $payload,
                $rowContexts,
                $basis['formula_weight'],
                $soapCalculation,
                $definition['key'],
            );
            $declarationRows = $this->declarationRows(
                $rowContexts,
                $ingredientRowsState['label_keys'],
                $basis['formula_weight'],
                $basis['threshold_percent'],
            );
            $finalLabels = $this->finalLabels($ingredientRowsState['rows'], $declarationRows);

            return [
                'key' => $definition['key'],
                'label' => $definition['label'],
                'note' => $definition['note'],
                'ingredient_rows' => $ingredientRowsState['rows'],
                'declaration_rows' => $declarationRows,
                'final_labels' => $finalLabels,
                'final_label_text' => implode(', ', $finalLabels),
                'warnings' => $ingredientRowsState['fallback_warnings'],
            ];
        }, $variantDefinitions);
    }

    /**
     * @param  array<int, array{
     *     key: string,
     *     label: string,
     *     note: string,
     *     ingredient_rows: array<int, array{
     *         label: string,
     *         weight: float,
     *         percent_of_formula: float,
     *         kind: string,
     *         source_ingredients: array<int, string>
     *     }>,
     *     declaration_rows: array<int, array{
     *         label: string,
     *         percent_of_formula: float,
     *         threshold_percent: float,
     *         exceeds_threshold: bool,
     *         included_in_inci: bool,
     *         suppressed_by_existing_label: bool,
     *         status_label: string,
     *         source_ingredients: array<int, string>,
     *         notes: string|null
     *     }>,
     *     final_labels: array<int, string>,
     *     final_label_text: string,
     *     warnings: array<int, string>
     * }>  $listVariants
     * @return array{
     *     key: string,
     *     label: string,
     *     note: string,
     *     ingredient_rows: array<int, array{
     *         label: string,
     *         weight: float,
     *         percent_of_formula: float,
     *         kind: string,
     *         source_ingredients: array<int, string>
     *     }>,
     *     declaration_rows: array<int, array{
     *         label: string,
     *         percent_of_formula: float,
     *         threshold_percent: float,
     *         exceeds_threshold: bool,
     *         included_in_inci: bool,
     *         suppressed_by_existing_label: bool,
     *         status_label: string,
     *         source_ingredients: array<int, string>,
     *         notes: string|null
     *     }>,
     *     final_labels: array<int, string>,
     *     final_label_text: string,
     *     warnings: array<int, string>
     * }
     */
    private function defaultListVariant(array $listVariants): array
    {
        foreach ($listVariants as $variant) {
            if ($variant['key'] === self::DEFAULT_LIST_VARIANT_KEY) {
                return $variant;
            }
        }

        return $listVariants[0] ?? [
            'key' => self::DEFAULT_LIST_VARIANT_KEY,
            'label' => 'Saponified + superfat oils',
            'note' => '',
            'ingredient_rows' => [],
            'declaration_rows' => [],
            'final_labels' => [],
            'final_label_text' => '',
            'warnings' => [],
        ];
    }

    /**
     * @param  array<int, array{
     *     label: string,
     *     weight: float,
     *     percent_of_formula: float,
     *     kind: string,
     *     source_ingredients: array<int, string>
     * }>  $ingredientRows
     * @param  array<int, array{
     *     label: string,
     *     percent_of_formula: float,
     *     threshold_percent: float,
     *     exceeds_threshold: bool,
     *     included_in_inci: bool,
     *     suppressed_by_existing_label: bool,
     *     status_label: string,
     *     source_ingredients: array<int, string>,
     *     notes: string|null
     * }>  $declarationRows
     * @return array<int, string>
     */
    private function finalLabels(array $ingredientRows, array $declarationRows): array
    {
        return [
            ...array_map(
                fn (array $row): string => $row['label'],
                $ingredientRows,
            ),
            ...array_map(
                fn (array $row): string => $row['label'],
                array_values(array_filter(
                    $declarationRows,
                    fn (array $row): bool => $row['included_in_inci'],
                )),
            ),
        ];
    }

    /**
     * @param  array<int, array{
     *     key: string,
     *     label: string,
     *     note: string,
     *     ingredient_rows: array<int, array{
     *         label: string,
     *         weight: float,
     *         percent_of_formula: float,
     *         kind: string,
     *         source_ingredients: array<int, string>
     *     }>,
     *     declaration_rows: array<int, array{
     *         label: string,
     *         percent_of_formula: float,
     *         threshold_percent: float,
     *         exceeds_threshold: bool,
     *         included_in_inci: bool,
     *         suppressed_by_existing_label: bool,
     *         status_label: string,
     *         source_ingredients: array<int, string>,
     *         notes: string|null
     *     }>,
     *     final_labels: array<int, string>,
     *     final_label_text: string,
     *     warnings: array<int, string>
     * }>  $listVariants
     * @return array<int, string>
     */
    private function variantWarnings(array $listVariants): array
    {
        $warnings = [];

        foreach ($listVariants as $variant) {
            $warnings = [...$warnings, ...$variant['warnings']];
        }

        return array_values(array_unique($warnings));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array{
     *     phase_key: string,
     *     weight: float,
     *     ingredient: Ingredient|null,
     *     ingredient_name: string
     * }>
     */
    private function resolveRowContexts(array $payload): array
    {
        $phaseItems = is_array($payload['phase_items'] ?? null) ? $payload['phase_items'] : [];

        $contexts = [];

        foreach (['saponified_oils', 'additives', 'fragrance'] as $phaseKey) {
            $rows = is_array($phaseItems[$phaseKey] ?? null) ? $phaseItems[$phaseKey] : [];

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $ingredientId = filled($row['ingredient_id'] ?? null)
                    ? (int) $row['ingredient_id']
                    : null;

                $ingredient = $ingredientId === null
                    ? null
                    : $this->ingredientById($ingredientId);

                $contexts = [
                    ...$contexts,
                    ...$this->expandedContexts(
                        $phaseKey,
                        $this->rowWeight($row, $payload),
                        $ingredient,
                        $ingredient?->display_name ?? trim((string) ($row['name'] ?? '')),
                    ),
                ];
            }
        }

        return array_values(array_filter(
            $contexts,
            fn (array $context): bool => $context['weight'] > 0,
        ));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function preloadIngredientsForPayload(array $payload): void
    {
        $phaseItems = is_array($payload['phase_items'] ?? null) ? $payload['phase_items'] : [];
        $ingredientIds = [];

        foreach (['saponified_oils', 'additives', 'fragrance'] as $phaseKey) {
            $rows = is_array($phaseItems[$phaseKey] ?? null) ? $phaseItems[$phaseKey] : [];

            foreach ($rows as $row) {
                if (! is_array($row) || ! filled($row['ingredient_id'] ?? null)) {
                    continue;
                }

                $ingredientIds[] = (int) $row['ingredient_id'];
            }
        }

        $this->preloadIngredientGraph($ingredientIds);
    }

    /**
     * @param  array<int, int>  $ingredientIds
     */
    private function preloadIngredientGraph(array $ingredientIds): void
    {
        $pendingIds = collect($ingredientIds)
            ->filter(fn (mixed $id): bool => is_int($id) && $id > 0)
            ->unique()
            ->values()
            ->all();

        while ($pendingIds !== []) {
            $idsToLoad = array_values(array_filter(
                $pendingIds,
                fn (int $id): bool => ! array_key_exists($id, $this->ingredientCache),
            ));

            if ($idsToLoad === []) {
                break;
            }

            $loadedIngredients = Ingredient::query()
                ->with($this->ingredientGraphRelations())
                ->whereKey($idsToLoad)
                ->get()
                ->keyBy('id');

            foreach ($idsToLoad as $ingredientId) {
                $this->ingredientCache[$ingredientId] = $loadedIngredients->get($ingredientId);
            }

            $pendingIds = $loadedIngredients
                ->flatMap(fn (Ingredient $ingredient) => $ingredient->components->pluck('component_ingredient_id'))
                ->filter(fn (mixed $id): bool => is_int($id) && $id > 0)
                ->unique()
                ->values()
                ->all();
        }
    }

    private function ingredientById(int $ingredientId): ?Ingredient
    {
        if (! array_key_exists($ingredientId, $this->ingredientCache)) {
            $this->ingredientCache[$ingredientId] = Ingredient::query()
                ->with($this->ingredientGraphRelations())
                ->find($ingredientId);
        }

        return $this->ingredientCache[$ingredientId];
    }

    /**
     * @return array<int, string>
     */
    private function ingredientGraphRelations(): array
    {
        return [
            'allergenEntries.allergen',
            'components',
        ];
    }

    /**
     * @return array<int, array{
     *     phase_key: string,
     *     weight: float,
     *     ingredient: Ingredient|null,
     *     ingredient_name: string
     * }>
     */
    private function expandedContexts(
        string $phaseKey,
        float $weight,
        ?Ingredient $ingredient,
        string $fallbackName,
        array $ancestry = [],
    ): array {
        if ($weight <= 0) {
            return [];
        }

        if (! $ingredient instanceof Ingredient) {
            return [[
                'phase_key' => $phaseKey,
                'weight' => $weight,
                'ingredient' => null,
                'ingredient_name' => $fallbackName,
            ]];
        }

        if (in_array($ingredient->id, $ancestry, true)) {
            return [[
                'phase_key' => $phaseKey,
                'weight' => $weight,
                'ingredient' => $ingredient,
                'ingredient_name' => $ingredient->display_name,
            ]];
        }

        $validComponents = $ingredient->components
            ->filter(fn ($component): bool => $component->component_ingredient_id !== null && (float) $component->percentage_in_parent > 0)
            ->values();

        if ($validComponents->isEmpty()) {
            return [[
                'phase_key' => $phaseKey,
                'weight' => $weight,
                'ingredient' => $ingredient,
                'ingredient_name' => $ingredient->display_name,
            ]];
        }

        $expandedContexts = [];
        $nextAncestry = [...$ancestry, $ingredient->id];

        foreach ($validComponents as $component) {
            $componentIngredient = $this->ingredientById((int) $component->component_ingredient_id);
            $componentWeight = $weight * (((float) $component->percentage_in_parent) / 100);

            $expandedContexts = [
                ...$expandedContexts,
                ...$this->expandedContexts(
                    $phaseKey,
                    $componentWeight,
                    $componentIngredient,
                    $componentIngredient?->display_name ?? $ingredient->display_name,
                    $nextAncestry,
                ),
            ];
        }

        return $expandedContexts;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, array{
     *     phase_key: string,
     *     weight: float,
     *     ingredient: Ingredient|null,
     *     ingredient_name: string
     * }>  $rowContexts
     * @param  array<string, mixed>|null  $soapCalculation
     * @return array{
     *     label: string,
     *     note: string,
     *     formula_weight: float,
     *     threshold_percent: float
     * }
     */
    private function basisState(array $payload, array $rowContexts, ?array $soapCalculation): array
    {
        $thresholdPercent = $this->thresholdPercent((string) ($payload['exposure_mode'] ?? 'rinse_off'));
        $phaseWeight = array_sum(array_map(
            fn (array $context): float => (float) $context['weight'],
            $rowContexts,
        ));
        $manufacturingMode = (string) ($payload['manufacturing_mode'] ?? 'saponify_in_formula');

        if ($manufacturingMode !== 'saponify_in_formula') {
            return [
                'label' => 'Current formula basis',
                'note' => 'Percentages use the current finished blend basis from the live formula rows.',
                'formula_weight' => round($phaseWeight, 5),
                'threshold_percent' => $thresholdPercent,
            ];
        }

        if (! is_array($soapCalculation)) {
            return [
                'label' => 'Current formula basis',
                'note' => 'Soap calculation is incomplete, so the preview currently uses only the live ingredient rows. Aqua and produced glycerine will appear once the soap calculation resolves.',
                'formula_weight' => round($phaseWeight, 5),
                'threshold_percent' => $thresholdPercent,
            ];
        }

        $waterWeight = (float) data_get($soapCalculation, 'lye.water.weight', 0);
        $naohWeight = (float) data_get($soapCalculation, 'lye.selected.naoh_weight', 0);
        $kohToWeigh = (float) data_get($soapCalculation, 'lye.selected.koh_to_weigh', 0);

        return [
            'label' => 'Current batch basis',
            'note' => 'Percentages use the live batch basis from oils, lye, water, and post-reaction additions. A dedicated dry-bar marketed basis still needs its own resolver.',
            'formula_weight' => round($phaseWeight + $waterWeight + $naohWeight + $kohToWeigh, 5),
            'threshold_percent' => $thresholdPercent,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, array{
     *     phase_key: string,
     *     weight: float,
     *     ingredient: Ingredient|null,
     *     ingredient_name: string
     * }>  $rowContexts
     * @param  array<string, mixed>|null  $soapCalculation
     * @return array{
     *     rows: array<int, array{
     *         label: string,
     *         weight: float,
     *         percent_of_formula: float,
     *         kind: string,
     *         source_ingredients: array<int, string>
     *     }>,
     *     label_keys: array<int, string>,
     *     fallback_warnings: array<int, string>
     * }
     */
    private function ingredientRowsState(
        array $payload,
        array $rowContexts,
        float $formulaWeight,
        ?array $soapCalculation,
        string $variantKey,
    ): array {
        $rowsByLabel = [];
        $fallbackWarnings = [];
        $thresholdPercent = $this->thresholdPercent((string) ($payload['exposure_mode'] ?? 'rinse_off'));

        foreach ($rowContexts as $context) {
            $rowContributions = $this->ingredientRowContributions(
                $context,
                $payload,
                $formulaWeight,
                $thresholdPercent,
                $soapCalculation,
                $variantKey,
            );

            foreach ($rowContributions as $contribution) {
                if ($contribution['label'] === null || $contribution['weight'] <= 0) {
                    continue;
                }

                if ($contribution['warning'] !== null) {
                    $fallbackWarnings[] = $contribution['warning'];
                }

                $labelKey = $this->normalizeLabel($contribution['label']);

                if (! array_key_exists($labelKey, $rowsByLabel)) {
                    $rowsByLabel[$labelKey] = [
                        'label' => $contribution['label'],
                        'weight' => 0.0,
                        'percent_of_formula' => 0.0,
                        'kind' => $contribution['kind'],
                        'source_ingredients' => [],
                    ];
                }

                $rowsByLabel[$labelKey]['weight'] += $contribution['weight'];
                $rowsByLabel[$labelKey]['kind'] = $this->mergeRowKind(
                    $rowsByLabel[$labelKey]['kind'],
                    $contribution['kind'],
                );
                $rowsByLabel[$labelKey]['source_ingredients'][] = $context['ingredient_name'];
            }
        }

        if (is_array($soapCalculation) && ($payload['manufacturing_mode'] ?? 'saponify_in_formula') === 'saponify_in_formula') {
            if ($variantKey === self::INCORPORATED_LIST_VARIANT_KEY) {
                $this->appendIncorporatedIngredientRows($rowsByLabel, $soapCalculation);
            } else {
                $this->appendSaponifiedIngredientRows($rowsByLabel, $soapCalculation);
            }
        }

        $rows = array_values(array_map(
            function (array $row) use ($formulaWeight): array {
                $sourceIngredients = array_values(array_unique(array_filter(
                    $row['source_ingredients'],
                    fn (mixed $value): bool => is_string($value) && $value !== '',
                )));

                return [
                    'label' => $row['label'],
                    'weight' => round((float) $row['weight'], 5),
                    'percent_of_formula' => $formulaWeight > 0
                        ? round((((float) $row['weight']) / $formulaWeight) * 100, 5)
                        : 0.0,
                    'kind' => $row['kind'],
                    'source_ingredients' => $sourceIngredients,
                ];
            },
            $rowsByLabel,
        ));

        usort($rows, function (array $left, array $right): int {
            if ($left['weight'] === $right['weight']) {
                return strcmp($left['label'], $right['label']);
            }

            return $right['weight'] <=> $left['weight'];
        });

        return [
            'rows' => $rows,
            'label_keys' => array_map(
                fn (array $row): string => $this->normalizeLabel($row['label']),
                $rows,
            ),
            'fallback_warnings' => array_values(array_unique($fallbackWarnings)),
        ];
    }

    /**
     * @param  array{
     *     phase_key: string,
     *     weight: float,
     *     ingredient: Ingredient|null,
     *     ingredient_name: string
     * }  $context
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $soapCalculation
     * @return array<int, array{label: string|null, weight: float, kind: string, warning: string|null}>
     */
    private function ingredientRowContributions(
        array $context,
        array $payload,
        float $formulaWeight,
        float $thresholdPercent,
        ?array $soapCalculation,
        string $variantKey,
    ): array {
        if ($variantKey === self::INCORPORATED_LIST_VARIANT_KEY) {
            $labelState = $this->incorporatedIngredientLabel($context);

            return [[
                'label' => $labelState['label'],
                'weight' => $context['weight'],
                'kind' => $labelState['kind'],
                'warning' => $labelState['warning'],
            ]];
        }

        if (
            $context['phase_key'] === 'saponified_oils'
            && ($payload['manufacturing_mode'] ?? 'saponify_in_formula') === 'saponify_in_formula'
        ) {
            $superfatWeight = $context['weight'] * $this->superfatRatio($payload, $soapCalculation);
            $saponifiedWeight = max(0.0, $context['weight'] - $superfatWeight);
            $contributions = [];

            if ($saponifiedWeight > 0) {
                $labelState = $this->ingredientListLabel(
                    $context,
                    $payload,
                    $formulaWeight,
                    $thresholdPercent,
                );

                $contributions[] = [
                    'label' => $labelState['label'],
                    'weight' => $saponifiedWeight,
                    'kind' => $labelState['kind'],
                    'warning' => $labelState['warning'],
                ];
            }

            if ($superfatWeight > 0) {
                $labelState = $this->incorporatedIngredientLabel($context);

                $contributions[] = [
                    'label' => $labelState['label'],
                    'weight' => $superfatWeight,
                    'kind' => 'theoretical_superfat',
                    'warning' => $labelState['warning'],
                ];
            }

            return $contributions;
        }

        $labelState = $this->ingredientListLabel(
            $context,
            $payload,
            $formulaWeight,
            $thresholdPercent,
        );

        return [[
            'label' => $labelState['label'],
            'weight' => $context['weight'],
            'kind' => $labelState['kind'],
            'warning' => $labelState['warning'],
        ]];
    }

    /**
     * @param  array<int, array{
     *     phase_key: string,
     *     weight: float,
     *     ingredient: Ingredient|null,
     *     ingredient_name: string
     * }>  $rowContexts
     * @param  array<int, string>  $ingredientLabelKeys
     * @return array<int, array{
     *     label: string,
     *     percent_of_formula: float,
     *     threshold_percent: float,
     *     exceeds_threshold: bool,
     *     included_in_inci: bool,
     *     suppressed_by_existing_label: bool,
     *     status_label: string,
     *     source_ingredients: array<int, string>,
     *     notes: string|null
     * }>
     */
    private function declarationRows(
        array $rowContexts,
        array $ingredientLabelKeys,
        float $formulaWeight,
        float $thresholdPercent,
    ): array {
        $rowsByLabel = [];

        foreach ($rowContexts as $context) {
            $ingredient = $context['ingredient'];

            if (! $ingredient instanceof Ingredient || $formulaWeight <= 0) {
                continue;
            }

            $ingredientPercentOfFormula = ($context['weight'] / $formulaWeight) * 100;

            foreach ($ingredient->allergenEntries as $entry) {
                $label = $this->normalizePrintedLabel($entry->allergen?->inci_name);

                if ($label === null) {
                    continue;
                }

                $labelKey = $this->normalizeLabel($label);

                if (! array_key_exists($labelKey, $rowsByLabel)) {
                    $rowsByLabel[$labelKey] = [
                        'label' => $label,
                        'percent_of_formula' => 0.0,
                        'source_ingredients' => [],
                    ];
                }

                $rowsByLabel[$labelKey]['percent_of_formula'] += $ingredientPercentOfFormula * (((float) $entry->concentration_percent) / 100);
                $rowsByLabel[$labelKey]['source_ingredients'][] = $context['ingredient_name'];
            }
        }

        $rows = array_values(array_map(
            function (array $row) use ($ingredientLabelKeys, $thresholdPercent): array {
                $percentOfFormula = round((float) $row['percent_of_formula'], 5);
                $suppressedByExistingLabel = $percentOfFormula >= $thresholdPercent
                    && in_array($this->normalizeLabel($row['label']), $ingredientLabelKeys, true);
                $includedInInci = $percentOfFormula >= $thresholdPercent && ! $suppressedByExistingLabel;

                return [
                    'label' => $row['label'],
                    'percent_of_formula' => $percentOfFormula,
                    'threshold_percent' => $thresholdPercent,
                    'exceeds_threshold' => $percentOfFormula >= $thresholdPercent,
                    'included_in_inci' => $includedInInci,
                    'suppressed_by_existing_label' => $suppressedByExistingLabel,
                    'status_label' => $this->declarationStatusLabel(
                        $percentOfFormula,
                        $thresholdPercent,
                        $suppressedByExistingLabel,
                    ),
                    'source_ingredients' => array_values(array_unique(array_filter(
                        $row['source_ingredients'],
                        fn (mixed $value): bool => is_string($value) && $value !== '',
                    ))),
                    'notes' => $this->declarationNotes(
                        $percentOfFormula,
                        $thresholdPercent,
                        $suppressedByExistingLabel,
                    ),
                ];
            },
            $rowsByLabel,
        ));

        usort($rows, function (array $left, array $right): int {
            if ($left['exceeds_threshold'] === $right['exceeds_threshold']) {
                if ($left['percent_of_formula'] === $right['percent_of_formula']) {
                    return strcmp($left['label'], $right['label']);
                }

                return $right['percent_of_formula'] <=> $left['percent_of_formula'];
            }

            return $left['exceeds_threshold'] ? -1 : 1;
        });

        return $rows;
    }

    /**
     * @param  array{
     *     phase_key: string,
     *     weight: float,
     *     ingredient: Ingredient|null,
     *     ingredient_name: string
     * }  $context
     * @param  array<string, mixed>  $payload
     * @return array{label: string|null, kind: string, warning: string|null}
     */
    private function ingredientListLabel(
        array $context,
        array $payload,
        float $formulaWeight,
        float $thresholdPercent,
    ): array {
        $ingredient = $context['ingredient'];
        $ingredientName = $context['ingredient_name'] !== '' ? $context['ingredient_name'] : 'Unnamed ingredient';

        if (! $ingredient instanceof Ingredient) {
            return [
                'label' => null,
                'kind' => 'ingredient',
                'warning' => null,
            ];
        }

        if (
            $context['phase_key'] === 'saponified_oils'
            && ($payload['manufacturing_mode'] ?? 'saponify_in_formula') === 'saponify_in_formula'
        ) {
            $soapLabel = $this->soapLabel($ingredient, (string) ($payload['lye_type'] ?? 'naoh'));

            if ($soapLabel !== null) {
                return [
                    'label' => $soapLabel,
                    'kind' => 'saponified_oil',
                    'warning' => null,
                ];
            }

            $fallbackLabel = $this->normalizePrintedLabel($ingredient->inci_name ?? $ingredient->display_name);

            return [
                'label' => $fallbackLabel,
                'kind' => 'saponified_oil',
                'warning' => $fallbackLabel === null
                    ? null
                    : "{$ingredientName} is missing soap-specific INCI output, so the preview is falling back to its regular ingredient label.",
            ];
        }

        $label = $this->normalizePrintedLabel($ingredient->inci_name);

        if ($label !== null) {
            if ($this->isParfumLabel($label)) {
                return [
                    'label' => 'PARFUM',
                    'kind' => 'parfum',
                    'warning' => null,
                ];
            }

            $replacementLabel = $this->declarationReplacementLabel(
                $context,
                $label,
                $formulaWeight,
                $thresholdPercent,
            );

            return [
                'label' => $replacementLabel ?? $label,
                'kind' => $replacementLabel !== null
                    ? 'declaration_alias'
                    : 'ingredient',
                'warning' => null,
            ];
        }

        $fallbackLabel = $this->normalizePrintedLabel($ingredient->display_name);

        return [
            'label' => $fallbackLabel,
            'kind' => 'ingredient',
            'warning' => $fallbackLabel === null
                ? null
                : "{$ingredientName} is missing an INCI name, so the preview is falling back to its display name.",
        ];
    }

    /**
     * @param  array{
     *     phase_key: string,
     *     weight: float,
     *     ingredient: Ingredient|null,
     *     ingredient_name: string
     * }  $context
     * @return array{label: string|null, kind: string, warning: string|null}
     */
    private function incorporatedIngredientLabel(array $context): array
    {
        $ingredient = $context['ingredient'];
        $ingredientName = $context['ingredient_name'] !== '' ? $context['ingredient_name'] : 'Unnamed ingredient';

        if (! $ingredient instanceof Ingredient) {
            return [
                'label' => null,
                'kind' => 'ingredient',
                'warning' => null,
            ];
        }

        $label = $this->normalizePrintedLabel($ingredient->inci_name);

        if ($label !== null) {
            return [
                'label' => $this->isParfumLabel($label) ? 'PARFUM' : $label,
                'kind' => 'ingredient',
                'warning' => null,
            ];
        }

        $fallbackLabel = $this->normalizePrintedLabel($ingredient->display_name);

        return [
            'label' => $fallbackLabel,
            'kind' => 'ingredient',
            'warning' => $fallbackLabel === null
                ? null
                : "{$ingredientName} is missing an INCI name, so the preview is falling back to its display name.",
        ];
    }

    /**
     * @param  array{
     *     phase_key: string,
     *     weight: float,
     *     ingredient: Ingredient|null,
     *     ingredient_name: string
     * }  $context
     */
    private function declarationReplacementLabel(
        array $context,
        string $defaultLabel,
        float $formulaWeight,
        float $thresholdPercent,
    ): ?string {
        $ingredient = $context['ingredient'];

        if (
            ! $ingredient instanceof Ingredient
            || $context['phase_key'] !== 'fragrance'
            || $formulaWeight <= 0
        ) {
            return null;
        }

        $ingredientPercentOfFormula = ($context['weight'] / $formulaWeight) * 100;

        foreach ($ingredient->allergenEntries->sortBy(fn ($entry) => $entry->allergen?->inci_name) as $entry) {
            $label = $this->normalizePrintedLabel($entry->allergen?->inci_name);
            $concentrationPercent = (float) $entry->concentration_percent;

            if (
                $label === null
                || $label === $defaultLabel
                || abs($concentrationPercent - 100) > 0.00001
            ) {
                continue;
            }

            $declarationPercentOfFormula = $ingredientPercentOfFormula * ($concentrationPercent / 100);

            if ($declarationPercentOfFormula < $thresholdPercent) {
                continue;
            }

            return $label;
        }

        return null;
    }

    private function soapLabel(Ingredient $ingredient, string $lyeType): ?string
    {
        $naohLabel = $this->normalizePrintedLabel($ingredient->soap_inci_naoh_name);
        $kohLabel = $this->normalizePrintedLabel($ingredient->soap_inci_koh_name);

        return match ($lyeType) {
            'koh' => $kohLabel ?? $naohLabel,
            'dual' => $this->combineSoapLabels($naohLabel, $kohLabel),
            default => $naohLabel ?? $kohLabel,
        };
    }

    private function combineSoapLabels(?string $naohLabel, ?string $kohLabel): ?string
    {
        $labels = array_values(array_unique(array_filter([$naohLabel, $kohLabel])));

        if ($labels === []) {
            return null;
        }

        return implode(', ', $labels);
    }

    private function appendStandaloneIngredientRow(
        array &$rowsByLabel,
        string $label,
        float $weight,
        string $kind,
        string $sourceIngredient,
    ): void {
        if ($weight <= 0) {
            return;
        }

        $labelKey = $this->normalizeLabel($label);

        if (! array_key_exists($labelKey, $rowsByLabel)) {
            $rowsByLabel[$labelKey] = [
                'label' => $label,
                'weight' => 0.0,
                'percent_of_formula' => 0.0,
                'kind' => $kind,
                'source_ingredients' => [],
            ];
        }

        $rowsByLabel[$labelKey]['weight'] += $weight;
        $rowsByLabel[$labelKey]['source_ingredients'][] = $sourceIngredient;
    }

    private function mergeRowKind(string $existingKind, string $incomingKind): string
    {
        if ($existingKind === $incomingKind) {
            return $existingKind;
        }

        $kinds = array_values(array_unique([$existingKind, $incomingKind]));

        sort($kinds);

        return match ($kinds) {
            ['saponified_oil', 'theoretical_superfat'] => 'mixed_saponified_superfat',
            default => $existingKind,
        };
    }

    /**
     * @param  array<string, array{
     *     label: string,
     *     weight: float,
     *     percent_of_formula: float,
     *     kind: string,
     *     source_ingredients: array<int, string>
     * }>  $rowsByLabel
     * @param  array<string, mixed>  $soapCalculation
     */
    private function appendSaponifiedIngredientRows(array &$rowsByLabel, array $soapCalculation): void
    {
        $this->appendStandaloneIngredientRow(
            $rowsByLabel,
            'AQUA',
            (float) data_get($soapCalculation, 'lye.water.weight', 0),
            'water',
            'Lye water',
        );
        $this->appendStandaloneIngredientRow(
            $rowsByLabel,
            'GLYCERIN',
            (float) data_get($soapCalculation, 'lye.selected.glycerine_weight', 0),
            'derived',
            'Saponification',
        );
    }

    /**
     * @param  array<string, array{
     *     label: string,
     *     weight: float,
     *     percent_of_formula: float,
     *     kind: string,
     *     source_ingredients: array<int, string>
     * }>  $rowsByLabel
     * @param  array<string, mixed>  $soapCalculation
     */
    private function appendIncorporatedIngredientRows(array &$rowsByLabel, array $soapCalculation): void
    {
        $this->appendStandaloneIngredientRow(
            $rowsByLabel,
            'AQUA',
            (float) data_get($soapCalculation, 'lye.water.weight', 0),
            'water',
            'Lye water',
        );
        $this->appendStandaloneIngredientRow(
            $rowsByLabel,
            'SODIUM HYDROXIDE',
            (float) data_get($soapCalculation, 'lye.selected.naoh_weight', 0),
            'lye',
            'Lye',
        );
        $this->appendStandaloneIngredientRow(
            $rowsByLabel,
            'POTASSIUM HYDROXIDE',
            (float) data_get($soapCalculation, 'lye.selected.koh_to_weigh', 0),
            'lye',
            'Lye',
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $soapCalculation
     */
    private function superfatRatio(array $payload, ?array $soapCalculation): float
    {
        $superfatPercentage = is_array($soapCalculation)
            ? (float) data_get($soapCalculation, 'lye.superfat_percentage', $payload['superfat'] ?? 0)
            : (float) ($payload['superfat'] ?? 0);

        return max(0.0, min(0.99999, $superfatPercentage / 100));
    }

    private function declarationStatusLabel(float $percentOfFormula, float $thresholdPercent, bool $suppressedByExistingLabel): string
    {
        if ($percentOfFormula < $thresholdPercent) {
            return 'Below threshold';
        }

        return $suppressedByExistingLabel
            ? 'Already named'
            : 'Added to INCI';
    }

    private function declarationNotes(float $percentOfFormula, float $thresholdPercent, bool $suppressedByExistingLabel): ?string
    {
        if ($percentOfFormula < $thresholdPercent) {
            return 'Present in the formula but currently below the declaration threshold.';
        }

        if ($suppressedByExistingLabel) {
            return 'The ingredient list already contains the same declaration name, so it is not appended a second time.';
        }

        return 'Above the current declaration threshold and appended to the generated ingredient list.';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, array{
     *     phase_key: string,
     *     weight: float,
     *     ingredient: Ingredient|null,
     *     ingredient_name: string
     * }>  $rowContexts
     * @param  array<int, string>  $fallbackWarnings
     * @param  array<string, mixed>|null  $soapCalculation
     * @return array<int, string>
     */
    private function warnings(
        array $payload,
        array $rowContexts,
        array $fallbackWarnings,
        ?array $soapCalculation,
    ): array {
        $warnings = $fallbackWarnings;

        if (
            ($payload['manufacturing_mode'] ?? 'saponify_in_formula') === 'saponify_in_formula'
            && ! is_array($soapCalculation)
            && array_any($rowContexts, fn (array $context): bool => $context['phase_key'] === 'saponified_oils')
        ) {
            $warnings[] = 'Soap calculation is not complete yet, so Aqua, lye inputs, and produced glycerine are still missing from these previews.';
        }

        foreach ($rowContexts as $context) {
            $ingredient = $context['ingredient'];

            if (
                $context['phase_key'] !== 'fragrance'
                || ! $ingredient instanceof Ingredient
                || $ingredient->allergenEntries->isNotEmpty()
            ) {
                continue;
            }

            $warnings[] = "{$context['ingredient_name']} has no allergen composition recorded yet, so declaration screening may be incomplete.";
        }

        return array_values(array_unique($warnings));
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $payload
     */
    private function rowWeight(array $row, array $payload): float
    {
        $explicitWeight = (float) ($row['weight'] ?? 0);

        if ($explicitWeight > 0) {
            return $explicitWeight;
        }

        $oilWeight = (float) ($payload['oil_weight'] ?? 0);
        $percentage = (float) ($row['percentage'] ?? 0);

        if ($oilWeight <= 0 || $percentage <= 0) {
            return 0;
        }

        return $oilWeight * ($percentage / 100);
    }

    private function thresholdPercent(string $exposureMode): float
    {
        return $exposureMode === 'leave_on' ? 0.001 : 0.01;
    }

    private function normalizePrintedLabel(?string $value): ?string
    {
        $normalized = trim((string) $value);

        if ($normalized === '') {
            return null;
        }

        return Str::upper(preg_replace('/\s+/', ' ', $normalized) ?? $normalized);
    }

    private function normalizeLabel(string $value): string
    {
        return Str::upper(preg_replace('/\s+/', ' ', trim($value)) ?? trim($value));
    }

    private function isParfumLabel(string $value): bool
    {
        return in_array($this->normalizeLabel($value), ['PARFUM', 'FRAGRANCE', 'PERFUME'], true);
    }
}
