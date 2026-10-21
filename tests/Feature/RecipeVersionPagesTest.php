<?php

use App\Enums\IngredientCategory;
use App\Enums\IngredientSubcategory;
use App\Enums\MediaAssetUsageRole;
use App\Enums\OwnerType;
use App\Enums\Visibility;
use App\Enums\WorkspaceMemberRole;
use App\Livewire\Dashboard\RecipesIndex;
use App\Livewire\Dashboard\RecipeWorkbench;
use App\Models\Ingredient;
use App\Models\IngredientSapProfile;
use App\Models\InterfaceTranslation;
use App\Models\MediaAsset;
use App\Models\PackagingItem;
use App\Models\Plan;
use App\Models\ProductFamily;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\RecipeVersionCosting;
use App\Models\RecipeVersionCostingItem;
use App\Models\RecipeVersionCostingPackagingItem;
use App\Models\SupportedLocale;
use App\Models\User;
use App\Models\UserEntitlement;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\FormulaDocumentOptions;
use App\Services\MediaAssetUsageService;
use App\Services\RecipeSopSnapshotService;
use App\Services\RecipeWorkbenchService;
use App\Support\RichContentAttachmentPaths;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;

uses(RefreshDatabase::class);

it('keeps saved formula outputs read only with or without saved costing', function (string $routeName, bool $hasCosting): void {
    [$user, $recipe, $version] = createSavedRecipeVersion();

    if ($hasCosting) {
        $costing = RecipeVersionCosting::query()->create([
            'recipe_version_id' => $version->id,
            'user_id' => $user->id,
            'oil_weight_for_costing' => 1000,
            'oil_unit_for_costing' => 'g',
            'units_produced' => 10,
            'currency' => 'EUR',
        ]);
        $item = $version->items()->withoutGlobalScopes()->firstOrFail();
        $costing->items()->create([
            'ingredient_id' => $item->ingredient_id,
            'phase_key' => 'saponified_oils',
            'position' => 1,
            'price_per_kg' => '8.5000',
        ]);
        $costing->packagingItems()->create([
            'name' => 'Free insert',
            'unit_cost' => 0,
            'quantity' => 1,
        ]);
    }

    $this->actingAs($user);
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $response = $this->get(route($routeName, ['recipe' => $recipe]))->assertSuccessful();

        if (str_contains($routeName, '.export.')) {
            expect($response->streamedContent())->not->toBeEmpty();
        }

        $domainWrites = collect(DB::getQueryLog())->pluck('query')->filter(
            fn (string $query): bool => preg_match('/^\s*(insert|update|delete|replace)\b/i', $query) === 1
                && preg_match('/\b(recipes|recipe_versions|recipe_phases|recipe_items|recipe_version_packaging_items|recipe_version_costings|recipe_version_costing_items|recipe_version_costing_packaging_items|current_material_prices|production_batches|production_batch_ingredients|production_batch_packaging_items)\b/i', $query) === 1,
        );
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }

    expect($domainWrites->all())->toBe([]);
})->with([
    'saved formula' => 'recipes.saved',
    'production print' => 'recipes.print.production',
    'technical print' => 'recipes.print.technical',
    'costing print' => 'recipes.print.costing',
    'Excel export' => 'recipes.export.xlsx',
    'CSV export' => 'recipes.export.csv',
    'Composition export' => 'recipes.export.composition',
])->with([
    'no saved costing' => false,
    'saved costing' => true,
]);

it('renders the formula sheet around one aligned table', function () {
    [$user, $recipe, $publishedVersion] = createSavedRecipeVersion();

    $response = $this->withSession([
        '_token' => 'formula-sheet-v1-csrf-token',
    ])->actingAs($user)
        ->get(route('recipes.saved', ['recipe' => $recipe]))
        ->assertSuccessful()
        ->assertSee('Formula Sheet')
        ->assertSee('data-update-uri', false)
        ->assertSeeInOrder(['Saponified oils', 'Lye and water', 'Formula additions'])
        ->assertSee('% of oils')
        ->assertSee('NaOH')
        ->assertSee('Water')
        ->assertSee('Calculated results')
        ->assertDontSeeText('v'.$publishedVersion->version_number)
        ->assertSee('Open formula')
        ->assertSee('Duplicate')
        ->assertDontSee('Reference formula')
        ->assertDontSee('Edit in draft')
        ->assertDontSee('Recovery snapshots')
        ->assertDontSee('Batch production sheet')
        ->assertDontSee('Technical recipe sheet')
        ->assertDontSee('Costing sheet')
        ->assertSee('Print sheet')
        ->assertSee('Export formula')
        ->assertSee('Excel (.xlsx)')
        ->assertSee('CSV')
        ->assertDontSee('Export Excel')
        ->assertDontSee('Export CSV')
        ->assertDontSee('name="include_analysis"', false)
        ->assertDontSee('How this recipe was calculated')
        ->assertSee('Olive Oil')
        ->assertSee('Weight (g)')
        ->assertDontSee('1000.00');

    expect(substr_count($response->getContent(), 'data-formula-document-table'))->toBe(1)
        ->and(strpos($response->getContent(), 'Lye and water'))
        ->toBeLessThan(strpos($response->getContent(), 'Calculated results'));
});

it('keeps historical version and scale parameters in saved sheet actions', function (): void {
    [$user, $recipe, $historicalVersion] = createSavedRecipeVersion();

    $response = $this->actingAs($user)
        ->get(route('recipes.version', [
            'recipe' => $recipe,
            'version' => $historicalVersion,
            'oil_weight' => 750,
            'batch_number' => 'TRIAL-14',
            'batch_basis' => 750,
            'units_produced' => 4,
        ]))
        ->assertSuccessful();

    $html = $response->getContent();
    $exportQuery = function (string $format) use ($html, $recipe): array {
        preg_match_all('/href="([^"]+)"/', $html, $matches);
        $routePath = parse_url(route('recipes.export.'.$format, ['recipe' => $recipe]), PHP_URL_PATH);
        $href = collect($matches[1])
            ->map(fn (string $value): string => html_entity_decode($value, ENT_QUOTES | ENT_HTML5))
            ->first(fn (string $value): bool => parse_url($value, PHP_URL_PATH) === $routePath);

        expect($href)->toBeString();
        parse_str((string) parse_url($href, PHP_URL_QUERY), $query);

        return $query;
    };

    expect($html)
        ->toContain('action="'.route('recipes.print.recipe', ['recipe' => $recipe]).'"')
        ->toContain('name="oil_weight" value="750"')
        ->toContain('name="version" value="'.$historicalVersion->public_id.'"')
        ->toContain('name="batch_number" value="TRIAL-14"')
        ->and($exportQuery('xlsx'))->toMatchArray([
            'oil_weight' => '750',
            'batch_number' => 'TRIAL-14',
            'batch_basis' => '750',
            'units_produced' => '4',
            'version' => $historicalVersion->public_id,
        ])
        ->and($exportQuery('csv'))->toMatchArray([
            'oil_weight' => '750',
            'batch_number' => 'TRIAL-14',
            'batch_basis' => '750',
            'units_produced' => '4',
            'version' => $historicalVersion->public_id,
        ])
        ->and($exportQuery('composition'))->toMatchArray([
            'oil_weight' => '750',
            'batch_number' => 'TRIAL-14',
            'batch_basis' => '750',
            'units_produced' => '4',
            'version' => $historicalVersion->public_id,
        ]);
});

