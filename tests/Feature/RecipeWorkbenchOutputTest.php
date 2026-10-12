<?php

use App\Enums\IngredientCategory;
use App\Enums\IngredientSubcategory;
use App\Enums\OwnerType;
use App\Enums\Visibility;
use App\Enums\WorkspaceMemberRole;
use App\Models\Ingredient;
use App\Models\IngredientSapProfile;
use App\Models\MediaAsset;
use App\Models\MediaAssetUsage;
use App\Models\PackagingItem;
use App\Models\Plan;
use App\Models\ProductFamily;
use App\Models\ProductType;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\RecipeVersionCosting;
use App\Models\User;
use App\Models\UserEntitlement;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Support\RichContentAttachmentPaths;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use OpenSpout\Reader\XLSX\Reader;

uses(RefreshDatabase::class);

it('exports the submitted current formula without creating saved records', function (): void {
    $this->travelTo(Carbon::parse('2026-09-28 12:00:00'));

    $user = User::factory()->create(['email_verified_at' => now()]);
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    $user->forceFill(['active_workspace_id' => $workspace->id])->save();
    $family = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
        'calculation_basis' => 'initial_oils',
        'is_active' => true,
    ]);
    $oil = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'display_name' => 'Olive Oil',
        'inci_name' => 'Olea Europaea (Olive) Fruit Oil',
        'is_soap_saponification_trusted' => true,
        'requires_admin_review' => false,
    ]);
    IngredientSapProfile::factory()->create([
        'ingredient_id' => $oil->id,
        'koh_sap_value' => 0.188,
    ]);

    $response = $this->actingAs($user)->postJson(route('recipes.workbench.export'), recipeWorkbenchOutputPayload(
        $workspace,
        $family,
        $oil,
    ));

    $response->assertSuccessful()
        ->assertDownload('working-soap-formula-current-formula-2026-09-28.csv');

    $csvRows = collect(explode("\n", trim($response->streamedContent())))
        ->map(fn (string $line): array => str_getcsv($line));
    $headers = $csvRows->first();
    $formulaIndex = array_search(__('formula_documents.exports.formula'), $headers, true);
    $basisIndex = array_search(__('formula_documents.exports.basis'), $headers, true);
    $basisUnitIndex = array_search(__('formula_documents.exports.formula_basis_unit'), $headers, true);
    $rowUnitIndex = array_search(__('formula_documents.exports.unit'), $headers, true);
    $basisLabelIndex = array_search(__('formula_documents.exports.percentage_basis'), $headers, true);
    $oilRow = $csvRows->first(fn (array $row): bool => ($row[array_search(__('formula_documents.exports.ingredient'), $headers, true)] ?? null) === 'Olive Oil');

    expect($csvRows->flatten())
        ->toContain('Olive Oil')
        ->toContain('750')
        ->and($headers)->toContain(
            __('formula_documents.exports.source'),
            __('formula_documents.exports.generated_at'),
            __('formula_documents.exports.percentage_oils'),
        )
        ->and($headers[$basisUnitIndex])->not->toBe($headers[$rowUnitIndex])
        ->and($oilRow[$formulaIndex])->toBe('Working Soap Formula')
        ->and($oilRow[$basisIndex])->toBe('750')
        ->and($oilRow[$basisUnitIndex])->toBe('g')
        ->and($oilRow[$basisLabelIndex])->toBe(__('formula_documents.exports.basis_oils'))
        ->and(Recipe::withoutGlobalScopes()->count())->toBe(0)
        ->and(RecipeVersion::withoutGlobalScopes()->count())->toBe(0)
        ->and(RecipeVersionCosting::withoutGlobalScopes()->count())->toBe(0);
});

it('serves a current soap workbook with its calculated output worksheet', function (): void {
    $this->travelTo(Carbon::parse('2026-09-28 12:00:00'));
    ['user' => $user, 'workspace' => $workspace, 'family' => $family, 'oil' => $oil] = createWorkbenchOutputFixture();
    $payload = recipeWorkbenchOutputPayload($workspace, $family, $oil);
    $payload['format'] = 'xlsx';

    $response = $this->actingAs($user)->postJson(route('recipes.workbench.export'), $payload);
    $response->assertSuccessful()->assertDownload('working-soap-formula-current-formula-2026-09-28.xlsx');

    $path = tempnam(sys_get_temp_dir(), 'formula-output-test-');
    expect($path)->not->toBeFalse();

    try {
        file_put_contents($path, $response->streamedContent());
        $reader = new Reader;
        $reader->open($path);
        $sheetNames = [];
        $sheetRows = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            $sheetNames[] = $sheet->getName();
            $sheetRows[$sheet->getName()] = collect($sheet->getRowIterator())
                ->map(fn ($row): array => $row->toArray())
                ->all();
        }

        $reader->close();
    } finally {
        @unlink($path);
    }

    expect($sheetNames)->toBe([
        __('formula_documents.exports.ingredient_batch'),
        __('formula_documents.exports.calculated_output'),
    ])
        ->and(collect($sheetRows[__('formula_documents.exports.ingredient_batch')])->flatten())
        ->toContain('Olive Oil')
        ->and(collect($sheetRows[__('formula_documents.exports.calculated_output')])->flatten())
        ->toContain(__('formula_documents.composition.calculated_inci'));
});

