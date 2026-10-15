<?php

namespace App\Services;

use App\Enums\IngredientLabelMarket;
use App\Enums\LiquidSoapNeutralizer;
use App\Models\Ingredient;
use App\Models\IngredientAllergenEntry;
use App\Models\RegulatoryRegime;
use App\Models\RegulatoryRegimeAllergen;
use App\SoapSap;
use App\Support\InciName;
use App\Support\NumberLocale;
use Illuminate\Support\Str;

class InciGenerationService
{
    private const DEFAULT_LIST_VARIANT_KEY = 'saponified_with_superfat';

    private const INCORPORATED_LIST_VARIANT_KEY = 'incorporated_ingredients';

    /**
     * Naming used when no market is checked: canonical INCI, with no market
     * declaration override applied.
     */
    private const CANONICAL_NAMING = 'canonical';

    public function __construct(
        private readonly IngredientFormulaContextResolver $ingredientFormulaContextResolver,
        private readonly IngredientDeclarationNameResolver $ingredientDeclarationNameResolver,
        private readonly SoapCalculationService $soapCalculationService,
        private readonly LiquidSoapFormulaProcessor $liquidSoapFormulaProcessor,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $soapCalculation
     * @return array{
     *     basis: array{
     *         label: string,
     *         note: string,
     *         formula_weight: float,
     *         threshold_percent: float,
     *         cured_weight: float|null,
     *         residual_water_weight: float|null
     *     },
     *     ingredient_rows: array<int, array{
     *         label: string,
     *         weight: float,
     *         lye_liquid_weight: float,
     *         percent_of_formula: float,
     *         kind: string,
     *         source_ingredients: array<int, string>,
     *         source_is_user_owned: array<int, bool>
     *     }>,
     *     declaration_rows: array<int, array{
     *         label: string,
     *         percent_of_formula: float,
     *         percent_of_cured_basis: float|null,
     *         threshold_percent: float|null,
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
     *             lye_liquid_weight: float,
     *             percent_of_formula: float,
     *             kind: string,
     *             source_ingredients: array<int, string>,
     *             source_is_user_owned: array<int, bool>
     *         }>,
     *         declaration_rows: array<int, array{
     *             label: string,
     *             percent_of_formula: float,
     *             percent_of_cured_basis: float|null,
     *             threshold_percent: float|null,
     *             exceeds_threshold: bool,
     *             included_in_inci: bool,
     *             suppressed_by_existing_label: bool,
     *             status_label: string,
     *             source_ingredients: array<int, string>,
     *             source_is_user_owned: array<int, bool>,
     *             notes: string|null
     *         }>,
     *         final_labels: array<int, string>,
     *         final_label_text: string,
     *         plain_label_text: string|null
     *     }>,
     *     default_variant_key: string,
     *     final_labels: array<int, string>,
     *     final_label_text: string,
     *     market_check: array{state: 'checked'|'not_selected'|'unavailable', regime_code: string},
     *     warnings: array<int, string>
     * }
     */
    public function generate(array $payload, ?array $soapCalculation = null): array
    {
        $rowContexts = $this->ingredientFormulaContextResolver->resolve(
            $this->withResolvedLyeLiquidWeights($payload, $soapCalculation),
            [
                'allergenEntries.allergen',
                'marketLabels',
                'sapProfile',
                'translations',
            ],
        );
        $declarationRuleState = $this->declarationRuleState($payload);
        $basis = $this->basisState(
            $payload,
            $rowContexts,
            $soapCalculation,
            $declarationRuleState['default_threshold_percent'],
        );
        $declarationRuleState['declaration_basis_weight'] = $this->declarationBasisWeight($basis);
        $listVariants = $this->listVariants($payload, $rowContexts, $basis, $soapCalculation, $declarationRuleState);
        $defaultVariant = $this->defaultListVariant($listVariants);
        $plainLanguageList = $this->plainLanguageList(
            $payload,
            $rowContexts,
            $soapCalculation,
            $declarationRuleState['market_code'] ?? null,
        );
        $ingredientListBasisHash = $this->ingredientListBasisHash($payload, $basis);
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
            'market_check' => [
                'state' => $declarationRuleState['screening_state'],
                'regime_code' => $declarationRuleState['regime_code'],
            ],
            'warnings' => $this->warnings(
                $payload,
                $rowContexts,
                [
                    ...$this->variantWarnings($listVariants),
                    ...($plainLanguageList['warnings'] ?? []),
                ],
                $soapCalculation,
                $declarationRuleState,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     screening_state: 'checked'|'not_selected'|'unavailable',
     *     uses_regime_rules: bool,
     *     default_threshold_percent: float,
     *     rules_by_allergen_id: array<int, array{label: string, threshold_percent: float, threshold_operator: string}>,
     *     regime_code: string,
     *     regime_label: string,
     *     market_code: string
     * }
     */
    private function declarationRuleState(array $payload): array
    {
        $exposureMode = (string) ($payload['exposure_mode'] ?? 'rinse_off');
        $regimeCode = Str::lower(trim((string) ($payload['regulatory_regime'] ?? RegulatoryRegime::NONE_CODE)));
        $regimeCode = $regimeCode !== '' ? $regimeCode : RegulatoryRegime::NONE_CODE;
        $defaultThresholdPercent = $this->thresholdPercent($exposureMode);
        $today = now()->toDateString();

        if ($regimeCode === RegulatoryRegime::NONE_CODE) {
            return $this->uncheckedDeclarationRuleState($defaultThresholdPercent, $regimeCode, 'not_selected');
        }

        $regime = RegulatoryRegime::query()
            ->where('code', $regimeCode)
            ->selectable($today)
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
            return $this->uncheckedDeclarationRuleState($defaultThresholdPercent, $regimeCode, 'unavailable');
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
            'screening_state' => 'checked',
            'uses_regime_rules' => true,
            'default_threshold_percent' => $defaultThresholdPercent,
            'rules_by_allergen_id' => $rulesByAllergenId,
            'regime_code' => $regime->code,
            'regime_label' => $regime->name,
            'market_code' => filled($regime->market_code)
                ? Str::lower(trim((string) $regime->market_code))
                : ($regime->code === 'eu' ? 'eu' : Str::lower(trim((string) $regime->code))),
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
     *     threshold_percent: float,
     *     cured_weight: float|null,
     *     residual_water_weight: float|null
     * }  $basis
     * @param  array<string, mixed>|null  $soapCalculation
     * @return array<int, array{
     *     key: string,
     *     label: string,
     *     note: string,
     *     ingredient_rows: array<int, array{
     *         label: string,
     *         weight: float,
     *         lye_liquid_weight: float,
     *         percent_of_formula: float,
     *         kind: string,
     *         source_ingredients: array<int, string>,
     *         source_is_user_owned: array<int, bool>
     *     }>,
     *     declaration_rows: array<int, array{
     *         label: string,
     *         percent_of_formula: float,
     *         threshold_percent: float|null,
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
     *     plain_label_text: string|null,
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
     *     threshold_percent: float,
     *     cured_weight: float|null,
     *     residual_water_weight: float|null
     * }  $basis
     * @param  array<string, mixed>|null  $soapCalculation
     * @return array<int, array{
     *     key: string,
     *     label: string,
     *     note: string,
     *     ingredient_rows: array<int, array{
     *         label: string,
     *         weight: float,
     *         lye_liquid_weight: float,
     *         percent_of_formula: float,
     *         kind: string,
     *         source_ingredients: array<int, string>,
     *         source_is_user_owned: array<int, bool>
     *     }>,
     *     declaration_rows: array<int, array{
     *         label: string,
     *         percent_of_formula: float,
     *         threshold_percent: float|null,
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
     *     plain_label_text: string|null,
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
        $plainLanguageTexts = $this->variantPlainLanguageTexts(
            $payload,
            $rowContexts,
            $soapCalculation,
            $declarationRuleState['market_code'] ?? null,
        );

        return array_map(function (array $definition) use ($payload, $rowContexts, $basis, $soapCalculation, $declarationRuleState, $plainLanguageTexts): array {
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
                $basis['cured_weight'],
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
                'display_final_label_text' => implode(', ', array_map(InciName::display(...), $finalLabels)),
                'plain_label_text' => $plainLanguageTexts[$definition['key']] ?? null,
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
     *         lye_liquid_weight: float,
     *         percent_of_formula: float,
     *         kind: string,
     *         source_ingredients: array<int, string>,
     *         source_is_user_owned: array<int, bool>
     *     }>,
     *     declaration_rows: array<int, array{
     *         label: string,
     *         percent_of_formula: float,
     *         threshold_percent: float|null,
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
     *     plain_label_text: string|null,
     *     warnings: array<int, string>
     * }>  $listVariants
     * @return array{
     *     key: string,
     *     label: string,
     *     note: string,
     *     ingredient_rows: array<int, array{
     *         label: string,
     *         weight: float,
     *         lye_liquid_weight: float,
     *         percent_of_formula: float,
     *         kind: string,
     *         source_ingredients: array<int, string>,
     *         source_is_user_owned: array<int, bool>
     *     }>,
     *     declaration_rows: array<int, array{
     *         label: string,
     *         percent_of_formula: float,
     *         threshold_percent: float|null,
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
     *     threshold_percent: float|null,
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
     * @return array{label: string, note: string, final_labels: array<int, string>, final_label_text: string, warnings: array<int, string>}
     */
    private function plainLanguageList(array $payload, array $rowContexts, ?array $soapCalculation, ?string $marketCode = null): array
    {
        $bilingual = Str::lower(trim((string) $marketCode)) === IngredientLabelMarket::Ca->value;

        if (($payload['manufacturing_mode'] ?? 'saponify_in_formula') === 'saponify_in_formula') {
            return $this->soapPlainLanguageList($rowContexts, $soapCalculation, $bilingual);
        }

        $rowsByLabel = [];
        $warnings = [];

        foreach ($rowContexts as $context) {
            $pair = $this->plainLabelPair($context, false, $bilingual);
            $this->appendPlainLanguageRow(
                $rowsByLabel,
                $this->joinBilingualLabel($pair),
                (float) $context['weight'],
            );
            $this->collectMissingFrenchWarning($warnings, $pair, $bilingual, (string) $context['ingredient_name']);
        }

        $labels = $this->sortedPlainLanguageLabels($rowsByLabel);

        return [
            'label' => 'Plain-language ingredient list',
            'note' => 'Uses ingredient display names in decreasing order on the full formula basis.',
            'final_labels' => $labels,
            'final_label_text' => implode(', ', $labels),
            'warnings' => array_values(array_unique($warnings)),
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
     * @return array{label: string, note: string, final_labels: array<int, string>, final_label_text: string, warnings: array<int, string>}
     */
    private function soapPlainLanguageList(array $rowContexts, ?array $soapCalculation, bool $bilingual = false): array
    {
        $oilNamesByKey = [];
        $restRowsByLabel = [];
        $warnings = [];

        foreach ($rowContexts as $context) {
            if ($context['phase_key'] === 'saponified_oils') {
                $oilPair = $this->plainSaponifiedOilLabelPair($context, $bilingual);
                $oilLabel = $this->joinBilingualLabel($oilPair);

                if ($oilLabel !== null) {
                    $oilNamesByKey[$this->normalizeLabel($oilLabel)] = $oilLabel;
                }

                $this->collectMissingFrenchWarning($warnings, $oilPair, $bilingual, (string) $context['ingredient_name']);

                continue;
            }

            $pair = $this->plainLabelPair($context, true, $bilingual);
            $this->appendPlainLanguageRow(
                $restRowsByLabel,
                $this->joinBilingualLabel($pair),
                (float) $context['weight'],
            );
            $this->collectMissingFrenchWarning($warnings, $pair, $bilingual, (string) $context['ingredient_name']);
        }

        if (is_array($soapCalculation)) {
            $this->appendPlainLanguageRow(
                $restRowsByLabel,
                $bilingual ? 'Water/Eau' : 'Water',
                $this->bottledWaterWeight($soapCalculation),
            );
            $this->appendPlainLanguageRow(
                $restRowsByLabel,
                $bilingual ? 'Glycerin/Glycérine' : 'Glycerin',
                (float) data_get($soapCalculation, 'lye.selected.glycerine_weight', 0),
            );
            $this->appendPlainLanguageRow(
                $restRowsByLabel,
                $bilingual ? 'Sodium hydroxide/Hydroxyde de sodium' : 'Sodium hydroxide',
                (float) data_get($soapCalculation, 'lye.excess.naoh_weight', 0),
            );
            $this->appendPlainLanguageRow(
                $restRowsByLabel,
                $bilingual ? 'Potassium hydroxide/Hydroxyde de potassium' : 'Potassium hydroxide',
                $this->freeKohWeight($soapCalculation),
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
            'warnings' => array_values(array_unique($warnings)),
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
     * @return array<string, string|null>
     */
    private function variantPlainLanguageTexts(array $payload, array $rowContexts, ?array $soapCalculation, ?string $marketCode = null): array
    {
        $bilingual = Str::lower(trim((string) $marketCode)) === IngredientLabelMarket::Ca->value;

        if (($payload['manufacturing_mode'] ?? 'saponify_in_formula') !== 'saponify_in_formula') {
            return [
                self::INCORPORATED_LIST_VARIANT_KEY => $this->plainLanguageList($payload, $rowContexts, $soapCalculation, $marketCode)['final_label_text'],
            ];
        }

        return [
            self::DEFAULT_LIST_VARIANT_KEY => $this->soapPlainLanguageList($rowContexts, $soapCalculation, $bilingual)['final_label_text'],
            self::INCORPORATED_LIST_VARIANT_KEY => $this->soapIncorporatedPlainLanguageList($rowContexts, $soapCalculation, $bilingual)['final_label_text'],
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
    private function soapIncorporatedPlainLanguageList(array $rowContexts, ?array $soapCalculation, bool $bilingual = false): array
    {
        $rowsByLabel = [];

        foreach ($rowContexts as $context) {
            $this->appendPlainLanguageRow(
                $rowsByLabel,
                $this->joinBilingualLabel($this->plainLabelPair($context, true, $bilingual)),
                (float) $context['weight'],
            );
        }

        if (is_array($soapCalculation)) {
            $this->appendPlainLanguageRow(
                $rowsByLabel,
                $bilingual ? 'Water/Eau' : 'Water',
                $this->addedWaterWeight($soapCalculation),
            );
            $this->appendPlainLanguageRow(
                $rowsByLabel,
                $bilingual ? 'Sodium Hydroxide/Sodium Hydroxyde' : 'Sodium Hydroxide',
                (float) data_get($soapCalculation, 'lye.selected.naoh_weight', 0),
            );
            $this->appendPlainLanguageRow(
                $rowsByLabel,
                $bilingual ? 'Potassium Hydroxide/Potassium Hydroxyde' : 'Potassium Hydroxide',
                (float) data_get($soapCalculation, 'lye.selected.koh_to_weigh', 0),
            );
        }

        $labels = $this->sortedPlainLanguageLabels($rowsByLabel);

        return [
            'label' => 'Plain-language ingredient list (incorporated)',
            'note' => 'Lists materials as incorporated before saponification in decreasing order.',
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
     * Plain-language label pair for one row, with an optional French equivalent
     * for bilingual Canadian lists. English always resolves; French resolves only
     * when the ingredient carries a distinct French translation.
     *
     * @param  array{
     *     phase_key: string,
     *     weight: float,
     *     ingredient: Ingredient|null,
     *     ingredient_name: string,
     *     is_user_owned: bool
     * }  $context
     * @return array{english: string|null, french: string|null}
     */
    private function plainLabelPair(array $context, bool $titleCase, bool $bilingual): array
    {
        $english = $this->plainIngredientLabel($context, $titleCase);

        $french = null;

        if ($bilingual && $english !== null && $context['ingredient'] instanceof Ingredient) {
            $frenchName = Str::squish((string) ($context['ingredient']->frenchDisplayName() ?? ''));

            if ($frenchName !== '' && $frenchName !== $english) {
                $french = $frenchName;
            }
        }

        return ['english' => $english, 'french' => $french];
    }

    private function joinBilingualLabel(array $pair): ?string
    {
        if ($pair['english'] === null) {
            return null;
        }

        return $pair['french'] === null
            || $pair['french'] === ''
            ? $pair['english']
            : "{$pair['english']}/{$pair['french']}";
    }

    /**
     * @param  array{english: string|null, french: string|null}  $pair
     */
    private function collectMissingFrenchWarning(array &$warnings, array $pair, bool $bilingual, string $ingredientName): void
    {
        if (! $bilingual || $pair['english'] === null || $pair['french'] !== null) {
            return;
        }

        $warnings[] = "{$ingredientName} has no French name yet, so the Canadian plain-language list shows it in English only.";
    }

    /**
     * @param  array{
     *     phase_key: string,
     *     weight: float,
     *     ingredient: Ingredient|null,
     *     ingredient_name: string,
     *     is_user_owned: bool
     * }  $context
     * @return array{english: string|null, french: string|null}
     */
    private function plainSaponifiedOilLabelPair(array $context, bool $bilingual): array
    {
        $pair = $this->plainLabelPair($context, true, $bilingual);

        foreach (['english', 'french'] as $key) {
            $value = $pair[$key];

            if ($value === null) {
                continue;
            }

            $value = preg_replace('/\s+(Oil|Oils|Butter|Butters)\z/i', '', $value) ?? $value;

            $pair[$key] = trim($value) !== '' ? trim($value) : null;
        }

        return $pair;
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
     * Hashes what a saved final list was based on. Soap declarations are
     * compared with the cured weight, so that basis is part of the hash: lists
     * accepted under the former wet-batch basis are flagged for review.
     *
     * @param  array<string, mixed>  $payload
     * @param  array{cured_weight: float|null}  $basis
     */
    private function ingredientListBasisHash(array $payload, array $basis): string
    {
        $basisPayload = $payload;

        if (($basis['cured_weight'] ?? null) !== null && $basis['cured_weight'] > 0) {
            $basisPayload['declaration_threshold_basis'] = 'cured_weight';
        }

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
     *         lye_liquid_weight: float,
     *         percent_of_formula: float,
     *         kind: string,
     *         source_ingredients: array<int, string>,
     *         source_is_user_owned: array<int, bool>
     *     }>,
     *     declaration_rows: array<int, array{
     *         label: string,
     *         percent_of_formula: float,
     *         threshold_percent: float|null,
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
     *     plain_label_text: string|null,
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
     *     threshold_percent: float,
     *     cured_weight: float|null,
     *     residual_water_weight: float|null,
     *     residual_water_percentage: float|null
     * }
     */
    private function basisState(array $payload, array $rowContexts, ?array $soapCalculation, float $thresholdPercent): array
    {
        $phaseWeight = array_sum(array_map(
            fn (array $context): float => $context['phase_key'] === 'lye_water'
                ? 0.0
                : (float) $context['weight'],
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
                'cured_weight' => null,
                'residual_water_weight' => null,
                'residual_water_percentage' => null,
            ];
        }

        if (! is_array($soapCalculation)) {
            return [
                'label' => 'Current formula basis',
                'note' => 'Soap calculation is incomplete, so the preview currently uses only the live ingredient rows. Aqua and produced glycerine will appear once the soap calculation resolves.',
                'formula_weight' => round($phaseWeight, 5),
                'threshold_percent' => $thresholdPercent,
                'cured_weight' => null,
                'residual_water_weight' => null,
                'residual_water_percentage' => null,
            ];
        }

        $liquidProcess = $this->liquidProcess($soapCalculation);

        if ($liquidProcess !== null) {
            return [
                'label' => 'Current batch basis',
                'note' => 'Percentages use the finished liquid soap: paste, dilution water, neutralizer and additions.',
                'formula_weight' => round((float) $liquidProcess['final_weight'], 5),
                'threshold_percent' => $thresholdPercent,
                'cured_weight' => null,
                'residual_water_weight' => null,
                'residual_water_percentage' => null,
            ];
        }

        $waterWeight = (float) data_get($soapCalculation, 'lye.water.weight', 0);
        $naohWeight = (float) data_get($soapCalculation, 'lye.selected.naoh_weight', 0);
        $kohToWeigh = (float) data_get($soapCalculation, 'lye.selected.koh_to_weigh', 0);
        $formulaWeight = round($phaseWeight + $waterWeight + $naohWeight + $kohToWeigh, 5);
        $nonWaterWeight = max(0.0, $formulaWeight - $waterWeight);
        $curedWaterPercentage = SoapSap::curedWaterPercentage($payload['cured_water_percentage'] ?? null);
        $curedWeight = $nonWaterWeight > 0 ? round(SoapSap::curedWeight($nonWaterWeight, $curedWaterPercentage), 5) : null;

        return [
            'label' => 'Current batch basis',
            'note' => 'Percentages use the live batch basis from oils, lye, water, and post-reaction additions. Cured-soap output percentages use the retained non-water mass with '.$this->formatPercentage($curedWaterPercentage).'% residual water.',
            'formula_weight' => $formulaWeight,
            'threshold_percent' => $thresholdPercent,
            'cured_weight' => $curedWeight,
            'residual_water_weight' => $curedWeight === null ? null : round($curedWeight * ($curedWaterPercentage / 100), 5),
            'residual_water_percentage' => $curedWaterPercentage,
        ];
    }

    /**
     * No market selected, or the stored market is not available: composition stays
     * visible under canonical INCI naming, and no declaration rule is applied.
     *
     * @param  'not_selected'|'unavailable'  $screeningState
     * @return array{
     *     screening_state: 'not_selected'|'unavailable',
     *     uses_regime_rules: bool,
     *     default_threshold_percent: float,
     *     rules_by_allergen_id: array<int, array{label: string, threshold_percent: float, threshold_operator: string}>,
     *     regime_code: string,
     *     regime_label: string,
     *     market_code: string
     * }
     */
    private function uncheckedDeclarationRuleState(float $defaultThresholdPercent, string $regimeCode, string $screeningState): array
    {
        return [
            'screening_state' => $screeningState,
            'uses_regime_rules' => false,
            'default_threshold_percent' => $defaultThresholdPercent,
            'rules_by_allergen_id' => [],
            'regime_code' => $regimeCode,
            'regime_label' => $screeningState === 'not_selected'
                ? __('workbench.output.market_check.none_label')
                : Str::upper($regimeCode),
            'market_code' => self::CANONICAL_NAMING,
        ];
    }

    /**
     * @param  array<string, mixed>  $declarationRuleState
     */
    private function declarationScreeningChecked(array $declarationRuleState): bool
    {
        return ($declarationRuleState['screening_state'] ?? 'checked') === 'checked';
    }

    /**
     * Declaration thresholds apply to the product as sold, so saponified soap is
     * measured against its cured weight rather than the wet batch.
     *
     * @param  array{formula_weight: float, cured_weight: float|null}  $basis
     */
    private function declarationBasisWeight(array $basis): float
    {
        $curedWeight = $basis['cured_weight'] ?? null;

        return $curedWeight !== null && $curedWeight > 0
            ? (float) $curedWeight
            : (float) $basis['formula_weight'];
    }

    /**
     * @param  array<string, mixed>  $declarationRuleState
     */
    private function percentOfDeclarationBasis(float $percentOfFormula, float $formulaWeight, array $declarationRuleState): float
    {
        $basisWeight = (float) ($declarationRuleState['declaration_basis_weight'] ?? $formulaWeight);

        if ($basisWeight <= 0 || $formulaWeight <= 0) {
            return $percentOfFormula;
        }

        return ($percentOfFormula * $formulaWeight) / $basisWeight;
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
     *         lye_liquid_weight: float,
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
                        'lye_liquid_weight' => 0.0,
                        'percent_of_formula' => 0.0,
                        'kind' => $contribution['kind'],
                        'source_ingredients' => [],
                        'source_is_user_owned' => [],
                    ];
                }

                $rowsByLabel[$labelKey]['weight'] += $contribution['weight'];
                $rowsByLabel[$labelKey]['lye_liquid_weight'] += $context['phase_key'] === 'lye_water'
                    ? $contribution['weight']
                    : 0.0;
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
                    'display_label' => InciName::display($row['label']),
                    'weight' => round((float) $row['weight'], 5),
                    'lye_liquid_weight' => round((float) $row['lye_liquid_weight'], 5),
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
            $labelState = $this->incorporatedIngredientLabel(
                $context,
                (string) $declarationRuleState['market_code'],
            );

            return [[
                'label' => $labelState['label'],
                'weight' => $context['weight'],
                'kind' => $context['phase_key'] === 'lye_water' ? 'lye_liquid' : $labelState['kind'],
                'warning' => $labelState['warning'],
            ]];
        }

        $neutralizerContributions = $this->neutralizerContributions(
            $context,
            $soapCalculation,
            (string) $declarationRuleState['market_code'],
        );

        if ($neutralizerContributions !== null) {
            return $neutralizerContributions;
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
                        $soapCalculation,
                        $saponifiedWeight,
                        $declarationRuleState,
                    ),
                ];
            }

            if ($superfatWeight > 0) {
                $labelState = $this->incorporatedIngredientLabel(
                    $context,
                    (string) $declarationRuleState['market_code'],
                );

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
            (string) $declarationRuleState['market_code'],
        );
        $postReactionShare = ($payload['manufacturing_mode'] ?? 'saponify_in_formula') === 'saponify_in_formula'
            ? $this->postReactionSaponifiedShare($context, $soapCalculation)
            : 0.0;

        if ($postReactionShare > 0) {
            // Label convention: oils added to the soap keep their oil INCI even
            // when the alkali saponifies part of them. Their mass still includes
            // the bound alkali, minus the glycerine listed separately, so the
            // list stays in mass balance.
            $saponifiedWeight = $context['weight'] * $postReactionShare;
            $soapSaltWeights = $this->soapSaltWeights(
                [...$context, 'weight' => $saponifiedWeight],
                $payload,
                $soapCalculation,
                $saponifiedWeight,
                fullySaponified: true,
            );

            return [[
                'label' => $labelState['label'],
                'weight' => $soapSaltWeights['total'] + ($context['weight'] - $saponifiedWeight),
                'kind' => $labelState['kind'],
                'warning' => $labelState['warning'],
            ]];
        }

        return [[
            'label' => $labelState['label'],
            'weight' => $context['weight'],
            'kind' => $context['phase_key'] === 'lye_water' ? 'lye_liquid' : $labelState['kind'],
            'warning' => $labelState['warning'],
        ]];
    }

    /**
     * A negative superfat leaves lye for the oils added after the reaction,
     * so every saponifiable oil, main or added, keeps the same free share.
     * With a non-negative superfat those oils stay as added.
     *
     * @param  array<string, mixed>|null  $soapCalculation
     */
    private function sharesLyeWithPostReactionOils(?array $soapCalculation): bool
    {
        return is_array($soapCalculation)
            && (float) data_get($soapCalculation, 'lye.post_reaction_saponified_fraction', 0) > 0;
    }

    /**
     * Share of an oil added after the reaction that the lye
     * saponifies. Only trusted saponifiable oils in the additions phase take
     * part, matching the oils the soap calculation counted.
     *
     * @param  array{phase_key: string, weight: float, ingredient: Ingredient|null}  $context
     * @param  array<string, mixed>|null  $soapCalculation
     */
    private function postReactionSaponifiedShare(array $context, ?array $soapCalculation): float
    {
        $ingredient = $context['ingredient'] ?? null;

        if ($context['phase_key'] !== 'additives'
            || ! is_array($soapCalculation)
            || ! $ingredient instanceof Ingredient
            || ! $ingredient->canDriveSoapSaponification()
            || (float) $ingredient->sapProfile?->koh_sap_value <= 0) {
            return 0.0;
        }

        return max(0.0, min(1.0, (float) data_get($soapCalculation, 'lye.post_reaction_saponified_fraction', 0)));
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
        ?array $soapCalculation,
        float $saponifiedWeight,
        array $declarationRuleState,
    ): array {
        $ingredient = $context['ingredient'];
        $soapSaltWeights = $this->soapSaltWeights(
            $context,
            $payload,
            $soapCalculation,
            $saponifiedWeight,
        );

        if ($ingredient instanceof Ingredient && ($payload['lye_type'] ?? 'naoh') === 'dual') {
            $naohLabel = $this->normalizePrintedLabel($ingredient->soap_inci_naoh_name);
            $kohLabel = $this->normalizePrintedLabel($ingredient->soap_inci_koh_name);

            if ($naohLabel !== null && $kohLabel !== null && $naohLabel !== $kohLabel) {
                return array_values(array_filter([
                    [
                        'label' => $naohLabel,
                        'weight' => $soapSaltWeights['naoh'],
                        'kind' => 'saponified_oil',
                        'warning' => null,
                    ],
                    [
                        'label' => $kohLabel,
                        'weight' => $soapSaltWeights['koh'],
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
            (string) $declarationRuleState['market_code'],
        );

        return [[
            'label' => $labelState['label'],
            'weight' => $soapSaltWeights['total'],
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
     * @param  array<string, mixed>|null  $soapCalculation
     * @return array{naoh: float, koh: float, total: float}
     */
    private function soapSaltWeights(
        array $context,
        array $payload,
        ?array $soapCalculation,
        float $saponifiedWeight,
        bool $fullySaponified = false,
    ): array {
        $ingredient = $context['ingredient'];
        $kohSapValue = $ingredient instanceof Ingredient
            ? $ingredient->sapProfile?->koh_sap_value
            : null;

        if ($kohSapValue === null || $soapCalculation === null) {
            $kohRatio = match ((string) ($payload['lye_type'] ?? 'naoh')) {
                'koh' => 1.0,
                'dual' => (float) ($payload['dual_lye_koh_percentage'] ?? 50) / 100,
                default => 0.0,
            };

            return [
                'naoh' => $saponifiedWeight * (1 - $kohRatio),
                'koh' => $saponifiedWeight * $kohRatio,
                'total' => $saponifiedWeight,
            ];
        }

        $products = $this->soapCalculationService->calculateOilReactionProducts(
            $context['weight'],
            (float) $kohSapValue,
            [
                'superfat' => match (true) {
                    $fullySaponified => 0,
                    $this->sharesLyeWithPostReactionOils($soapCalculation) => 100 * (1 - (float) data_get($soapCalculation, 'lye.saponified_fraction', 1)),
                    default => data_get($soapCalculation, 'lye.superfat_percentage', $payload['superfat'] ?? 5),
                },
                'lye_type' => data_get($soapCalculation, 'lye.selected.type', $payload['lye_type'] ?? 'naoh'),
                'dual_lye_koh_percentage' => data_get(
                    $soapCalculation,
                    'lye.selected.dual_lye_koh_percentage',
                    $payload['dual_lye_koh_percentage'] ?? 50,
                ),
                'koh_purity_percentage' => data_get(
                    $soapCalculation,
                    'lye.selected.koh_purity_percentage',
                    $payload['koh_purity_percentage'] ?? 100,
                ),
            ],
        );

        return [
            'naoh' => $products['naoh_soap_salt_weight'],
            'koh' => $products['koh_soap_salt_weight'],
            'total' => $products['soap_salt_weight'],
        ];
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
     * @param  array<string, mixed>  $declarationRuleState
     * @return array<int, array{
     *     label: string,
     *     percent_of_formula: float,
     *     percent_of_cured_basis: float|null,
     *     threshold_percent: float|null,
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
        ?float $curedWeight,
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
            function (array $row) use ($ingredientLabelKeys, $curedWeight, $formulaWeight, $declarationRuleState): array {
                $percentOfFormula = round((float) $row['percent_of_formula'], 5);
                $screeningChecked = $this->declarationScreeningChecked($declarationRuleState);
                $thresholdPercent = $screeningChecked ? (float) $row['threshold_percent'] : null;
                $thresholdOperator = (string) $row['threshold_operator'];
                $exceedsThreshold = $thresholdPercent !== null && $this->passesDeclarationThreshold(
                    $this->percentOfDeclarationBasis($percentOfFormula, $formulaWeight, $declarationRuleState),
                    $thresholdPercent,
                    $thresholdOperator,
                );
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
                    'display_label' => InciName::display($row['label']),
                    'percent_of_formula' => $percentOfFormula,
                    'percent_of_cured_basis' => $curedWeight !== null && $curedWeight > 0
                        ? round((($percentOfFormula * $formulaWeight) / $curedWeight), 5)
                        : null,
                    'threshold_percent' => $thresholdPercent,
                    'exceeds_threshold' => $exceedsThreshold,
                    'included_in_inci' => $includedInInci,
                    'suppressed_by_existing_label' => $suppressedByExistingLabel,
                    'status_label' => $screeningChecked
                        ? $this->declarationStatusLabel($exceedsThreshold, $suppressedByExistingLabel)
                        : __('workbench.output.market_check.not_checked'),
                    'source_ingredients' => $sourceIngredients,
                    'source_is_user_owned' => $sourceIsUserOwned,
                    'notes' => $screeningChecked
                        ? $this->declarationNotes($exceedsThreshold, $suppressedByExistingLabel)
                        : __('workbench.output.market_check.declaration_not_checked_note'),
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
        string $marketCode,
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

            $fallbackState = $this->resolveIngredientDeclarationWithWarning($ingredient, $marketCode);
            // Like other preview rows, a missing INCI falls back to the display
            // name so the saponified mass never drops out of the list.
            $fallbackLabel = $fallbackState['label'] ?? $this->normalizePrintedLabel($ingredient->display_name);

            if ($fallbackState['warning'] === null && $fallbackLabel !== null) {
                $fallbackWarning = "{$ingredientName} is missing soap-specific INCI output, so the preview is falling back to its regular ingredient label.";
            } else {
                $fallbackWarning = $fallbackState['warning'];
            }

            return [
                'label' => $fallbackLabel,
                'kind' => 'saponified_oil',
                'warning' => $fallbackWarning,
            ];
        }

        $declarationState = $this->resolveIngredientDeclarationWithWarning($ingredient, $marketCode);
        $label = $declarationState['label'];
        $warning = $declarationState['warning'];

        if ($label !== null) {
            if ($this->isParfumLabel($label)) {
                return [
                    'label' => 'PARFUM',
                    'kind' => 'parfum',
                    'warning' => $warning,
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
                'warning' => $warning,
            ];
        }

        $fallbackLabel = $this->normalizePrintedLabel($ingredient->display_name);

        return [
            'label' => $fallbackLabel,
            'kind' => 'ingredient',
            'warning' => $warning,
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
    private function incorporatedIngredientLabel(array $context, string $marketCode): array
    {
        $ingredient = $context['ingredient'];

        if (! $ingredient instanceof Ingredient) {
            return [
                'label' => null,
                'kind' => 'ingredient',
                'warning' => null,
            ];
        }

        $declarationState = $this->resolveIngredientDeclarationWithWarning($ingredient, $marketCode);

        if ($declarationState['label'] !== null) {
            return [
                'label' => $this->isParfumLabel($declarationState['label']) ? 'PARFUM' : $declarationState['label'],
                'kind' => 'ingredient',
                'warning' => $declarationState['warning'],
            ];
        }

        $fallbackLabel = $this->normalizePrintedLabel($ingredient->display_name);

        return [
            'label' => $fallbackLabel,
            'kind' => 'ingredient',
            'warning' => $declarationState['warning'],
        ];
    }

    /**
     * @return array{label: string|null, warning: string|null}
     */
    private function resolveIngredientDeclarationWithWarning(Ingredient $ingredient, string $marketCode): array
    {
        if ($marketCode === self::CANONICAL_NAMING) {
            return ['label' => $this->normalizePrintedLabel($ingredient->inci_name), 'warning' => null];
        }

        ['declaration_name' => $declarationName, 'fallback' => $fallback] = $this->ingredientDeclarationNameResolver->resolveWithFallback(
            $ingredient,
            $marketCode,
            allowLegacyEuFallback: in_array(
                $marketCode,
                [IngredientLabelMarket::Eu->value, IngredientLabelMarket::Ca->value],
                true,
            ),
        );

        if ($fallback === null) {
            return ['label' => $declarationName, 'warning' => null];
        }

        $warning = $fallback === IngredientDeclarationNameResolver::FALLBACK_US_BARE_CI
            ? __('ingredient_admin.market_labels.warnings.us_bare_ci_fallback', [
                'ingredient' => $ingredient->display_name,
            ])
            : __('ingredient_admin.market_labels.warnings.missing_declaration_fallback', [
                'ingredient' => $ingredient->display_name,
                'market' => IngredientLabelMarket::tryFrom($marketCode)?->label() ?? $marketCode,
            ]);

        return ['label' => $declarationName, 'warning' => $warning];
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
            || ! $this->declarationScreeningChecked($declarationRuleState)
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

            $declarationPercentOfFormula = $this->percentOfDeclarationBasis(
                $ingredientPercentOfFormula * ($concentrationPercent / 100),
                $formulaWeight,
                $declarationRuleState,
            );
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
        float $lyeLiquidWeight = 0.0,
    ): void {
        if ($weight <= 0) {
            return;
        }

        $labelKey = $this->normalizeLabel($label);

        if (! array_key_exists($labelKey, $rowsByLabel)) {
            $rowsByLabel[$labelKey] = [
                'label' => $label,
                'weight' => 0.0,
                'lye_liquid_weight' => 0.0,
                'percent_of_formula' => 0.0,
                'kind' => $kind,
                'source_ingredients' => [],
                'source_is_user_owned' => [],
            ];
        }

        $rowsByLabel[$labelKey]['weight'] += $weight;
        $rowsByLabel[$labelKey]['lye_liquid_weight'] += $lyeLiquidWeight;
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
            $this->bottledWaterWeight($soapCalculation),
            'water',
            'Lye water',
            $this->bottledWaterWeight($soapCalculation),
        );
        $this->appendStandaloneIngredientRow(
            $rowsByLabel,
            'GLYCERIN',
            (float) data_get($soapCalculation, 'lye.selected.glycerine_weight', 0),
            'derived',
            'Saponification',
        );
        $this->appendStandaloneIngredientRow(
            $rowsByLabel,
            'SODIUM HYDROXIDE',
            (float) data_get($soapCalculation, 'lye.excess.naoh_weight', 0),
            'lye',
            'Excess lye',
        );
        $this->appendStandaloneIngredientRow(
            $rowsByLabel,
            'POTASSIUM HYDROXIDE',
            $this->freeKohWeight($soapCalculation),
            'lye',
            'Excess lye',
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
            $this->addedWaterWeight($soapCalculation),
            'water',
            'Lye water',
            $this->addedWaterWeight($soapCalculation),
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
     * The liquid soap paste process when it was calculated; null for bar soap
     * and for liquid settings that could not be made.
     *
     * @param  array<string, mixed>|null  $soapCalculation
     * @return array<string, mixed>|null
     */
    private function liquidProcess(?array $soapCalculation): ?array
    {
        $process = $soapCalculation['liquid_process'] ?? null;

        return is_array($process) && ($process['status'] ?? null) === 'ok' ? $process : null;
    }

    /**
     * Water in the finished product. A liquid soap also holds its dilution
     * water, the neutralizer solution water and the water the neutralization
     * forms; lye-liquid substitutions keep their own rows.
     *
     * @param  array<string, mixed>  $soapCalculation
     */
    private function bottledWaterWeight(array $soapCalculation): float
    {
        $process = $this->liquidProcess($soapCalculation);

        if ($process === null) {
            return $this->freshWaterWeight($soapCalculation);
        }

        $substitutedLiquidWeight = (float) data_get($soapCalculation, 'lye.water.weight', 0) - $this->freshWaterWeight($soapCalculation);

        return max(0.0, (float) data_get($process, 'composition.water_weight', 0) - $substitutedLiquidWeight);
    }

    /**
     * Water as weighed in: for a liquid soap, the alkali solution water plus
     * both dilutions and the neutralizer solution water.
     *
     * @param  array<string, mixed>  $soapCalculation
     */
    private function addedWaterWeight(array $soapCalculation): float
    {
        $process = $this->liquidProcess($soapCalculation);
        $freshWaterWeight = $this->freshWaterWeight($soapCalculation);

        if ($process === null) {
            return $freshWaterWeight;
        }

        return $freshWaterWeight + (float) $this->liquidSoapFormulaProcessor->addedDilutionWaterWeight($process);
    }

    /**
     * KOH left free in the product: after neutralization for a liquid soap.
     *
     * @param  array<string, mixed>  $soapCalculation
     */
    private function freeKohWeight(array $soapCalculation): float
    {
        $process = $this->liquidProcess($soapCalculation);

        return $process === null
            ? (float) data_get($soapCalculation, 'lye.excess.koh_weight', 0)
            : (float) data_get($process, 'composition.free_koh_weight', 0);
    }

    /**
     * What a liquid soap neutralizer becomes in the product: its potassium
     * salt, or for a fatty acid its potassium soap plus any unreacted acid.
     *
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>|null  $soapCalculation
     * @return array<int, array{label: string|null, weight: float, kind: string, warning: string|null}>|null
     */
    private function neutralizerContributions(array $context, ?array $soapCalculation, string $marketCode): ?array
    {
        $summary = $this->liquidProcess($soapCalculation)['neutralizer'] ?? null;
        $type = is_array($summary) ? LiquidSoapNeutralizer::tryFrom((string) $summary['type']) : null;

        if ($context['phase_key'] !== 'neutralization' || $type === null) {
            return null;
        }

        $incorporated = $this->incorporatedIngredientLabel($context, $marketCode);
        $productLabel = $type->formsSoap()
            ? ($context['ingredient'] instanceof Ingredient ? $this->soapLabel($context['ingredient'], 'koh') : null) ?? $incorporated['label']
            : $type->saltInciName();

        return collect([
            ['label' => $productLabel, 'weight' => (float) $summary['product_weight'], 'kind' => 'derived', 'warning' => $incorporated['warning']],
            ['label' => $incorporated['label'], 'weight' => (float) $summary['excess_fatty_acid_weight'], 'kind' => 'theoretical_superfat', 'warning' => $incorporated['warning']],
        ])->filter(fn (array $contribution): bool => $contribution['weight'] > 0)->values()->all();
    }

    /** @param array<string, mixed> $soapCalculation */
    private function freshWaterWeight(array $soapCalculation): float
    {
        return (float) data_get(
            $soapCalculation,
            'lye.liquid_composition.water_weight',
            data_get($soapCalculation, 'lye.water.weight', 0),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $soapCalculation
     * @return array<string, mixed>
     */
    private function withResolvedLyeLiquidWeights(array $payload, ?array $soapCalculation): array
    {
        $resolvedSubstitutions = data_get($soapCalculation, 'lye.liquid_composition.substitutions');
        $lyeLiquidRows = data_get($payload, 'phase_items.lye_water');

        if (! is_array($resolvedSubstitutions) || ! is_array($lyeLiquidRows)) {
            return $payload;
        }

        $resolvedIndex = 0;
        $payload['phase_items']['lye_water'] = collect($lyeLiquidRows)
            ->values()
            ->map(function (mixed $row) use (&$resolvedIndex, $resolvedSubstitutions): mixed {
                if (! is_array($row)) {
                    return $row;
                }

                $percentage = NumberLocale::normalizeDecimalString($row['percentage'] ?? null);

                if ($percentage === null || bccomp($percentage, '0', 12) <= 0) {
                    return [...$row, 'weight' => 0.0];
                }

                $resolvedSubstitution = $resolvedSubstitutions[$resolvedIndex] ?? null;
                $resolvedIndex++;

                if (! is_array($resolvedSubstitution)) {
                    return $row;
                }

                return [
                    ...$row,
                    'weight' => (float) ($resolvedSubstitution['weight'] ?? $row['weight'] ?? 0),
                ];
            })
            ->all();

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $soapCalculation
     */
    private function superfatRatio(array $payload, ?array $soapCalculation): float
    {
        if ($this->sharesLyeWithPostReactionOils($soapCalculation)) {
            return max(0.0, min(0.99999, 1 - (float) data_get($soapCalculation, 'lye.saponified_fraction', 1)));
        }

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
     * @param  array<string, mixed>  $declarationRuleState
     * @return array<int, string>
     */
    private function warnings(
        array $payload,
        array $rowContexts,
        array $fallbackWarnings,
        ?array $soapCalculation,
        array $declarationRuleState,
    ): array {
        $warnings = $fallbackWarnings;

        if (($declarationRuleState['screening_state'] ?? null) === 'unavailable') {
            $warnings[] = __('workbench.output.market_check.unavailable', [
                'market' => $declarationRuleState['regime_code'],
            ]);
        }

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
                || ! $this->declarationScreeningChecked($declarationRuleState)
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

    private function formatPercentage(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