it('localizes the formula sheet title and recalculation action from the interface database', function () {
    [$user, $recipe] = createSavedRecipeVersion();

    $this->seed('Database\\Seeders\\SupportedLocaleSeeder');
    SupportedLocale::query()->where('code', 'fr')->update(['is_active' => true]);
    $user->update(['locale' => 'fr']);
    App::setLocale('fr');

    InterfaceTranslation::query()->create([
        'group' => 'formula_documents',
        'key' => 'title',
        'text' => ['fr' => 'Fiche de formule'],
    ]);
    InterfaceTranslation::query()->create([
        'group' => 'formula_documents',
        'key' => 'actions.recalculate',
        'text' => ['fr' => 'Recalculer'],
    ]);
    InterfaceTranslation::query()->create([
        'group' => 'formula_documents',
        'key' => 'actions.reset',
        'text' => ['fr' => 'Réinitialiser'],
    ]);
    InterfaceTranslation::query()->create([
        'group' => 'formula_documents',
        'key' => 'scale.title',
        'text' => ['fr' => 'Ajuster la quantité'],
    ]);
    InterfaceTranslation::query()->create([
        'group' => 'formula_documents',
        'key' => 'scale.oil_quantity',
        'text' => ['fr' => 'Quantité d’huiles'],
    ]);

    $this->actingAs($user)
        ->get(route('recipes.saved', ['recipe' => $recipe]))
        ->assertSuccessful()
        ->assertSee('<title>'.$recipe->name.' · Fiche de formule', false)
        ->assertSee('Ajuster la quantité')
        ->assertSee('Quantité d’huiles')
        ->assertSee('Recalculer')
        ->assertSee('Réinitialiser');
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
        ->assertSeeInOrder(['Lock product', 'Duplicate product'])
        ->assertDontSeeHtml('<span class="sr-only">More actions</span>')
        ->assertDontSeeHtml('<p class="sk-eyebrow">Product details</p>')
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

    $queries = collect(DB::getQueryLog());
    $helpQueries = $queries->filter(fn (array $query): bool => str_contains($query['query'], '"help_topics"')
        || (str_contains($query['query'], '"language_lines"') && $query['bindings'] === ['contextual_help']));

    DB::disableQueryLog();

    // Includes snapshot locks/revisions and fresh update/create policy checks; render reuses the mount result.
    // +1 for the sidebar's DIP module grant lookup (role checks only run for granted workspaces).
    expect($queries->count() - $helpQueries->count())->toBeLessThanOrEqual(58)
        ->and($helpQueries->count())->toBeLessThanOrEqual(2);
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
    grantRecipeVersionPageHistory($user, 3);

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
        ->assertSee('Saved history')
        ->assertSee('Earlier saved formula')
        ->assertDontSee('Formula A')
        ->assertSee('2026-07-12 09:30')
        ->assertSee('View saved formula')
        ->assertSee('href="'.route('recipes.version', ['recipe' => $recipe, 'version' => $olderSavedVersion]).'"', false)
        ->assertSee('Use as current formula')
        ->assertSee('method="POST" action="'.route('recipes.use-version-as-current', ['recipe' => $recipe, 'version' => $olderSavedVersion]).'"', false)
        ->assertDontSee('action="'.route('recipes.saved.restore', ['recipe' => $recipe, 'version' => $olderSavedVersion]).'"', false)
        ->assertDontSee('Version history');

    $onlyVersion = $service->save($user, $soapFamily, soapVersionDraftPayload($ingredient, 'Only Formula'));

    $this->actingAs($user)
        ->get(route('recipes.saved', [
            'recipe' => Recipe::withoutGlobalScopes()->findOrFail($onlyVersion->recipe_id),
        ]))
        ->assertSuccessful()
        ->assertDontSee('Saved history');
});

it('does not expose a saved snapshot pruned by the free history limit', function (): void {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create(['slug' => 'soap', 'name' => 'Soap']);
    $ingredient = makeSavedRecipeIngredient();
    $service = app(RecipeWorkbenchService::class);
    $draftVersion = $service->save($user, $soapFamily, soapVersionDraftPayload($ingredient, 'Formula A'));
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);

    $service->publish($user, $soapFamily, soapVersionDraftPayload($ingredient, 'Formula A'), $recipe);
    $prunedVersion = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_current', false)
        ->firstOrFail();
    $prunedVersionUrl = route('recipes.version', ['recipe' => $recipe, 'version' => $prunedVersion]);

    $service->publish($user, $soapFamily, soapVersionDraftPayload($ingredient, 'Formula B'), $recipe);

    $this->actingAs($user)
        ->get(route('recipes.saved', ['recipe' => $recipe]))
        ->assertSuccessful()
        ->assertDontSee($prunedVersionUrl, false)
        ->assertDontSee('Formula A');

    $this->actingAs($user)
        ->get($prunedVersionUrl)
        ->assertNotFound();
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
            'expected_revision' => (int) $recipe->fresh()->edit_revision,
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
            'expected_revision' => (int) $recipe->fresh()->edit_revision,
            'confirm_replace_current' => '1',
        ])
        ->assertNotFound();

    $this->actingAs($viewer)
        ->post(route('recipes.saved.restore', [
            'recipe' => $recipe,
            'version' => $savedVersion,
        ]), ['expected_revision' => (int) $recipe->fresh()->edit_revision])
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
            'expected_revision' => (int) $recipe->fresh()->edit_revision,
            'confirm_replace_current' => '1',
        ])
        ->assertNotFound();

    $this->actingAs($editor)
        ->post(route('recipes.saved.restore', [
            'recipe' => $recipe,
            'version' => $savedVersion,
        ]), ['expected_revision' => (int) $recipe->fresh()->edit_revision])
        ->assertNotFound();
});

it('locks and unlocks a formula', function () {
    [$user, $recipe] = createSavedRecipeVersion();

    $this->actingAs($user)
        ->post(route('recipes.lock', $recipe), ['expected_revision' => (int) $recipe->fresh()->edit_revision])
        ->assertRedirect(route('recipes.edit', $recipe))
        ->assertSessionHas('status', 'Product locked.');

    expect($recipe->fresh()->locked_at)->not->toBeNull()
        ->and($recipe->fresh()->locked_by)->toBe($user->id);

    $this->actingAs($user)
        ->get(route('recipes.edit', $recipe))
        ->assertSuccessful()
        ->assertSee('Unlock product')
        ->assertSeeInOrder(['Unlock product', 'Duplicate product'])
        ->assertDontSeeHtml('<span class="sr-only">More actions</span>');

    $this->actingAs($user)
        ->post(route('recipes.unlock', $recipe), ['expected_revision' => (int) $recipe->fresh()->edit_revision])
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
    [$user, $recipe, $publishedVersion] = createSavedRecipeVersion();
    $procedure = collect(range(1, 40))
        ->map(fn (int $step): string => '<p>Step '.$step.': Mix to emulsion, pour, and cure.</p>')
        ->implode('');
    $recipe->update(['manufacturing_instructions' => $procedure]);
    $publishedVersion->update(['manufacturing_instructions' => $procedure]);

    $response = $this->actingAs($user)
        ->get(route('recipes.print.production', ['recipe' => $recipe]))
        ->assertSuccessful()
        ->assertSee('Formula sheet')
        ->assertSeeInOrder(['Saponified oils', 'Lye and water', 'Formula additions'])
        ->assertSee('Reference')
        ->assertSee('Date')
        ->assertSee('Prepared by')
        ->assertSee('Checked by')
        ->assertDontSee('Trial / batch no.')
        ->assertDontSee('Made by')
        ->assertSee('Observations')
        ->assertSee('Result')
        ->assertSee('Manufacturing procedure')
        ->assertSee('Step 40: Mix to emulsion, pour, and cure.')
        ->assertSee('[data-formula-document-table] thead {', false)
        ->assertSee('display: table-header-group;', false)
        ->assertSee('body.formula-sheet-document main {', false)
        ->assertSee('[data-formula-procedure-content] {', false)
        ->assertSee('break-inside: auto;', false)
        ->assertDontSee('Cost summary')
        ->assertDontSee('Declaration details')
        ->assertDontSee('Packaging costs');

    $response = $this->actingAs($user)
        ->get(route('recipes.print.production', [
            'recipe' => $recipe,
            'include_analysis' => 1,
        ]))
        ->assertSuccessful()
        ->assertSee('Soap analysis')
        ->assertSee('Soap qualities')
        ->assertSee('Fatty-acid profile');
});

