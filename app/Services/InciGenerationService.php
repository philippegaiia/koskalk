<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\IngredientAllergenEntry;
use App\Models\RegulatoryRegime;
use App\Models\RegulatoryRegimeAllergen;
use Illuminate\Support\Str;

class InciGenerationService
{
    private const DEFAULT_LIST_VARIANT_KEY = 'saponified_with_superfat';

    private const INCORPORATED_LIST_VARIANT_KEY = 'incorporated_ingredients';

    public function __construct(
        private readonly IngredientFormulaContextResolver $ingredientFormulaContextResolver,
    ) {}

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
     *         source_ingredients: array<int, string>,
     *         source_is_user_owned: array<int, bool>
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
     *         source_is_user_owned: array<int, bool>,
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
     *             source_ingredients: array<int, string>,
     *             source_is_user_owned: array<int, bool>
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
     *             source_is_user_owned: array<int, bool>,
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
        $rowContexts = $this->ingredientFormulaContextResolver->resolve($payload, [
            'allergenEntries.allergen',
        ]);
        $declarationRuleState = $this->declarationRuleState($payload);
        $basis = $this->basisState(
            $payload,
            $rowContexts,
            $soapCalculation,
            $declarationRuleState['default_threshold_percent'],
        );
        $listVariants = $this->listVariants($payload, $rowContexts, $basis, $soapCalculation, $declarationRuleState);
        $defaultVariant = $this->defaultListVariant($listVariants);
        $plainLanguageList = $this->plainLanguageList($payload, $rowContexts, $soapCalculation);
        $ingredientListBasisHash = $this->ingredientListBasisHash($payload);
        $finalIngredientList = $this->finalIngredientListState(
            $payload,
            $ingredientListBasisHash,
            $defaultVariant['final_label_text'],
        );
        $plainLanguageList = [
            ...$plainLanguageList,
            ...$this->finalPlainLanguageListState($payload, $ingredientListBasisHash, $plainLanguageList['final_label_text']),
        ];