it('exports current composition from the captured draft and shares the formula workbook output projection', function (): void {
    $this->travelTo(Carbon::parse('2026-09-28 12:00:00'));
    ['user' => $user, 'workspace' => $workspace, 'family' => $family, 'oil' => $oil] = createWorkbenchOutputFixture();
    $naoh = Ingredient::factory()->create([
        'catalog_key' => 'CH1',
        'category' => IngredientCategory::SoapmakingAlkalis,
        'subcategory' => IngredientSubcategory::SodiumHydroxide,
        'display_name' => 'Sodium hydroxide',
        'inci_name' => 'SODIUM HYDROXIDE',
        'is_active' => true,
    ]);
    $water = Ingredient::factory()->create([
        'category' => IngredientCategory::WaterSolventsCarriers,
        'subcategory' => IngredientSubcategory::Water,
        'display_name' => 'Water',
        'inci_name' => 'AQUA',
        'is_active' => true,
    ]);
    $payload = recipeWorkbenchOutputPayload($workspace, $family, $oil);
    $payload['kind'] = 'composition';
    $payload['format'] = 'xlsx';
    $before = [
        Recipe::withoutGlobalScopes()->count(),
        RecipeVersion::withoutGlobalScopes()->count(),
        RecipeVersionCosting::withoutGlobalScopes()->count(),
    ];
    $compositionResponse = $this->actingAs($user)->postJson(route('recipes.workbench.export'), $payload);
    $compositionResponse->assertSuccessful()
        ->assertDownload('working-soap-formula-current-composition-2026-09-28.xlsx');
    $compositionWorkbook = readWorkbenchOutputWorkbookSheets($compositionResponse->streamedContent());

    $formulaPayload = [...$payload, 'kind' => 'formula'];
    $formulaResponse = $this->actingAs($user)->postJson(route('recipes.workbench.export'), $formulaPayload);
    $formulaResponse->assertSuccessful();
    $formulaWorkbook = readWorkbenchOutputWorkbookSheets($formulaResponse->streamedContent());
    $rawSheet = $compositionWorkbook[__('formula_documents.composition.sheet')];
    $rawCells = collect($rawSheet)->flatten();
    $rawHeaders = collect($rawSheet)->first(fn (array $row): bool => in_array(__('formula_documents.composition.ingredient_id'), $row, true));
    $idIndex = array_search(__('formula_documents.composition.ingredient_id'), $rawHeaders, true);
    $naohRow = collect($rawSheet)->first(fn (array $row): bool => ($row[$idIndex] ?? null) === $naoh->id);
    $waterRow = collect($rawSheet)->first(fn (array $row): bool => ($row[$idIndex] ?? null) === $water->id);

    expect(array_keys($compositionWorkbook))->toBe([
        __('formula_documents.composition.sheet'),
        __('formula_documents.exports.calculated_output'),
    ])
        ->and($rawCells)
        ->toContain('Olive Oil', $oil->displayInciName(), 'Recorded INCI', 'Actual fresh mixture')
        ->not->toContain(__('formula_documents.exports.price_per_kg'), __('formula_documents.exports.currency'))
        ->and($naohRow)->not->toBeNull()
        ->and($waterRow)->not->toBeNull()
        ->and($compositionWorkbook[__('formula_documents.exports.calculated_output')])
        ->toBe($formulaWorkbook[__('formula_documents.exports.calculated_output')])
        ->and([
            Recipe::withoutGlobalScopes()->count(),
            RecipeVersion::withoutGlobalScopes()->count(),
            RecipeVersionCosting::withoutGlobalScopes()->count(),
        ])->toBe($before);
});

