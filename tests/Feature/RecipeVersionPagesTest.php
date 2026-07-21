<?php

use App\IngredientCategory;
use App\Models\Ingredient;
use App\Models\IngredientSapProfile;
use App\Models\ProductFamily;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\RecipeVersionCosting;
use App\Models\RecipeVersionCostingItem;
use App\Models\RecipeVersionCostingPackagingItem;
use App\Models\User;
use App\Models\UserPackagingItem;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\OwnerType;
use App\Services\RecipeWorkbenchService;
use App\Visibility;
use App\WorkspaceMemberRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('renders the formula sheet around one aligned table', function () {
    [$user, $recipe, $publishedVersion] = createSavedRecipeVersion();

    $response = $this->actingAs($user)
        ->get(route('recipes.saved', ['recipe' => $recipe]))
        ->assertSuccessful()
        ->assertSee('Formula Sheet')
        ->assertSeeInOrder(['Saponified oils', 'Lye and water', 'Formula additions'])
        ->assertSee('% of oils')
        ->assertSee('NaOH')
        ->assertSee('Water')
        ->assertSee('Calculated results')
        ->assertDontSee('v'.$publishedVersion->version_number)
        ->assertSee('Open formula')
        ->assertSee('Duplicate')
        ->assertDontSee('Reference formula')
        ->assertDontSee('Edit in draft')
        ->assertDontSee('Recovery snapshots')
        ->assertDontSee('Batch production sheet')
        ->assertDontSee('Technical recipe sheet')
        ->assertDontSee('Costing sheet')
        ->assertSee('Export Excel')
        ->assertSee('Export CSV')
        ->assertDontSee('How this recipe was calculated')
        ->assertSee('Olive Oil')
        ->assertSee('Weight (g)')
        ->assertDontSee('1000.00');

    expect(substr_count($response->getContent(), 'data-formula-document-table'))->toBe(1)
        ->and(strpos($response->getContent(), 'Lye and water'))
        ->toBeLessThan(strpos($response->getContent(), 'Calculated results'));
});

it('renders the formula workbench with one save path and lock controls', function () {
    [$user, $recipe] = createSavedRecipeVersion();

    $response = $this->actingAs($user)
        ->get(route('recipes.edit', ['recipe' => $recipe]))
        ->assertSuccessful()
        ->assertSee('Formula')
        ->assertSee('Product sheet')
        ->assertSee('Save')
        ->assertSee('Lock product')
        ->assertSeeInOrder(['Save', 'Lock product', 'More actions'])
        ->assertDontSee('Editable draft')
        ->assertDontSee('Save draft')
        ->assertDontSee('Save as reference formula')
        ->assertDontSee('Update reference formula?')
        ->assertDontSee('This will replace the reference formula with your current draft.')
        ->assertDontSee('Save recipe');

    expect(substr_count($response->getContent(), 'sk-formula-sheet-link'))->toBe(1);
});

it('renders an existing formula workbench within its initial query budget', function () {
    [$user, $recipe] = createSavedRecipeVersion();

    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->actingAs($user)
        ->get(route('recipes.edit', ['recipe' => $recipe]))
        ->assertSuccessful();

    $queryCount = count(DB::getQueryLog());

    DB::disableQueryLog();

    expect($queryCount)->toBeLessThanOrEqual(25);
});

it('keeps an inactive saved ingredient available in the formula workbench', function () {
    [$user, $recipe] = createSavedRecipeVersion();

    Ingredient::query()
        ->where('display_name', 'Olive Oil')
        ->update(['is_active' => false]);

    $this->actingAs($user)
        ->get(route('recipes.edit', ['recipe' => $recipe]))
        ->assertSuccessful()
        ->assertSee('Olive Oil');
});

it('shows older backups in version history', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create(['slug' => 'soap', 'name' => 'Soap']);
    $ingredient = makeSavedRecipeIngredient();
    $service = app(RecipeWorkbenchService::class);

    $draftVersion = $service->save($user, $soapFamily, soapVersionDraftPayload($ingredient, 'Formula A'));
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    $service->publish($user, $soapFamily, soapVersionDraftPayload($ingredient, 'Formula A'), $recipe);
    $service->publish($user, $soapFamily, soapVersionDraftPayload($ingredient, 'Formula B'), $recipe);

    $olderSavedVersion = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_current', false)
        ->where('name', 'Formula A')
        ->firstOrFail();

    $olderSavedVersion->update([
        'saved_at' => '2026-07-12 09:30:00',
    ]);

    $this->actingAs($user)
        ->get(route('recipes.saved', ['recipe' => $recipe]))
        ->assertSuccessful()
        ->assertSee('Version history')
        ->assertSee('Backup')
        ->assertDontSee('Formula A')
        ->assertSee('2026-07-12 09:30')
        ->assertSee('View version')
        ->assertSee('href="'.route('recipes.version', ['recipe' => $recipe, 'version' => $olderSavedVersion]).'"', false)
        ->assertSee('Restore to current formula')
        ->assertSee('method="POST" action="'.route('recipes.use-version-as-current', ['recipe' => $recipe, 'version' => $olderSavedVersion]).'"', false)
        ->assertDontSee('action="'.route('recipes.saved.restore', ['recipe' => $recipe, 'version' => $olderSavedVersion]).'"', false);

    $onlyVersion = $service->save($user, $soapFamily, soapVersionDraftPayload($ingredient, 'Only Formula'));

    $this->actingAs($user)
        ->get(route('recipes.saved', [
            'recipe' => Recipe::withoutGlobalScopes()->findOrFail($onlyVersion->recipe_id),
        ]))
        ->assertSuccessful()
        ->assertDontSee('Version history');
});