it('shows available formula sections with the working-copy defaults', function () {
    [$user, $recipe, $publishedVersion] = createSavedRecipeVersion();
    $recipe->update(['description' => '<p>Our family formula.</p>']);
    $publishedVersion->update(['manufacturing_instructions' => '<p>Mix gently and pour.</p>']);

    $response = $this->actingAs($user)
        ->get(route('recipes.print.production', ['recipe' => $recipe]))
        ->assertSuccessful()
        ->assertSee('Formula sheet')
        ->assertDontSee('<dt class="font-semibold text-slate-600">Source</dt>', false)
        ->assertDontSee('<dt class="font-semibold text-slate-600">Version</dt>', false)
        ->assertSee('Print sheet')
        ->assertDontSee('Print / Save PDF')
        ->assertSee('To save a PDF, choose')
        ->assertViewHas('returnUrl', route('recipes.saved', ['recipe' => $recipe]))
        ->assertDontSee('data-return-to-workbench', false)
        ->assertSee('Oil basis quantity')
        ->assertSee('Generated')
        ->assertSee('Our family formula.')
        ->assertSee('Formula settings')
        ->assertSee('Calculated results')
        ->assertSee('Mix gently and pour.')
        ->assertSee('Label text')
        ->assertSeeInOrder([
            'data-formula-section-toggle="formula"',
            'data-formula-section-toggle="procedure"',
            'data-formula-section-toggle="label"',
            'data-formula-section-toggle="analysis"',
            'data-formula-section-toggle="notes"',
        ], false)
        ->assertSee('data-formula-section="formula"', false)
        ->assertSee('data-formula-section="procedure"', false)
        ->assertSee('data-formula-section="label"', false)
        ->assertSee('data-formula-section="notes"', false)
        ->assertDontSee('name="sections[]" value="composition"', false)
        ->assertDontSee('name="sections[]" value="costing"', false)
        ->assertDontSee('name="sections[]" value="analysis" checked', false);

    $html = new DOMDocument;
    $html->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
    expect((new DOMXPath($html))->query('//article/header/p'))->toHaveCount(0);

    expect($response->getContent())
        ->toMatch('/<input(?=[^>]*data-formula-section-toggle="formula")(?=[^>]*\bchecked\b)[^>]*>/s')
        ->toMatch('/<input(?=[^>]*data-formula-section-toggle="procedure")(?=[^>]*\bchecked\b)[^>]*>/s')
        ->toMatch('/<input(?=[^>]*data-formula-section-toggle="label")(?=[^>]*\bchecked\b)[^>]*>/s')
        ->toMatch('/<input(?=[^>]*data-formula-section-toggle="notes")(?=[^>]*\bchecked\b)[^>]*>/s')
        ->not->toMatch('/<input(?=[^>]*data-formula-section-toggle="analysis")(?=[^>]*\bchecked\b)[^>]*>/s');
});

it('uses formula and procedure as the instruction context defaults', function (): void {
    $options = app(FormulaDocumentOptions::class);
    $availableSections = $options->availableSections([
        'procedure' => true,
        'label' => true,
        'analysis' => true,
    ]);

    expect($options->defaultSections($availableSections, 'instructions'))
        ->toBe(['formula', 'procedure'])
        ->and($availableSections)
        ->not->toContain('composition')
        ->not->toContain('costing');
});

it('prints only the selected formula sections', function () {
    [$user, $recipe, $publishedVersion] = createSavedRecipeVersion();
    $publishedVersion->update(['manufacturing_instructions' => '<p>Selected procedure.</p>']);

    $response = $this->actingAs($user)
        ->get(route('recipes.print.production', [
            'recipe' => $recipe,
            'sections' => ['procedure'],
        ]))
        ->assertSuccessful();

    expect($response->getContent())
        ->toMatch('/<input(?=[^>]*value="procedure")(?=[^>]*\bchecked\b)[^>]*>/s')
        ->and($response->getContent())
        ->toMatch('/data-formula-section="formula"[^>]*\bhidden\b/')
        ->toMatch('/data-formula-section="label"[^>]*\bhidden\b/')
        ->toMatch('/data-formula-section="notes"[^>]*\bhidden\b/')
        ->not->toMatch('/data-formula-section="procedure"[^>]*\bhidden\b/');
});

it('rejects an empty formula print selection', function (): void {
    [$user, $recipe] = createSavedRecipeVersion();
    $returnUrl = route('recipes.saved', ['recipe' => $recipe]);

    $response = $this->from($returnUrl)
        ->actingAs($user)
        ->get(route('recipes.print.production', ['recipe' => $recipe]).'?sections%5B%5D=')
        ->assertRedirect($returnUrl)
        ->assertSessionHasErrors(['sections']);

    expect($response->getSession()->get('errors')->getBag('default')->first('sections'))
        ->toBe(__('formula_documents.validation.sections_required'));
});

it('rejects an unknown formula print section', function (): void {
    [$user, $recipe] = createSavedRecipeVersion();
    $returnUrl = route('recipes.saved', ['recipe' => $recipe]);

    $response = $this->from($returnUrl)
        ->actingAs($user)
        ->get(route('recipes.print.production', ['recipe' => $recipe]).'?sections%5B%5D=not-a-section')
        ->assertRedirect($returnUrl)
        ->assertSessionHasErrors(['sections']);

    expect($response->getSession()->get('errors')->getBag('default')->first('sections'))
        ->toBe(__('formula_documents.validation.sections_unknown'));
});

it('rejects a formula print section with no content', function (): void {
    [$user, $recipe] = createSavedRecipeVersion();
    $returnUrl = route('recipes.saved', ['recipe' => $recipe]);

    $response = $this->from($returnUrl)
        ->actingAs($user)
        ->get(route('recipes.print.production', ['recipe' => $recipe]).'?sections%5B%5D=costing')
        ->assertRedirect($returnUrl)
        ->assertSessionHasErrors(['sections']);

    expect($response->getSession()->get('errors')->getBag('default')->first('sections'))
        ->toBe(__('formula_documents.validation.sections_unavailable'));
});

it('does not offer soap analysis for a cosmetic formula', function () {
    $user = User::factory()->create();
    $cosmeticFamily = ProductFamily::factory()->create([
        'slug' => 'cosmetic',
        'name' => 'Cosmetic',
        'calculation_basis' => 'total_formula',
    ]);
    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::Other,
        'display_name' => 'Glycerin',
        'inci_name' => 'GLYCERIN',
        'is_active' => true,
    ]);
    $version = app(RecipeWorkbenchService::class)->save(
        $user,
        $cosmeticFamily,
        cosmeticSavedFormulaPayload($ingredient),
    );
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($version->recipe_id);
    app(RecipeWorkbenchService::class)->saveAsNewVersion(
        $user,
        $cosmeticFamily,
        cosmeticSavedFormulaPayload($ingredient),
        $recipe,
    );

    $response = $this->actingAs($user)
        ->get(route('recipes.print.production', ['recipe' => $recipe]))
        ->assertSuccessful()
        ->assertSee('Formula')
        ->assertSee('Formula basis quantity')
        ->assertDontSee('data-formula-section-toggle="analysis"', false)
        ->assertDontSee('Soap analysis');
});