it('exports loaded current costing as a typed workbook from the live formula and current prices', function (): void {
    $this->travelTo(Carbon::parse('2026-09-28 12:00:00'));
    ['user' => $user, 'workspace' => $workspace, 'family' => $family, 'oil' => $oil] = createWorkbenchOutputFixture();
    $koh = Ingredient::factory()->create([
        'catalog_key' => 'CH3',
        'category' => IngredientCategory::SoapmakingAlkalis,
        'subcategory' => IngredientSubcategory::PotassiumHydroxide,
        'display_name' => 'Potassium hydroxide',
        'is_active' => true,
    ]);
    $water = Ingredient::factory()->create([
        'category' => IngredientCategory::WaterSolventsCarriers,
        'display_name' => 'Water',
        'inci_name' => 'AQUA',
        'is_active' => true,
    ]);
    $packaging = createPackagingItemForWorkspace([
        'workspace_id' => $workspace->id,
        'name' => 'Catalog jar',
        'unit_cost' => 0.9,
        'currency' => 'EUR',
    ]);
    $packaging->update(['name' => 'Renamed catalog jar']);
    $payload = recipeWorkbenchOutputPayload($workspace, $family, $oil);
    $payload['kind'] = 'costing';
    $payload['format'] = 'xlsx';
    $payload['draft']['lye_type'] = 'koh';
    $payload['draft']['koh_purity_percentage'] = 90;
    $payload['costing'] = [
        'loaded' => true,
        'oil_weight_for_costing' => 0.5,
        'oil_unit_for_costing' => 'kg',
        'units_produced' => 2,
        'currency' => 'EUR',
        'items' => [
            ['ingredient_id' => $oil->id, 'phase_key' => 'saponified_oils', 'position' => 1, 'price_per_kg' => 10],
            ['ingredient_id' => $koh->id, 'phase_key' => 'lye_alkali', 'position' => 1, 'price_per_kg' => 20],
            ['ingredient_id' => $water->id, 'phase_key' => 'implicit_water', 'position' => 1, 'price_per_kg' => 0],
        ],
        'packaging_items' => [[
            'packaging_item_id' => $packaging->id,
            'name' => 'Original jar',
            'unit_cost' => 0.5,
            'components_per_unit' => 1,
        ]],
    ];
    $payload['draft']['packaging_items'] = [[
        'packaging_item_id' => $packaging->id,
        'name' => 'Original jar',
        'components_per_unit' => 1,
    ]];

    $response = $this->actingAs($user)->postJson(route('recipes.workbench.export'), $payload);
    $response->assertSuccessful()->assertDownload('working-soap-formula-current-costing-2026-09-28.xlsx');

    $path = $response->baseResponse->getFile()->getPathname();

    try {
        $reader = new Reader;
        $reader->open($path);
        $sheets = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            $sheets[$sheet->getName()] = collect($sheet->getRowIterator())
                ->map(fn ($row): array => $row->toArray())
                ->all();
        }

        $reader->close();
    } finally {
        @unlink($path);
    }

    $ingredientSheet = $sheets[__('formula_documents.exports.costing_ingredients')];
    $ingredientHeaders = collect($ingredientSheet)->first(fn (array $row): bool => in_array(__('formula_documents.exports.ingredient'), $row, true));
    $ingredientHeaderIndex = array_search(__('formula_documents.exports.ingredient'), $ingredientHeaders, true);
    $ingredientRow = collect($ingredientSheet)->first(fn (array $row): bool => ($row[$ingredientHeaderIndex] ?? null) === 'Olive Oil');
    $kohLabel = __('workbench.costing.ingredients.koh_with_purity', ['name' => 'Potassium hydroxide', 'purity' => '90']);
    $kohRow = collect($ingredientSheet)->first(fn (array $row): bool => ($row[$ingredientHeaderIndex] ?? null) === $kohLabel);
    $packagingSheet = $sheets[__('formula_documents.exports.costing_packaging')];
    $packagingHeaders = collect($packagingSheet)->first(fn (array $row): bool => in_array(__('formula_documents.exports.packaging'), $row, true)
        && in_array(__('formula_documents.exports.batch_cost'), $row, true));
    $packagingNameIndex = array_search(__('formula_documents.exports.packaging'), $packagingHeaders, true);
    $packagingRows = collect($packagingSheet)->filter(fn (array $row): bool => ($row[$packagingNameIndex] ?? null) === 'Original jar');
    $packagingRow = $packagingRows->first();
    $summarySheet = $sheets[__('formula_documents.exports.costing_summary')];
    $summaryHeaders = collect($summarySheet)->first(fn (array $row): bool => in_array(__('formula_documents.exports.cost_item'), $row, true));
    $summaryItemIndex = array_search(__('formula_documents.exports.cost_item'), $summaryHeaders, true);
    $summaryAmountIndex = array_search(__('formula_documents.exports.amount'), $summaryHeaders, true);
    $summaryRows = collect($summarySheet)->filter(fn (array $row): bool => in_array($row[$summaryItemIndex] ?? null, [
        __('formula_documents.exports.ingredient_total'),
        __('formula_documents.exports.packaging_total'),
        __('formula_documents.exports.total_cost'),
        __('formula_documents.exports.cost_per_unit'),
    ], true))->keyBy($summaryItemIndex);

    expect(array_keys($sheets))->toBe([
        __('formula_documents.exports.costing_ingredients'),
        __('formula_documents.exports.costing_packaging'),
        __('formula_documents.exports.costing_summary'),
    ])
        ->and($ingredientRow)->not->toBeNull()
        ->and($ingredientRow[array_search(__('formula_documents.exports.quantity'), $ingredientHeaders, true)])->toBe(0.5)
        ->and($ingredientRow[array_search(__('formula_documents.exports.unit'), $ingredientHeaders, true)])->toBe('kg')
        ->and($ingredientRow[array_search(__('formula_documents.exports.price_per_kg'), $ingredientHeaders, true)])->toBe(10)
        ->and($ingredientRow[array_search(__('formula_documents.exports.price_currency'), $ingredientHeaders, true)])->toBe('EUR')
        ->and($ingredientRow[array_search(__('formula_documents.exports.line_cost'), $ingredientHeaders, true)])->toBe(5)
        ->and($kohRow)->not->toBeNull()
        ->and($kohRow[array_search(__('formula_documents.exports.price_per_kg'), $ingredientHeaders, true)])->toBe(20)
        ->and($packagingRow)->not->toBeNull()
        ->and($packagingRows)->toHaveCount(1)
        ->and($packagingRow[array_search(__('formula_documents.exports.batch_cost'), $packagingHeaders, true)])->toBe(1)
        ->and($summaryRows->get(__('formula_documents.exports.packaging_total'))[$summaryAmountIndex])->toBe(1)
        ->and($summaryRows->get(__('formula_documents.exports.total_cost'))[$summaryAmountIndex])->toBeGreaterThan(6)
        ->and(Recipe::withoutGlobalScopes()->count())->toBe(0)
        ->and(RecipeVersion::withoutGlobalScopes()->count())->toBe(0)
        ->and(RecipeVersionCosting::withoutGlobalScopes()->count())->toBe(0);
});

