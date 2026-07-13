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
use App\Services\RecipeWorkbenchService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders the formula sheet with print actions', function () {
    [$user, $recipe, $publishedVersion] = createSavedRecipeVersion();

    $this->actingAs($user)
        ->get(route('recipes.saved', ['recipe' => $recipe->id]))
        ->assertSuccessful()
        ->assertSee('Formula sheet')
        ->assertSee('Use this saved formula for scaling, printing, and export.')
        ->assertDontSee('v'.$publishedVersion->version_number)
        ->assertSee('Open formula')
        ->assertSee('Duplicate')
        ->assertDontSee('Reference formula')
        ->assertDontSee('Edit in draft')
        ->assertDontSee('Recovery snapshots')
        ->assertSee('Batch production sheet')
        ->assertSee('Technical recipe sheet')
        ->assertSee('Costing sheet')
        ->assertSee('Export Excel')
        ->assertSee('Export CSV')
        ->assertSee('Published Formula')
        ->assertSee('1000<span class="ml-1 text-sm font-medium text-[var(--color-ink-soft)]">g</span>', false)
        ->assertDontSee('1000.00');
});

it('renders the formula workbench with one save path and lock controls', function () {
    [$user, $recipe] = createSavedRecipeVersion();

    $this->actingAs($user)
        ->get(route('recipes.edit', ['recipe' => $recipe->id]))
        ->assertSuccessful()
        ->assertSee('Formula')
        ->assertSee('Save')
        ->assertSee('Lock formula')
        ->assertSeeInOrder(['Save', 'Lock formula', 'More formula actions'])
        ->assertDontSee('Editable draft')
        ->assertDontSee('Save draft')
        ->assertDontSee('Save as reference formula')
        ->assertDontSee('Update reference formula?')
        ->assertDontSee('This will replace the reference formula with your current draft.')
        ->assertDontSee('Save recipe');
});

it('shows older saved formulas in version history', function () {
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
        ->get(route('recipes.saved', ['recipe' => $recipe->id]))
        ->assertSuccessful()
        ->assertSee('Version history')
        ->assertSee('Formula A')
        ->assertSee('2026-07-12 09:30')
        ->assertSee('View version')
        ->assertSee('href="'.route('recipes.version', ['recipe' => $recipe->id, 'version' => $olderSavedVersion->id]).'"', false)
        ->assertSee('Restore to current formula')
        ->assertSee('method="POST" action="'.route('recipes.use-version-as-current', ['recipe' => $recipe->id, 'version' => $olderSavedVersion->id]).'"', false)
        ->assertDontSee('action="'.route('recipes.saved.restore', ['recipe' => $recipe->id, 'version' => $olderSavedVersion->id]).'"', false);

    $onlyVersion = $service->save($user, $soapFamily, soapVersionDraftPayload($ingredient, 'Only Formula'));

    $this->actingAs($user)
        ->get(route('recipes.saved', ['recipe' => $onlyVersion->recipe_id]))
        ->assertSuccessful()
        ->assertDontSee('Version history');
});

it('locks and unlocks a formula', function () {
    [$user, $recipe] = createSavedRecipeVersion();

    $this->actingAs($user)
        ->post(route('recipes.lock', $recipe->id))
        ->assertRedirect(route('recipes.edit', $recipe->id))
        ->assertSessionHas('status', 'Formula locked.');

    expect($recipe->fresh()->locked_at)->not->toBeNull()
        ->and($recipe->fresh()->locked_by)->toBe($user->id);

    $this->actingAs($user)
        ->get(route('recipes.edit', $recipe->id))
        ->assertSuccessful()
        ->assertSee('Unlock formula')
        ->assertSeeInOrder(['Unlock formula', 'More formula actions']);

    $this->actingAs($user)
        ->post(route('recipes.unlock', $recipe->id))
        ->assertRedirect(route('recipes.edit', $recipe->id))
        ->assertSessionHas('status', 'Formula unlocked.');

    expect($recipe->fresh()->locked_at)->toBeNull()
        ->and($recipe->fresh()->locked_by)->toBeNull();
});

