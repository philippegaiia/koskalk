# Formula Document Outputs Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the overloaded saved-formula presentation and six-sheet workbook with one normalized Formula Sheet, one working printout, a reuse-focused CSV, and a one- or two-sheet Excel workbook.

**Architecture:** Add a source-agnostic `FormulaDocumentBuilder` that receives the existing normalized workbench snapshot and produces presentation rows once. The saved-version adapter, Blade views, CSV exporter, and Excel exporter consume that document; none recalculates weights independently. Keep production recording, costing, Product-page work, SOP snapshotting, history entitlements, and the public calculator out of this plan.

**Tech Stack:** PHP 8.5, Laravel 13, Blade, Tailwind CSS 4, Pest 4, OpenSpout XLSX.

---

## Scope and branch precondition

This is the first of three implementation plans derived from `docs/superpowers/specs/2026-07-21-formula-outputs-and-product-page-design.md`.

- This plan: shared formula document, saved Formula Sheet, working print, CSV, Excel.
- Follow-up plan: Product page, SOP snapshots, entitlement-controlled saved history.
- Follow-up plan: public soap calculator and registration handoff.

Do not begin Task 1 in the current dirty localization worktree. First preserve the approved localization work in a commit, then create a fresh implementation worktree from that commit. The three unrelated modified files under `.claude/skills/laravel-best-practices/` remain untouched.

Before every Laravel code task, run version-specific documentation search for the APIs being changed. Suggested Boost queries are `blade components tables`, `stream download response`, and `testing streamed response`. No dependency change is required.

## File map

- Create `app/Services/FormulaDocumentBuilder.php`: normalize a calculated snapshot into one renderer-neutral document.
- Create `app/Services/SoapCuredOutputBuilder.php`: derive the cured-soap table from the selected generated INCI variant and the existing 11% residual-water assumption.
- Modify `app/Services/RecipeVersionViewDataBuilder.php`: attach `formulaDocument` after its existing saved-version loading and rescaling.
- Modify `app/Services/RecipeExportDataBuilder.php`: expose only the normalized document required by CSV/XLSX.
- Create `resources/views/components/formula-document/table.blade.php`: one aligned table for soap sections or cosmetic phases.
- Create `resources/views/components/formula-document/results.blade.php`: compact calculated-result rows below the formula.
- Modify `resources/views/recipes/partials/version-sheet.blade.php`: use the document components and remove the top metric cards and separate lye card.
- Modify `resources/views/recipes/version.blade.php`: simplify actions and copy while retaining scaling, authorization, production recording, and history until the Product-page plan moves them.
- Modify `resources/views/recipes/print.blade.php`: replace print modes with the approved working print and optional soap-analysis page.
- Modify `app/Http/Controllers/RecipeController.php`: send the document to print and export; keep legacy print URLs as compatibility aliases to the working print.
- Modify `app/Services/RecipeCsvExporter.php`: emit the seven approved reuse columns.
- Modify `app/Services/RecipeWorkbookExporter.php`: emit `Ingredient batch`, plus `Soap output` for soap only.
- Create `lang/en/formula_documents.php`: reviewed English interface copy only.
- Modify `tests/Feature/RecipeVersionPagesTest.php`: saved sheet, print, CSV, workbook, authorization, and legacy-route coverage.
- Modify `tests/Feature/CosmeticRecipeWorkbenchTest.php`: cosmetic table and one-sheet workbook coverage.
- Create `tests/Unit/FormulaDocumentBuilderTest.php`: snapshot normalization contract.
- Create `tests/Unit/SoapCuredOutputBuilderTest.php`: cured-basis arithmetic and allergen-INCI behavior.

### Task 1: Normalize formula snapshots into one document

**Files:**
- Create: `app/Services/FormulaDocumentBuilder.php`
- Create: `app/Services/SoapCuredOutputBuilder.php`
- Modify: `app/Services/RecipeVersionViewDataBuilder.php`
- Test: `tests/Unit/FormulaDocumentBuilderTest.php`
- Test: `tests/Unit/SoapCuredOutputBuilderTest.php`

- [ ] **Step 1: Generate the two Pest unit-test files**

Run:

```bash
php artisan make:test --pest --unit FormulaDocumentBuilderTest --no-interaction
php artisan make:test --pest --unit SoapCuredOutputBuilderTest --no-interaction
```

Expected: both files are created under `tests/Unit`.

- [ ] **Step 2: Write the failing document-contract tests**

Use a hand-built normalized snapshot so this unit contract does not depend on database factories:

```php
<?php

use App\Services\FormulaDocumentBuilder;

it('places soap lye and water inside the aligned formula sections', function () {
    $snapshot = soapFormulaDocumentSnapshot();

    $document = app(FormulaDocumentBuilder::class)->build($snapshot, [
        'name' => 'Workshop soap',
        'calculation_basis' => 'oil_weight',
        'state' => 'saved',
        'saved_at' => '2026-07-21 10:00',
    ]);

    expect($document['percentage_basis'])->toBe('oils')
        ->and(collect($document['sections'])->pluck('key')->all())
        ->toBe(['saponified_oils', 'lye_water', 'formula_additions'])
        ->and(collect($document['sections'][1]['rows'])->pluck('name')->all())
        ->toBe(['NaOH', 'Water'])
        ->and($document['sections'][1]['rows'][0]['percentage'])->toBe(13.5)
        ->and($document['sections'][1]['rows'][0]['weight'])->toBe(135.0);
});

it('keeps cosmetic phases aligned on total formula percentage', function () {
    $document = app(FormulaDocumentBuilder::class)->build(cosmeticFormulaDocumentSnapshot(), [
        'name' => 'Face cream',
        'calculation_basis' => 'total_formula',
        'state' => 'saved',
    ]);

    expect($document['percentage_basis'])->toBe('formula')
        ->and($document['sections'][0]['label'])->toBe('Phase A')
        ->and($document['sections'][0]['rows'][0])
        ->toMatchArray(['name' => 'Water', 'percentage' => 70.0, 'weight' => 70.0]);
});

it('normalizes equivalent draft and saved snapshots to identical formula values', function () {
    $builder = app(FormulaDocumentBuilder::class);
    $snapshot = soapFormulaDocumentSnapshot();
    $draft = $builder->build($snapshot, [
        'name' => 'Workshop soap',
        'calculation_basis' => 'oil_weight',
        'state' => 'current',
    ]);
    $saved = $builder->build($snapshot, [
        'name' => 'Workshop soap',
        'calculation_basis' => 'oil_weight',
        'state' => 'saved',
        'saved_at' => '2026-07-21 10:00',
    ]);

    expect($draft['sections'])->toBe($saved['sections'])
        ->and($draft['results'])->toBe($saved['results'])
        ->and($draft['soap_output'])->toBe($saved['soap_output']);
});
```

At the bottom of the same test file, add these fixtures:

```php
function soapFormulaDocumentSnapshot(): array
{
    return [
        'draft' => [
            'oilWeight' => 1000,
            'oilUnit' => 'g',
            'lyeType' => 'naoh',
            'superfat' => 5,
            'waterMode' => 'percent_of_oils',
            'waterValue' => 30,
            'exposureMode' => 'rinse_off',
            'regulatoryRegime' => 'eu',
            'phaseItems' => [
                'saponified_oils' => [[
                    'name' => 'Olive oil',
                    'percentage' => 100,
                    'note' => null,
                    'is_user_owned' => false,
                ]],
                'additives' => [[
                    'name' => 'Clay',
                    'percentage' => 2,
                    'note' => 'Disperse first',
                    'is_user_owned' => false,
                ]],
                'fragrance' => [],
            ],
        ],
        'calculation' => [
            'lye' => [
                'superfat_percentage' => 5,
                'selected' => [
                    'naoh_weight' => 135,
                    'koh_to_weigh' => 0,
                    'glycerine_weight' => 70,
                ],
                'water' => ['weight' => 300],
            ],
            'properties' => [
                'qualities' => ['hardness' => 42],
                'fatty_acid_profile' => ['oleic' => 72],
            ],
        ],
        'labeling' => [
            'default_variant_key' => 'saponified_with_superfat',
            'print_ingredient_list_text' => 'SODIUM OLIVATE, AQUA',
            'warnings' => [],
            'list_variants' => [[
                'key' => 'saponified_with_superfat',
                'ingredient_rows' => [
                    ['label' => 'SODIUM OLIVATE', 'weight' => 900, 'kind' => 'saponified_oil', 'source_ingredients' => ['Olive oil']],
                    ['label' => 'AQUA', 'weight' => 300, 'kind' => 'ingredient', 'source_ingredients' => ['Water']],
                ],
                'declaration_rows' => [],
                'final_label_text' => 'SODIUM OLIVATE, AQUA',
            ]],
        ],
    ];
}

function cosmeticFormulaDocumentSnapshot(): array
{
    return [
        'draft' => [
            'oilWeight' => 100,
            'oilUnit' => 'g',
            'editMode' => 'percentage',
            'exposureMode' => 'leave_on',
            'regulatoryRegime' => 'eu',
            'phases' => [['key' => 'phase_a', 'name' => 'Phase A']],
            'phaseItems' => [
                'phase_a' => [[
                    'name' => 'Water',
                    'percentage' => 70,
                    'note' => null,
                    'is_user_owned' => false,
                ]],
            ],
        ],
        'calculation' => null,
        'labeling' => [
            'print_ingredient_list_text' => 'AQUA',
            'warnings' => [],
        ],
    ];
}
```

- [ ] **Step 3: Write the failing cured-output test**

```php
<?php

use App\Services\SoapCuredOutputBuilder;

it('normalizes the selected soap output to 89 percent non-water and 11 percent water', function () {
    $output = app(SoapCuredOutputBuilder::class)->build(
        labeling: [
            'default_variant_key' => 'saponified_with_superfat',
            'list_variants' => [[
                'key' => 'saponified_with_superfat',
                'ingredient_rows' => [
                    ['label' => 'SODIUM OLIVATE', 'weight' => 900, 'kind' => 'saponified_oil', 'source_ingredients' => ['Olive oil']],
                    ['label' => 'AQUA', 'weight' => 300, 'kind' => 'ingredient', 'source_ingredients' => ['Water']],
                ],
                'declaration_rows' => [[
                    'label' => 'LIMONENE',
                    'percent_of_formula' => 0.2,
                    'included_in_inci' => true,
                ]],
                'final_label_text' => 'SODIUM OLIVATE, AQUA, LIMONENE',
            ]],
        ],
        curedWeight: 1000,
    );

    expect($output['rows'][0])->toMatchArray([
        'name' => 'SODIUM OLIVATE',
        'percentage' => 89.0,
        'weight' => 890.0,
    ])->and($output['rows'][1])->toMatchArray([
        'name' => 'AQUA',
        'percentage' => 11.0,
        'weight' => 110.0,
    ])->and($output['inci'])->toBe('SODIUM OLIVATE, AQUA, LIMONENE');
});
```

- [ ] **Step 4: Run the tests and verify the missing classes fail**

Run:

```bash
php artisan test --compact tests/Unit/FormulaDocumentBuilderTest.php tests/Unit/SoapCuredOutputBuilderTest.php
```

Expected: FAIL because `FormulaDocumentBuilder` and `SoapCuredOutputBuilder` do not exist.

- [ ] **Step 5: Implement `SoapCuredOutputBuilder`**

Create the class with this public contract and exact normalization rules:

```php
<?php

namespace App\Services;

use Illuminate\Support\Arr;

class SoapCuredOutputBuilder
{
    private const RESIDUAL_WATER_PERCENTAGE = 11.0;

    /**
     * @param  array<string, mixed>  $labeling
     * @return array{basis_weight: float, residual_water_percentage: float, rows: array<int, array<string, mixed>>, inci: string}
     */
    public function build(array $labeling, float $curedWeight): array
    {
        $variantKey = (string) Arr::get($labeling, 'default_variant_key', '');
        $variant = collect(Arr::get($labeling, 'list_variants', []))
            ->first(fn (mixed $candidate): bool => is_array($candidate) && ($candidate['key'] ?? null) === $variantKey);
        $variant = is_array($variant) ? $variant : [];
        $ingredientRows = collect(Arr::get($variant, 'ingredient_rows', []))
            ->filter(fn (mixed $row): bool => is_array($row));
        $nonWaterSourceWeight = (float) $ingredientRows
            ->reject(fn (array $row): bool => ($row['label'] ?? '') === 'AQUA')
            ->sum(fn (array $row): float => (float) ($row['weight'] ?? 0));
        $nonWaterOutputWeight = $curedWeight * 0.89;

        $rows = $ingredientRows
            ->map(function (array $row) use ($curedWeight, $nonWaterSourceWeight, $nonWaterOutputWeight): array {
                $isWater = ($row['label'] ?? '') === 'AQUA';
                $weight = $isWater
                    ? $curedWeight * 0.11
                    : ($nonWaterSourceWeight > 0
                        ? $nonWaterOutputWeight * ((float) ($row['weight'] ?? 0) / $nonWaterSourceWeight)
                        : 0.0);

                return [
                    'name' => (string) ($row['label'] ?? ''),
                    'role' => $this->role((string) ($row['kind'] ?? ''), $isWater),
                    'percentage' => $curedWeight > 0 ? round(($weight / $curedWeight) * 100, 4) : 0.0,
                    'weight' => round($weight, 4),
                    'sources' => array_values(Arr::wrap($row['source_ingredients'] ?? [])),
                ];
            })
            ->filter(fn (array $row): bool => $row['weight'] > 0)
            ->sortByDesc('weight')
            ->values()
            ->all();

        return [
            'basis_weight' => round($curedWeight, 4),
            'residual_water_percentage' => self::RESIDUAL_WATER_PERCENTAGE,
            'rows' => $rows,
            'inci' => (string) ($variant['final_label_text'] ?? $labeling['print_ingredient_list_text'] ?? ''),
        ];
    }

    private function role(string $kind, bool $isWater): string
    {
        if ($isWater) {
            return 'residual_water';
        }

        return match ($kind) {
            'mixed_saponified_superfat' => 'soap_and_superfat',
            'theoretical_superfat' => 'superfat',
            'saponified_oil' => 'saponified_oil',
            'parfum' => 'aromatic_blend',
            'derived' => 'reaction_by_product',
            default => 'ingredient',
        };
    }
}
```

- [ ] **Step 6: Implement `FormulaDocumentBuilder`**