it('selects costing print sections by context and does not save captured prices', function (): void {
    ['user' => $user, 'workspace' => $workspace, 'family' => $family, 'oil' => $oil] = createWorkbenchOutputFixture();
    $productType = ProductType::factory()->create(['product_family_id' => $family->id, 'slug' => 'bar-soap']);
    $koh = Ingredient::factory()->create([
        'catalog_key' => 'CH3',
        'category' => IngredientCategory::SoapmakingAlkalis,
        'subcategory' => IngredientSubcategory::PotassiumHydroxide,
        'display_name' => 'Potassium hydroxide',
        'is_active' => true,
    ]);
    $payload = recipeWorkbenchOutputPayload($workspace, $family, $oil);
    $payload['draft']['product_type_id'] = $productType->id;
    $payload['draft']['lye_type'] = 'koh';
    $payload['draft']['koh_purity_percentage'] = 100;
    $payload['costing'] = [
        'loaded' => true,
        'oil_weight_for_costing' => 500,
        'oil_unit_for_costing' => 'g',
        'units_produced' => 2,
        'currency' => 'CHF',
        'items' => [
            ['ingredient_id' => $oil->id, 'phase_key' => 'saponified_oils', 'position' => 1, 'price_per_kg' => 7],
            ['ingredient_id' => $koh->id, 'phase_key' => 'lye_alkali', 'position' => 1, 'price_per_kg' => 0],
        ],
        'packaging_items' => [],
    ];
    $payload['manufacturing_instructions'] = '<p>Blend and pour.</p>';
    $domainCounts = [
        Recipe::withoutGlobalScopes()->count(),
        RecipeVersion::withoutGlobalScopes()->count(),
        RecipeVersionCosting::withoutGlobalScopes()->count(),
    ];

    foreach ([
        'formula' => ['formula', 'procedure', 'label', 'notes'],
        'instructions' => ['formula', 'procedure'],
        'costing' => ['costing'],
        'output' => ['label', 'composition'],
    ] as $context => $expectedSections) {
        $contextPayload = [...$payload, 'print_context' => $context];
        $response = $this->actingAs($user)->postJson(route('recipes.workbench.print'), $contextPayload)->assertSuccessful();
        $response->assertViewHas('selectedSections', $expectedSections)
            ->assertViewHas('returnUrl', route('recipes.create', ['family' => 'soap', 'type' => 'bar-soap']).'#'.$context)
            ->assertSee('data-return-to-workbench', false);

        if ($context === 'costing') {
            $response->assertViewHas('costingProjection', fn (array $projection): bool => $projection['currency'] === 'CHF'
                && $projection['basis_weight'] === 500.0
                && $projection['ingredient_rows'][0]['line_cost'] === 3.5);
            $response->assertSee(__('workbench.costing.ingredients.koh_with_purity', [
                'name' => 'Potassium hydroxide',
                'purity' => '100',
            ]));
        }

        if ($context === 'output') {
            $response->assertViewHas('compositionExportData', fn (array $composition): bool => $composition['family'] === 'soap')
                ->assertSee(__('formula_documents.composition.review_note'));
        }

        expect($response->getContent())
            ->toMatch($context === 'costing'
                ? '/data-formula-section="costing"(?:(?!\bhidden\b)[^>])*>/s'
                : '/data-formula-section="costing"[^>]*\bhidden\b/s');
    }

    expect([
        Recipe::withoutGlobalScopes()->count(),
        RecipeVersion::withoutGlobalScopes()->count(),
        RecipeVersionCosting::withoutGlobalScopes()->count(),
    ])->toBe($domainCounts);
});

it('converts the current formula fallback basis into its captured costing unit', function (): void {
    ['user' => $user, 'workspace' => $workspace, 'family' => $family, 'oil' => $oil] = createWorkbenchOutputFixture();
    $naoh = Ingredient::factory()->create([
        'catalog_key' => 'CH1',
        'category' => IngredientCategory::SoapmakingAlkalis,
        'subcategory' => IngredientSubcategory::SodiumHydroxide,
        'display_name' => 'Sodium hydroxide',
        'is_active' => true,
    ]);
    $payload = recipeWorkbenchOutputPayload($workspace, $family, $oil);
    $payload['draft']['oil_weight'] = 1000;
    $payload['costing'] = [
        'loaded' => true,
        'oil_weight_for_costing' => null,
        'oil_unit_for_costing' => 'kg',
        'units_produced' => null,
        'currency' => 'EUR',
        'items' => [
            ['ingredient_id' => $oil->id, 'phase_key' => 'saponified_oils', 'position' => 1, 'price_per_kg' => 10],
            ['ingredient_id' => $naoh->id, 'phase_key' => 'lye_alkali', 'position' => 1, 'price_per_kg' => 0],
        ],
        'packaging_items' => [],
    ];

    $this->actingAs($user)
        ->postJson(route('recipes.workbench.print'), [...$payload, 'print_context' => 'costing'])
        ->assertSuccessful()
        ->assertViewHas('costingProjection', fn (array $projection): bool => $projection['basis_weight'] === 1.0
            && $projection['basis_unit'] === 'kg'
            && $projection['ingredient_rows'][0]['quantity'] === 1.0
            && $projection['ingredient_rows'][0]['line_cost'] === 10.0
            && $projection['packaging_rows'] === []
            && $projection['packaging_total'] === 0.0
            && $projection['total_cost'] === 10.0
            && $projection['cost_per_unit'] === null);
});

it('uses the submitted weight when weight editing mode is authoritative', function (): void {
    ['user' => $user, 'workspace' => $workspace, 'family' => $family, 'oil' => $oil] = createWorkbenchOutputFixture();
    $payload = recipeWorkbenchOutputPayload($workspace, $family, $oil);
    $payload['draft']['editing_mode'] = 'weight';
    $payload['draft']['phase_items']['saponified_oils'][0]['percentage'] = 100;
    $payload['draft']['phase_items']['saponified_oils'][0]['weight'] = 500;

    $response = $this->actingAs($user)->postJson(route('recipes.workbench.export'), $payload);
    $response->assertSuccessful();

    $ingredientRow = collect(explode("\n", trim($response->streamedContent())))
        ->map(fn (string $line): array => str_getcsv($line))
        ->first(fn (array $row): bool => ($row[10] ?? null) === 'Olive Oil');

    expect($ingredientRow)->not->toBeNull()
        ->and((float) $ingredientRow[11])->toBe(66.6667)
        ->and((float) $ingredientRow[12])->toBe(500.0);
});