it('recalculates the saved formula view when a different oil quantity is requested', function () {
    [$user, $recipe, $publishedVersion] = createSavedRecipeVersion();

    $this->actingAs($user)
        ->get(route('recipes.saved', [
            'recipe' => $recipe->id,
            'oil_weight' => 1500,
        ]))
        ->assertSuccessful()
        ->assertSee('value="1500"', false)
        ->assertSee('Recalculate');
});

it('renders purpose-based print pages for the current saved formula', function () {
    [$user, $recipe, $publishedVersion] = createSavedRecipeVersion();
    attachCostingToSavedVersion($user, $publishedVersion);

    $this->actingAs($user)
        ->get(route('recipes.print.production', ['recipe' => $recipe->id]))
        ->assertSuccessful()
        ->assertSee('Batch production sheet')
        ->assertSee('Batch no.')
        ->assertSee('Made by')
        ->assertSee('Checked by')
        ->assertSee('document-sheet', false)
        ->assertDontSee('Declaration details');

    $this->actingAs($user)
        ->get(route('recipes.print.technical', ['recipe' => $recipe->id]))
        ->assertSuccessful()
        ->assertSee('Technical recipe sheet')
        ->assertSee('Ingredient list preview')
        ->assertSee('Declaration details')
        ->assertDontSee('Batch no.');

    $this->actingAs($user)
        ->get(route('recipes.print.costing', ['recipe' => $recipe->id]))
        ->assertSuccessful()
        ->assertSee('Costing sheet')
        ->assertSee('Ingredient costs')
        ->assertSee('Packaging costs')
        ->assertSee('Olive Oil')
        ->assertSee('Bottle')
        ->assertSee('Total batch cost')
        ->assertSee('120 EUR');
});

it('passes batch context from the saved page to print sheets', function () {
    [$user, $recipe, $publishedVersion] = createSavedRecipeVersion();
    attachCostingToSavedVersion($user, $publishedVersion);

    $response = $this->actingAs($user)
        ->get(route('recipes.saved', [
            'recipe' => $recipe->id,
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
            'recipe' => $recipe->id,
            'oil_weight' => 1500,
            'batch_number' => 'B-2026-042',
            'manufacture_date' => '2026-04-20',
            'units_produced' => 24,
        ]))
        ->assertSuccessful()
        ->assertSee('B-2026-042')
        ->assertSee('2026-04-20')
        ->assertSee('24');
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
        ->get(route('recipes.saved', ['recipe' => $recipe->id]))
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
        ->get(route('recipes.saved', ['recipe' => $recipe->id]))
        ->assertSuccessful()
        ->assertSee('Total batch quantity')
        ->assertDontSee('Oil quantity');
});

it('downloads the saved formula as a simple csv', function () {
    [$user, $recipe] = createSavedRecipeVersion();

    $response = $this->actingAs($user)
        ->get(route('recipes.export.csv', ['recipe' => $recipe->id]))
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
            'recipe' => $recipe->id,
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
        ->get(route('recipes.export.csv', ['recipe' => $recipe->id]))
        ->assertNotFound();

    $this->actingAs($otherUser)
        ->get(route('recipes.export.xlsx', ['recipe' => $recipe->id]))
        ->assertNotFound();
});