Create `app/Services/FormulaDocumentBuilder.php` with the complete implementation below. Ingredient names, notes, and authored cosmetic phase names pass through unchanged; only Soapkraft-authored labels use translation keys.

```php
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
```

- [ ] **Step 7: Attach the document in the saved-version adapter**

Inject `FormulaDocumentBuilder` into `RecipeVersionViewDataBuilder` and add this after rescaling the snapshot:

```php
$formulaDocument = $this->formulaDocumentBuilder->build($snapshot, [
    'name' => $recipe->name,
    'product_family' => $recipe->productFamily?->name,
    'product_type' => $recipe->productType?->name,
    'calculation_basis' => $recipe->productFamily?->calculation_basis,
    'state' => $version->saved_at === null ? 'current' : 'saved',
    'saved_at' => $version->saved_at?->format('Y-m-d H:i'),
    'description' => $recipe->description,
    'manufacturing_procedure' => $recipe->manufacturing_instructions,
]);
```

Return it as `'formulaDocument' => $formulaDocument` while retaining existing keys for the production/costing UI during this plan.

- [ ] **Step 8: Run the unit tests, format, and commit**

Run:

```bash
php artisan test --compact tests/Unit/FormulaDocumentBuilderTest.php tests/Unit/SoapCuredOutputBuilderTest.php
vendor/bin/pint --dirty --format agent
```

Expected: PASS; Pint reports no remaining changes.

```bash
git add app/Services/FormulaDocumentBuilder.php app/Services/SoapCuredOutputBuilder.php app/Services/RecipeVersionViewDataBuilder.php tests/Unit/FormulaDocumentBuilderTest.php tests/Unit/SoapCuredOutputBuilderTest.php
git commit -m "feat: add shared formula document"
```

### Task 2: Rebuild the saved Formula Sheet around the aligned table

**Files:**
- Create: `resources/views/components/formula-document/table.blade.php`
- Create: `resources/views/components/formula-document/results.blade.php`
- Modify: `resources/views/recipes/partials/version-sheet.blade.php`
- Modify: `resources/views/recipes/version.blade.php`
- Create: `lang/en/formula_documents.php`
- Modify: `tests/Feature/RecipeVersionPagesTest.php`
- Modify: `tests/Feature/CosmeticRecipeWorkbenchTest.php`

- [ ] **Step 1: Write failing soap and cosmetic page assertions**

Replace the first saved-sheet expectations in `RecipeVersionPagesTest.php` with the approved structure:

```php
$response = $this->actingAs($user)
    ->get(route('recipes.saved', ['recipe' => $recipe]))
    ->assertSuccessful()
    ->assertSee('Formula Sheet')
    ->assertSeeInOrder(['Saponified oils', 'Lye and water', 'Formula additions'])
    ->assertSee('% of oils')
    ->assertSee('NaOH')
    ->assertSee('Water')
    ->assertSee('Calculated results')
    ->assertDontSee('How this recipe was calculated')
    ->assertDontSee('Batch production sheet')
    ->assertDontSee('Technical recipe sheet')
    ->assertDontSee('Costing sheet');

expect(substr_count($response->getContent(), 'data-formula-document-table'))->toBe(1)
    ->and(strpos($response->getContent(), 'Lye and water'))
    ->toBeLessThan(strpos($response->getContent(), 'Calculated results'));
```

Add this cosmetic assertion to `CosmeticRecipeWorkbenchTest.php` after creating a saved cosmetic formula:

```php
$this->actingAs($user)
    ->get(route('recipes.saved', $recipe))
    ->assertSuccessful()
    ->assertSee('% of formula')
    ->assertSeeInOrder(['Phase A', 'Water', 'Calculated results'])
    ->assertDontSee('Lye and water')
    ->assertDontSee('% of oils');
```

- [ ] **Step 2: Run the focused tests and confirm the old card layout fails**

Run:

```bash
php artisan test --compact tests/Feature/RecipeVersionPagesTest.php --filter="renders the formula sheet"
php artisan test --compact tests/Feature/CosmeticRecipeWorkbenchTest.php --filter="saved cosmetic formula sheet"
```

Expected: FAIL because the old page has separate cards/tables and legacy print actions.

- [ ] **Step 3: Add reviewed English keys**

Create `lang/en/formula_documents.php`:

```php
<?php

return [
    'title' => 'Formula Sheet',
    'intro' => 'Review the formula, scale the quantity, print a bench copy, or export the ingredient data.',
    'states' => [
        'current' => 'Current formula',
        'saved' => 'Saved formula',
        'history' => 'Saved history',
    ],
    'actions' => [
        'back' => 'Back',
        'open' => 'Open formula',
        'print' => 'Print formula',
        'export_excel' => 'Export Excel',
        'export_csv' => 'Export CSV',
        'recalculate' => 'Recalculate',
    ],
    'columns' => [
        'ingredient' => 'Ingredient',
        'percentage_oils' => '% of oils',
        'percentage_formula' => '% of formula',
        'weight' => 'Weight (:unit)',
        'note' => 'Note',
    ],
    'ingredients' => [
        'naoh' => 'NaOH',
        'koh' => 'KOH',
        'water' => 'Water',
        'unnamed' => 'Unnamed ingredient',
    ],
    'settings' => [
        'lye_system' => 'Lye system',
        'superfat' => 'Superfat',
        'water' => 'Water setting',
        'exposure' => 'Exposure',
        'regime' => 'Regulatory regime',
        'entry_mode' => 'Entry mode',
        'leave_on' => 'Leave-on',
        'rinse_off' => 'Rinse-off',
        'weight' => 'Weight',
        'percentage' => 'Percentage',
        'lye_ratio_value' => 'Lye ratio: :value',
        'lye_concentration_value' => 'Lye concentration: :value%',
        'oil_water_value' => ':value% of oils',
    ],
    'results' => [
        'batch_quantity' => 'Total batch quantity',
        'formula_total' => 'Formula total',
        'wet_batch' => 'Wet batch weight',
        'cured_weight' => 'Estimated weight after cure',
        'glycerine' => 'Produced glycerine',
        'additions' => 'Formula additions',
    ],
    'sections' => [
        'settings' => 'Formula settings',
        'saponified_oils' => 'Saponified oils',
        'lye_water' => 'Lye and water',
        'formula_additions' => 'Formula additions',
        'results' => 'Calculated results',
        'description' => 'Description',
        'manufacturing_procedure' => 'Manufacturing procedure',
        'label' => 'Label text',
    ],
    'print' => [
        'title' => 'Working Formula Sheet',
        'batch_number' => 'Trial / batch no.',
        'date' => 'Date',
        'made_by' => 'Made by',
        'checked_by' => 'Checked by',
        'observations' => 'Observations',
        'result' => 'Result',
        'include_analysis' => 'Include soap analysis',
    ],
    'analysis' => [
        'title' => 'Soap analysis',
        'qualities' => 'Soap qualities',
        'fatty_acids' => 'Fatty-acid profile',
        'quality' => 'Quality',
        'fatty_acid' => 'Fatty acid',
        'value' => 'Value',
        'percentage' => 'Percentage',
        'suggested_range' => 'Suggested range',
        'status' => 'Status',
        'contribution' => 'Practical contribution',
        'below_range' => 'Below range',
        'in_range' => 'Within range',
        'above_range' => 'Above range',
        'reference_only' => 'Reference only',
        'contributions' => [
            'quick_lather' => 'Quick lather',
            'cleansing_bubbles' => 'Cleansing and bubbles',
            'hardness_lasting' => 'Hardness and longevity',
            'hardness_creamy' => 'Hardness and creamy lather',
            'lather_support' => 'Supports and stabilizes lather',
            'mildness_conditioning' => 'Mildness and conditioning feel',
            'conditioning_softness' => 'Conditioning feel and softness',
            'other' => 'Part of the fatty-acid balance',
        ],
        'quality_metrics' => [
            'hardness' => 'Hardness',
            'cleansing' => 'Cleansing',
            'conditioning' => 'Conditioning',
            'bubbly' => 'Bubbly lather',
            'creamy' => 'Creamy lather',
            'unmolding_firmness' => 'Unmolding firmness',
            'cured_hardness' => 'Cured hardness',
            'longevity' => 'Longevity',
            'cleansing_strength' => 'Cleansing strength',
            'mildness' => 'Mildness',
            'bubble_volume' => 'Bubble volume',
            'creamy_lather' => 'Creamy lather',
            'lather_stability' => 'Lather stability',
            'conditioning_feel' => 'Conditioning feel',
            'dos_risk' => 'DOS risk',
            'slime_risk' => 'Slime tendency',
            'cure_speed' => 'Cure speed',
            'iodine' => 'Iodine value',
            'ins' => 'INS value',
        ],
    ],
    'exports' => [
        'ingredient_batch' => 'Ingredient batch',
        'soap_output' => 'Soap output',
    ],
];
```

