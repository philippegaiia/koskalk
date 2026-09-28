<?php

use App\Enums\IngredientCategory;
use App\Enums\IngredientSubcategory;
use App\Enums\MassDisplaySystem;
use App\Enums\OwnerType;
use App\Enums\WorkspaceMemberRole;
use App\Models\CurrentMaterialPrice;
use App\Models\Ingredient;
use App\Models\IngredientTranslation;
use App\Models\Plan;
use App\Models\SupportedLocale;
use App\Models\User;
use App\Models\UserEntitlement;
use App\Models\Workspace;
use App\Models\WorkspaceIngredientCode;
use App\Models\WorkspaceMember;
use App\Services\PriceBasisConverter;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Reader\XLSX\Reader;

uses(RefreshDatabase::class);

it('exports every matching public ingredient in sorted order as CSV', function (): void {
    $actor = User::factory()->create();
    $workspace = Workspace::factory()->for($actor, 'owner')->create();

    foreach (range(0, 29) as $number) {
        Ingredient::factory()->create([
            'display_name' => sprintf('Filtered Oil %02d', $number),
            'owner_type' => null,
            'owner_id' => null,
            'is_active' => true,
        ]);
    }

    Ingredient::factory()->create([
        'display_name' => 'Filtered Personal Oil',
        'owner_type' => OwnerType::User,
        'owner_id' => $actor->id,
        'is_active' => true,
    ]);
    Ingredient::factory()->create([
        'display_name' => 'Filtered Workspace Oil',
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'is_active' => true,
    ]);

    Ingredient::factory()->create([
        'display_name' => 'Filtered Foreign Oil',
        'owner_type' => 'workspace',
        'owner_id' => Workspace::factory()->create()->id,
        'is_active' => true,
    ]);
    Ingredient::factory()->create([
        'display_name' => 'Filtered Inactive Oil',
        'owner_type' => null,
        'owner_id' => null,
        'is_active' => false,
    ]);

    $query = http_build_query([
        'workspace' => $workspace->public_id,
        'format' => 'csv',
        'search' => 'Filtered',
        'ownershipFilter' => 'platform',
        'sortField' => 'display_name',
        'sortDirection' => 'asc',
    ]);

    $response = $this->actingAs($actor)->get('/dashboard/ingredients/export?'.$query);
    $response->assertSuccessful()->assertDownload();

    $rows = collect(explode("\n", trim($response->streamedContent())))
        ->map(fn (string $line): array => str_getcsv($line));
    $nameColumn = array_search(__('ingredients.exports.columns.name'), $rows->first(), true);
    $names = $rows->skip(1)->values()->map(fn (array $row): string => $row[$nameColumn])->all();

    expect($names)
        ->toHaveCount(30)
        ->toBe(collect(range(0, 29))->map(fn (int $number): string => sprintf('Filtered Oil %02d', $number))->all());

    $mineQuery = http_build_query([
        'workspace' => $workspace->public_id,
        'format' => 'csv',
        'search' => 'Filtered',
        'ownershipFilter' => 'mine',
        'sortField' => 'display_name',
        'sortDirection' => 'asc',
    ]);
    $mineResponse = $this->get('/dashboard/ingredients/export?'.$mineQuery);
    $mineResponse->assertSuccessful()->assertDownload();
    $mineRows = collect(explode("\n", trim($mineResponse->streamedContent())))
        ->map(fn (string $line): array => str_getcsv($line));
    $mineNameColumn = array_search(__('ingredients.exports.columns.name'), $mineRows->first(), true);

    expect($mineRows->skip(1)->values()->map(fn (array $row): string => $row[$mineNameColumn])->all())
        ->toBe(['Filtered Personal Oil', 'Filtered Workspace Oil']);
});

it('shows the Excel-first export menu beside the ingredient search and current catalog controls', function (): void {
    $actor = User::factory()->create();
    $workspace = Workspace::factory()->for($actor, 'owner')->create();

    $this->actingAs($actor)
        ->get(route('ingredients.index'))
        ->assertSuccessful()
        ->assertSee('Export ingredients')
        ->assertSee('Excel workbook (.xlsx)')
        ->assertSee('CSV file (.csv)')
        ->assertSeeHtml('aria-haspopup="menu"')
        ->assertSeeHtml(':aria-disabled="(outputPending || currentResultsAreEmpty()).toString()"')
        ->assertSeeHtml(':disabled="currentResultsAreEmpty()"')
        ->assertSeeHtml('role="menu"')
        ->assertSee(route('ingredients.export'), false)
        ->assertSee($workspace->public_id, false);
});