it('keeps nonzero small formula quantities readable on the print sheet', function () {
    [$user, $recipe] = createSavedRecipeVersion();

    $this->actingAs($user)
        ->get(route('recipes.print.production', [
            'recipe' => $recipe,
            'oil_weight' => '0.001',
        ]))
        ->assertSuccessful()
        ->assertSee('>0.001</td>', false);
});

it('uses the requested saved-sheet batch basis and units for its optional costing projection', function (): void {
    [$user, $recipe, $version] = createSavedRecipeVersion();
    $ingredient = $version->items()->withoutGlobalScopes()->firstOrFail()->ingredient;
    $packagingItem = createPackagingItemForWorkspace([
        'user_id' => $user->id,
        'name' => 'Requested-batch jar',
        'unit_cost' => 0.25,
        'currency' => 'EUR',
    ]);
    $version->packagingItems()->create([
        'packaging_item_id' => $packagingItem->id,
        'name' => 'Requested-batch jar',
        'components_per_unit' => 2,
        'position' => 1,
    ]);
    $costing = RecipeVersionCosting::query()->create([
        'recipe_version_id' => $version->id,
        'user_id' => $user->id,
        'oil_weight_for_costing' => 1000,
        'oil_unit_for_costing' => 'g',
        'units_produced' => 10,
        'currency' => 'EUR',
    ]);
    $costing->items()->create([
        'ingredient_id' => $ingredient->id,
        'phase_key' => 'saponified_oils',
        'position' => 1,
        'price_per_kg' => 8.5,
    ]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $response = $this->actingAs($user)
            ->get(route('recipes.print.production', [
                'recipe' => $recipe,
                'version' => $version,
                'oil_weight' => 500,
                'batch_basis' => 500,
                'units_produced' => 4,
                'sections' => ['costing'],
            ]))
            ->assertSuccessful()
            ->assertViewHas('selectedSections', ['costing'])
            ->assertViewHas('costingProjection', function (array $projection) use ($ingredient): bool {
                $oilRow = collect($projection['ingredient_rows'])->firstWhere('ingredient_id', $ingredient->id);

                return $projection['basis_weight'] === 500.0
                    && $projection['basis_unit'] === 'g'
                    && $projection['units_produced'] === 4.0
                    && $oilRow['quantity'] === 500.0
                    && $oilRow['line_cost'] === 4.25
                    && $projection['packaging_rows'][0]['line_cost'] === 2.0
                    && $projection['total_cost'] === 6.25
                    && $projection['cost_per_unit'] === 1.5625;
            });

        $domainWrites = collect(DB::getQueryLog())->pluck('query')->filter(
            fn (string $query): bool => preg_match('/^\s*(insert|update|delete|replace)\b/i', $query) === 1
                && preg_match('/\b(recipes|recipe_versions|recipe_phases|recipe_items|recipe_version_packaging_items|recipe_version_costings|recipe_version_costing_items|recipe_version_costing_packaging_items|current_material_prices|production_batches|production_batch_ingredients|production_batch_packaging_items)\b/i', $query) === 1,
        );
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }

    expect($domainWrites->all())->toBe([])
        ->and($response->getContent())->toContain('6.25 EUR');
});

it('renders the procedure stored on the selected formula snapshot', function () {
    [$user, $recipe, $historicalVersion] = createSavedRecipeVersion();
    $currentVersion = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_current', true)
        ->firstOrFail();

    $historicalVersion->update([
        'manufacturing_instructions' => '<p>Original procedure</p>',
    ]);
    $currentVersion->update([
        'manufacturing_instructions' => '<p>Revised procedure</p>',
    ]);
    $recipe->update([
        'manufacturing_instructions' => '<p>Revised procedure</p>',
    ]);

    foreach (['recipes.version', 'recipes.print.production'] as $routeName) {
        $this->actingAs($user)
            ->get(route($routeName, ['recipe' => $recipe, 'version' => $historicalVersion]))
            ->assertSuccessful()
            ->assertSee('Original procedure')
            ->assertDontSee('Revised procedure');
    }

    $this->actingAs($user)
        ->get(route('recipes.print.production', ['recipe' => $recipe, 'version' => $historicalVersion]))
        ->assertSuccessful()
        ->assertDontSee('<dt class="font-semibold text-slate-600">Source</dt>', false)
        ->assertDontSee('<dt class="font-semibold text-slate-600">Version</dt>', false)
        ->assertViewHas('returnUrl', route('recipes.version', ['recipe' => $recipe, 'version' => $historicalVersion]))
        ->assertSee('Original procedure')
        ->assertDontSee('Revised procedure');

    $this->actingAs($user)
        ->get(route('recipes.saved', ['recipe' => $recipe]))
        ->assertSuccessful()
        ->assertSee('Revised procedure')
        ->assertDontSee('Original procedure');
});

it('renders inline SOP media stored on the selected historical formula snapshot', function () {
    [$user, $recipe, $historicalVersion] = createSavedRecipeVersion();
    $workspace = $user->company();
    $recipe->update(['workspace_id' => $workspace->id]);
    $historicalVersion->update(['workspace_id' => $workspace->id]);
    $asset = MediaAsset::factory()->ready()->create(['workspace_id' => $workspace->id, 'original_filename' => 'Historic SOP.webp']);
    $historicalVersion->update([
        'manufacturing_instructions' => sprintf(
            '<img data-id="%s" src="https://attacker.invalid/tampered.webp">',
            RichContentAttachmentPaths::mediaAssetIdentity($asset->public_id),
        ),
    ]);

    app(MediaAssetUsageService::class)->syncSingle($user, $historicalVersion, MediaAssetUsageRole::RecipeSop, $asset->id);

    $this->actingAs($user)
        ->get(route('recipes.version', ['recipe' => $recipe, 'version' => $historicalVersion]))
        ->assertSuccessful()
        ->assertSee(route('media.show', [$asset, 'master']))
        ->assertDontSee('attacker.invalid')
        ->assertDontSee('Historic SOP.webp');

    $response = $this->actingAs($user)
        ->get(route('recipes.print.production', ['recipe' => $recipe, 'version' => $historicalVersion]))
        ->assertSuccessful();

    $response->assertSee('data-formula-section-toggle="procedure"', false)
        ->assertSee('data-formula-procedure-content', false)
        ->assertSee(route('media.show', [$asset, 'master']))
        ->assertDontSee('attacker.invalid');
});

it('renders inline SOP media from the current version rather than the mutable recipe', function () {
    [$user, $recipe] = createSavedRecipeVersion();
    $workspace = $user->company();
    $recipe->update(['workspace_id' => $workspace->id]);
    $currentVersion = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_current', true)
        ->firstOrFail();
    $currentVersion->update(['workspace_id' => $workspace->id]);
    $recipeAsset = MediaAsset::factory()->ready()->create(['workspace_id' => $workspace->id, 'original_filename' => 'Mutable recipe SOP.webp']);
    $versionAsset = MediaAsset::factory()->ready()->create(['workspace_id' => $workspace->id, 'original_filename' => 'Current snapshot SOP.webp']);
    $recipe->update([
        'manufacturing_instructions' => sprintf(
            '<img data-id="%s" src="%s">',
            RichContentAttachmentPaths::mediaAssetIdentity($recipeAsset->public_id),
            route('media.show', [$recipeAsset, 'master']),
        ),
    ]);
    $currentVersion->update([
        'manufacturing_instructions' => sprintf(
            '<img data-id="%s" src="%s">',
            RichContentAttachmentPaths::mediaAssetIdentity($versionAsset->public_id),
            route('media.show', [$versionAsset, 'master']),
        ),
    ]);
    $usages = app(MediaAssetUsageService::class);
    $usages->syncSingle($user, $recipe, MediaAssetUsageRole::RecipeSop, $recipeAsset->id);
    $usages->syncSingle($user, $currentVersion, MediaAssetUsageRole::RecipeSop, $versionAsset->id);

    $this->actingAs($user)
        ->get(route('recipes.saved', ['recipe' => $recipe]))
        ->assertSuccessful()
        ->assertSee(route('media.show', [$versionAsset, 'master']))
        ->assertDontSee(route('media.show', [$recipeAsset, 'master']))
        ->assertDontSee('Current snapshot SOP.webp')
        ->assertDontSee('Mutable recipe SOP.webp');
});