- [ ] **Step 4: Create the explicit table component**

Create `resources/views/components/formula-document/table.blade.php` with explicit props and one header:

```blade
@props(['document'])

@php
    $percentageHeading = $document['percentage_basis'] === 'formula'
        ? __('formula_documents.columns.percentage_formula')
        : __('formula_documents.columns.percentage_oils');
@endphp

<div data-formula-document-table {{ $attributes->merge(['class' => 'overflow-x-auto rounded-xl border border-[var(--color-line)] bg-white']) }}>
    <table class="min-w-full border-collapse text-sm">
        <thead class="bg-[var(--color-panel-strong)] text-left text-xs font-semibold tracking-[0.08em] text-[var(--color-ink-soft)] uppercase print:table-header-group">
            <tr>
                <th scope="col" class="px-4 py-3">{{ __('formula_documents.columns.ingredient') }}</th>
                <th scope="col" class="px-4 py-3 text-right">{{ $percentageHeading }}</th>
                <th scope="col" class="px-4 py-3 text-right">{{ __('formula_documents.columns.weight', ['unit' => $document['unit']]) }}</th>
                <th scope="col" class="px-4 py-3">{{ __('formula_documents.columns.note') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-[var(--color-line)]">
            @foreach ($document['sections'] as $section)
                <tr class="bg-[var(--color-panel)]">
                    <th scope="rowgroup" colspan="4" class="px-4 py-2 text-left text-xs font-semibold tracking-[0.08em] text-[var(--color-ink-strong)] uppercase">
                        {{ $section['label'] }}
                    </th>
                </tr>
                @foreach ($section['rows'] as $row)
                    <tr>
                        <td class="px-4 py-2.5 font-medium text-[var(--color-ink-strong)]">{{ $row['name'] }} <x-ingredient-source-marker :is-user-owned="$row['is_user_owned']" /></td>
                        <td class="numeric px-4 py-2.5 text-right">{{ number_format($row['percentage'], 2) }}%</td>
                        <td class="numeric px-4 py-2.5 text-right">{{ number_format($row['weight'], 2) }}</td>
                        <td class="px-4 py-2.5 text-[var(--color-ink-soft)]">{{ $row['note'] ?: '—' }}</td>
                    </tr>
                @endforeach
            @endforeach
        </tbody>
    </table>
</div>
```

- [ ] **Step 5: Create the compact results component**

Create `resources/views/components/formula-document/results.blade.php`:

```blade
@props(['document'])

<section {{ $attributes->merge(['class' => 'border-y border-[var(--color-line)] py-4']) }}>
    <h2 class="text-xs font-semibold tracking-[0.08em] text-[var(--color-ink-soft)] uppercase">
        {{ __('formula_documents.sections.results') }}
    </h2>
    <dl class="mt-3 grid gap-x-6 gap-y-3 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($document['results'] as $result)
            <div class="flex items-baseline justify-between gap-3 border-b border-[var(--color-line)] pb-2">
                <dt class="text-xs text-[var(--color-ink-soft)]">{{ $result['label'] }}</dt>
                <dd class="numeric text-sm font-semibold text-[var(--color-ink-strong)]">
                    {{ number_format($result['value'], 2) }} {{ $result['unit'] }}
                </dd>
            </div>
        @endforeach
    </dl>
</section>
```

- [ ] **Step 6: Replace the old saved-sheet partial**

In `resources/views/recipes/partials/version-sheet.blade.php`, replace the summary-card, settings-card, lye-card, and per-section table blocks with:

```blade
<div class="space-y-5">
    <section class="rounded-xl border border-[var(--color-line)] bg-[var(--color-panel)] px-4 py-3">
        <h2 class="sr-only">{{ __('formula_documents.sections.settings') }}</h2>
        <dl class="flex flex-wrap gap-x-6 gap-y-2 text-xs">
            @foreach ($formulaDocument['settings'] as $setting)
                <div class="flex gap-1.5">
                    <dt class="text-[var(--color-ink-soft)]">{{ $setting['label'] }}:</dt>
                    <dd class="font-medium text-[var(--color-ink-strong)]">{{ $setting['value'] }}</dd>
                </div>
            @endforeach
        </dl>
    </section>

    <x-formula-document.table :document="$formulaDocument" />
    <x-formula-document.results :document="$formulaDocument" />

    @if (filled($formulaDocument['identity']['description'] ?? null))
        <section>
            <h2 class="text-sm font-semibold">{{ __('formula_documents.sections.description') }}</h2>
            <div class="prose prose-stone mt-2 max-w-none text-sm">{!! str($formulaDocument['identity']['description'])->sanitizeHtml() !!}</div>
        </section>
    @endif

    @if (filled($formulaDocument['identity']['manufacturing_procedure'] ?? null))
        <section>
            <h2 class="text-sm font-semibold">{{ __('formula_documents.sections.manufacturing_procedure') }}</h2>
            <div class="prose prose-stone mt-2 max-w-none text-sm">{!! str($formulaDocument['identity']['manufacturing_procedure'])->sanitizeHtml() !!}</div>
        </section>
    @endif

    @if (filled($formulaDocument['label_text']))
        <section>
            <h2 class="text-sm font-semibold">{{ __('formula_documents.sections.label') }}</h2>
            <p class="mt-2 text-sm leading-6 text-[var(--color-ink-strong)]">{{ $formulaDocument['label_text'] }}</p>
        </section>
    @endif
</div>
```

Pass `formulaDocument` explicitly from `version.blade.php`. Keep the existing source legend only if the document contains a user-owned row.

- [ ] **Step 7: Simplify the header actions without moving production/history yet**

In `resources/views/recipes/version.blade.php`, keep the existing Open formula, Duplicate, scaling, production-recording, and saved-history blocks, but replace the document intro and print/export action cluster with:

```blade
<p class="mt-2 max-w-3xl text-sm text-[var(--color-ink-soft)]">{{ __('formula_documents.intro') }}</p>

<div class="mt-4 flex flex-wrap gap-2">
    <a href="{{ route('recipes.edit', $recipe) }}" class="inline-flex rounded-full border border-[var(--color-line-strong)] bg-white px-4 py-2 text-sm font-medium text-[var(--color-ink-strong)]">
        {{ __('formula_documents.actions.open') }}
    </a>
    <form method="POST" action="{{ route('recipes.duplicate', $recipe) }}">
        @csrf
        <button type="submit" class="inline-flex rounded-full border border-[var(--color-line)] px-4 py-2 text-sm font-medium text-[var(--color-ink-soft)]">{{ __('products.actions.duplicate') }}</button>
    </form>
    <form method="GET" action="{{ route('recipes.print.production', ['recipe' => $recipe]) }}" class="flex flex-wrap items-center gap-2">
        @foreach (collect($printQuery)->except('recipe') as $key => $value)
            <input type="hidden" name="{{ $key }}" value="{{ $value }}" />
        @endforeach
        <label class="inline-flex items-center gap-2 text-xs text-[var(--color-ink-soft)]">
            <input type="checkbox" name="include_analysis" value="1" class="rounded border-[var(--color-line)]" />
            {{ __('formula_documents.print.include_analysis') }}
        </label>
        <button type="submit" class="inline-flex rounded-full bg-[var(--color-accent-strong)] px-4 py-2 text-sm font-medium text-white">
            {{ __('formula_documents.actions.print') }}
        </button>
    </form>
    <a href="{{ route('recipes.export.xlsx', $printQuery) }}" class="inline-flex rounded-full border border-[var(--color-line)] px-4 py-2 text-sm font-medium text-[var(--color-ink-soft)]">
        {{ __('formula_documents.actions.export_excel') }}
    </a>
    <a href="{{ route('recipes.export.csv', $printQuery) }}" class="inline-flex rounded-full border border-[var(--color-line)] px-4 py-2 text-sm font-medium text-[var(--color-ink-soft)]">
        {{ __('formula_documents.actions.export_csv') }}
    </a>
</div>
```

Retain the historical `Back to active formula` action immediately before this cluster. Replace state badge literals with `__('formula_documents.states.history')` and `__('formula_documents.states.saved')`. Remove the three old print links completely.

- [ ] **Step 8: Run page tests, format, and commit**

Run:

```bash
php artisan test --compact tests/Feature/RecipeVersionPagesTest.php tests/Feature/CosmeticRecipeWorkbenchTest.php
vendor/bin/pint --dirty --format agent
```

Expected: PASS, including existing authorization and historical-version assertions.

```bash
git add app/Services/RecipeVersionViewDataBuilder.php lang/en/formula_documents.php resources/views/components/formula-document resources/views/recipes/partials/version-sheet.blade.php resources/views/recipes/version.blade.php tests/Feature/RecipeVersionPagesTest.php tests/Feature/CosmeticRecipeWorkbenchTest.php
git commit -m "feat: simplify the formula sheet"
```

### Task 3: Replace print modes with one working Formula Sheet

**Files:**
- Modify: `app/Http/Controllers/RecipeController.php`
- Create: `resources/js/print-document.js`
- Modify: `vite.config.js`
- Modify: `resources/views/layouts/print.blade.php`
- Modify: `resources/views/recipes/print.blade.php`
- Modify: `tests/Feature/RecipeVersionPagesTest.php`

- [ ] **Step 1: Write failing working-print assertions**

Replace the purpose-based print test with:

```php
it('prints one working formula sheet with optional soap analysis', function () {
    [$user, $recipe] = createSavedRecipeVersion();
    $recipe->update(['manufacturing_instructions' => '<p>Mix to emulsion, pour, and cure.</p>']);

    $this->actingAs($user)
        ->get(route('recipes.print.production', ['recipe' => $recipe]))
        ->assertSuccessful()
        ->assertSee('Working Formula Sheet')
        ->assertSeeInOrder(['Saponified oils', 'Lye and water', 'Formula additions'])
        ->assertSee('Trial / batch no.')
        ->assertSee('Made by')
        ->assertSee('Checked by')
        ->assertSee('Observations')
        ->assertSee('Result')
        ->assertSee('Manufacturing procedure')
        ->assertDontSee('Cost summary')
        ->assertDontSee('Declaration details')
        ->assertDontSee('Packaging costs');

    $this->actingAs($user)
        ->get(route('recipes.print.production', [
            'recipe' => $recipe,
            'include_analysis' => 1,
        ]))
        ->assertSuccessful()
        ->assertSee('Soap analysis')
        ->assertSee('Soap qualities')
        ->assertSee('Fatty-acid profile');
});
```

Add a dataset assertion that `recipes.print.technical`, `recipes.print.costing`, `recipes.legacy.print.recipe`, and `recipes.legacy.print.details` still authorize and render the same `Working Formula Sheet`. This preserves bookmarks while removing print-mode behavior.

Update the historical-version action test so exports retain the selected version query and the print form retains it as a hidden field:

```php
foreach (['recipes.export.xlsx', 'recipes.export.csv'] as $routeName) {
    $routePath = parse_url(route($routeName, ['recipe' => $recipe]), PHP_URL_PATH);
    $html = html_entity_decode($response->getContent());

    expect($html)->toContain((string) $routePath)
        ->and($html)->toContain('version='.$formulaA->public_id);
}

$response
    ->assertSee('action="'.route('recipes.print.production', ['recipe' => $recipe]).'"', false)
    ->assertSee('name="version" value="'.$formulaA->public_id.'"', false);
```

In the main-identity test, change every print-route title expectation to `Main Formula · Working Formula Sheet`; the legacy costing URL must now assert `Working Formula Sheet` and must not contain `Cost summary`.

- [ ] **Step 2: Run the focused print test and confirm it fails**

Run:

```bash
php artisan test --compact tests/Feature/RecipeVersionPagesTest.php --filter="working formula sheet"
```

Expected: FAIL because the existing view branches on production, technical, and costing modes.

- [ ] **Step 3: Collapse controller print behavior to one renderer**

Change `printSheet()` so every existing print action calls the same view data and no mode-specific costing data is selected:

```php
private function printSheet(
    string $recipePublicId,
    Request $request,
    CurrentAppUserResolver $currentAppUserResolver,
    RecipeVersionViewDataBuilder $recipeVersionViewDataBuilder,
    string $printMode,
    ?string $explicitVersionPublicId = null,
): View {
    [$recipe, $version] = $this->accessibleSheetVersion(
        $recipePublicId,
        $request,
        $currentAppUserResolver,
        $explicitVersionPublicId,
    );

    return view('recipes.print', [
        ...$recipeVersionViewDataBuilder->build(
            $recipe,
            $version,
            $request->query('oil_weight'),
            $request->query(),
        ),
        'includeAnalysis' => $request->boolean('include_analysis'),
        'isVersionSelected' => $explicitVersionPublicId !== null || $request->has('version'),
    ]);
}
```

Keep the unused `$printMode` parameter during this compatibility step so public controller methods and routes do not need a simultaneous rewrite. Place this docblock immediately above `printSheet()`:

```php
/**
 * @param  string  $printMode  Retained while legacy print routes share the canonical working-print renderer.
 */
```

- [ ] **Step 4: Replace `recipes/print.blade.php` with the bench document**

Create `resources/js/print-document.js`:

```js
document.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target : null;

    if (target?.closest('[data-print-document]')) {
        window.print();
    }
});
```

Add the entry beside the existing application JavaScript input:

```js
'resources/js/app.js',
'resources/js/print-document.js',
```

Then change the print layout call to `@vite(['resources/css/app.css', 'resources/js/print-document.js'])`.

Begin the replacement print template with:

```blade
@extends('layouts.print')

@section('title', $formulaDocument['identity']['name'].' · '.__('formula_documents.print.title').' · '.config('app.name'))

@section('content')
    <div class="print-hidden mb-4 flex items-center justify-between gap-3 border border-slate-300 bg-white p-4">
        <a href="{{ route('recipes.saved', ['recipe' => $recipe]) }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium">{{ __('formula_documents.actions.back') }}</a>
        <button type="button" data-print-document class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white">{{ __('formula_documents.actions.print') }}</button>
    </div>
```

Then render the document body in this order:

```blade
<article class="document-sheet mx-auto max-w-5xl bg-white p-6 text-slate-900 print:max-w-none print:p-0">
    <header class="flex items-start justify-between gap-6 border-b border-slate-400 pb-4">
        <div>
            <p class="text-xs font-semibold tracking-[0.12em] text-slate-500 uppercase">{{ __('formula_documents.print.title') }}</p>
            <h1 class="mt-1 text-2xl font-semibold">{{ $formulaDocument['identity']['name'] }}</h1>
        </div>
        <p class="text-right text-xs text-slate-600">{{ number_format($formulaDocument['basis_weight'], 2) }} {{ $formulaDocument['unit'] }}</p>
    </header>

    <section class="mt-4 grid grid-cols-4 border border-slate-400 text-xs">
        @foreach ([
            __('formula_documents.print.batch_number'),
            __('formula_documents.print.date'),
            __('formula_documents.print.made_by'),
            __('formula_documents.print.checked_by'),
        ] as $field)
            <div class="min-h-14 border-r border-slate-300 p-2 last:border-r-0">
                <p class="font-semibold">{{ $field }}</p>
            </div>
        @endforeach
    </section>

    <x-formula-document.table :document="$formulaDocument" class="mt-4" />
    <x-formula-document.results :document="$formulaDocument" class="mt-4" />

    @if (filled($formulaDocument['identity']['description'] ?? null))
        <section class="mt-5 break-inside-avoid">
            <h2 class="text-xs font-semibold uppercase">{{ __('formula_documents.sections.description') }}</h2>
            <div class="prose prose-sm mt-2 max-w-none">{!! str($formulaDocument['identity']['description'])->sanitizeHtml() !!}</div>
        </section>
    @endif

    @if (filled($formulaDocument['identity']['manufacturing_procedure'] ?? null))
        <section class="mt-5 break-inside-avoid">
            <h2 class="text-xs font-semibold uppercase">{{ __('formula_documents.sections.manufacturing_procedure') }}</h2>
            <div class="prose prose-sm mt-2 max-w-none">{!! str($formulaDocument['identity']['manufacturing_procedure'])->sanitizeHtml() !!}</div>
        </section>
    @endif

    @if (filled($formulaDocument['label_text']))
        <section class="mt-5 break-inside-avoid">
            <h2 class="text-xs font-semibold uppercase">{{ __('formula_documents.sections.label') }}</h2>
            <p class="mt-2 text-sm leading-6">{{ $formulaDocument['label_text'] }}</p>
        </section>
    @endif

    <section class="mt-5 grid grid-cols-2 gap-4 break-inside-avoid">
        <div class="min-h-28 border border-slate-400 p-3"><h2 class="text-xs font-semibold uppercase">{{ __('formula_documents.print.observations') }}</h2></div>
        <div class="min-h-28 border border-slate-400 p-3"><h2 class="text-xs font-semibold uppercase">{{ __('formula_documents.print.result') }}</h2></div>
    </section>
</article>
```

After the first article, add the optional second page below. It contains no prose interpretation and is never passed to CSV or Excel.

```blade
@if ($includeAnalysis && is_array($formulaDocument['soap_analysis']))
    <article class="document-sheet mx-auto max-w-5xl break-before-page bg-white p-6 text-slate-900 print:max-w-none print:p-0">
        <h1 class="text-xl font-semibold">{{ __('formula_documents.analysis.title') }}</h1>

        <section class="mt-5">
            <h2 class="text-xs font-semibold tracking-[0.08em] uppercase">{{ __('formula_documents.analysis.qualities') }}</h2>
            <table class="mt-2 w-full border-collapse text-xs">
                <thead><tr><th class="border border-slate-300 p-2 text-left">{{ __('formula_documents.analysis.quality') }}</th><th class="border border-slate-300 p-2 text-right">{{ __('formula_documents.analysis.value') }}</th><th class="border border-slate-300 p-2 text-right">{{ __('formula_documents.analysis.suggested_range') }}</th><th class="border border-slate-300 p-2 text-left">{{ __('formula_documents.analysis.status') }}</th></tr></thead>
                <tbody>
                    @foreach ($formulaDocument['soap_analysis']['qualities'] as $row)
                        <tr><th class="border border-slate-300 p-2 text-left font-medium">{{ $row['label'] }}</th><td class="numeric border border-slate-300 p-2 text-right">{{ number_format($row['value'], 2) }}</td><td class="numeric border border-slate-300 p-2 text-right">{{ $row['range'] }}</td><td class="border border-slate-300 p-2">{{ $row['status'] }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </section>

        <section class="mt-5">
            <h2 class="text-xs font-semibold tracking-[0.08em] uppercase">{{ __('formula_documents.analysis.fatty_acids') }}</h2>
            <table class="mt-2 w-full border-collapse text-xs">
                <thead><tr><th class="border border-slate-300 p-2 text-left">{{ __('formula_documents.analysis.fatty_acid') }}</th><th class="border border-slate-300 p-2 text-right">{{ __('formula_documents.analysis.percentage') }}</th><th class="border border-slate-300 p-2 text-left">{{ __('formula_documents.analysis.contribution') }}</th></tr></thead>
                <tbody>
                    @foreach ($formulaDocument['soap_analysis']['fatty_acids'] as $row)
                        <tr><th class="border border-slate-300 p-2 text-left font-medium">{{ $row['label'] }}</th><td class="numeric border border-slate-300 p-2 text-right">{{ number_format($row['value'], 2) }}%</td><td class="border border-slate-300 p-2">{{ $row['contribution'] }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    </article>
@endif
@endsection
```

Use print-safe Tailwind utilities already present in the project; do not add inline CSS or JavaScript.

- [ ] **Step 5: Run print and authorization tests, then commit**

Run:

```bash
php artisan test --compact tests/Feature/RecipeVersionPagesTest.php
vendor/bin/pint --dirty --format agent
```

Expected: PASS; inaccessible and mutable versions remain 404, and historical-version URLs still print the exact selected saved formula.

```bash
git add app/Http/Controllers/RecipeController.php resources/js/print-document.js resources/views/layouts/print.blade.php resources/views/recipes/print.blade.php tests/Feature/RecipeVersionPagesTest.php vite.config.js
git commit -m "feat: add working formula printout"
```