it('prevents read-only collaborators from restoring saved formula versions', function () {
    [$owner, $recipe, $savedVersion] = createSavedRecipeVersion();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $viewer = User::factory()->create();

    WorkspaceMember::factory()->for($workspace)->for($viewer)->create([
        'role' => WorkspaceMemberRole::Viewer,
    ]);

    $recipe->update([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Workspace,
    ]);

    $this->actingAs($viewer)
        ->post(route('recipes.use-version-as-current', ['recipe' => $recipe, 'version' => $savedVersion]), [
            'confirm_replace_current' => '1',
        ])
        ->assertNotFound();

    $this->actingAs($viewer)
        ->get(route('recipes.saved', $recipe))
        ->assertNotFound();
});

it('prevents read-only collaborators from using legacy saved formula restore actions', function () {
    [$owner, $recipe, $savedVersion] = createSavedRecipeVersion();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $viewer = User::factory()->create();

    WorkspaceMember::factory()->for($workspace)->for($viewer)->create([
        'role' => WorkspaceMemberRole::Viewer,
    ]);

    $recipe->update([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Workspace,
    ]);

    $this->actingAs($viewer)
        ->post(route('recipes.saved.edit-current', ['recipe' => $recipe]), [
            'confirm_replace_current' => '1',
        ])
        ->assertNotFound();

    $this->actingAs($viewer)
        ->post(route('recipes.saved.restore', [
            'recipe' => $recipe,
            'version' => $savedVersion,
        ]))
        ->assertNotFound();
});

it('prevents workspace editors from using legacy saved formula restore actions during the MVP', function () {
    [$owner, $recipe, $savedVersion] = createSavedRecipeVersion();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $editor = User::factory()->create();

    WorkspaceMember::factory()->for($workspace)->for($editor)->create([
        'role' => WorkspaceMemberRole::Editor,
    ]);

    $recipe->update([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Workspace,
    ]);

    $this->actingAs($editor)
        ->post(route('recipes.saved.edit-current', ['recipe' => $recipe]), [
            'confirm_replace_current' => '1',
        ])
        ->assertNotFound();

    $this->actingAs($editor)
        ->post(route('recipes.saved.restore', [
            'recipe' => $recipe,
            'version' => $savedVersion,
        ]))
        ->assertNotFound();
});

it('locks and unlocks a formula', function () {
    [$user, $recipe] = createSavedRecipeVersion();

    $this->actingAs($user)
        ->post(route('recipes.lock', $recipe))
        ->assertRedirect(route('recipes.edit', $recipe))
        ->assertSessionHas('status', 'Product locked.');

    expect($recipe->fresh()->locked_at)->not->toBeNull()
        ->and($recipe->fresh()->locked_by)->toBe($user->id);

    $this->actingAs($user)
        ->get(route('recipes.edit', $recipe))
        ->assertSuccessful()
        ->assertSee('Unlock product')
        ->assertSeeInOrder(['Unlock product', 'More actions']);

    $this->actingAs($user)
        ->post(route('recipes.unlock', $recipe))
        ->assertRedirect(route('recipes.edit', $recipe))
        ->assertSessionHas('status', 'Product unlocked.');

    expect($recipe->fresh()->locked_at)->toBeNull()
        ->and($recipe->fresh()->locked_by)->toBeNull();
});

it('recalculates the saved formula view when a different oil quantity is requested', function () {
    [$user, $recipe, $publishedVersion] = createSavedRecipeVersion();

    $this->actingAs($user)
        ->get(route('recipes.saved', [
            'recipe' => $recipe,
            'oil_weight' => 1500,
        ]))
        ->assertSuccessful()
        ->assertSee('value="1500"', false)
        ->assertSee('Recalculate');
});

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

it('renders compatibility print routes as the working formula sheet', function (string $routeName) {
    [$user, $recipe, $savedVersion] = createSavedRecipeVersion();

    $this->actingAs($user)
        ->get(route($routeName, ['recipe' => $recipe, 'version' => $savedVersion]))
        ->assertSuccessful()
        ->assertSee('Working Formula Sheet')
        ->assertDontSee('Cost summary');
})->with([
    'technical route' => 'recipes.print.technical',
    'costing route' => 'recipes.print.costing',
    'legacy recipe route' => 'recipes.legacy.print.recipe',
    'legacy details route' => 'recipes.legacy.print.details',
]);