        return [
            'basis' => $basis,
            'ingredient_list_basis_hash' => $ingredientListBasisHash,
            'ingredient_rows' => $defaultVariant['ingredient_rows'],
            'declaration_rows' => $defaultVariant['declaration_rows'],
            'list_variants' => $listVariants,
            'default_variant_key' => $defaultVariant['key'],
            'final_labels' => $defaultVariant['final_labels'],
            'final_label_text' => $defaultVariant['final_label_text'],
            'final_ingredient_list' => $finalIngredientList,
            'plain_language_list' => $plainLanguageList,
            'print_ingredient_list_text' => $finalIngredientList['final_text'],
            'print_plain_ingredient_list_text' => $plainLanguageList['final_text'],
            'warnings' => $this->warnings($payload, $rowContexts, $this->variantWarnings($listVariants), $soapCalculation),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     uses_regime_rules: bool,
     *     default_threshold_percent: float,
     *     rules_by_allergen_id: array<int, array{label: string, threshold_percent: float, threshold_operator: string}>,
     *     regime_code: string,
     *     regime_label: string
     * }
     */
    private function declarationRuleState(array $payload): array
    {
        $exposureMode = (string) ($payload['exposure_mode'] ?? 'rinse_off');
        $regimeCode = Str::lower(trim((string) ($payload['regulatory_regime'] ?? 'eu')));
        $regimeCode = $regimeCode !== '' ? $regimeCode : 'eu';
        $defaultThresholdPercent = $this->thresholdPercent($exposureMode);
        $today = now()->toDateString();

        $regime = RegulatoryRegime::query()
            ->where('code', $regimeCode)
            ->whereIn('status', ['active', 'preview'])
            ->where(function ($query) use ($today): void {
                $query->whereNull('effective_from')
                    ->orWhereDate('effective_from', '<=', $today);
            })
            ->where(function ($query) use ($today): void {
                $query->whereNull('effective_until')
                    ->orWhereDate('effective_until', '>=', $today);
            })
            ->with(['allergenRules' => function ($query) use ($today): void {
                $query
                    ->where('is_active', true)
                    ->where(function ($query) use ($today): void {
                        $query->whereNull('effective_from')
                            ->orWhereDate('effective_from', '<=', $today);
                    })
                    ->where(function ($query) use ($today): void {
                        $query->whereNull('effective_until')
                            ->orWhereDate('effective_until', '>=', $today);
                    })
                    ->with('allergen');
            }])
            ->first();

        if (! $regime instanceof RegulatoryRegime) {
            return [
                'uses_regime_rules' => false,
                'default_threshold_percent' => $defaultThresholdPercent,
                'rules_by_allergen_id' => [],
                'regime_code' => $regimeCode,
                'regime_label' => Str::upper($regimeCode),
            ];
        }

        $rulesByAllergenId = [];

        foreach ($regime->allergenRules as $rule) {
            if (! $rule instanceof RegulatoryRegimeAllergen) {
                continue;
            }

            $allergenId = (int) $rule->allergen_id;
            $label = $this->normalizePrintedLabel(
                $rule->declaration_label
                    ?? $rule->group_label
                    ?? $rule->allergen?->inci_name,
            );

            if ($allergenId <= 0 || $label === null) {
                continue;
            }

            $rulesByAllergenId[$allergenId] = [
                'label' => $label,
                'threshold_percent' => $this->ruleThresholdPercent($rule, $exposureMode),
                'threshold_operator' => filled($rule->threshold_operator)
                    ? (string) $rule->threshold_operator
                    : 'greater_than_or_equal',
            ];
        }

        return [
            'uses_regime_rules' => true,
            'default_threshold_percent' => $defaultThresholdPercent,
            'rules_by_allergen_id' => $rulesByAllergenId,
            'regime_code' => $regime->code,
            'regime_label' => $regime->name,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, array{
     *     phase_key: string,
     *     weight: float,
     *     ingredient: Ingredient|null,
     *     ingredient_name: string,
     *     is_user_owned: bool
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
     *         source_ingredients: array<int, string>,
     *         source_is_user_owned: array<int, bool>
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
     *         source_is_user_owned: array<int, bool>,
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
        array $declarationRuleState,
    ): array {
        if (($payload['manufacturing_mode'] ?? 'saponify_in_formula') === 'blend_only') {
            return $this->buildListVariants([
                [
                    'key' => self::INCORPORATED_LIST_VARIANT_KEY,
                    'label' => 'Ingredient list',
                    'note' => 'Uses the ingredients as entered on the full formula basis.',
                ],
            ], $payload, $rowContexts, $basis, $soapCalculation, $declarationRuleState);
        }

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

        return $this->buildListVariants($variantDefinitions, $payload, $rowContexts, $basis, $soapCalculation, $declarationRuleState);
    }

    /**
     * @param  array<int, array{key: string, label: string, note: string}>  $variantDefinitions
     * @param  array<string, mixed>  $payload
     * @param  array<int, array{
     *     phase_key: string,
     *     weight: float,
     *     ingredient: Ingredient|null,
     *     ingredient_name: string,
     *     is_user_owned: bool
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
     *         source_ingredients: array<int, string>,
     *         source_is_user_owned: array<int, bool>
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
     *         source_is_user_owned: array<int, bool>,
     *         notes: string|null
     *     }>,
     *     final_labels: array<int, string>,
     *     final_label_text: string,
     *     warnings: array<int, string>
     * }>
     */
    private function buildListVariants(
        array $variantDefinitions,
        array $payload,
        array $rowContexts,
        array $basis,
        ?array $soapCalculation,
        array $declarationRuleState,
    ): array {
        return array_map(function (array $definition) use ($payload, $rowContexts, $basis, $soapCalculation, $declarationRuleState): array {
            $ingredientRowsState = $this->ingredientRowsState(
                $payload,
                $rowContexts,
                $basis['formula_weight'],
                $soapCalculation,
                $definition['key'],
                $declarationRuleState,
            );
            $declarationRows = $this->declarationRows(
                $rowContexts,
                $ingredientRowsState['label_keys'],
                $basis['formula_weight'],
                $declarationRuleState,
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
     *         source_ingredients: array<int, string>,
     *         source_is_user_owned: array<int, bool>
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
     *         source_is_user_owned: array<int, bool>,
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
     *         source_ingredients: array<int, string>,
     *         source_is_user_owned: array<int, bool>
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
     *         source_is_user_owned: array<int, bool>,
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
     *     source_ingredients: array<int, string>,
     *     source_is_user_owned: array<int, bool>
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
     *     source_is_user_owned: array<int, bool>,
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
     * @param  array<string, mixed>  $payload
     * @param  array<int, array{
     *     phase_key: string,
     *     weight: float,
     *     ingredient: Ingredient|null,
     *     ingredient_name: string,
     *     is_user_owned: bool
     * }>  $rowContexts
     * @param  array<string, mixed>|null  $soapCalculation
     * @return array{label: string, note: string, final_labels: array<int, string>, final_label_text: string}
     */
    private function plainLanguageList(array $payload, array $rowContexts, ?array $soapCalculation): array
    {
        if (($payload['manufacturing_mode'] ?? 'saponify_in_formula') === 'saponify_in_formula') {
            return $this->soapPlainLanguageList($rowContexts, $soapCalculation);
        }

        $rowsByLabel = [];

        foreach ($rowContexts as $context) {
            $this->appendPlainLanguageRow(
                $rowsByLabel,
                $this->plainIngredientLabel($context, false),
                (float) $context['weight'],
            );
        }

        $labels = $this->sortedPlainLanguageLabels($rowsByLabel);

        return [
            'label' => 'Plain-language ingredient list',
            'note' => 'Uses ingredient display names in decreasing order on the full formula basis.',
            'final_labels' => $labels,
            'final_label_text' => implode(', ', $labels),
        ];
    }

    /**
     * @param  array<int, array{
     *     phase_key: string,
     *     weight: float,
     *     ingredient: Ingredient|null,
     *     ingredient_name: string,
     *     is_user_owned: bool
     * }>  $rowContexts
     * @param  array<string, mixed>|null  $soapCalculation
     * @return array{label: string, note: string, final_labels: array<int, string>, final_label_text: string}
     */
    private function soapPlainLanguageList(array $rowContexts, ?array $soapCalculation): array
    {
        $oilNamesByKey = [];
        $restRowsByLabel = [];

        foreach ($rowContexts as $context) {
            if ($context['phase_key'] === 'saponified_oils') {
                $oilLabel = $this->plainSaponifiedOilLabel($context);

                if ($oilLabel !== null) {
                    $oilNamesByKey[$this->normalizeLabel($oilLabel)] = $oilLabel;
                }

                continue;
            }

            $this->appendPlainLanguageRow(
                $restRowsByLabel,
                $this->plainIngredientLabel($context, true),
                (float) $context['weight'],
            );
        }

        if (is_array($soapCalculation)) {
            $this->appendPlainLanguageRow(
                $restRowsByLabel,
                'Water',
                (float) data_get($soapCalculation, 'lye.water.weight', 0),
            );
            $this->appendPlainLanguageRow(
                $restRowsByLabel,
                'Glycerin',
                (float) data_get($soapCalculation, 'lye.selected.glycerine_weight', 0),
            );
        }

        $labels = [];
        $oilNames = array_values($oilNamesByKey);

        if ($oilNames !== []) {
            $labels[] = 'Saponified Oils of ('.implode(', ', $oilNames).')';
        }

        $labels = [
            ...$labels,
            ...$this->sortedPlainLanguageLabels($restRowsByLabel),
        ];

        return [
            'label' => 'Plain-language ingredient list',
            'note' => 'Starts with the saponified oil basis, then lists remaining materials in decreasing order.',
            'final_labels' => $labels,
            'final_label_text' => implode(', ', $labels),
        ];
    }

    /**
     * @param  array<string, array{label: string, weight: float}>  $rowsByLabel
     */
    private function appendPlainLanguageRow(array &$rowsByLabel, ?string $label, float $weight): void
    {
        if ($label === null || $weight <= 0) {
            return;
        }

        $labelKey = $this->normalizeLabel($label);

        if (! array_key_exists($labelKey, $rowsByLabel)) {
            $rowsByLabel[$labelKey] = [
                'label' => $label,
                'weight' => 0.0,
            ];
        }

        $rowsByLabel[$labelKey]['weight'] += $weight;
    }

    /**
     * @param  array<string, array{label: string, weight: float}>  $rowsByLabel
     * @return array<int, string>
     */
    private function sortedPlainLanguageLabels(array $rowsByLabel): array
    {
        $rows = array_values($rowsByLabel);

        usort($rows, function (array $left, array $right): int {
            if ($left['weight'] === $right['weight']) {
                return strcmp($left['label'], $right['label']);
            }

            return $right['weight'] <=> $left['weight'];
        });

        return array_map(
            fn (array $row): string => $row['label'],
            $rows,
        );
    }

    /**
     * @param  array{
     *     phase_key: string,
     *     weight: float,
     *     ingredient: Ingredient|null,
     *     ingredient_name: string,
     *     is_user_owned: bool
     * }  $context
     */
    private function plainIngredientLabel(array $context, bool $titleCase): ?string
    {
        $label = $context['ingredient'] instanceof Ingredient
            ? $context['ingredient']->display_name
            : $context['ingredient_name'];

        $label = Str::squish((string) $label);

        if ($label === '') {
            return null;
        }

        return $titleCase ? Str::title($label) : $label;
    }

    /**
     * @param  array{
     *     phase_key: string,
     *     weight: float,
     *     ingredient: Ingredient|null,
     *     ingredient_name: string,
     *     is_user_owned: bool
     * }  $context
     */
    private function plainSaponifiedOilLabel(array $context): ?string
    {
        $label = $this->plainIngredientLabel($context, true);

        if ($label === null) {
            return null;
        }

        // US-style soap common-name lists group the commodity suffix once in "Saponified Oils of (...)".
        $label = preg_replace('/\s+(Oil|Oils|Butter|Butters)\z/i', '', $label) ?? $label;

        return trim($label) !== '' ? trim($label) : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     text: string|null,
     *     basis_hash: string|null,
     *     current_basis_hash: string,
     *     is_present: bool,
     *     is_outdated: bool,
     *     generated_text: string,
     *     final_text: string
     * }
     */
    private function finalIngredientListState(array $payload, string $basisHash, string $generatedText): array
    {
        return $this->finalListState(
            $payload['final_ingredient_list'] ?? null,
            $payload['final_ingredient_list_basis_hash'] ?? null,
            $basisHash,
            $generatedText,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     text: string|null,
     *     basis_hash: string|null,
     *     current_basis_hash: string,
     *     is_present: bool,
     *     is_outdated: bool,
     *     generated_text: string,
     *     final_text: string
     * }
     */
    private function finalPlainLanguageListState(array $payload, string $basisHash, string $generatedText): array
    {
        return $this->finalListState(
            $payload['final_plain_ingredient_list'] ?? null,
            $payload['final_plain_ingredient_list_basis_hash'] ?? null,
            $basisHash,
            $generatedText,
        );
    }

    /**
     * @return array{
     *     text: string|null,
     *     basis_hash: string|null,
     *     current_basis_hash: string,
     *     is_present: bool,
     *     is_outdated: bool,
     *     generated_text: string,
     *     final_text: string
     * }
     */
    private function finalListState(mixed $text, mixed $basisHash, string $currentBasisHash, string $generatedText): array
    {
        $finalText = trim((string) $text);
        $finalText = $finalText !== '' ? $finalText : null;
        $storedBasisHash = Str::squish((string) $basisHash);
        $storedBasisHash = $storedBasisHash !== '' ? $storedBasisHash : null;

        return [
            'text' => $finalText,
            'basis_hash' => $storedBasisHash,
            'current_basis_hash' => $currentBasisHash,
            'is_present' => $finalText !== null,
            'is_outdated' => $finalText !== null
                && $storedBasisHash !== null
                && $storedBasisHash !== $currentBasisHash,
            'generated_text' => $generatedText,
            'final_text' => $finalText ?? $generatedText,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function ingredientListBasisHash(array $payload): string
    {
        $basisPayload = $payload;

        foreach ([
            'final_ingredient_list',
            'final_ingredient_list_basis_hash',
            'final_plain_ingredient_list',
            'final_plain_ingredient_list_basis_hash',
        ] as $key) {
            unset($basisPayload[$key]);
        }

        $basisPayload = $this->sortArrayRecursively($basisPayload);

        return hash('sha256', json_encode($basisPayload, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function sortArrayRecursively(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortArrayRecursively($item);
            }
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
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
     *         source_ingredients: array<int, string>,
     *         source_is_user_owned: array<int, bool>
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
     *         source_is_user_owned: array<int, bool>,
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
     * @param  array<int, array{
     *     phase_key: string,
     *     weight: float,
     *     ingredient: Ingredient|null,
     *     ingredient_name: string,
     *     is_user_owned: bool
     * }>  $rowContexts
     * @param  array<string, mixed>|null  $soapCalculation
     * @return array{
     *     label: string,
     *     note: string,
     *     formula_weight: float,
     *     threshold_percent: float
     * }
     */
    private function basisState(array $payload, array $rowContexts, ?array $soapCalculation, float $thresholdPercent): array
    {
        $phaseWeight = array_sum(array_map(
            fn (array $context): float => (float) $context['weight'],
            $rowContexts,
        ));
        $manufacturingMode = (string) ($payload['manufacturing_mode'] ?? 'saponify_in_formula');

        if ($manufacturingMode !== 'saponify_in_formula') {
            $declaredFormulaWeight = (float) ($payload['oil_weight'] ?? 0);

            return [
                'label' => 'Current formula basis',
                'note' => 'Percentages use the current finished blend basis from the total batch weight.',
                'formula_weight' => round($declaredFormulaWeight > 0 ? $declaredFormulaWeight : $phaseWeight, 5),
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
     *     ingredient_name: string,
     *     is_user_owned: bool
     * }>  $rowContexts
     * @param  array<string, mixed>|null  $soapCalculation
     * @return array{
     *     rows: array<int, array{
     *         label: string,
     *         weight: float,
     *         percent_of_formula: float,
     *         kind: string,
     *         source_ingredients: array<int, string>,
     *         source_is_user_owned: array<int, bool>
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
        array $declarationRuleState,
    ): array {
        $rowsByLabel = [];
        $fallbackWarnings = [];

        foreach ($rowContexts as $context) {
            $rowContributions = $this->ingredientRowContributions(
                $context,
                $payload,
                $formulaWeight,
                $soapCalculation,
                $variantKey,
                $declarationRuleState,
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
                        'source_is_user_owned' => [],
                    ];
                }

                $rowsByLabel[$labelKey]['weight'] += $contribution['weight'];
                $rowsByLabel[$labelKey]['kind'] = $this->mergeRowKind(
                    $rowsByLabel[$labelKey]['kind'],
                    $contribution['kind'],
                );
                $rowsByLabel[$labelKey]['source_ingredients'][] = $context['ingredient_name'];
                $rowsByLabel[$labelKey]['source_is_user_owned'][] = $context['is_user_owned'];
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

                $sourceIsUserOwned = $this->deduplicateOwnershipFlags(
                    $row['source_ingredients'],
                    $row['source_is_user_owned'],
                );

                return [
                    'label' => $row['label'],
                    'weight' => round((float) $row['weight'], 5),
                    'percent_of_formula' => $formulaWeight > 0
                        ? round((((float) $row['weight']) / $formulaWeight) * 100, 5)
                        : 0.0,
                    'kind' => $row['kind'],
                    'source_ingredients' => $sourceIngredients,
                    'source_is_user_owned' => $sourceIsUserOwned,
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
     * Deduplicate source_is_user_owned in the same order as the deduplicated source_ingredients.
     * For each unique source ingredient, keeps the first ownership flag encountered.
     *
     * @param  array<int, string>  $sourceIngredients
     * @param  array<int, bool>  $sourceIsUserOwned
     * @return array<int, bool>
     */
    private function deduplicateOwnershipFlags(array $sourceIngredients, array $sourceIsUserOwned): array
    {
        $seen = [];
        $result = [];

        foreach ($sourceIngredients as $idx => $name) {
            if (! is_string($name) || $name === '') {
                continue;
            }

            $key = $name;

            if (! array_key_exists($key, $seen)) {
                $seen[$key] = true;
                $result[] = $sourceIsUserOwned[$idx] ?? false;
            }
        }

        return $result;
    }

    /**
     * @param  array{
     *     phase_key: string,
     *     weight: float,
     *     ingredient: Ingredient|null,
     *     ingredient_name: string,
     *     is_user_owned: bool
     * }  $context
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $soapCalculation
     * @return array<int, array{label: string|null, weight: float, kind: string, warning: string|null}>
     */
    private function ingredientRowContributions(
        array $context,
        array $payload,
        float $formulaWeight,
        ?array $soapCalculation,
        string $variantKey,
        array $declarationRuleState,
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
                $contributions = [
                    ...$contributions,
                    ...$this->saponifiedOilContributions(
                        $context,
                        $payload,
                        $formulaWeight,
                        $saponifiedWeight,
                        $declarationRuleState,
                    ),
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
            $declarationRuleState,
        );

        return [[
            'label' => $labelState['label'],
            'weight' => $context['weight'],
            'kind' => $labelState['kind'],
            'warning' => $labelState['warning'],
        ]];
    }

    /**
     * @param  array{
     *     phase_key: string,
     *     weight: float,
     *     ingredient: Ingredient|null,
     *     ingredient_name: string,
     *     is_user_owned: bool
     * }  $context
     * @param  array<string, mixed>  $payload
     * @return array<int, array{label: string|null, weight: float, kind: string, warning: string|null}>
     */
    private function saponifiedOilContributions(
        array $context,
        array $payload,
        float $formulaWeight,
        float $saponifiedWeight,
        array $declarationRuleState,
    ): array {
        $ingredient = $context['ingredient'];

        if ($ingredient instanceof Ingredient && ($payload['lye_type'] ?? 'naoh') === 'dual') {
            $naohLabel = $this->normalizePrintedLabel($ingredient->soap_inci_naoh_name);
            $kohLabel = $this->normalizePrintedLabel($ingredient->soap_inci_koh_name);

            if ($naohLabel !== null && $kohLabel !== null && $naohLabel !== $kohLabel) {
                $kohRatio = $this->dualKohRatio($payload);

                return array_values(array_filter([
                    [
                        'label' => $naohLabel,
                        'weight' => $saponifiedWeight * (1 - $kohRatio),
                        'kind' => 'saponified_oil',
                        'warning' => null,
                    ],
                    [
                        'label' => $kohLabel,
                        'weight' => $saponifiedWeight * $kohRatio,
                        'kind' => 'saponified_oil',
                        'warning' => null,
                    ],
                ], fn (array $contribution): bool => $contribution['weight'] > 0));
            }
        }

        $labelState = $this->ingredientListLabel(
            $context,
            $payload,
            $formulaWeight,
            $declarationRuleState,
        );

        return [[
            'label' => $labelState['label'],
            'weight' => $saponifiedWeight,
            'kind' => $labelState['kind'],
            'warning' => $labelState['warning'],
        ]];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function dualKohRatio(array $payload): float
    {
        $kohPercentage = is_numeric($payload['dual_lye_koh_percentage'] ?? null)
            ? (float) $payload['dual_lye_koh_percentage']
            : 50.0;

        return max(0.0, min(100.0, $kohPercentage)) / 100;
    }

    /**
     * @param  array<int, array{
     *     phase_key: string,
     *     weight: float,
     *     ingredient: Ingredient|null,
     *     ingredient_name: string,
     *     is_user_owned: bool
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
     *     source_is_user_owned: array<int, bool>,
     *     notes: string|null
     * }>
     */
    private function declarationRows(
        array $rowContexts,
        array $ingredientLabelKeys,
        float $formulaWeight,
        array $declarationRuleState,
    ): array {
        $rowsByLabel = [];

        foreach ($rowContexts as $context) {
            $ingredient = $context['ingredient'];

            if (! $ingredient instanceof Ingredient || $formulaWeight <= 0) {
                continue;
            }

            $ingredientPercentOfFormula = ($context['weight'] / $formulaWeight) * 100;

            foreach ($ingredient->allergenEntries as $entry) {
                $rule = $this->declarationRuleForEntry($entry, $declarationRuleState);

                if (($declarationRuleState['uses_regime_rules'] ?? false) && $rule === null) {
                    continue;
                }

                $label = $this->declarationLabelForEntry($entry, $rule);

                if ($label === null) {
                    continue;
                }

                $labelKey = $this->normalizeLabel($label);
                $thresholdPercent = (float) ($rule['threshold_percent'] ?? $declarationRuleState['default_threshold_percent']);
                $thresholdOperator = (string) ($rule['threshold_operator'] ?? 'greater_than_or_equal');

                if (! array_key_exists($labelKey, $rowsByLabel)) {
                    $rowsByLabel[$labelKey] = [
                        'label' => $label,
                        'percent_of_formula' => 0.0,
                        'threshold_percent' => $thresholdPercent,
                        'threshold_operator' => $thresholdOperator,
                        'source_ingredients' => [],
                        'source_is_user_owned' => [],
                    ];
                }

                $rowsByLabel[$labelKey]['percent_of_formula'] += $ingredientPercentOfFormula * (((float) $entry->concentration_percent) / 100);
                $rowsByLabel[$labelKey]['threshold_percent'] = min(
                    (float) $rowsByLabel[$labelKey]['threshold_percent'],
                    $thresholdPercent,
                );
                $rowsByLabel[$labelKey]['source_ingredients'][] = $context['ingredient_name'];
                $rowsByLabel[$labelKey]['source_is_user_owned'][] = $context['is_user_owned'];
            }
        }

        $rows = array_values(array_map(
            function (array $row) use ($ingredientLabelKeys): array {
                $percentOfFormula = round((float) $row['percent_of_formula'], 5);
                $thresholdPercent = (float) $row['threshold_percent'];
                $thresholdOperator = (string) $row['threshold_operator'];
                $exceedsThreshold = $this->passesDeclarationThreshold($percentOfFormula, $thresholdPercent, $thresholdOperator);
                $suppressedByExistingLabel = $exceedsThreshold
                    && in_array($this->normalizeLabel($row['label']), $ingredientLabelKeys, true);
                $includedInInci = $exceedsThreshold && ! $suppressedByExistingLabel;

                $sourceIngredients = array_values(array_unique(array_filter(
                    $row['source_ingredients'],
                    fn (mixed $value): bool => is_string($value) && $value !== '',
                )));

                $sourceIsUserOwned = $this->deduplicateOwnershipFlags(
                    $row['source_ingredients'],
                    $row['source_is_user_owned'],
                );

                return [
                    'label' => $row['label'],
                    'percent_of_formula' => $percentOfFormula,
                    'threshold_percent' => $thresholdPercent,
                    'exceeds_threshold' => $exceedsThreshold,
                    'included_in_inci' => $includedInInci,
                    'suppressed_by_existing_label' => $suppressedByExistingLabel,
                    'status_label' => $this->declarationStatusLabel(
                        $exceedsThreshold,
                        $suppressedByExistingLabel,
                    ),
                    'source_ingredients' => $sourceIngredients,
                    'source_is_user_owned' => $sourceIsUserOwned,
                    'notes' => $this->declarationNotes(
                        $exceedsThreshold,
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
     *     ingredient_name: string,
     *     is_user_owned: bool
     * }  $context
     * @param  array<string, mixed>  $payload
     * @return array{label: string|null, kind: string, warning: string|null}
     */
    private function ingredientListLabel(
        array $context,
        array $payload,
        float $formulaWeight,
        array $declarationRuleState,
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
                $declarationRuleState,
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
     *     ingredient_name: string,
     *     is_user_owned: bool
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
     *     ingredient_name: string,
     *     is_user_owned: bool
     * }  $context
     */
    private function declarationReplacementLabel(
        array $context,
        string $defaultLabel,
        float $formulaWeight,
        array $declarationRuleState,
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
            $rule = $this->declarationRuleForEntry($entry, $declarationRuleState);

            if (($declarationRuleState['uses_regime_rules'] ?? false) && $rule === null) {
                continue;
            }

            $label = $this->declarationLabelForEntry($entry, $rule);
            $concentrationPercent = (float) $entry->concentration_percent;

            if (
                $label === null
                || $label === $defaultLabel
                || abs($concentrationPercent - 100) > 0.00001
            ) {
                continue;
            }

            $declarationPercentOfFormula = $ingredientPercentOfFormula * ($concentrationPercent / 100);
            $thresholdPercent = (float) ($rule['threshold_percent'] ?? $declarationRuleState['default_threshold_percent']);
            $thresholdOperator = (string) ($rule['threshold_operator'] ?? 'greater_than_or_equal');

            if (! $this->passesDeclarationThreshold($declarationPercentOfFormula, $thresholdPercent, $thresholdOperator)) {
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
                'source_is_user_owned' => [],
            ];
        }

        $rowsByLabel[$labelKey]['weight'] += $weight;
        $rowsByLabel[$labelKey]['source_ingredients'][] = $sourceIngredient;
        $rowsByLabel[$labelKey]['source_is_user_owned'][] = false;
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
     *     source_ingredients: array<int, string>,
     *     source_is_user_owned: array<int, bool>
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
     *     source_ingredients: array<int, string>,
     *     source_is_user_owned: array<int, bool>
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

    private function declarationStatusLabel(bool $exceedsThreshold, bool $suppressedByExistingLabel): string
    {
        if (! $exceedsThreshold) {
            return 'Below threshold';
        }

        return $suppressedByExistingLabel
            ? 'Already named'
            : 'Added to INCI';
    }

    private function declarationNotes(bool $exceedsThreshold, bool $suppressedByExistingLabel): ?string
    {
        if (! $exceedsThreshold) {
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
     *     ingredient_name: string,
     *     is_user_owned: bool
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

    private function thresholdPercent(string $exposureMode): float
    {
        return $exposureMode === 'leave_on' ? 0.001 : 0.01;
    }

    private function ruleThresholdPercent(RegulatoryRegimeAllergen $rule, string $exposureMode): float
    {
        $threshold = $exposureMode === 'leave_on'
            ? $rule->leave_on_threshold_percent
            : $rule->rinse_off_threshold_percent;

        return is_numeric($threshold)
            ? max(0.0, (float) $threshold)
            : $this->thresholdPercent($exposureMode);
    }

    /**
     * @param  array{
     *     uses_regime_rules: bool,
     *     default_threshold_percent: float,
     *     rules_by_allergen_id: array<int, array{label: string, threshold_percent: float, threshold_operator: string}>,
     *     regime_code: string,
     *     regime_label: string
     * }  $declarationRuleState
     * @return array{label: string, threshold_percent: float, threshold_operator: string}|null
     */
    private function declarationRuleForEntry(IngredientAllergenEntry $entry, array $declarationRuleState): ?array
    {
        $allergenId = (int) $entry->allergen_id;

        if ($allergenId <= 0) {
            return null;
        }

        return $declarationRuleState['rules_by_allergen_id'][$allergenId] ?? null;
    }

    /**
     * @param  array{label: string, threshold_percent: float, threshold_operator: string}|null  $rule
     */
    private function declarationLabelForEntry(IngredientAllergenEntry $entry, ?array $rule): ?string
    {
        return $this->normalizePrintedLabel(
            $rule['label']
                ?? $entry->allergen?->inci_name,
        );
    }

    private function passesDeclarationThreshold(float $percentOfFormula, float $thresholdPercent, string $thresholdOperator): bool
    {
        return match ($thresholdOperator) {
            'greater_than' => $percentOfFormula > $thresholdPercent,
            default => $percentOfFormula >= $thresholdPercent,
        };
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