it('copies SOP media from the synchronized current version to both versions created by publish', function () {
    [$user, $recipe] = createSavedRecipeVersion();
    $workspace = $user->company();
    $recipe->update(['workspace_id' => $workspace->id]);
    RecipeVersion::withoutGlobalScopes()->where('recipe_id', $recipe->id)->update(['workspace_id' => $workspace->id]);
    $currentVersion = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_current', true)
        ->firstOrFail();
    $asset = MediaAsset::factory()->ready()->create(['workspace_id' => $workspace->id]);
    $instructions = sprintf(
        '<img data-id="%s" src="%s">',
        RichContentAttachmentPaths::mediaAssetIdentity($asset->public_id),
        route('media.show', [$asset, 'master']),
    );
    $recipe->update(['manufacturing_instructions' => $instructions]);
    app(MediaAssetUsageService::class)->syncSingle($user, $recipe, MediaAssetUsageRole::RecipeSop, $asset->id);
    app(RecipeSopSnapshotService::class)->syncCurrentVersion($recipe, $instructions);

    $ingredient = makeSavedRecipeIngredient();
    $payload = soapVersionDraftPayload($ingredient, 'SOP published');
    $payload['manufacturing_instructions'] = $instructions;
    $newCurrentVersion = app(RecipeWorkbenchService::class)->saveAsNewVersion(
        $user,
        $recipe->productFamily()->withoutGlobalScopes()->firstOrFail(),
        $payload,
        $recipe,
    );

    $publishedVersion = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_current', false)
        ->latest('version_number')
        ->firstOrFail();

    expect($publishedVersion->mediaAssetsForRole(MediaAssetUsageRole::RecipeSop)->pluck('id')->all())->toBe([$asset->id])
        ->and($newCurrentVersion->mediaAssetsForRole(MediaAssetUsageRole::RecipeSop)->pluck('id')->all())->toBe([$asset->id]);
});

it('copies SOP media from the restored source version rather than recipe or current media', function () {
    [$user, $recipe, $sourceVersion] = createSavedRecipeVersion();
    $workspace = $user->company();
    $recipe->update(['workspace_id' => $workspace->id]);
    RecipeVersion::withoutGlobalScopes()->where('recipe_id', $recipe->id)->update(['workspace_id' => $workspace->id]);
    $currentVersion = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_current', true)
        ->firstOrFail();
    $sourceVersion = RecipeVersion::withoutGlobalScopes()->findOrFail($sourceVersion->id);
    $sourceAsset = MediaAsset::factory()->ready()->create(['workspace_id' => $workspace->id]);
    $currentAsset = MediaAsset::factory()->ready()->create(['workspace_id' => $workspace->id]);
    $recipeAsset = MediaAsset::factory()->ready()->create(['workspace_id' => $workspace->id]);
    $usages = app(MediaAssetUsageService::class);
    $usages->syncSingle($user, $sourceVersion, MediaAssetUsageRole::RecipeSop, $sourceAsset->id);
    $usages->syncSingle($user, $currentVersion, MediaAssetUsageRole::RecipeSop, $currentAsset->id);
    $usages->syncSingle($user, $recipe, MediaAssetUsageRole::RecipeSop, $recipeAsset->id);

    $restoredVersion = app(RecipeWorkbenchService::class)->restorePublishedFormula($user, $recipe, $sourceVersion->id);

    expect($restoredVersion->mediaAssetsForRole(MediaAssetUsageRole::RecipeSop)->pluck('id')->all())
        ->toBe([$sourceAsset->id]);
});

it('copies featured and SOP recipe usages when duplicating and snapshots SOP on the new current version', function () {
    [$user, $recipe] = createSavedRecipeVersion();
    $workspace = $user->company();
    $recipe->update(['workspace_id' => $workspace->id]);
    RecipeVersion::withoutGlobalScopes()->where('recipe_id', $recipe->id)->update(['workspace_id' => $workspace->id]);
    $featured = MediaAsset::factory()->ready()->create(['workspace_id' => $workspace->id]);
    $sop = MediaAsset::factory()->ready()->create(['workspace_id' => $workspace->id]);
    $instructions = sprintf(
        '<img data-id="%s" src="%s">',
        RichContentAttachmentPaths::mediaAssetIdentity($sop->public_id),
        route('media.show', [$sop, 'master']),
    );
    $currentVersion = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_current', true)
        ->firstOrFail();
    $recipe->update(['manufacturing_instructions' => $instructions]);
    $currentVersion->update(['manufacturing_instructions' => $instructions]);
    $usages = app(MediaAssetUsageService::class);
    $usages->syncSingle($user, $recipe, MediaAssetUsageRole::RecipeFeatured, $featured->id);
    $usages->syncSingle($user, $recipe, MediaAssetUsageRole::RecipeSop, $sop->id);

    $duplicateCurrent = app(RecipeWorkbenchService::class)->duplicateRecipe($user, $recipe);
    $duplicateRecipe = Recipe::withoutGlobalScopes()->findOrFail($duplicateCurrent->recipe_id);

    expect($usages->idsFor($duplicateRecipe, MediaAssetUsageRole::RecipeFeatured))->toBe([$featured->id])
        ->and($usages->idsFor($duplicateRecipe, MediaAssetUsageRole::RecipeSop))->toBe([$sop->id])
        ->and($duplicateCurrent->mediaAssetsForRole(MediaAssetUsageRole::RecipeSop)->pluck('id')->all())->toBe([$sop->id]);
});

it('restores source-version SOP media to both the recipe and current version', function () {
    [$user, $recipe, $sourceVersion] = createSavedRecipeVersion();
    $workspace = $user->company();
    $recipe->update(['workspace_id' => $workspace->id]);
    RecipeVersion::withoutGlobalScopes()->where('recipe_id', $recipe->id)->update(['workspace_id' => $workspace->id]);
    $sourceVersion = RecipeVersion::withoutGlobalScopes()->findOrFail($sourceVersion->id);
    $currentVersion = RecipeVersion::withoutGlobalScopes()->where('recipe_id', $recipe->id)->where('is_current', true)->firstOrFail();
    $sourceAsset = MediaAsset::factory()->ready()->create(['workspace_id' => $workspace->id]);
    $currentAsset = MediaAsset::factory()->ready()->create(['workspace_id' => $workspace->id]);
    $usages = app(MediaAssetUsageService::class);
    $usages->syncSingle($user, $sourceVersion, MediaAssetUsageRole::RecipeSop, $sourceAsset->id);
    $usages->syncSingle($user, $recipe, MediaAssetUsageRole::RecipeSop, $currentAsset->id);
    $usages->syncSingle($user, $currentVersion, MediaAssetUsageRole::RecipeSop, $currentAsset->id);

    $restoredCurrent = app(RecipeWorkbenchService::class)->restoreCurrentVersion($user, $recipe, $sourceVersion->id);

    expect($usages->idsFor($recipe, MediaAssetUsageRole::RecipeSop))->toBe([$sourceAsset->id])
        ->and($restoredCurrent->mediaAssetsForRole(MediaAssetUsageRole::RecipeSop)->pluck('id')->all())->toBe([$sourceAsset->id]);
});