it('passes batch context from the saved page to print sheets', function () {
    [$user, $recipe, $publishedVersion] = createSavedRecipeVersion();
    attachCostingToSavedVersion($user, $publishedVersion);

    $response = $this->actingAs($user)
        ->get(route('recipes.saved', [
            'recipe' => $recipe,
            'oil_weight' => 1500,
            'batch_basis' => 1250,
            'batch_number' => 'B-2026-042',
            'manufacture_date' => '2026-04-20',
            'units_produced' => 24,
        ]))
        ->assertSuccessful()
        ->assertSee('B-2026-042')
        ->assertSee('2026-04-20')
        ->assertSee('value="24"', false);

    $response->assertSee('batch_number=B-2026-042', false)
        ->assertSee('batch_basis=1250', false)
        ->assertSee('manufacture_date=2026-04-20', false)
        ->assertSee('units_produced=24', false);

    $this->actingAs($user)
        ->get(route('recipes.print.production', [
            'recipe' => $recipe,
            'oil_weight' => 1500,
            'batch_number' => 'B-2026-042',
            'manufacture_date' => '2026-04-20',
            'units_produced' => 24,
        ]))
        ->assertSuccessful()
        ->assertSee('Working Formula Sheet')
        ->assertSee('Trial / batch no.')
        ->assertSee('1,500.00 g');
});

it('prefills production units and priced packaging cost on the saved formula page', function () {
    [$user, $recipe] = createSavedRecipeVersion();
    $currentFormula = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->orderByDesc('version_number')
        ->firstOrFail();
    $ingredient = Ingredient::query()
        ->where('display_name', 'Olive Oil')
        ->firstOrFail();
    $packagingItem = UserPackagingItem::query()->create([
        'user_id' => $user->id,
        'name' => 'Soap box',
        'unit_cost' => 0.06,
        'currency' => 'EUR',
    ]);

    $currentFormula->packagingItems()->create([
        'user_packaging_item_id' => $packagingItem->id,
        'name' => 'Soap box',
        'components_per_unit' => 1,
        'position' => 1,
    ]);

    $costing = RecipeVersionCosting::query()->create([
        'recipe_version_id' => $currentFormula->id,
        'user_id' => $user->id,
        'oil_weight_for_costing' => 1000,
        'oil_unit_for_costing' => 'g',
        'units_produced' => 10,
        'currency' => 'EUR',
    ]);

    RecipeVersionCostingItem::query()->create([
        'recipe_version_costing_id' => $costing->id,
        'ingredient_id' => $ingredient->id,
        'phase_key' => 'saponified_oils',
        'position' => 1,
        'price_per_kg' => 8.5,
    ]);

    RecipeVersionCostingPackagingItem::query()->create([
        'recipe_version_costing_id' => $costing->id,
        'user_packaging_item_id' => $packagingItem->id,
        'name' => 'Soap box',
        'unit_cost' => 0.06,
        'quantity' => 1,
    ]);

    $this->actingAs($user)
        ->get(route('recipes.saved', ['recipe' => $recipe]))
        ->assertSuccessful()
        ->assertSee('value="10"', false)
        ->assertSee('0.6 EUR')
        ->assertSee('name="batch_basis" value="1000"', false)
        ->assertDontSee('inputmode="decimal"', false);
});

it('labels cosmetic formula sheet quantity as total batch quantity', function () {
    $user = User::factory()->create();
    $cosmeticFamily = ProductFamily::factory()->create([
        'slug' => 'cosmetic',
        'name' => 'Cosmetic',
        'calculation_basis' => 'total_formula',
    ]);
    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::Additive,
        'display_name' => 'Glycerin',
        'inci_name' => 'GLYCERIN',
        'is_active' => true,
    ]);
    $service = app(RecipeWorkbenchService::class);
    $draftVersion = $service->save($user, $cosmeticFamily, cosmeticSavedFormulaPayload($ingredient));
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);
    $service->saveAsNewVersion($user, $cosmeticFamily, cosmeticSavedFormulaPayload($ingredient), $recipe);

    $this->actingAs($user)
        ->get(route('recipes.saved', ['recipe' => $recipe]))
        ->assertSuccessful()
        ->assertSee('Total batch quantity')
        ->assertDontSee('Oil quantity');
});

it('downloads the saved formula as a simple csv', function () {
    [$user, $recipe] = createSavedRecipeVersion();

    $response = $this->actingAs($user)
        ->get(route('recipes.export.csv', ['recipe' => $recipe]))
        ->assertSuccessful()
        ->assertDownload('published-formula.csv');

    expect($response->streamedContent())
        ->toContain('Phase,Ingredient,Source,"INCI name",Percentage,Weight,Note')
        ->toContain('"Saponified oils","Olive Oil",Platform,"OLEA EUROPAEA FRUIT OIL",100,1000,');
});

it('downloads the saved formula as an excel workbook', function () {
    [$user, $recipe, $publishedVersion] = createSavedRecipeVersion();
    attachCostingToSavedVersion($user, $publishedVersion);

    $response = $this->actingAs($user)
        ->get(route('recipes.export.xlsx', [
            'recipe' => $recipe,
            'batch_number' => 'B-2026-043',
            'manufacture_date' => '2026-04-21',
            'units_produced' => 12,
        ]))
        ->assertSuccessful()
        ->assertDownload('published-formula.xlsx');

    $content = $response->streamedContent();

    expect(substr($content, 0, 2))->toBe('PK');

    $path = tempnam(sys_get_temp_dir(), 'koskalk-export-test-');
    file_put_contents($path, $content);

    $zip = new ZipArchive;

    expect($zip->open($path))->toBeTrue();

    $workbookXml = (string) $zip->getFromName('xl/workbook.xml');
    $worksheetXml = collect(range(1, 6))
        ->map(fn (int $index): string => (string) $zip->getFromName("xl/worksheets/sheet{$index}.xml"))
        ->implode("\n");

    $zip->close();
    unlink($path);

    expect($workbookXml)
        ->toContain('Summary')
        ->toContain('Formula')
        ->toContain('Packaging')
        ->toContain('Outputs')
        ->toContain('INCI Declaration')
        ->toContain('Costing')
        ->and($worksheetXml)
        ->toContain('Published Formula')
        ->toContain('Olive Oil')
        ->toContain('B-2026-043')
        ->toContain('<f>SUM(D4:D4)</f>')
        ->toContain('<f>C10*D10/1000</f>')
        ->toContain('customWidth="true"')
        ->toContain('<autoFilter');
});