it('exports localized names, workspace prices, codes, null and zero distinctly in CSV and typed XLSX', function (): void {
    SupportedLocale::factory()->create(['code' => 'fr']);
    $actor = User::factory()->create(['locale' => 'fr']);
    $workspace = Workspace::factory()->for($actor, 'owner')->create([
        'default_currency' => 'GBP',
        'mass_display_system' => MassDisplaySystem::UsCustomary,
    ]);
    $otherWorkspace = Workspace::factory()->create();
    $priced = Ingredient::factory()->create([
        'display_name' => 'Olive Oil',
        'inci_name' => 'OLEA EUROPAEA FRUIT OIL',
        'category' => IngredientCategory::Lipids,
        'subcategory' => IngredientSubcategory::VegetableOils,
        'owner_type' => null,
        'owner_id' => null,
        'is_active' => true,
    ]);
    $zero = Ingredient::factory()->create([
        'display_name' => 'Zero Oil',
        'inci_name' => 'ZERO OIL',
        'owner_type' => null,
        'owner_id' => null,
        'is_active' => true,
    ]);
    $unpriced = Ingredient::factory()->create([
        'display_name' => 'Unpriced Oil',
        'inci_name' => 'UNPRICED OIL',
        'owner_type' => null,
        'owner_id' => null,
        'is_active' => true,
    ]);
    IngredientTranslation::factory()->for($priced)->create([
        'locale' => 'fr',
        'display_name' => 'Huile d’olive',
    ]);
    WorkspaceIngredientCode::factory()->create([
        'workspace_id' => $workspace->id,
        'ingredient_id' => $priced->id,
        'material_code' => 'OIL-001',
    ]);
    CurrentMaterialPrice::factory()->create([
        'workspace_id' => $workspace->id,
        'ingredient_id' => $priced->id,
        'price_per_canonical_unit' => '0.005000000000',
        'currency' => 'EUR',
        'recorded_at' => '2026-03-04 00:00:00',
    ]);
    CurrentMaterialPrice::factory()->create([
        'workspace_id' => $otherWorkspace->id,
        'ingredient_id' => $priced->id,
        'price_per_canonical_unit' => '9.000000000000',
        'currency' => 'EUR',
    ]);
    CurrentMaterialPrice::factory()->create([
        'workspace_id' => $workspace->id,
        'ingredient_id' => $zero->id,
        'price_per_canonical_unit' => '0.000000000000',
        'currency' => 'GBP',
        'recorded_at' => '2026-05-06 00:00:00',
    ]);

    $baseQuery = http_build_query([
        'workspace' => $workspace->public_id,
        'ownershipFilter' => 'platform',
        'sortField' => 'display_name',
        'sortDirection' => 'asc',
    ]);
    $csvResponse = $this->actingAs($actor)->get('/dashboard/ingredients/export?'.$baseQuery.'&format=csv');
    $csvResponse->assertSuccessful()->assertDownload();
    $csvRows = collect(explode("\n", trim($csvResponse->streamedContent())))
        ->map(fn (string $line): array => str_getcsv($line));
    $headers = $csvRows->first();
    $priceIndex = array_search(__('ingredients.exports.columns.price', ['unit' => 'lb']), $headers, true);
    $unitIndex = array_search(__('ingredients.exports.columns.price_unit'), $headers, true);
    $currencyIndex = array_search(__('ingredients.exports.columns.currency'), $headers, true);
    $dateIndex = array_search(__('ingredients.exports.columns.recorded_at'), $headers, true);
    $nameIndex = array_search(__('ingredients.exports.columns.name'), $headers, true);
    $inciIndex = array_search(__('ingredients.exports.columns.inci_name'), $headers, true);
    $codeIndex = array_search(__('ingredients.exports.columns.material_code'), $headers, true);
    $workspaceIndex = array_search(__('ingredients.exports.columns.source_workspace'), $headers, true);
    $categoryIndex = array_search(__('ingredients.exports.columns.category'), $headers, true);
    $subcategoryIndex = array_search(__('ingredients.exports.columns.subcategory'), $headers, true);
    $generatedAtIndex = array_search(__('ingredients.exports.columns.generated_at'), $headers, true);
    $ownershipFilterIndex = array_search(__('ingredients.exports.columns.ownership_filter'), $headers, true);
    $searchIndex = array_search(__('ingredients.exports.columns.search_filter'), $headers, true);
    $rowsByName = $csvRows->skip(1)->values()->keyBy(fn (array $row): string => $row[$nameIndex]);
    $pricedRow = $rowsByName->get('Huile d’olive');
    $zeroRow = $rowsByName->get('Zero Oil');
    $unpricedRow = $rowsByName->get('Unpriced Oil');

    expect($pricedRow[$inciIndex])->toBe('OLEA EUROPAEA FRUIT OIL')
        ->and($pricedRow[$codeIndex])->toBe('OIL-001')
        ->and($pricedRow[$categoryIndex])->toBe((string) IngredientCategory::Lipids->localizedLabel())
        ->and($pricedRow[$subcategoryIndex])->toBe((string) IngredientSubcategory::VegetableOils->localizedLabel())
        ->and($pricedRow[$priceIndex])->toBe(app(PriceBasisConverter::class)->fromPerKilogram('5', 'lb'))
        ->and($pricedRow[$unitIndex])->toBe('lb')
        ->and($pricedRow[$currencyIndex])->toBe('EUR')
        ->and($pricedRow[$dateIndex])->toBe('2026-03-04')
        ->and($pricedRow[$workspaceIndex])->toBe($workspace->name)
        ->and($pricedRow[$generatedAtIndex])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/')
        ->and($pricedRow[$searchIndex])->toBe('')
        ->and($pricedRow[$ownershipFilterIndex])->toBe(__('ingredients.filters.soapkraft'))
        ->and($zeroRow[$priceIndex])->toBe('0.000000000')
        ->and($zeroRow[$dateIndex])->toBe('2026-05-06')
        ->and($unpricedRow[$priceIndex])->toBe('')
        ->and($unpricedRow[$dateIndex])->toBe('');

    $xlsxResponse = $this->get('/dashboard/ingredients/export?'.$baseQuery.'&format=xlsx');
    $xlsxResponse->assertSuccessful()->assertDownload();
    $path = tempnam(sys_get_temp_dir(), 'ingredient-export-test-');
    expect($path)->not->toBeFalse();

    try {
        file_put_contents($path, $xlsxResponse->streamedContent());
        $reader = new Reader;
        $reader->open($path);
        $sheetRows = collect($reader->getSheetIterator()->current()->getRowIterator())->values();
        $reader->close();
    } finally {
        @unlink($path);
    }

    $headerIndex = $sheetRows->search(fn ($row): bool => ($row->toArray()[0] ?? null) === __('ingredients.exports.columns.name'));
    $xlsxHeaders = $sheetRows[$headerIndex]->toArray();
    $xlsxPriceIndex = array_search(__('ingredients.exports.columns.price', ['unit' => 'lb']), $xlsxHeaders, true);
    $xlsxCurrencyIndex = array_search(__('ingredients.exports.columns.currency'), $xlsxHeaders, true);
    $xlsxZeroRow = $sheetRows->skip($headerIndex + 1)->first(fn ($row): bool => ($row->toArray()[0] ?? null) === 'Zero Oil');
    $xlsxPricedRow = $sheetRows->skip($headerIndex + 1)->first(fn ($row): bool => ($row->toArray()[0] ?? null) === 'Huile d’olive');

    expect($headerIndex)->not->toBeFalse()
        ->and($xlsxZeroRow->getCells()[$xlsxPriceIndex])->toBeInstanceOf(NumericCell::class)
        ->and((float) $xlsxZeroRow->toArray()[$xlsxPriceIndex])->toBe(0.0)
        ->and($xlsxPricedRow->getCells()[$xlsxPriceIndex])->toBeInstanceOf(NumericCell::class)
        ->and((float) $xlsxPricedRow->toArray()[$xlsxPriceIndex])->toBe((float) app(PriceBasisConverter::class)->fromPerKilogram('5', 'lb'))
        ->and($xlsxPricedRow->toArray()[$xlsxCurrencyIndex])->toBe('EUR')
        ->and(CurrentMaterialPrice::query()->count())->toBe(3);
});