it('does not substitute current procedure content into a legacy null snapshot', function () {
    [$user, $recipe, $historicalVersion] = createSavedRecipeVersion();
    $historicalVersion->update(['manufacturing_instructions' => null]);
    $recipe->update([
        'manufacturing_instructions' => '<p>Newer recipe procedure</p>',
    ]);

    foreach (['recipes.version', 'recipes.print.production'] as $routeName) {
        $this->actingAs($user)
            ->get(route($routeName, ['recipe' => $recipe, 'version' => $historicalVersion]))
            ->assertSuccessful()
            ->assertDontSee('Newer recipe procedure')
            ->assertDontSee('Manufacturing procedure');
    }
});

it('omits empty authored sections from the working formula sheet', function () {
    [$user, $recipe] = createSavedRecipeVersion();
    $recipe->update([
        'description' => '<p><br></p>',
        'manufacturing_instructions' => '<p><br></p>',
    ]);

    $this->actingAs($user)
        ->get(route('recipes.print.production', ['recipe' => $recipe]))
        ->assertSuccessful()
        ->assertDontSee('Description')
        ->assertDontSee('Manufacturing procedure');
});

it('renders compatibility print routes as the working formula sheet', function (string $routeName) {
    [$user, $recipe, $savedVersion] = createSavedRecipeVersion();

    $this->actingAs($user)
        ->get(route($routeName, ['recipe' => $recipe, 'version' => $savedVersion]))
        ->assertSuccessful()
        ->assertSee('Formula sheet')
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
        ->assertSee('Formula sheet')
        ->assertSee('1500.00 g');
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
    $packagingItem = createPackagingItemForWorkspace([
        'user_id' => $user->id,
        'name' => 'Soap box',
        'unit_cost' => 0.06,
        'currency' => 'EUR',
    ]);

    $currentFormula->packagingItems()->create([
        'packaging_item_id' => $packagingItem->id,
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
        'packaging_item_id' => $packagingItem->id,
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
        'category' => IngredientCategory::Other,
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

it('downloads the saved formula csv with source and basis metadata repeated on each row', function () {
    [$user, $recipe] = createSavedRecipeVersion();

    $response = $this->actingAs($user)
        ->get(route('recipes.export.csv', ['recipe' => $recipe]))
        ->assertSuccessful()
        ->assertDownload('published-formula.csv');

    $rows = collect(explode("\n", trim($response->streamedContent())))
        ->map(fn (string $line): array => str_getcsv($line));
    $headers = $rows->first();
    $ingredientIndex = array_search(__('formula_documents.exports.ingredient'), $headers, true);
    $oilRow = $rows->first(fn (array $row): bool => ($row[$ingredientIndex] ?? null) === 'Olive Oil');

    expect($headers)->toContain(
        __('formula_documents.exports.formula'),
        __('formula_documents.exports.source'),
        __('formula_documents.exports.version'),
        __('formula_documents.exports.generated_at'),
        __('formula_documents.exports.basis'),
        __('formula_documents.exports.formula_basis_unit'),
        __('formula_documents.exports.percentage_basis'),
    )
        ->and($headers[array_search(__('formula_documents.exports.formula_basis_unit'), $headers, true)])
        ->not->toBe($headers[array_search(__('formula_documents.exports.unit'), $headers, true)])
        ->and($oilRow[$ingredientIndex])->toBe('Olive Oil')
        ->and($oilRow[array_search(__('formula_documents.exports.formula'), $headers, true)])->toBe('Published Formula')
        ->and($oilRow[array_search(__('formula_documents.exports.basis'), $headers, true)])->toBe('1000')
        ->and($oilRow[array_search(__('formula_documents.exports.formula_basis_unit'), $headers, true)])->toBe('g')
        ->and($oilRow[array_search(__('formula_documents.exports.percentage_basis'), $headers, true)])->toBe(__('formula_documents.exports.basis_oils'))
        ->and($oilRow[array_search(__('formula_documents.exports.percentage_oils'), $headers, true)])->toBe('100')
        ->not->toContain('Platform')
        ->not->toContain('User')
        ->not->toContain('INCI name');
});

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
    $worksheetXml = collect(range(1, 2))
        ->map(fn (int $index): string => (string) $zip->getFromName("xl/worksheets/sheet{$index}.xml"))
        ->implode("\n");

    $zip->close();
    unlink($path);

    expect($workbookXml)
        ->toContain('Ingredient batch')
        ->toContain('Calculated output')
        ->not->toContain('Summary')
        ->not->toContain('Packaging')
        ->not->toContain('Costing')
        ->not->toContain('INCI Declaration');

    expect(substr_count($workbookXml, '<sheet '))->toBe(2)
        ->and($worksheetXml)
        ->toContain('Olive Oil')
        ->toContain('NaOH')
        ->toContain('SODIUM OLIVATE')
        ->toContain(__('formula_documents.composition.calculated_inci'))
        ->not->toContain('Soap qualities')
        ->not->toContain('Fatty-acid profile')
        ->toContain('customWidth="true"')
        ->toContain('<autoFilter');
});

it('exports the selected saved composition at the requested scale without writing formula records', function (): void {
    [$user, $recipe, $historicalVersion] = createRecipeWithTwoDistinctSavedVersions();
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
    $before = [
        Recipe::withoutGlobalScopes()->count(),
        RecipeVersion::withoutGlobalScopes()->count(),
        RecipeVersionCosting::withoutGlobalScopes()->count(),
    ];

    $response = $this->actingAs($user)->get(route('recipes.export.composition', [
        'recipe' => $recipe,
        'version' => $historicalVersion->public_id,
        'oil_weight' => 750,
        'batch_number' => 'TRIAL-14',
    ]));

    $response->assertSuccessful()
        ->assertDownload(Str::slug($recipe->name).'-composition.xlsx');
    $sheets = readRecipeCompositionWorkbook($response->streamedContent());
    $compositionRows = collect($sheets[__('formula_documents.composition.sheet')]);
    $headers = $compositionRows->first(fn (array $row): bool => in_array(__('formula_documents.composition.ingredient_id'), $row, true));
    $idIndex = array_search(__('formula_documents.composition.ingredient_id'), $headers, true);
    $nameIndex = array_search(__('formula_documents.exports.ingredient'), $headers, true);
    $inciIndex = array_search(__('formula_documents.composition.recorded_inci'), $headers, true);
    $quantityIndex = array_search(__('formula_documents.exports.quantity'), $headers, true);
    $oilRow = $compositionRows->first(fn (array $row): bool => ($row[$nameIndex] ?? null) === 'Olive Oil');
    $naohRow = $compositionRows->first(fn (array $row): bool => ($row[$idIndex] ?? null) === $naoh->id);
    $waterRow = $compositionRows->first(fn (array $row): bool => ($row[$idIndex] ?? null) === $water->id);
    $currentSheet = $this->actingAs($user)->get(route('recipes.saved', $recipe))->assertSuccessful()->getContent();
    $compositionUrl = route('recipes.export.composition', [
        'recipe' => $recipe,
        'version' => $historicalVersion->public_id,
        'oil_weight' => 750,
    ]);

    expect(array_keys($sheets))->toBe([
        __('formula_documents.composition.sheet'),
        __('formula_documents.exports.calculated_output'),
    ])
        ->and($oilRow[$quantityIndex])->toBe(750)
        ->and($oilRow[$inciIndex])->toBe('OLEA EUROPAEA FRUIT OIL')
        ->and($naohRow[$inciIndex])->toBe('Sodium hydroxide')
        ->and($waterRow[$inciIndex])->toBe('Aqua')
        ->and(collect($sheets)->flatten())->toContain(
            $recipe->name,
            __('formula_documents.states.saved'),
            (int) $historicalVersion->version_number,
        )
        ->not->toContain('Coconut Oil', __('formula_documents.exports.price_per_kg'))
        ->and($currentSheet)->toContain('Export composition')
        ->and([
            Recipe::withoutGlobalScopes()->count(),
            RecipeVersion::withoutGlobalScopes()->count(),
            RecipeVersionCosting::withoutGlobalScopes()->count(),
        ])->toBe($before);
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

    $this->actingAs($otherUser)
        ->get(route('recipes.export.composition', ['recipe' => $recipe]))
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
    grantRecipeVersionPageHistory($user, 3);
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
        ->assertSee('Saved history');

    $this->actingAs($user)
        ->get(route('recipes.version', ['recipe' => $recipe, 'version' => $formulaA]))
        ->assertSuccessful()
        ->assertSee('Formula Sheet')
        ->assertSee('<title>Formula B · Formula Sheet', false)
        ->assertDontSee('<title>Formula A · Formula Sheet', false)
        ->assertSee('>Formula B</h1>', false)
        ->assertDontSee('>Formula A</h1>', false)
        ->assertSee('Saved history')
        ->assertSee('Back to current formula')
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

    foreach (['recipes.export.xlsx', 'recipes.export.csv', 'recipes.export.composition'] as $routeName) {
        $routePath = parse_url(route($routeName, ['recipe' => $recipe]), PHP_URL_PATH);
        $html = html_entity_decode($response->getContent());

        expect($html)->toContain((string) $routePath)
            ->and($html)->toContain('version='.$formulaA->public_id);
    }

    $response
        ->assertSee('action="'.route('recipes.print.recipe', ['recipe' => $recipe]).'"', false)
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

it('shows the selected KOH purity on a saved costing-only print', function (): void {
    $user = User::factory()->create();
    grantRecipeVersionPageHistory($user, 3);
    $family = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
        'calculation_basis' => 'initial_oils',
    ]);
    $oil = makeSavedRecipeIngredient();
    Ingredient::factory()->create([
        'catalog_key' => 'CH3',
        'category' => IngredientCategory::SoapmakingAlkalis,
        'subcategory' => IngredientSubcategory::PotassiumHydroxide,
        'display_name' => 'Potassium hydroxide',
        'is_active' => true,
    ]);
    $draft = soapVersionDraftPayload($oil, 'KOH costing formula');
    $draft['lye_type'] = 'koh';
    $draft['koh_purity_percentage'] = 100;
    $workbench = app(RecipeWorkbenchService::class);
    $draftVersion = $workbench->save($user, $family, $draft);
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);
    $workbench->publish($user, $family, $draft, $recipe);
    $version = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('name', 'KOH costing formula')
        ->where('is_current', false)
        ->firstOrFail();
    $costing = RecipeVersionCosting::query()->create([
        'recipe_version_id' => $version->id,
        'user_id' => $user->id,
        'oil_weight_for_costing' => 1000,
        'oil_unit_for_costing' => 'g',
        'units_produced' => 2,
        'currency' => 'EUR',
    ]);
    $costing->items()->createMany([
        ['ingredient_id' => $oil->id, 'phase_key' => 'saponified_oils', 'position' => 1, 'price_per_kg' => '10'],
        ['ingredient_id' => Ingredient::query()->where('catalog_key', 'CH3')->value('id'), 'phase_key' => 'lye_alkali', 'position' => 1, 'price_per_kg' => '20'],
    ]);

    $this->actingAs($user)
        ->get(route('recipes.print.costing', ['recipe' => $recipe, 'version' => $version, 'sections' => ['costing']]))
        ->assertSuccessful()
        ->assertSee(__('workbench.costing.ingredients.koh_with_purity', [
            'name' => 'Potassium hydroxide',
            'purity' => '100',
        ]));
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
        ->assertSee('<title>Main Formula · Formula sheet', false)
        ->assertDontSee('<title>Formula A · Formula sheet', false)
        ->assertSee('>Main Formula</h1>', false)
        ->assertDontSee('>Formula A</h1>', false)
        ->assertSee('Olive Oil')
        ->assertDontSee('Coconut Oil');

    $this->actingAs($user)
        ->get(route('recipes.print.costing', ['recipe' => $recipe, 'version' => $formulaA]))
        ->assertSuccessful()
        ->assertSee('<title>Main Formula · Formula sheet', false)
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
        ->assertSee('<title>Formula B · Formula sheet', false)
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
        ->post(route('recipes.saved.edit-current', ['recipe' => $recipe]), ['expected_revision' => (int) $recipe->fresh()->edit_revision])
        ->assertRedirect(route('recipes.edit', $recipe));

    $draft->refresh();

    expect($draft->name)->toBe('Published Formula');
});

it('redirects signed-out users before refreshing the draft from the saved formula', function () {
    [$user, $recipe, $publishedVersion] = createSavedRecipeVersion();

    $this->post(route('recipes.saved.edit-current', ['recipe' => $recipe]), ['expected_revision' => (int) $recipe->fresh()->edit_revision])
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
        ->post(route('recipes.saved.edit-current', ['recipe' => $recipe]), ['expected_revision' => (int) $recipe->fresh()->edit_revision])
        ->assertRedirect(route('recipes.saved', $recipe))
        ->assertSessionHas('currentReplaceConfirmation');

    $draft->refresh();

    expect($draft->name)->toBe('Experimental Draft');

    $this->actingAs($user)
        ->post(route('recipes.saved.edit-current', ['recipe' => $recipe]), [
            'expected_revision' => (int) $recipe->fresh()->edit_revision,
            'confirm_replace_current' => '1',
        ])
        ->assertRedirect(route('recipes.edit', $recipe));

    $draft->refresh();

    expect($draft->name)->toBe('Published Formula');
});

it('can restore an older saved snapshot as the current saved formula', function () {
    $user = User::factory()->create();
    grantRecipeVersionPageHistory($user, 3);
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
        ->post(route('recipes.saved.restore', ['recipe' => $recipe, 'version' => $olderSavedVersion]), ['expected_revision' => (int) $recipe->fresh()->edit_revision])
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

    $this->post(route('recipes.saved.restore', ['recipe' => $recipe, 'version' => $savedVersion]), ['expected_revision' => (int) $recipe->fresh()->edit_revision])
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
        ]), ['expected_revision' => (int) $recipe->fresh()->edit_revision])
        ->assertNotFound();
});