it('does not expose exports to other users', function () {
    [$owner, $recipe] = createSavedRecipeVersion();
    $otherUser = User::factory()->create();

    $this->actingAs($otherUser)
        ->get(route('recipes.export.csv', ['recipe' => $recipe]))
        ->assertNotFound();

    $this->actingAs($otherUser)
        ->get(route('recipes.export.xlsx', ['recipe' => $recipe]))
        ->assertNotFound();
});

it('does not expose the saved formula to other users', function () {
    [$owner, $recipe, $publishedVersion] = createSavedRecipeVersion();
    $otherUser = User::factory()->create();

    $this->actingAs($otherUser)
        ->get(route('recipes.saved', ['recipe' => $recipe]))
        ->assertNotFound();
});

it('routes active and historical formula sheets to their exact saved versions', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create(['slug' => 'soap', 'name' => 'Soap']);
    $ingredient = makeSavedRecipeIngredient();
    $service = app(RecipeWorkbenchService::class);

    $draftVersion = $service->save($user, $soapFamily, soapVersionDraftPayload($ingredient, 'Formula A'));
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    $service->publish($user, $soapFamily, soapVersionDraftPayload($ingredient, 'Formula A'), $recipe);
    $service->publish($user, $soapFamily, soapVersionDraftPayload($ingredient, 'Formula B'), $recipe);

    $formulaA = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_current', false)
        ->where('name', 'Formula A')
        ->firstOrFail();

    $this->actingAs($user)
        ->get(route('recipes.saved', ['recipe' => $recipe]))
        ->assertSuccessful()
        ->assertSee('<title>Formula B · Formula Sheet', false)
        ->assertSee('>Formula B</h1>', false)
        ->assertSee('Saved formula')
        ->assertDontSee('Saved history');

    $this->actingAs($user)
        ->get(route('recipes.version', ['recipe' => $recipe, 'version' => $formulaA]))
        ->assertSuccessful()
        ->assertSee('Formula Sheet')
        ->assertSee('<title>Formula B · Formula Sheet', false)
        ->assertDontSee('<title>Formula A · Formula Sheet', false)
        ->assertSee('>Formula B</h1>', false)
        ->assertDontSee('>Formula A</h1>', false)
        ->assertSee('Saved history')
        ->assertSee('Back to active formula')
        ->assertSee('href="'.route('recipes.saved', $recipe).'"', false)
        ->assertDontSee('action="'.route('recipes.use-version-as-current', ['recipe' => $recipe, 'version' => $formulaA]).'"', false);
});

it('rejects the mutable draft from formula history', function () {
    [$user, $recipe] = createRecipeWithTwoDistinctSavedVersions();
    $draft = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_current', true)
        ->firstOrFail();

    $this->actingAs($user)
        ->get(route('recipes.version', ['recipe' => $recipe, 'version' => $draft]))
        ->assertNotFound();
});

it('keeps the displayed historical version in every sheet action', function () {
    [$user, $recipe, $formulaA] = createRecipeWithTwoDistinctSavedVersions();

    $response = $this->actingAs($user)
        ->get(route('recipes.version', ['recipe' => $recipe, 'version' => $formulaA]))
        ->assertSuccessful();

    foreach (['recipes.export.xlsx', 'recipes.export.csv'] as $routeName) {
        $routePath = parse_url(route($routeName, ['recipe' => $recipe]), PHP_URL_PATH);
        $html = html_entity_decode($response->getContent());

        expect($html)->toContain((string) $routePath)
            ->and($html)->toContain('version='.$formulaA->public_id);
    }

    $response
        ->assertSee('action="'.route('recipes.print.production', ['recipe' => $recipe]).'"', false)
        ->assertSee('name="version" value="'.$formulaA->public_id.'"', false);

    $activeResponse = $this->actingAs($user)
        ->get(route('recipes.saved', ['recipe' => $recipe]))
        ->assertSuccessful();

    $activeResponse->assertDontSee('version='.$formulaA->id, false);
});