it('accepts context-valid negative KOH superfat and rejects invalid lye concentration', function (): void {
    ['user' => $user, 'workspace' => $workspace, 'family' => $family, 'oil' => $oil] = createWorkbenchOutputFixture();
    $payload = recipeWorkbenchOutputPayload($workspace, $family, $oil);
    $payload['draft']['lye_type'] = 'koh';
    $payload['draft']['superfat'] = -5;

    $this->actingAs($user)->postJson(route('recipes.workbench.export'), $payload)->assertSuccessful();

    $payload['draft']['lye_type'] = 'dual';
    $payload['draft']['superfat'] = 5;
    $dualLyeResponse = $this->postJson(route('recipes.workbench.export'), $payload);
    $dualLyeResponse->assertSuccessful();
    expect($dualLyeResponse->streamedContent())
        ->toContain(__('formula_documents.ingredients.naoh'))
        ->toContain(__('formula_documents.ingredients.koh'));

    $payload['draft']['lye_type'] = 'naoh';
    $payload['draft']['water_mode'] = 'lye_concentration';
    $payload['draft']['water_value'] = 100;

    $this->postJson(route('recipes.workbench.export'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('draft');
});

it('rejects a nonzero formula row without a resolvable ingredient identity', function (): void {
    ['user' => $user, 'workspace' => $workspace, 'family' => $family, 'oil' => $oil] = createWorkbenchOutputFixture();
    $payload = recipeWorkbenchOutputPayload($workspace, $family, $oil);
    unset($payload['draft']['phase_items']['saponified_oils'][0]['ingredient_id']);

    $this->actingAs($user)->postJson(route('recipes.workbench.export'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('draft.phase_items.saponified_oils');
});

it('bounds combined output rows and the raw request size', function (): void {
    ['user' => $user, 'workspace' => $workspace, 'family' => $family, 'oil' => $oil] = createWorkbenchOutputFixture();
    $payload = recipeWorkbenchOutputPayload($workspace, $family, $oil);
    $payload['draft']['packaging_items'][] = [
        'packaging_item_id' => null,
        'name' => 'Unselected container',
        'components_per_unit' => 1,
        'notes' => null,
    ];
    $payload['costing']['items'][] = [
        'ingredient_id' => null,
        'phase_key' => 'saponified_oils',
        'position' => 2,
        'price_per_kg' => null,
    ];
    $payload['costing']['packaging_items'][] = [
        'packaging_item_id' => null,
        'name' => 'Unselected costing container',
        'unit_cost' => null,
        'components_per_unit' => 1,
    ];
    config()->set('workspaces.formula_output_limits.formula_rows', 3);

    $this->actingAs($user)->postJson(route('recipes.workbench.export'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('draft');

    config()->set('workspaces.formula_output_limits.formula_rows', 1000);
    config()->set('workspaces.formula_output_limits.payload_bytes', 128);
    $this->postJson(route('recipes.workbench.export'), recipeWorkbenchOutputPayload($workspace, $family, $oil))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('payload');

    config()->set('workspaces.formula_output_limits.payload_bytes', 2 * 1024 * 1024);
    $unsupportedPayload = recipeWorkbenchOutputPayload($workspace, $family, $oil);
    $unsupportedPayload['kind'] = 'costing';
    $this->postJson(route('recipes.workbench.export'), $unsupportedPayload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('costing');

    $unsupportedPayload['costing']['loaded'] = true;
    $this->postJson(route('recipes.workbench.export'), $unsupportedPayload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('format');

    $compositionCsv = recipeWorkbenchOutputPayload($workspace, $family, $oil);
    $compositionCsv['kind'] = 'composition';
    $this->postJson(route('recipes.workbench.export'), $compositionCsv)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('format');
});

it('preserves tiny float values through the real current output boundary', function (): void {
    ['user' => $user, 'workspace' => $workspace, 'family' => $family, 'oil' => $oil] = createWorkbenchOutputFixture();
    $payload = recipeWorkbenchOutputPayload($workspace, $family, $oil);
    $payload['draft']['oil_unit'] = 'kg';
    $payload['draft']['oil_weight'] = 0.00001;
    $payload['draft']['phase_items']['saponified_oils'][0]['percentage'] = 100;
    $payload['draft']['phase_items']['saponified_oils'][0]['weight'] = 0.00001;

    $this->actingAs($user)
        ->postJson(route('recipes.workbench.print'), $payload)
        ->assertSuccessful()
        ->assertViewHas('compositionExportData', function (array $composition): bool {
            $oilRow = collect($composition['incorporated']['raw_material_rows'])
                ->firstWhere('name', 'Olive Oil');

            return $oilRow['quantity'] === 0.00001
                && $composition['incorporated']['actual_mass'] > 0;
        })
        ->assertViewHas('snapshot', fn (array $snapshot): bool => $snapshot['draft']['phaseItems']['saponified_oils'][0]['weight'] === 0.00001);
});

it('checks foreign ingredient identities in zero rows, dilution rows, costing rows, and output settings', function (): void {
    ['user' => $user, 'workspace' => $workspace, 'family' => $family, 'oil' => $oil] = createWorkbenchOutputFixture();
    $foreignOwner = User::factory()->create();
    $foreignWorkspace = Workspace::factory()->for($foreignOwner, 'owner')->create();
    $foreignIngredient = Ingredient::factory()->create([
        'display_name' => 'Foreign material',
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $foreignWorkspace->id,
        'workspace_id' => $foreignWorkspace->id,
        'visibility' => Visibility::Workspace,
    ]);
    $foreignPackaging = PackagingItem::factory()->for($foreignWorkspace)->create();
    $mutators = [
        fn (array &$payload) => $payload['draft']['phase_items']['additives'][] = [
            'id' => 'foreign-zero-row',
            'ingredient_id' => $foreignIngredient->id,
            'percentage' => 0,
            'weight' => 0,
        ],
        fn (array &$payload) => $payload['draft']['phase_items']['lye_water'][] = [
            'id' => 'foreign-dilution-row',
            'ingredient_id' => $foreignIngredient->id,
            'percentage' => 0,
            'weight' => 0,
        ],
        fn (array &$payload) => $payload['costing']['items'][] = [
            'ingredient_id' => $foreignIngredient->id,
            'phase_key' => 'additives',
            'position' => 9,
            'price_per_kg' => null,
        ],
        fn (array &$payload) => $payload['draft']['output_ingredient_id'] = $foreignIngredient->id,
        fn (array &$payload) => $payload['draft']['packaging_items'][] = [
            'packaging_item_id' => $foreignPackaging->id,
            'name' => 'Untrusted packaging label',
            'components_per_unit' => 1,
        ],
    ];

    foreach ($mutators as $mutate) {
        $payload = recipeWorkbenchOutputPayload($workspace, $family, $oil);
        $mutate($payload);

        $this->actingAs($user)->postJson(route('recipes.workbench.export'), $payload)->assertForbidden();
    }
});

it('preserves private dilution material occurrences after allocation skips zero rows', function (): void {
    ['user' => $user, 'workspace' => $workspace, 'family' => $family, 'oil' => $oil] = createWorkbenchOutputFixture();
    $dilutionMaterial = Ingredient::factory()->create([
        'category' => IngredientCategory::WaterSolventsCarriers,
        'display_name' => 'Workspace dilution liquid',
        'inci_name' => 'Dilutionis Liquidus',
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Workspace,
    ]);
    $payload = recipeWorkbenchOutputPayload($workspace, $family, $oil);
    $payload['draft']['phase_items']['lye_water'] = [
        [
            'id' => 'zero-occurrence',
            'ingredient_id' => $dilutionMaterial->id,
            'percentage' => 0,
            'weight' => 0,
            'note' => 'Zero occurrence',
        ],
        [
            'id' => 'positive-occurrence',
            'ingredient_id' => $dilutionMaterial->id,
            'percentage' => 25,
            'weight' => 11,
            'note' => 'Current dilution occurrence',
        ],
    ];

    $response = $this->actingAs($user)->postJson(route('recipes.workbench.export'), $payload);
    $response->assertSuccessful();

    $dilutionRow = collect(explode("\n", trim($response->streamedContent())))
        ->map(fn (string $line): array => str_getcsv($line))
        ->first(fn (array $row): bool => ($row[10] ?? null) === 'Workspace dilution liquid');

    expect($dilutionRow)->not->toBeNull()
        ->and((float) $dilutionRow[12])->toBe(71.25)
        ->and($dilutionRow[14])->toBe('Current dilution occurrence')
        ->and($response->streamedContent())->not->toContain('Zero occurrence');
});

it('exports incomplete cosmetic formulas and derives missing named phases once', function (): void {
    ['user' => $user, 'workspace' => $workspace, 'oil' => $oil] = createWorkbenchOutputFixture('cosmetic', 'total_formula');
    $family = ProductFamily::query()->where('slug', 'cosmetic')->firstOrFail();
    $payload = recipeWorkbenchOutputPayload($workspace, $family, $oil);
    $payload['draft']['manufacturing_mode'] = 'blend_only';
    $payload['draft']['phases'] = [];
    $payload['draft']['phase_items'] = [
        'custom_blend' => [[
            'id' => 'cosmetic-row',
            'ingredient_id' => $oil->id,
            'percentage' => 20,
            'weight' => 150,
            'note' => 'Still being formulated',
        ]],
    ];

    $response = $this->actingAs($user)->postJson(route('recipes.workbench.export'), $payload);
    $response->assertSuccessful();

    expect($response->streamedContent())
        ->toContain('Olive Oil')
        ->toContain('150')
        ->toContain('Still being formulated');

    $payload['draft']['manufacturing_mode'] = 'saponify_in_formula';
    $this->postJson(route('recipes.workbench.export'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('draft.manufacturing_mode');

    $payload['draft']['manufacturing_mode'] = 'blend_only';
    $payload['draft']['phases'] = [
        ['key' => 'custom_blend', 'name' => 'Blend'],
        ['key' => 'custom_blend', 'name' => 'Duplicate Blend'],
    ];
    $this->postJson(route('recipes.workbench.export'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('draft.phases');
});

it('allows a viewer to print the current working copy of a locked saved recipe without changing it', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $plan = Plan::factory()->create(['allows_collaboration' => true]);
    UserEntitlement::factory()->for($owner)->for($plan)->create();
    $viewer = User::factory()->create(['email_verified_at' => now(), 'active_workspace_id' => $workspace->id]);
    WorkspaceMember::factory()->for($workspace)->for($viewer)->create(['role' => WorkspaceMemberRole::Viewer]);
    $family = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
        'calculation_basis' => 'initial_oils',
        'is_active' => true,
    ]);
    $oil = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'display_name' => 'Olive Oil',
        'inci_name' => 'Olea Europaea (Olive) Fruit Oil',
        'is_soap_saponification_trusted' => true,
        'requires_admin_review' => false,
    ]);
    IngredientSapProfile::factory()->create(['ingredient_id' => $oil->id, 'koh_sap_value' => 0.188]);
    $recipe = Recipe::factory()->for($family, 'productFamily')->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Workspace,
        'name' => 'Saved Soap Formula',
        'locked_at' => now(),
    ]);
    $savedVersion = RecipeVersion::factory()->for($recipe)->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'batch_size' => 1000,
        'batch_mass_grams' => '1000.000000000',
        'name' => 'Saved Soap Formula',
    ]);
    $payload = recipeWorkbenchOutputPayload($workspace, $family, $oil);
    $payload['recipe'] = $recipe->public_id;
    $payload['draft']['name'] = 'Current Working Soap';
    $payload['draft']['oil_weight'] = 750;
    $payload['manufacturing_instructions'] = '<p>Current draft procedure</p>';
    $originalRecipe = $recipe->fresh();

    $response = $this->actingAs($viewer)->postJson(route('recipes.workbench.print'), $payload);
    $response->assertSuccessful()
        ->assertDontSee('<dt class="font-semibold text-slate-600">Source</dt>', false)
        ->assertDontSee('<dt class="font-semibold text-slate-600">Version</dt>', false)
        ->assertSee('Current Working Soap')
        ->assertSee('Current draft procedure')
        ->assertSee('750')
        ->assertViewHas('formulaDocument', fn (array $document): bool => $document['identity']['state'] === 'current_working_copy'
            && $document['metadata']['version_number'] === null
            && $document['basis_weight'] === 750.0)
        ->assertViewHas('returnUrl', route('recipes.edit', ['recipe' => $recipe]).'#formula')
        ->assertSee('data-return-to-workbench', false);

    expect($recipe->fresh()->edit_revision)->toBe($originalRecipe->edit_revision)
        ->and($recipe->fresh()->updated_at)->toEqual($originalRecipe->updated_at)
        ->and((float) $savedVersion->fresh()->batch_size)->toBe(1000.0)
        ->and(RecipeVersion::withoutGlobalScopes()->where('recipe_id', $recipe->id)->count())->toBe(1)
        ->and(RecipeVersionCosting::withoutGlobalScopes()->count())->toBe(0);
});

it('rejects output when the active workspace has switched or membership was revoked', function (): void {
    ['user' => $user, 'workspace' => $workspace, 'family' => $family, 'oil' => $oil] = createWorkbenchOutputFixture();
    $otherWorkspace = Workspace::factory()->for($user, 'owner')->create();
    $payload = recipeWorkbenchOutputPayload($workspace, $family, $oil);

    $user->forceFill(['active_workspace_id' => $otherWorkspace->id])->save();
    $this->actingAs($user)->postJson(route('recipes.workbench.export'), $payload)
        ->assertStatus(409);

    $owner = User::factory()->create();
    $sharedWorkspace = Workspace::factory()->for($owner, 'owner')->create();
    $plan = Plan::factory()->create(['allows_collaboration' => true]);
    UserEntitlement::factory()->for($owner)->for($plan)->create();
    $member = User::factory()->create(['email_verified_at' => now(), 'active_workspace_id' => $sharedWorkspace->id]);
    $membership = WorkspaceMember::factory()->for($sharedWorkspace)->for($member)->create([
        'role' => WorkspaceMemberRole::Editor,
    ]);
    $sharedPayload = recipeWorkbenchOutputPayload($sharedWorkspace, $family, $oil);
    $membership->delete();

    $this->actingAs($member)->postJson(route('recipes.workbench.export'), $sharedPayload)->assertForbidden();
});

it('authorizes pending procedure images and rejects malformed, foreign, or legacy file identities', function (): void {
    ['user' => $user, 'workspace' => $workspace, 'family' => $family, 'oil' => $oil] = createWorkbenchOutputFixture();
    $asset = MediaAsset::factory()->ready()->create(['workspace_id' => $workspace->id]);
    $payload = recipeWorkbenchOutputPayload($workspace, $family, $oil);
    $payload['manufacturing_instructions'] = [
        'type' => 'doc',
        'content' => [[
            'type' => 'image',
            'attrs' => [
                'id' => RichContentAttachmentPaths::mediaAssetIdentity($asset->public_id),
                'src' => '/pending-image-source',
            ],
        ]],
    ];
    $tipTapInstructions = $payload['manufacturing_instructions'];

    $this->actingAs($user)->postJson(route('recipes.workbench.print'), $payload)->assertSuccessful();
    expect(MediaAssetUsage::query()->count())->toBe(0);

    $payload['manufacturing_instructions'] = sprintf(
        "<img data-id='%s' src='/pending-image-source'>",
        RichContentAttachmentPaths::mediaAssetIdentity($asset->public_id),
    );
    $this->postJson(route('recipes.workbench.print'), $payload)->assertSuccessful();

    $foreignOwner = User::factory()->create();
    $foreignWorkspace = Workspace::factory()->for($foreignOwner, 'owner')->create();
    $foreignAsset = MediaAsset::factory()->ready()->create(['workspace_id' => $foreignWorkspace->id]);
    $payload['manufacturing_instructions'] = sprintf(
        "<img data-id='%s' src='/pending-image-source'>",
        RichContentAttachmentPaths::mediaAssetIdentity($foreignAsset->public_id),
    );
    $this->postJson(route('recipes.workbench.print'), $payload)->assertForbidden();

    foreach ([
        "<img data-id='media-asset:not-a-uuid' src='/pending-image-source'>",
        '<img data-id=media-asset:not-a-uuid src="/pending-image-source">',
    ] as $malformedImage) {
        $payload['manufacturing_instructions'] = $malformedImage;
        $this->postJson(route('recipes.workbench.print'), $payload)->assertUnprocessable()
            ->assertJsonValidationErrors('manufacturing_instructions');
    }

    $payload['manufacturing_instructions'] = $tipTapInstructions;
    $payload['manufacturing_instructions']['content'][0]['attrs']['id'] = RichContentAttachmentPaths::mediaAssetIdentity($foreignAsset->public_id);
    $this->postJson(route('recipes.workbench.print'), $payload)->assertForbidden();

    $payload['manufacturing_instructions']['content'][0]['attrs']['id'] = 'media-asset:not-a-uuid';
    $this->postJson(route('recipes.workbench.print'), $payload)->assertUnprocessable()
        ->assertJsonValidationErrors('manufacturing_instructions');

    $payload['manufacturing_instructions']['content'][0]['attrs'] = ['src' => 'https://private.example.test/procedure.jpg'];
    $this->postJson(route('recipes.workbench.print'), $payload)->assertForbidden();
    expect(MediaAssetUsage::query()->count())->toBe(0);
});

it('rejects malformed structured procedure documents and reports the procedure size limit accurately', function (): void {
    ['user' => $user, 'workspace' => $workspace, 'family' => $family, 'oil' => $oil] = createWorkbenchOutputFixture();
    $payload = recipeWorkbenchOutputPayload($workspace, $family, $oil);

    foreach ([
        ['unexpected' => 'value'],
        [
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'content' => 'not-a-node-list',
            ]],
        ],
    ] as $malformedDocument) {
        $payload['manufacturing_instructions'] = $malformedDocument;

        $this->actingAs($user)->postJson(route('recipes.workbench.print'), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('manufacturing_instructions');
    }

    $payload['manufacturing_instructions'] = str_repeat('x', 512_001);
    $response = $this->postJson(route('recipes.workbench.print'), $payload)->assertUnprocessable();

    expect($response->json('errors.manufacturing_instructions.0'))
        ->toBe(__('formula_documents.validation.output_payload_too_large'))
        ->not->toContain('512 characters');
});

it('preserves the legacy IFRA category selection mode in the output snapshot', function (): void {
    ['user' => $user, 'workspace' => $workspace, 'family' => $family, 'oil' => $oil] = createWorkbenchOutputFixture();
    $payload = recipeWorkbenchOutputPayload($workspace, $family, $oil);
    $payload['draft']['ifra_category_selection_mode'] = 'legacy';

    $this->actingAs($user)->postJson(route('recipes.workbench.print'), $payload)
        ->assertSuccessful()
        ->assertViewHas('snapshot', fn (array $snapshot): bool => $snapshot['draft']['ifraCategorySelectionMode'] === 'legacy');
});

/**
 * @return array<string, mixed>
 */
function recipeWorkbenchOutputPayload(Workspace $workspace, ProductFamily $family, Ingredient $oil): array
{
    return [
        'workspace' => $workspace->public_id,
        'product_family' => $family->slug,
        'kind' => 'formula',
        'format' => 'csv',
        'draft' => [
            'name' => 'Working Soap Formula',
            'oil_unit' => 'g',
            'oil_weight' => 750,
            'manufacturing_mode' => 'saponify_in_formula',
            'exposure_mode' => 'rinse_off',
            'regulatory_regime' => 'eu',
            'product_type_id' => null,
            'editing_mode' => 'percentage',
            'lye_type' => 'naoh',
            'koh_purity_percentage' => 90,
            'dual_lye_koh_percentage' => 40,
            'water_mode' => 'percent_of_oils',
            'water_value' => 38,
            'superfat' => 5,
            'ifra_category_selection_mode' => 'automatic',
            'ifra_product_category_id' => null,
            'final_ingredient_list' => null,
            'final_ingredient_list_basis_hash' => null,
            'final_plain_ingredient_list' => null,
            'final_plain_ingredient_list_basis_hash' => null,
            'phases' => [
                ['key' => 'saponified_oils', 'name' => 'Saponified Oils'],
                ['key' => 'additives', 'name' => 'Additives'],
                ['key' => 'fragrance', 'name' => 'Fragrance'],
            ],
            'phase_items' => [
                'saponified_oils' => [[
                    'id' => 'oil-row',
                    'ingredient_id' => $oil->id,
                    'percentage' => 100,
                    'weight' => 750,
                    'note' => 'Current working note',
                ]],
                'additives' => [],
                'fragrance' => [],
                'lye_water' => [],
            ],
            'packaging_items' => [],
            'production_output_type' => 'finished_product',
            'output_ingredient_id' => null,
            'ready_delay_days' => null,
            'product_reference' => null,
            'nominal_content_value' => null,
            'nominal_content_unit' => null,
        ],
        'manufacturing_instructions' => '',
        'costing' => [
            'oil_weight_for_costing' => null,
            'oil_unit_for_costing' => 'g',
            'units_produced' => null,
            'currency' => 'EUR',
            'items' => [],
            'packaging_items' => [],
        ],
        'print_context' => 'formula',
    ];
}

/**
 * @return array{user: User, workspace: Workspace, family: ProductFamily, oil: Ingredient}
 */
function createWorkbenchOutputFixture(string $familySlug = 'soap', string $calculationBasis = 'initial_oils'): array
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    $user->forceFill(['active_workspace_id' => $workspace->id])->save();
    $family = ProductFamily::factory()->create([
        'slug' => $familySlug,
        'name' => ucfirst($familySlug),
        'calculation_basis' => $calculationBasis,
        'is_active' => true,
    ]);
    $oil = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'display_name' => 'Olive Oil',
        'inci_name' => 'Olea Europaea (Olive) Fruit Oil',
        'is_soap_saponification_trusted' => true,
        'requires_admin_review' => false,
    ]);
    IngredientSapProfile::factory()->create([
        'ingredient_id' => $oil->id,
        'koh_sap_value' => 0.188,
    ]);

    return compact('user', 'workspace', 'family', 'oil');
}

/** @return array<string, list<array<int, mixed>>> */
function readWorkbenchOutputWorkbookSheets(string $contents): array
{
    $path = tempnam(sys_get_temp_dir(), 'workbench-output-test-');
    expect($path)->not->toBeFalse();

    try {
        file_put_contents($path, $contents);
        $reader = new Reader;
        $reader->open($path);
        $sheets = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            $sheets[$sheet->getName()] = collect($sheet->getRowIterator())
                ->map(fn ($row): array => $row->toArray())
                ->all();
        }

        $reader->close();

        return $sheets;
    } finally {
        @unlink($path);
    }
}