### Task 4: Replace CSV with the reuse-focused ingredient batch

**Files:**
- Modify: `app/Services/RecipeExportDataBuilder.php`
- Modify: `app/Services/RecipeCsvExporter.php`
- Modify: `tests/Feature/RecipeVersionPagesTest.php`

- [ ] **Step 1: Write the failing CSV contract**

Replace the existing CSV content assertion with:

```php
expect($response->streamedContent())
    ->toContain('Section,Ingredient,"Percentage basis",Percentage,"Scaled weight",Unit,Note')
    ->toContain('"Saponified oils","Olive Oil",oils,100,1000,g,')
    ->toContain('"Lye and water",NaOH,oils,')
    ->toContain('"Lye and water",Water,oils,')
    ->not->toContain('Platform')
    ->not->toContain('User')
    ->not->toContain('INCI name');
```

- [ ] **Step 2: Run the CSV test and verify it fails**

Run:

```bash
php artisan test --compact tests/Feature/RecipeVersionPagesTest.php --filter="simple csv"
```

Expected: FAIL on the old Source and INCI columns.

- [ ] **Step 3: Make export data a projection of `formulaDocument`**

In `RecipeExportDataBuilder::build()`, retain recipe identity and replace independent formula-row construction with:

```php
$document = $viewData['formulaDocument'];

return [
    'document' => $document,
    'ingredientRows' => collect($document['sections'])
        ->flatMap(fn (array $section) => collect($section['rows'])->map(fn (array $row): array => [
            'section' => $section['label'],
            'ingredient' => $row['name'],
            'percentage_basis' => $document['percentage_basis'],
            'percentage' => $row['percentage'],
            'weight' => $row['weight'],
            'unit' => $document['unit'],
            'note' => $row['note'] ?? '',
        ]))
        ->values()
        ->all(),
];
```

Remove `source`, `inci_name`, packaging, costing, summary, and declaration projections from this export builder. The view builder may still retain those keys for existing production UI until the follow-up Product-page plan.

- [ ] **Step 4: Replace the CSV columns**

Use this exact exporter loop:

```php
fputcsv($stream, ['Section', 'Ingredient', 'Percentage basis', 'Percentage', 'Scaled weight', 'Unit', 'Note']);

foreach ($exportData['ingredientRows'] ?? [] as $row) {
    fputcsv($stream, [
        $row['section'] ?? '',
        $row['ingredient'] ?? '',
        $row['percentage_basis'] ?? '',
        $row['percentage'] ?? '',
        $row['weight'] ?? '',
        $row['unit'] ?? '',
        $row['note'] ?? '',
    ]);
}
```

- [ ] **Step 5: Run the CSV and historical-version tests, then commit**

Run:

```bash
php artisan test --compact tests/Feature/RecipeVersionPagesTest.php --filter="csv|historical formula version|latest saved formula"
vendor/bin/pint --dirty --format agent
```

Expected: PASS.

```bash
git add app/Services/RecipeExportDataBuilder.php app/Services/RecipeCsvExporter.php tests/Feature/RecipeVersionPagesTest.php
git commit -m "feat: simplify formula csv export"
```

### Task 5: Replace the six-sheet workbook with reusable formula data

**Files:**
- Modify: `app/Services/RecipeWorkbookExporter.php`
- Modify: `tests/Feature/RecipeVersionPagesTest.php`
- Modify: `tests/Feature/CosmeticRecipeWorkbenchTest.php`

- [ ] **Step 1: Write the failing soap workbook contract**

Change the existing workbook test to inspect every worksheet present in the ZIP and assert:

```php
expect($workbookXml)
    ->toContain('Ingredient batch')
    ->toContain('Soap output')
    ->not->toContain('Summary')
    ->not->toContain('Packaging')
    ->not->toContain('Costing')
    ->not->toContain('INCI Declaration');

expect(substr_count($workbookXml, '<sheet '))->toBe(2)
    ->and($worksheetXml)
    ->toContain('Olive Oil')
    ->toContain('NaOH')
    ->toContain('SODIUM OLIVATE')
    ->toContain('Final INCI')
    ->not->toContain('Soap qualities')
    ->not->toContain('Fatty-acid profile');
```

Use the existing `ZipArchive` helper pattern in `RecipeVersionPagesTest.php`; read worksheet XML for `range(1, 2)` only.

Update `recipeWorkbookXml()` in the same test file so historical soap-workbook assertions also read only the two supported sheets:

```php
$xml = collect(range(1, 2))
    ->map(fn (int $index): string => (string) $zip->getFromName("xl/worksheets/sheet{$index}.xml"))
    ->implode("\n");
```

- [ ] **Step 2: Write the failing cosmetic workbook contract**

In `CosmeticRecipeWorkbenchTest.php`, download the saved cosmetic formula workbook, open it with `ZipArchive`, and assert:

```php
expect($workbookXml)
    ->toContain('Ingredient batch')
    ->not->toContain('Soap output');

expect(substr_count($workbookXml, '<sheet '))->toBe(1)
    ->and($worksheetXml)
    ->toContain('Phase A')
    ->toContain('Water');
```

- [ ] **Step 3: Run both workbook tests and verify they fail**

Run:

```bash
php artisan test --compact tests/Feature/RecipeVersionPagesTest.php --filter="excel workbook"
php artisan test --compact tests/Feature/CosmeticRecipeWorkbenchTest.php --filter="excel workbook"
```

Expected: FAIL because the current exporter creates six sheets for every family.

- [ ] **Step 4: Replace the workbook orchestration**

Keep the existing OpenSpout style helpers, but replace `export()` sheet calls with:

```php
$this->writeIngredientBatchSheet($writer, $exportData);

if (($exportData['document']['family'] ?? null) === 'soap') {
    $this->writeSoapOutputSheet($writer, $exportData['document']);
}
```

Delete the old private writers for Summary, Formula, Packaging, Outputs, INCI Declaration, and Costing after the new tests pass. Do not retain hidden sheets.

- [ ] **Step 5: Implement the Ingredient batch sheet**

Add this private method, reusing `prepareSheet()`, `addTitle()`, `addHeader()`, `addRows()`, `wrapStyle()`, and `numberStyle()`:

```php
private function writeIngredientBatchSheet(Writer $writer, array $exportData): void
{
    $document = $exportData['document'];
    $sheet = $this->prepareSheet(
        $writer->getCurrentSheet(),
        __('formula_documents.exports.ingredient_batch'),
        [24, 34, 20, 16, 18, 10, 40],
    );

    $this->addTitle($writer, (string) data_get($document, 'identity.name', 'Formula'));
    $this->addRows($writer, [[
        data_get($document, 'identity.product_family', ''),
        data_get($document, 'identity.product_type', ''),
        $document['basis_weight'],
        $document['unit'],
    ]]);
    $this->addBlank($writer);
    $this->addHeader($writer, ['Section', 'Ingredient', 'Percentage basis', 'Percentage', 'Scaled weight', 'Unit', 'Note']);
    $this->addRows($writer, collect($exportData['ingredientRows'])
        ->map(fn (array $row): array => [
            $row['section'],
            $row['ingredient'],
            $row['percentage_basis'],
            $row['percentage'],
            $row['weight'],
            $row['unit'],
            $row['note'],
        ])
        ->all(), [
            0 => $this->wrapStyle(),
            1 => $this->wrapStyle(),
            2 => $this->wrapStyle(),
            3 => $this->numberStyle('0.0000'),
            4 => $this->numberStyle('0.0000'),
            6 => $this->wrapStyle(),
        ]);

    $lastRow = count($exportData['ingredientRows']) + 5;
    $sheet->setAutoFilter(new AutoFilter(0, 5, 6, max(5, $lastRow)));
}
```