it('renders and exports the exact requested historical formula version', function () {
    [$user, $recipe, $formulaA] = createRecipeWithTwoDistinctSavedVersions();
    attachCostingToSavedVersion($user, $formulaA);

    foreach (['recipes.print.production', 'recipes.print.technical', 'recipes.print.costing'] as $routeName) {
        $this->actingAs($user)
            ->get(route($routeName, ['recipe' => $recipe, 'version' => $formulaA]))
            ->assertSuccessful()
            ->assertSee('Olive Oil')
            ->assertDontSee('Coconut Oil');
    }

    $this->actingAs($user)
        ->get(route('recipes.legacy.print.recipe', ['recipe' => $recipe, 'version' => $formulaA]))
        ->assertSuccessful()
        ->assertSee('Olive Oil')
        ->assertDontSee('Coconut Oil');

    $this->actingAs($user)
        ->get(route('recipes.legacy.print.details', ['recipe' => $recipe, 'version' => $formulaA]))
        ->assertSuccessful()
        ->assertSee('Olive Oil')
        ->assertDontSee('Coconut Oil');

    $csvResponse = $this->actingAs($user)
        ->get(route('recipes.export.csv', ['recipe' => $recipe, 'version' => $formulaA]))
        ->assertSuccessful();

    expect($csvResponse->streamedContent())
        ->toContain('Olive Oil')
        ->not->toContain('Coconut Oil');

    $workbookResponse = $this->actingAs($user)
        ->get(route('recipes.export.xlsx', ['recipe' => $recipe, 'version' => $formulaA]))
        ->assertSuccessful();

    expect(recipeWorkbookXml($workbookResponse->streamedContent()))
        ->toContain('Olive Oil')
        ->not->toContain('Coconut Oil');
});

it('keeps the main recipe identity while rendering selected backup content', function () {
    [$user, $recipe, $formulaA] = createRecipeWithTwoDistinctSavedVersions();
    $recipe->update(['name' => 'Main Formula']);

    $this->actingAs($user)
        ->get(route('recipes.version', ['recipe' => $recipe, 'version' => $formulaA]))
        ->assertSuccessful()
        ->assertSee('<title>Main Formula · Formula Sheet', false)
        ->assertDontSee('<title>Formula A · Formula Sheet', false)
        ->assertSee('>Main Formula</h1>', false)
        ->assertDontSee('>Formula A</h1>', false)
        ->assertSee('Saved history')
        ->assertSee('Olive Oil')
        ->assertDontSee('Coconut Oil');

    $this->actingAs($user)
        ->get(route('recipes.print.production', ['recipe' => $recipe, 'version' => $formulaA]))
        ->assertSuccessful()
        ->assertSee('<title>Main Formula · Working Formula Sheet', false)
        ->assertDontSee('<title>Formula A · Working Formula Sheet', false)
        ->assertSee('>Main Formula</h1>', false)
        ->assertDontSee('>Formula A</h1>', false)
        ->assertSee('Olive Oil')
        ->assertDontSee('Coconut Oil');

    $this->actingAs($user)
        ->get(route('recipes.print.costing', ['recipe' => $recipe, 'version' => $formulaA]))
        ->assertSuccessful()
        ->assertSee('<title>Main Formula · Working Formula Sheet', false)
        ->assertDontSee('Cost summary');

    $workbookResponse = $this->actingAs($user)
        ->get(route('recipes.export.xlsx', ['recipe' => $recipe, 'version' => $formulaA]))
        ->assertSuccessful()
        ->assertDownload('main-formula.xlsx');

    expect(recipeWorkbookXml($workbookResponse->streamedContent()))
        ->toContain('Main Formula')
        ->not->toContain('Formula A')
        ->toContain('Olive Oil')
        ->not->toContain('Coconut Oil');
});

it('uses the latest saved formula when sheet outputs do not request a version', function () {
    [$user, $recipe] = createRecipeWithTwoDistinctSavedVersions();
    RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_current', true)
        ->firstOrFail()
        ->update(['name' => 'Formula C Draft']);

    $this->actingAs($user)
        ->get(route('recipes.print.production', ['recipe' => $recipe]))
        ->assertSuccessful()
        ->assertSee('<title>Formula B · Working Formula Sheet', false)
        ->assertDontSee('Formula C Draft')
        ->assertSee('Coconut Oil')
        ->assertDontSee('Olive Oil');

    $csvResponse = $this->actingAs($user)
        ->get(route('recipes.export.csv', ['recipe' => $recipe]))
        ->assertSuccessful();

    expect($csvResponse->streamedContent())
        ->toContain('Coconut Oil')
        ->not->toContain('Olive Oil');
});

it('rejects cross-recipe and inaccessible formula versions in sheet outputs', function (string $routeName) {
    [$user, $recipe] = createRecipeWithTwoDistinctSavedVersions();
    [, , $otherAccessibleRecipeVersion] = createRecipeWithTwoDistinctSavedVersions($user);
    [, , $inaccessibleRecipeVersion] = createRecipeWithTwoDistinctSavedVersions();

    foreach ([$otherAccessibleRecipeVersion, $inaccessibleRecipeVersion] as $otherRecipeVersion) {
        $this->actingAs($user)
            ->get(route($routeName, [
                'recipe' => $recipe,
                'version' => $otherRecipeVersion,
            ]))
            ->assertNotFound();
    }
})->with([
    'production sheet' => 'recipes.print.production',
    'technical sheet' => 'recipes.print.technical',
    'costing sheet' => 'recipes.print.costing',
    'Excel export' => 'recipes.export.xlsx',
    'CSV export' => 'recipes.export.csv',
]);