it('allows a workspace viewer and rejects revoked or switched workspace context', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $member = User::factory()->create();
    $membership = WorkspaceMember::factory()->for($workspace)->for($member)->create([
        'role' => WorkspaceMemberRole::Viewer,
    ]);
    $plan = Plan::factory()->create(['allows_collaboration' => true]);
    UserEntitlement::factory()->for($owner)->for($plan)->create();
    Ingredient::factory()->create([
        'display_name' => 'Viewer Ingredient',
        'owner_type' => null,
        'owner_id' => null,
        'is_active' => true,
    ]);

    $url = '/dashboard/ingredients/export?'.http_build_query([
        'workspace' => $workspace->public_id,
        'format' => 'csv',
    ]);
    $viewerResponse = $this->actingAs($member)->get($url);
    $viewerResponse->assertSuccessful()->assertDownload();
    $viewerResponse->streamedContent();

    $membership->delete();
    $this->getJson($url)->assertForbidden();

    $actor = User::factory()->create();
    $origin = Workspace::factory()->for($actor, 'owner')->create();
    $selected = Workspace::factory()->for($actor, 'owner')->create();
    DB::table('users')->where('id', $actor->id)->update(['active_workspace_id' => $selected->id]);

    $this->actingAs($actor)->getJson('/dashboard/ingredients/export?'.http_build_query([
        'workspace' => $origin->public_id,
        'format' => 'csv',
    ]))->assertStatus(409);
});