- [ ] **Step 6: Implement the Soap output sheet**

```php
private function writeSoapOutputSheet(Writer $writer, array $document): void
{
    $output = $document['soap_output'];
    $this->prepareSheet(
        $writer->addNewSheetAndMakeItCurrent(),
        __('formula_documents.exports.soap_output'),
        [34, 24, 20, 18, 44],
    );

    $this->addTitle($writer, __('formula_documents.exports.soap_output'));
    $this->addRows($writer, [
        ['Cured basis', $output['basis_weight'], $document['unit']],
        ['Residual water', $output['residual_water_percentage'], '%'],
    ]);
    $this->addBlank($writer);
    $this->addHeader($writer, ['Component', 'Role', '% cured soap', 'Weight', 'Sources']);
    $this->addRows($writer, collect($output['rows'])
        ->map(fn (array $row): array => [
            $row['name'],
            str($row['role'])->replace('_', ' ')->title()->toString(),
            $row['percentage'],
            $row['weight'],
            implode(', ', $row['sources']),
        ])
        ->all(), [
            0 => $this->wrapStyle(),
            1 => $this->wrapStyle(),
            2 => $this->numberStyle('0.0000'),
            3 => $this->numberStyle('0.0000'),
            4 => $this->wrapStyle(),
        ]);
    $this->addBlank($writer);
    $this->addHeader($writer, ['Final INCI', 'Value']);
    $this->addRows($writer, [['Final INCI', $output['inci']]], $this->labelValueColumnStyles());
}
```

The INCI value comes from the selected list variant and therefore already includes qualifying declarable allergens. Do not create a separate allergen table.

- [ ] **Step 7: Run workbook, CSV, and historical-version tests**

Run:

```bash
php artisan test --compact tests/Feature/RecipeVersionPagesTest.php tests/Feature/CosmeticRecipeWorkbenchTest.php
vendor/bin/pint --dirty --format agent
```

Expected: PASS; the soap workbook has exactly two sheets and the cosmetic workbook exactly one.

```bash
git add app/Services/RecipeWorkbookExporter.php tests/Feature/RecipeVersionPagesTest.php tests/Feature/CosmeticRecipeWorkbenchTest.php
git commit -m "feat: simplify formula excel exports"
```

### Task 6: English review gate, localization ownership, and release verification

**Files:**
- Modify: `config/interface-translations.php`
- Modify: `tests/Feature/InterfaceTranslationFoundationTest.php`
- Modify: `tests/Feature/RecipeVersionPagesTest.php`
- Modify: `tests/Feature/CosmeticRecipeWorkbenchTest.php`
- Modify: `docs/developer/content-audit.md`

- [ ] **Step 1: Add the new group to interface ownership**

Add `formula_documents` to the application-owned group list in `config/interface-translations.php`. Extend `InterfaceTranslationFoundationTest.php`:

```php
expect(app(EnglishTranslationSource::class)->all())
    ->toHaveKey('formula_documents.title')
    ->toHaveKey('formula_documents.sections.lye_water')
    ->toHaveKey('formula_documents.actions.print');
```

- [ ] **Step 2: Run synchronization and the ownership test**

Run:

```bash
php artisan translations:sync
php artisan test --compact tests/Feature/InterfaceTranslationFoundationTest.php
```

Expected: PASS; missing `formula_documents` keys are added locally with blank non-English values and no existing translations are overwritten.

- [ ] **Step 3: Review the English interface before translating**

Open the saved soap and cosmetic Formula Sheets and verify this exact vocabulary:

- Formula Sheet
- Saponified oils
- Lye and water
- Formula additions
- % of oils for soap
- % of formula for cosmetics
- Manufacturing procedure
- Working Formula Sheet
- Ingredient batch
- Soap output

Reject and revise any remaining user-facing `Recipe`, `Backup`, `Published version`, `Platform`, `Core reaction`, or `Post-reaction phases` wording on these output surfaces. Internal route, model, and service names may remain unchanged.

- [ ] **Step 4: Add an English-source regression test**

In `RecipeVersionPagesTest.php`, add:

```php
it('uses approved product and formula terminology on formula outputs', function () {
    [$user, $recipe] = createSavedRecipeVersion();

    $content = $this->actingAs($user)
        ->get(route('recipes.saved', $recipe))
        ->assertSuccessful()
        ->getContent();

    expect($content)
        ->toContain('Formula Sheet')
        ->toContain('Formula additions')
        ->not->toContain('Core reaction')
        ->not->toContain('Post-reaction phases')
        ->not->toContain('Published version');
});
```

- [ ] **Step 5: Record the completed English surface in the existing audit**

Update `docs/developer/content-audit.md` under the authenticated-surface status section with one concise entry:

```markdown
- Formula outputs: the English Formula Sheet, working print, CSV, and Excel vocabulary is approved. Interface copy is owned by `formula_documents.*`; authored product text and scientific INCI data remain outside interface translation. Contextual `fr`, `es`, `de`, `it`, and `nl` drafting begins only after this English browser review.
```

- [ ] **Step 6: Run automated verification**

Run:

```bash
php artisan test --compact tests/Unit/FormulaDocumentBuilderTest.php tests/Unit/SoapCuredOutputBuilderTest.php tests/Feature/RecipeVersionPagesTest.php tests/Feature/CosmeticRecipeWorkbenchTest.php tests/Feature/InterfaceTranslationFoundationTest.php
vendor/bin/pint --dirty --format agent
npm run build
graphify update .
git diff --check
```

Expected: all tests pass; Vite build succeeds; graph refresh succeeds; `git diff --check` prints nothing.

- [ ] **Step 7: Complete manual output checks**

Verify in the local Herd site:

- soap and cosmetic saved Formula Sheets on desktop and narrow mobile widths;
- long ingredient, phase, and note values without column misalignment;
- NaOH-only, KOH-only, and dual-lye formulas;
- working print with and without soap analysis;
- repeated table headers on a multi-page browser print preview;
- missing description, procedure, and label text without empty cards;
- CSV opens with seven columns and no source/INCI column;
- cosmetic XLSX contains only `Ingredient batch`;
- soap XLSX contains only `Ingredient batch` and `Soap output`;
- Excel formulas are absent, so no stale or shifted cell references remain.

- [ ] **Step 8: Commit the English ownership and audit**

```bash
git add config/interface-translations.php docs/developer/content-audit.md tests/Feature/InterfaceTranslationFoundationTest.php tests/Feature/RecipeVersionPagesTest.php tests/Feature/CosmeticRecipeWorkbenchTest.php
git commit -m "docs: finalize formula output copy"
```

Stop here for owner review. After the English wording and rendered layout are approved, draft `fr`, `es`, `de`, `it`, and `nl` into blank local `language_lines` values using the complete key and formula-making context, then review each locale in the rendered surface. Do not add locale PHP files or deployment translation seeders.