it('rejects the mutable draft when requested from a saved formula output', function (string $routeName) {
    [$user, $recipe] = createRecipeWithTwoDistinctSavedVersions();
    $draft = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_current', true)
        ->firstOrFail();

    $this->actingAs($user)
        ->get(route($routeName, [
            'recipe' => $recipe,
            'version' => $draft,
        ]))
        ->assertNotFound();
})->with([
    'production sheet' => 'recipes.print.production',
    'technical sheet' => 'recipes.print.technical',
    'costing sheet' => 'recipes.print.costing',
    'Excel export' => 'recipes.export.xlsx',
    'CSV export' => 'recipes.export.csv',
]);

it('rejects the mutable draft from legacy saved formula output routes', function (string $routeName) {
    [$user, $recipe] = createRecipeWithTwoDistinctSavedVersions();
    $draft = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_current', true)
        ->firstOrFail();

    $this->actingAs($user)
        ->get(route($routeName, [
            'recipe' => $recipe,
            'version' => $draft,
        ]))
        ->assertNotFound();
})->with([
    'production sheet' => 'recipes.legacy.print.recipe',
    'details sheet' => 'recipes.legacy.print.details',
]);

it('duplicates a recipe into a new draft recipe', function () {
    [$user, $recipe, $publishedVersion] = createSavedRecipeVersion();

    $this->actingAs($user)
        ->post(route('recipes.duplicate', ['recipe' => $recipe]))
        ->assertRedirect();

    expect(Recipe::withoutGlobalScopes()->count())->toBe(2)
        ->and(RecipeVersion::withoutGlobalScopes()->where('is_current', true)->count())->toBe(2)
        ->and(Recipe::withoutGlobalScopes()->latest('id')->firstOrFail()->name)->toBe('Copy of Published Formula');
});

it('can refresh the draft from the current saved formula page', function () {
    [$user, $recipe, $publishedVersion] = createSavedRecipeVersion();

    $draft = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_current', true)
        ->firstOrFail();

    $this->actingAs($user)
        ->post(route('recipes.saved.edit-current', ['recipe' => $recipe]))
        ->assertRedirect(route('recipes.edit', $recipe));

    $draft->refresh();

    expect($draft->name)->toBe('Published Formula');
});

it('redirects signed-out users before refreshing the draft from the saved formula', function () {
    [$user, $recipe, $publishedVersion] = createSavedRecipeVersion();

    $this->post(route('recipes.saved.edit-current', ['recipe' => $recipe]))
        ->assertRedirect(route('login'));
});

it('asks for confirmation before replacing a changed draft with the saved formula', function () {
    [$user, $recipe, $publishedVersion] = createSavedRecipeVersion();

    $draft = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_current', true)
        ->firstOrFail();

    $draft->update([
        'name' => 'Experimental Draft',
    ]);

    $this->actingAs($user)
        ->post(route('recipes.saved.edit-current', ['recipe' => $recipe]))
        ->assertRedirect(route('recipes.saved', $recipe))
        ->assertSessionHas('currentReplaceConfirmation');

    $draft->refresh();

    expect($draft->name)->toBe('Experimental Draft');

    $this->actingAs($user)
        ->post(route('recipes.saved.edit-current', ['recipe' => $recipe]), [
            'confirm_replace_current' => '1',
        ])
        ->assertRedirect(route('recipes.edit', $recipe));

    $draft->refresh();

    expect($draft->name)->toBe('Published Formula');
});

it('can restore an older saved snapshot as the current saved formula', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeSavedRecipeIngredient();
    $service = app(RecipeWorkbenchService::class);

    $draftVersion = $service->save(
        $user,
        $soapFamily,
        soapVersionDraftPayload($ingredient, 'Formula A'),
    );

    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    $service->publish($user, $soapFamily, soapVersionDraftPayload($ingredient, 'Formula A'), $recipe);
    $service->publish($user, $soapFamily, soapVersionDraftPayload($ingredient, 'Formula B'), $recipe);

    $olderSavedVersion = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_current', false)
        ->where('name', 'Formula A')
        ->latest('version_number')
        ->firstOrFail();

    $this->actingAs($user)
        ->post(route('recipes.saved.restore', ['recipe' => $recipe, 'version' => $olderSavedVersion]))
        ->assertRedirect(route('recipes.saved', $recipe));

    $latestSavedVersion = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_current', false)
        ->latest('version_number')
        ->firstOrFail();

    expect($latestSavedVersion->name)->toBe('Formula A');
});

it('redirects signed-out users before restoring a saved snapshot', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeSavedRecipeIngredient();
    $service = app(RecipeWorkbenchService::class);

    $draftVersion = $service->save(
        $user,
        $soapFamily,
        soapVersionDraftPayload($ingredient, 'Formula A'),
    );

    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    $service->publish($user, $soapFamily, soapVersionDraftPayload($ingredient, 'Formula A'), $recipe);

    $savedVersion = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_current', false)
        ->latest('version_number')
        ->firstOrFail();

    $this->post(route('recipes.saved.restore', ['recipe' => $recipe, 'version' => $savedVersion]))
        ->assertRedirect(route('login'));
});