it('rejects empty, over-limit, and changed result sets before returning a partial file', function (): void {
    $actor = User::factory()->create();
    $workspace = Workspace::factory()->for($actor, 'owner')->create();
    $baseQuery = [
        'workspace' => $workspace->public_id,
        'format' => 'csv',
        'ownershipFilter' => 'platform',
        'search' => 'Bounded Oil',
    ];

    $this->actingAs($actor)->getJson('/dashboard/ingredients/export?'.http_build_query($baseQuery))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('export');

    foreach (range(1, 3) as $number) {
        Ingredient::factory()->create([
            'display_name' => "Bounded Oil {$number}",
            'owner_type' => null,
            'owner_id' => null,
            'is_active' => true,
        ]);
    }

    config()->set('workspaces.list_output_limits.max_rows', 2);
    $before = glob(sys_get_temp_dir().'/koskalk-export-*') ?: [];
    $this->getJson('/dashboard/ingredients/export?'.http_build_query($baseQuery))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('export');
    expect(glob(sys_get_temp_dir().'/koskalk-export-*') ?: [])->toBe($before);

    config()->set('workspaces.list_output_limits.max_rows', 1);
    Ingredient::query()->where('display_name', 'Bounded Oil 2')->delete();
    Ingredient::query()->where('display_name', 'Bounded Oil 3')->delete();
    $inserted = false;
    DB::listen(function (QueryExecuted $query) use (&$inserted): void {
        if (! $inserted && str_contains(strtolower($query->sql), 'count(*) as') && str_contains(strtolower($query->sql), 'ingredients')) {
            $inserted = true;
            Ingredient::factory()->create([
                'display_name' => 'Bounded Oil 2',
                'owner_type' => null,
                'owner_id' => null,
                'is_active' => true,
            ]);
        }
    });
    $before = glob(sys_get_temp_dir().'/koskalk-export-*') ?: [];
    $changedResponse = $this->getJson('/dashboard/ingredients/export?'.http_build_query($baseQuery));
    expect($inserted)->toBeTrue();
    $changedResponse
        ->assertUnprocessable()
        ->assertJsonValidationErrors('export');
    expect(glob(sys_get_temp_dir().'/koskalk-export-*') ?: [])->toBe($before);
});