it('preserves the current draft when restoring an older saved snapshot', function () {
    $user = User::factory()->create();
    grantRecipeVersionPageHistory($user, 3);
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
        ->post(route('recipes.saved.restore', ['recipe' => $recipe, 'version' => $olderSavedVersion]), ['expected_revision' => (int) $recipe->fresh()->edit_revision])
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
    grantRecipeVersionPageHistory($user, 3);
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
        ->post(route('recipes.use-version-as-current', ['recipe' => $recipe, 'version' => $olderSavedVersion]), ['expected_revision' => (int) $recipe->fresh()->edit_revision])
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
            'expected_revision' => (int) $recipe->fresh()->edit_revision,
            'confirm_replace_current' => '1',
        ])
        ->assertRedirect(route('recipes.edit', $recipe));

    $currentDraft->refresh();

    expect($currentDraft->name)->toBe('Formula A');
});

it('redirects signed-out users before replacing the draft with a saved version', function () {
    $user = User::factory()->create();
    grantRecipeVersionPageHistory($user, 3);
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

    $this->post(route('recipes.use-version-as-current', ['recipe' => $recipe, 'version' => $olderSavedVersion]), ['expected_revision' => (int) $recipe->fresh()->edit_revision])
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
    grantRecipeVersionPageHistory($user, 3);
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap-'.fake()->unique()->slug(),
        'name' => 'Soap',
    ]);
    $oliveOil = makeSavedRecipeIngredient();
    $coconutOil = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'display_name' => 'Coconut Oil',
        'inci_name' => 'COCOS NUCIFERA OIL',
        'soap_inci_naoh_name' => 'SODIUM COCOATE',
        'soap_inci_koh_name' => 'POTASSIUM COCOATE',
        'is_soap_saponification_trusted' => true,
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