it('does not expose the saved formula to other users', function () {
    [$owner, $recipe, $publishedVersion] = createSavedRecipeVersion();
    $otherUser = User::factory()->create();

    $this->actingAs($otherUser)
        ->get(route('recipes.saved', ['recipe' => $recipe->id]))
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
        ->get(route('recipes.saved', ['recipe' => $recipe->id]))
        ->assertSuccessful()
        ->assertSee('<title>Formula B · Formula Sheet', false)
        ->assertSee('>Formula B</h1>', false)
        ->assertSee('Saved formula')
        ->assertDontSee('Previous version');

    $this->actingAs($user)
        ->get(route('recipes.version', ['recipe' => $recipe->id, 'version' => $formulaA->id]))
        ->assertSuccessful()
        ->assertSee('Formula sheet')
        ->assertSee('<title>Formula A · Formula Sheet', false)
        ->assertDontSee('<title>Formula B · Formula Sheet', false)
        ->assertSee('>Formula A</h1>', false)
        ->assertDontSee('>Formula B</h1>', false)
        ->assertSee('Previous version')
        ->assertSee('Back to active formula')
        ->assertSee('href="'.route('recipes.saved', $recipe->id).'"', false)
        ->assertDontSee('action="'.route('recipes.use-version-as-current', ['recipe' => $recipe->id, 'version' => $formulaA->id]).'"', false);
});

it('duplicates a recipe into a new draft recipe', function () {
    [$user, $recipe, $publishedVersion] = createSavedRecipeVersion();

    $this->actingAs($user)
        ->post(route('recipes.duplicate', ['recipe' => $recipe->id]))
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
        ->post(route('recipes.saved.edit-current', ['recipe' => $recipe->id]))
        ->assertRedirect(route('recipes.edit', $recipe->id));

    $draft->refresh();

    expect($draft->name)->toBe('Published Formula');
});

it('redirects signed-out users before refreshing the draft from the saved formula', function () {
    [$user, $recipe, $publishedVersion] = createSavedRecipeVersion();

    $this->post(route('recipes.saved.edit-current', ['recipe' => $recipe->id]))
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
        ->post(route('recipes.saved.edit-current', ['recipe' => $recipe->id]))
        ->assertRedirect(route('recipes.saved', $recipe->id))
        ->assertSessionHas('currentReplaceConfirmation');

    $draft->refresh();

    expect($draft->name)->toBe('Experimental Draft');

    $this->actingAs($user)
        ->post(route('recipes.saved.edit-current', ['recipe' => $recipe->id]), [
            'confirm_replace_current' => '1',
        ])
        ->assertRedirect(route('recipes.edit', $recipe->id));

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
        ->post(route('recipes.saved.restore', ['recipe' => $recipe->id, 'version' => $olderSavedVersion->id]))
        ->assertRedirect(route('recipes.saved', $recipe->id));

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

    $this->post(route('recipes.saved.restore', ['recipe' => $recipe->id, 'version' => $savedVersion->id]))
        ->assertRedirect(route('login'));
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
        ->post(route('recipes.saved.restore', ['recipe' => $recipe->id, 'version' => $olderSavedVersion->id]))
        ->assertRedirect(route('recipes.saved', $recipe->id));

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
        ->post(route('recipes.use-version-as-current', ['recipe' => $recipe->id, 'version' => $olderSavedVersion->id]))
        ->assertRedirect(route('recipes.saved', $recipe->id))
        ->assertSessionHas('currentReplaceConfirmation');

    $currentDraft->refresh();

    expect($currentDraft->name)->toBe('Experimental Draft');

    $this->actingAs($user)
        ->get(route('recipes.saved', $recipe->id))
        ->assertSuccessful()
        ->assertSee('Replace the current formula?')
        ->assertSee('name="confirm_replace_current" value="1"', false)
        ->assertSee('action="'.route('recipes.use-version-as-current', ['recipe' => $recipe->id, 'version' => $olderSavedVersion->id]).'"', false);

    $this->actingAs($user)
        ->post(route('recipes.use-version-as-current', ['recipe' => $recipe->id, 'version' => $olderSavedVersion->id]), [
            'confirm_replace_current' => '1',
        ])
        ->assertRedirect(route('recipes.edit', $recipe->id));

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

    $this->post(route('recipes.use-version-as-current', ['recipe' => $recipe->id, 'version' => $olderSavedVersion->id]))
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