it('rejects the current draft before invoking the legacy saved backup restore service', function () {
    [$user, $recipe] = createSavedRecipeVersion();
    $draft = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_current', true)
        ->firstOrFail();

    $this->mock(RecipeWorkbenchService::class, function ($mock): void {
        $mock->shouldNotReceive('restorePublishedFormula');
    });

    $this->actingAs($user)
        ->post(route('recipes.saved.restore', [
            'recipe' => $recipe,
            'version' => $draft,
        ]))
        ->assertNotFound();
});

it('preserves the current draft when restoring an older saved snapshot', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeSavedRecipeIngredient();
    $service = app(RecipeWorkbenchService::class);

    $draftVersion = $service->save(
        $user,
        $soapFamily,
        soapVersionDraftPayload($ingredient, 'Formula A'),
    );

    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    $service->publish($user, $soapFamily, soapVersionDraftPayload($ingredient, 'Formula A'), $recipe);
    $service->publish($user, $soapFamily, soapVersionDraftPayload($ingredient, 'Formula B'), $recipe);

    $currentDraft = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_current', true)
        ->firstOrFail();

    $currentDraft->update([
        'name' => 'Experimental Draft',
    ]);

    $olderSavedVersion = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_current', false)
        ->where('name', 'Formula A')
        ->latest('version_number')
        ->firstOrFail();

    $this->actingAs($user)
        ->post(route('recipes.saved.restore', ['recipe' => $recipe, 'version' => $olderSavedVersion]))
        ->assertRedirect(route('recipes.saved', $recipe));

    $currentDraft->refresh();

    expect($currentDraft->name)->toBe('Experimental Draft');

    $latestSavedVersion = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_current', false)
        ->latest('version_number')
        ->firstOrFail();

    expect($latestSavedVersion->name)->toBe('Formula A');
});

it('asks for confirmation before replacing the draft with an older recovery snapshot', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeSavedRecipeIngredient();
    $service = app(RecipeWorkbenchService::class);

    $draftVersion = $service->save(
        $user,
        $soapFamily,
        soapVersionDraftPayload($ingredient, 'Formula A'),
    );

    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    $service->publish($user, $soapFamily, soapVersionDraftPayload($ingredient, 'Formula A'), $recipe);
    $service->publish($user, $soapFamily, soapVersionDraftPayload($ingredient, 'Formula B'), $recipe);

    $currentDraft = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_current', true)
        ->firstOrFail();

    $currentDraft->update([
        'name' => 'Experimental Draft',
    ]);

    $olderSavedVersion = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_current', false)
        ->where('name', 'Formula A')
        ->latest('version_number')
        ->firstOrFail();

    $this->actingAs($user)
        ->post(route('recipes.use-version-as-current', ['recipe' => $recipe, 'version' => $olderSavedVersion]))
        ->assertRedirect(route('recipes.saved', $recipe))
        ->assertSessionHas('currentReplaceConfirmation');

    $currentDraft->refresh();

    expect($currentDraft->name)->toBe('Experimental Draft');

    $this->actingAs($user)
        ->get(route('recipes.saved', $recipe))
        ->assertSuccessful()
        ->assertSee('Replace the current formula?')
        ->assertSee('name="confirm_replace_current" value="1"', false)
        ->assertSee('action="'.route('recipes.use-version-as-current', ['recipe' => $recipe, 'version' => $olderSavedVersion]).'"', false);

    $this->actingAs($user)
        ->post(route('recipes.use-version-as-current', ['recipe' => $recipe, 'version' => $olderSavedVersion]), [
            'confirm_replace_current' => '1',
        ])
        ->assertRedirect(route('recipes.edit', $recipe));

    $currentDraft->refresh();

    expect($currentDraft->name)->toBe('Formula A');
});

it('redirects signed-out users before replacing the draft with a saved version', function () {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeSavedRecipeIngredient();
    $service = app(RecipeWorkbenchService::class);

    $draftVersion = $service->save(
        $user,
        $soapFamily,
        soapVersionDraftPayload($ingredient, 'Formula A'),
    );

    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    $service->publish($user, $soapFamily, soapVersionDraftPayload($ingredient, 'Formula A'), $recipe);
    $service->publish($user, $soapFamily, soapVersionDraftPayload($ingredient, 'Formula B'), $recipe);

    $olderSavedVersion = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_current', false)
        ->where('name', 'Formula A')
        ->latest('version_number')
        ->firstOrFail();

    $this->post(route('recipes.use-version-as-current', ['recipe' => $recipe, 'version' => $olderSavedVersion]))
        ->assertRedirect(route('login'));
});

/**
 * @return array{0: User, 1: Recipe, 2: RecipeVersion}
 */
function createSavedRecipeVersion(): array
{
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeSavedRecipeIngredient();

    $service = app(RecipeWorkbenchService::class);
    $draftVersion = $service->save(
        $user,
        $soapFamily,
        soapVersionDraftPayload($ingredient, 'Workbench Draft'),
    );

    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    $service->saveAsNewVersion(
        $user,
        $soapFamily,
        soapVersionDraftPayload($ingredient, 'Published Formula'),
        $recipe,
    );

    $publishedVersion = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_current', false)
        ->latest('version_number')
        ->firstOrFail();

    return [$user, $recipe, $publishedVersion];
}

/**
 * @return array{0: User, 1: Recipe, 2: RecipeVersion}
 */