function grantRecipeVersionPageHistory(User $user, int $limit): void
{
    $hasHistoryPlan = $user->entitlements()
        ->active()
        ->whereHas('plan.limits', function ($query) use ($limit): void {
            $query
                ->where('key', 'saved_formula_history')
                ->where('value', '>=', $limit);
        })
        ->exists();

    if ($hasHistoryPlan) {
        return;
    }

    $plan = Plan::factory()
        ->hasLimit('saved_formula_history', $limit)
        ->create();

    $user->entitlements()->create([
        'plan_id' => $plan->id,
        'status' => 'active',
        'starts_at' => now(),
    ]);
}

function recipeWorkbookXml(string $content): string
{
    $path = tempnam(sys_get_temp_dir(), 'koskalk-version-export-test-');
    file_put_contents($path, $content);

    $zip = new ZipArchive;

    expect($zip->open($path))->toBeTrue();

    $xml = collect(range(1, 2))
        ->map(fn (int $index): string => (string) $zip->getFromName("xl/worksheets/sheet{$index}.xml"))
        ->implode("\n");

    $zip->close();
    unlink($path);

    return $xml;
}

/** @return array<string, list<array<int, mixed>>> */
function readRecipeCompositionWorkbook(string $content): array
{
    $path = tempnam(sys_get_temp_dir(), 'koskalk-composition-export-test-');
    file_put_contents($path, $content);

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

        return $sheets;
    } finally {
        unlink($path);
    }
}

function makeSavedRecipeIngredient(): Ingredient
{
    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'display_name' => 'Olive Oil',
        'inci_name' => 'OLEA EUROPAEA FRUIT OIL',
        'soap_inci_naoh_name' => 'SODIUM OLIVATE',
        'soap_inci_koh_name' => 'POTASSIUM OLIVATE',
        'is_soap_saponification_trusted' => true,
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
        'product_type_id' => testProductTypeIdForFamily('soap'),
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
        'product_type_id' => testProductTypeIdForFamily('cosmetic'),
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

it('renders shared formulas as read only for viewers without acquiring an editing reservation', function (): void {
    [$owner, $recipe] = createSavedRecipeVersion();
    $workspace = Workspace::withoutGlobalScopes()->findOrFail($recipe->workspace_id);
    UserEntitlement::factory()->for($owner)->for(Plan::factory()->create(['allows_collaboration' => true]))->create();
    $viewer = User::factory()->create(['active_workspace_id' => $workspace->id]);
    WorkspaceMember::factory()->for($workspace)->for($viewer)->create(['role' => WorkspaceMemberRole::Viewer]);

    Livewire::actingAs($viewer)->test(RecipesIndex::class)
        ->assertSee($recipe->name)
        ->assertViewHas('canCreateRecipe', false)
        ->assertViewHas('canUpdateRecipes', fn (array $abilities): bool => $abilities[$recipe->id] === false)
        ->assertViewHas('canDeleteRecipes', fn (array $abilities): bool => $abilities[$recipe->id] === false);

    Livewire::test(RecipeWorkbench::class, ['recipe' => $recipe])
        ->assertViewHas('canEditRecipe', false)
        ->assertViewHas('workbench', fn (array $workbench): bool => $workbench['editing'] === null
            && $workbench['recipe']['can_duplicate'] === false)
        ->assertDontSee('wire:click="duplicateRecipe')
        ->assertSet('editingToken', null);

    $this->get(route('recipes.saved', $recipe))->assertSuccessful()
        ->assertDontSee('Record production')
        ->assertDontSeeHtml('action="'.route('recipes.duplicate', $recipe).'"');
    $this->get(route('recipes.print.technical', $recipe))->assertSuccessful();
});

it('binds unsaved workbench writes to their original authorized workspace', function (string $action, string $change): void {
    $workspace = Workspace::factory()->create();
    UserEntitlement::factory()->for($workspace->owner)->for(Plan::factory()->create(['allows_collaboration' => true]))->create();
    $actor = User::factory()->create(['active_workspace_id' => $workspace->id]);
    $membership = WorkspaceMember::factory()->for($workspace)->for($actor)->create(['role' => WorkspaceMemberRole::Editor]);
    ProductFamily::factory()->create(['slug' => 'soap', 'name' => 'Soap']);
    $ingredient = makeSavedRecipeIngredient();
    $page = Livewire::actingAs($actor)->test(RecipeWorkbench::class, ['productFamilySlug' => 'soap'])
        ->assertOk()
        ->assertSet('creationWorkspaceId', $workspace->id);
    $payload = $action === 'savePackagingCatalogItem'
        ? ['name' => 'Must not move', 'unit_cost' => '1.20']
        : soapVersionDraftPayload($ingredient, 'Must not move');

    if ($change === 'selection') {
        $otherWorkspace = Workspace::factory()->for($actor, 'owner')->create();
        $actor->forceFill(['active_workspace_id' => $otherWorkspace->id])->save();
    } elseif ($change === 'removal') {
        $membership->delete();
    } else {
        $membership->update(['role' => WorkspaceMemberRole::Viewer]);
    }

    $page->call($action, $payload)->assertForbidden();

    expect(Recipe::withoutGlobalScopes()->count())->toBe(0)
        ->and(RecipeVersion::withoutGlobalScopes()->count())->toBe(0)
        ->and(PackagingItem::query()->count())->toBe(0);
})->with(['save', 'publish', 'duplicateFormula', 'savePackagingCatalogItem'])
    ->with(['selection', 'removal', 'demotion']);

it('allows a guest calculation to be saved after signing in', function (): void {
    ProductFamily::factory()->create(['slug' => 'soap', 'name' => 'Soap']);
    $ingredient = makeSavedRecipeIngredient();
    $page = Livewire::test(RecipeWorkbench::class, ['productFamilySlug' => 'soap'])
        ->assertOk()
        ->assertSet('hasCreationWorkspaceContext', false);
    $actor = User::factory()->create();
    $workspace = Workspace::factory()->for($actor, 'owner')->create();
    $this->actingAs($actor);

    $page->call('save', soapVersionDraftPayload($ingredient, 'Signed-in calculation'))
        ->assertReturned(fn (array $response): bool => $response['ok'] === true);

    expect(Recipe::withoutGlobalScopes()->sole()->workspace_id)->toBe($workspace->id);
});

it('does not retarget existing workbench duplication or packaging after switching workspaces', function (string $action): void {
    [$actor, $recipe] = createSavedRecipeVersion();
    $ingredient = Ingredient::query()->where('display_name', 'Olive Oil')->firstOrFail();
    $payload = $action === 'savePackagingCatalogItem'
        ? ['name' => 'Must not move', 'unit_cost' => '1.20']
        : soapVersionDraftPayload($ingredient, 'Must not move');
    $recipeCount = Recipe::withoutGlobalScopes()->count();
    $versionCount = RecipeVersion::withoutGlobalScopes()->count();
    $page = Livewire::actingAs($actor)->test(RecipeWorkbench::class, ['recipe' => $recipe])->assertOk();
    $otherWorkspace = Workspace::factory()->for($actor, 'owner')->create();
    $actor->forceFill(['active_workspace_id' => $otherWorkspace->id])->save();

    $page->call($action, $payload)->assertNotFound();

    expect(Recipe::withoutGlobalScopes()->count())->toBe($recipeCount)
        ->and(RecipeVersion::withoutGlobalScopes()->count())->toBe($versionCount)
        ->and(PackagingItem::query()->count())->toBe(0);
})->with(['duplicateFormula', 'savePackagingCatalogItem']);