function createRecipeWithTwoDistinctSavedVersions(?User $user = null): array
{
    $user ??= User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap-'.fake()->unique()->slug(),
        'name' => 'Soap',
    ]);
    $oliveOil = makeSavedRecipeIngredient();
    $coconutOil = Ingredient::factory()->create([
        'category' => IngredientCategory::CarrierOil,
        'display_name' => 'Coconut Oil',
        'inci_name' => 'COCOS NUCIFERA OIL',
        'soap_inci_naoh_name' => 'SODIUM COCOATE',
        'soap_inci_koh_name' => 'POTASSIUM COCOATE',
        'is_potentially_saponifiable' => true,
        'is_active' => true,
    ]);

    IngredientSapProfile::factory()->create([
        'ingredient_id' => $coconutOil->id,
        'koh_sap_value' => 0.257,
    ]);

    $service = app(RecipeWorkbenchService::class);
    $draftVersion = $service->save($user, $soapFamily, soapVersionDraftPayload($oliveOil, 'Formula A'));
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    $service->publish($user, $soapFamily, soapVersionDraftPayload($oliveOil, 'Formula A'), $recipe);
    $service->publish($user, $soapFamily, soapVersionDraftPayload($coconutOil, 'Formula B'), $recipe);

    $formulaA = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_current', false)
        ->where('name', 'Formula A')
        ->firstOrFail();

    return [$user, $recipe, $formulaA];
}

function recipeWorkbookXml(string $content): string
{
    $path = tempnam(sys_get_temp_dir(), 'koskalk-version-export-test-');
    file_put_contents($path, $content);

    $zip = new ZipArchive;

    expect($zip->open($path))->toBeTrue();

    $xml = collect(range(1, 6))
        ->map(fn (int $index): string => (string) $zip->getFromName("xl/worksheets/sheet{$index}.xml"))
        ->implode("\n");

    $zip->close();
    unlink($path);

    return $xml;
}

function makeSavedRecipeIngredient(): Ingredient
{
    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::CarrierOil,
        'display_name' => 'Olive Oil',
        'inci_name' => 'OLEA EUROPAEA FRUIT OIL',
        'soap_inci_naoh_name' => 'SODIUM OLIVATE',
        'soap_inci_koh_name' => 'POTASSIUM OLIVATE',
        'is_potentially_saponifiable' => true,
        'is_active' => true,
    ]);

    IngredientSapProfile::factory()->create([
        'ingredient_id' => $ingredient->id,
        'koh_sap_value' => 0.188,
    ]);

    return $ingredient;
}

function attachCostingToSavedVersion(User $user, RecipeVersion $version): RecipeVersionCosting
{
    $ingredient = Ingredient::query()
        ->where('display_name', 'Olive Oil')
        ->firstOrFail();

    $costing = RecipeVersionCosting::query()->create([
        'recipe_version_id' => $version->id,
        'user_id' => $user->id,
        'oil_weight_for_costing' => 1000,
        'oil_unit_for_costing' => 'g',
        'units_produced' => 10,
        'currency' => 'EUR',
    ]);

    RecipeVersionCostingItem::query()->create([
        'recipe_version_costing_id' => $costing->id,
        'ingredient_id' => $ingredient->id,
        'phase_key' => 'saponified_oils',
        'position' => 1,
        'price_per_kg' => 8.5,
    ]);

    RecipeVersionCostingPackagingItem::query()->create([
        'recipe_version_costing_id' => $costing->id,
        'name' => 'Bottle',
        'unit_cost' => 1.2,
        'quantity' => 10,
    ]);

    return $costing;
}

/**
 * @return array<string, mixed>
 */
function soapVersionDraftPayload(Ingredient $ingredient, string $name): array
{
    return [
        'name' => $name,
        'oil_unit' => 'g',
        'oil_weight' => 1000,
        'manufacturing_mode' => 'saponify_in_formula',
        'exposure_mode' => 'rinse_off',
        'regulatory_regime' => 'eu',
        'editing_mode' => 'percentage',
        'lye_type' => 'naoh',
        'koh_purity_percentage' => 90,
        'dual_lye_koh_percentage' => 40,
        'water_mode' => 'percent_of_oils',
        'water_value' => 38,
        'superfat' => 5,
        'ifra_product_category_id' => null,
        'phase_items' => [
            'saponified_oils' => [
                [
                    'ingredient_id' => $ingredient->id,
                    'percentage' => 100,
                    'weight' => 1000,
                    'note' => null,
                ],
            ],
            'additives' => [],
            'fragrance' => [],
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function cosmeticSavedFormulaPayload(Ingredient $ingredient): array
{
    return [
        'name' => 'Daily Moisturizer',
        'product_type_id' => null,
        'oil_unit' => 'g',
        'oil_weight' => 500,
        'manufacturing_mode' => 'blend_only',
        'exposure_mode' => 'leave_on',
        'regulatory_regime' => 'eu',
        'editing_mode' => 'percentage',
        'ifra_product_category_id' => null,
        'phases' => [
            [
                'key' => 'phase_a',
                'name' => 'Phase A',
            ],
        ],
        'phase_items' => [
            'phase_a' => [
                [
                    'ingredient_id' => $ingredient->id,
                    'percentage' => 100,
                    'weight' => 500,
                    'note' => null,
                ],
            ],
        ],
    ];
}
