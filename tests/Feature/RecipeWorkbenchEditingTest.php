<?php

use App\Livewire\Dashboard\RecipeWorkbench;
use App\Models\Ingredient;
use App\Models\ProductFamily;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\RecipeVersionCosting;
use App\Models\Workspace;
use App\Services\RecipeEditingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('keeps the mounted editing baseline in workbench renders after a saved mutation', function (): void {
    $workspace = Workspace::factory()->create();
    $family = ProductFamily::factory()->create(['slug' => 'soap']);
    $recipe = Recipe::factory()->create(['workspace_id' => $workspace->id, 'product_family_id' => $family->id]);
    $version = RecipeVersion::factory()->create(['recipe_id' => $recipe->id, 'workspace_id' => $workspace->id, 'is_current' => true]);
    $this->actingAs($workspace->owner);
    $component = Livewire::test(RecipeWorkbench::class, ['recipe' => $recipe])
        ->call('beginEditing', (string) Str::uuid())
        ->set('data.description', '<p>First save.</p>')
        ->call('saveRecipeContent')
        ->assertReturned(fn (array $response): bool => $response['ok']);
    $recipe->increment('edit_revision');

    $component->call('$refresh')
        ->assertViewHas('workbench', fn (array $workbench): bool => $workbench['editing'] !== null
            && $workbench['editing']['recipe_revision'] === 1
            && $workbench['editing']['current_version_id'] === $version->id
            && $workbench['editing']['costing_revision'] === 0)
        ->assertSet('expectedRecipeRevision', 1);
});

it('allows inspecting locked formula settings while protecting their controls', function (string $familySlug): void {
    $workspace = Workspace::factory()->create();
    $family = ProductFamily::factory()->create(['slug' => $familySlug, 'calculation_basis' => $familySlug === 'soap' ? 'initial_oils' : 'total_formula']);
    $recipe = Recipe::factory()->create(['workspace_id' => $workspace->id, 'product_family_id' => $family->id, 'locked_at' => now()]);
    RecipeVersion::factory()->create(['recipe_id' => $recipe->id, 'workspace_id' => $workspace->id, 'is_current' => true]);
    $this->actingAs($workspace->owner);
    $component = Livewire::test(RecipeWorkbench::class, ['recipe' => $recipe]);

    $document = new DOMDocument;
    $document->loadHTML($component->html(), LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($document);
    $disclosure = $xpath->query('//button[@aria-controls="formula-settings-panel"]')->item(0);

    expect($disclosure)->not->toBeNull();
    expect($xpath->query('ancestor::fieldset', $disclosure)->length)->toBe(0);
    expect($disclosure->hasAttribute('disabled'))->toBeFalse();
    expect($disclosure->getAttribute(':aria-expanded'))->toBe('isFormulaSettingsOpen.toString()');
    expect($xpath->query('span', $disclosure)->item(0)->getAttribute('x-text'))->toContain("t('settings.view')");

    $compliancePanel = $xpath->query('//*[@data-formula-compliance-settings]')->item(0);
    expect($compliancePanel)->not->toBeNull();
    expect($compliancePanel->getAttribute(':class'))->toBe("isComplianceSettingsOpen || !canWriteRecipe ? 'grid-rows-[1fr]' : 'grid-rows-[0fr] invisible'");

    $setting = $xpath->query('//*[@id="formula-settings-panel"]//input[@inputmode="decimal"]')->item(0);
    expect($setting)->not->toBeNull();
    $protection = $xpath->query('ancestor::fieldset', $setting);
    expect($protection->length)->toBe(1);
    expect($protection->item(0)->getAttribute(':disabled'))->toBe('!canWriteRecipe || (isSaving && !hasSavedRecipe)');

    $entryMode = $xpath->query('//div[@aria-labelledby="formula-entry-mode-heading"]/button')->item(0);
    expect($entryMode)->not->toBeNull();
    expect($xpath->query('ancestor::fieldset', $entryMode)->length)->toBe(1);
    expect($xpath->query('ancestor::fieldset', $entryMode)->item(0)->getAttribute(':disabled'))->toBe('!canWriteRecipe || (isSaving && !hasSavedRecipe)');
    expect((int) $recipe->fresh()->edit_revision)->toBe(0);
})->with(['soap', 'cosmetic']);

it('loads locked costing without writes and rejects saving a simulation', function (string $familySlug): void {
    $workspace = Workspace::factory()->create();
    $family = ProductFamily::factory()->create(['slug' => $familySlug, 'calculation_basis' => $familySlug === 'soap' ? 'initial_oils' : 'total_formula']);
    if ($familySlug === 'soap') {
        Ingredient::factory()->create(['catalog_key' => 'CH1', 'owner_type' => null, 'owner_id' => null, 'workspace_id' => null]);
        Ingredient::factory()->create(['catalog_key' => 'CH3', 'owner_type' => null, 'owner_id' => null, 'workspace_id' => null]);
    }
    $recipe = Recipe::factory()->create(['workspace_id' => $workspace->id, 'product_family_id' => $family->id, 'locked_at' => now()]);
    $version = RecipeVersion::factory()->create(['recipe_id' => $recipe->id, 'workspace_id' => $workspace->id, 'is_current' => true]);
    $costing = RecipeVersionCosting::query()->create(['recipe_version_id' => $version->id, 'user_id' => $workspace->owner_user_id, 'currency' => 'EUR', 'oil_weight_for_costing' => 1000, 'oil_unit_for_costing' => 'g']);
    $this->actingAs($workspace->owner);
    $component = Livewire::test(RecipeWorkbench::class, ['recipe' => $recipe])
        ->call('beginEditing', (string) Str::uuid());

    $document = new DOMDocument;
    $document->loadHTML($component->html(), LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($document);
    $costingPanel = $xpath->query('//*[@data-costing-controls]//*[@id="panel-costing"]')->item(0);
    expect($costingPanel)->not->toBeNull();
    expect($xpath->query('ancestor::fieldset', $costingPanel)->length)->toBe(1);
    expect($costingPanel->parentNode->getAttribute(':disabled'))->toBe('!canAdjustCosting || (isSaving && !hasSavedRecipe)');

    $component->call('loadCosting')
        ->assertHasNoErrors()
        ->assertReturned(fn (array $response): bool => $response['ok'])
        ->call('saveCosting', ['oil_weight_for_costing' => 2000, 'oil_unit_for_costing' => 'g', 'units_produced' => 20, 'currency' => 'EUR', 'items' => [], 'packaging_items' => []])
        ->assertReturned(fn (array $response): bool => ! $response['ok'] && $response['message'] === __('editing.formula_locked'));

    expect((float) $costing->fresh()->oil_weight_for_costing)->toBe(1000.0);
    expect((int) $costing->fresh()->edit_revision)->toBe(0);
    expect((int) $recipe->fresh()->edit_revision)->toBe(0);
    $this->assertDatabaseCount('recipe_version_costings', 1);
})->with(['soap', 'cosmetic']);

it('does not announce a deletion rolled back after the editing reservation expires', function (bool $current): void {
    $this->freezeTime();
    $workspace = Workspace::factory()->create();
    $family = ProductFamily::factory()->create(['slug' => 'soap']);
    $recipe = Recipe::factory()->create(['workspace_id' => $workspace->id, 'product_family_id' => $family->id]);
    $version = RecipeVersion::factory()->create(['recipe_id' => $recipe->id, 'workspace_id' => $workspace->id, 'is_current' => $current]);
    $this->actingAs($workspace->owner);
    $component = Livewire::test(RecipeWorkbench::class, ['recipe' => $recipe])
        ->call('beginEditing', (string) Str::uuid());
    RecipeVersion::deleting(function (): void {
        $this->travel(90)->seconds();
    });

    $component->call('deleteVersion', $version->id, $version->name)
        ->assertHasErrors(['editing_lease'])
        ->assertNoRedirect()
        ->assertNotDispatched('version-deleted')
        ->assertNotDispatched('editing-updated');

    expect($version->fresh())->not->toBeNull();
    expect(session()->has('status'))->toBeFalse();
    expect((int) $recipe->fresh()->edit_revision)->toBe(0);
})->with([true, false]);

it('reports changes to an observing tab without reserving editing or replacing its baseline', function (): void {
    $workspace = Workspace::factory()->create();
    $family = ProductFamily::factory()->create(['slug' => 'soap']);
    $recipe = Recipe::factory()->create(['workspace_id' => $workspace->id, 'product_family_id' => $family->id]);
    $this->actingAs($workspace->owner);
    $component = Livewire::test(RecipeWorkbench::class, ['recipe' => $recipe]);
    $recipe->increment('edit_revision');
    $component->call('editingStatus')
        ->assertReturned(fn (array $response): bool => $response['ok'] && $response['editing']['recipe_revision'] === 1 && $response['editing']['status'] === 'available')
        ->assertSet('expectedRecipeRevision', 0)
        ->assertSet('editingToken', null);
    $this->assertDatabaseCount('recipe_edit_leases', 0);
});

it('requires an explicit tab reservation before each saved formula mutation', function (string $action, array $arguments): void {
    $workspace = Workspace::factory()->create();
    $family = ProductFamily::factory()->create(['slug' => 'soap']);
    $recipe = Recipe::factory()->create(['workspace_id' => $workspace->id, 'product_family_id' => $family->id]);
    $this->actingAs($workspace->owner);

    Livewire::test(RecipeWorkbench::class, ['recipe' => $recipe])
        ->call($action, ...$arguments)
        ->assertReturned(fn (array $response): bool => ! $response['ok'] && isset($response['errors']['editing_lease']));

    $this->assertDatabaseCount('recipe_edit_leases', 0);
    expect((int) $recipe->fresh()->edit_revision)->toBe(0);
})->with([
    'formula' => ['save', [[]]],
    'published formula' => ['publish', [[]]],
    'costing' => ['saveCosting', [[]]],
    'content' => ['saveRecipeContent', []],
    'output ingredient' => ['createManufacturedIngredient', ['Unsaved output']],
]);

it('does not replace the loaded revision when a stale tab acquires or heartbeats', function (): void {
    $workspace = Workspace::factory()->create();
    $family = ProductFamily::factory()->create(['slug' => 'soap']);
    $recipe = Recipe::factory()->create(['workspace_id' => $workspace->id, 'product_family_id' => $family->id]);
    $this->actingAs($workspace->owner);
    $component = Livewire::test(RecipeWorkbench::class, ['recipe' => $recipe]);
    $recipe->increment('edit_revision');

    $component->call('beginEditing', (string) Str::uuid())
        ->assertSet('expectedRecipeRevision', 0)
        ->call('heartbeatEditing')
        ->assertSet('expectedRecipeRevision', 0)
        ->call('save', [])
        ->assertReturned(fn (array $response): bool => ! $response['ok'] && isset($response['errors']['edit_revision']));

    expect((int) $recipe->fresh()->edit_revision)->toBe(1);
});

it('preserves typed content after takeover and refuses the old tab save', function (): void {
    $workspace = Workspace::factory()->create();
    $family = ProductFamily::factory()->create(['slug' => 'soap']);
    $recipe = Recipe::factory()->create(['workspace_id' => $workspace->id, 'product_family_id' => $family->id]);
    $this->actingAs($workspace->owner);
    $component = Livewire::test(RecipeWorkbench::class, ['recipe' => $recipe])
        ->call('beginEditing', (string) Str::uuid())
        ->set('data.description', 'My unsaved notes');
    app(RecipeEditingService::class)->takeover($recipe, $workspace->owner, (string) Str::uuid(), 'Continue in another tab');

    $component->call('saveRecipeContent')
        ->assertReturned(fn (array $response): bool => ! $response['ok'] && isset($response['errors']['editing_lease']))
        ->assertSet('data.description', fn (mixed $state): bool => str_contains(json_encode($state), 'My unsaved notes'));

    expect($recipe->fresh()->description)->toBeNull();
});

it('rejects background costing changes without advancing the loaded expectation', function (): void {
    $workspace = Workspace::factory()->create();
    $family = ProductFamily::factory()->create(['slug' => 'soap']);
    $recipe = Recipe::factory()->create(['workspace_id' => $workspace->id, 'product_family_id' => $family->id]);
    $version = RecipeVersion::factory()->create(['recipe_id' => $recipe->id, 'workspace_id' => $workspace->id, 'is_current' => true]);
    $costing = RecipeVersionCosting::query()->create(['recipe_version_id' => $version->id, 'user_id' => $workspace->owner_user_id, 'currency' => 'EUR', 'oil_unit_for_costing' => 'g']);
    $this->actingAs($workspace->owner);
    $component = Livewire::test(RecipeWorkbench::class, ['recipe' => $recipe])
        ->call('beginEditing', (string) Str::uuid());
    $costing->increment('edit_revision');

    $component->call('saveCosting', [])
        ->assertReturned(fn (array $response): bool => ! $response['ok'] && isset($response['errors']['edit_revision']))
        ->assertSet('expectedCostingRevision', 0);
    expect((int) $recipe->fresh()->edit_revision)->toBe(0);
});

it('advances signed revisions only after a content save commits', function (): void {
    $workspace = Workspace::factory()->create();
    $family = ProductFamily::factory()->create(['slug' => 'soap']);
    $recipe = Recipe::factory()->create(['workspace_id' => $workspace->id, 'product_family_id' => $family->id]);
    $this->actingAs($workspace->owner);

    Livewire::test(RecipeWorkbench::class, ['recipe' => $recipe])
        ->call('beginEditing', (string) Str::uuid())
        ->set('data.description', '<p>Saved notes.</p>')
        ->call('saveRecipeContent')
        ->assertReturned(fn (array $response): bool => $response['ok'] && $response['editing']['recipe_revision'] === 1)
        ->assertSet('expectedRecipeRevision', 1);

    expect($recipe->fresh()->description)->toBe('<p>Saved notes.</p>');
});

it('keeps the original form input and rolls back when a lease expires during persistence', function (): void {
    $this->freezeTime();
    $workspace = Workspace::factory()->create();
    $family = ProductFamily::factory()->create(['slug' => 'soap']);
    $recipe = Recipe::factory()->create(['workspace_id' => $workspace->id, 'product_family_id' => $family->id]);
    $this->actingAs($workspace->owner);
    $component = Livewire::test(RecipeWorkbench::class, ['recipe' => $recipe])
        ->call('beginEditing', (string) Str::uuid())
        ->set('data.description', '<p>Keep these notes.</p>');
    Recipe::updating(function (): void {
        $this->travel(90)->seconds();
    });

    $component->call('saveRecipeContent')
        ->assertReturned(fn (array $response): bool => ! $response['ok'] && isset($response['errors']['editing_lease']))
        ->assertSet('data.description', fn (mixed $state): bool => str_contains(json_encode($state), 'Keep these notes.'))
        ->assertSet('expectedRecipeRevision', 0)
        ->assertSet('recipeContentStatus', 'idle');

    expect($recipe->fresh()->description)->toBeNull();
});

it('accepts fresh costing after explicit reload while preserving the formula revision', function (): void {
    $workspace = Workspace::factory()->create();
    $family = ProductFamily::factory()->create(['slug' => 'cosmetic', 'calculation_basis' => 'total_formula']);
    $recipe = Recipe::factory()->create(['workspace_id' => $workspace->id, 'product_family_id' => $family->id]);
    $version = RecipeVersion::factory()->create(['recipe_id' => $recipe->id, 'workspace_id' => $workspace->id, 'is_current' => true]);
    $costing = RecipeVersionCosting::query()->create(['recipe_version_id' => $version->id, 'user_id' => $workspace->owner_user_id, 'currency' => 'EUR', 'oil_unit_for_costing' => 'g']);
    $this->actingAs($workspace->owner);
    $component = Livewire::test(RecipeWorkbench::class, ['recipe' => $recipe]);
    $costing->increment('edit_revision');

    $component->call('loadCosting')
        ->assertHasNoErrors()
        ->assertReturned(fn (array $response): bool => $response['ok'] && $response['editing']['costing_revision'] === 1)
        ->assertSet('expectedCostingRevision', 1)
        ->assertSet('expectedRecipeRevision', 0);
});

it('rejects missing editing tokens without replacing an existing reservation', function (string $action, array $arguments): void {
    $workspace = Workspace::factory()->create();
    $family = ProductFamily::factory()->create(['slug' => 'soap']);
    $recipe = Recipe::factory()->create(['workspace_id' => $workspace->id, 'product_family_id' => $family->id]);
    $this->actingAs($workspace->owner);
    $token = (string) Str::uuid();
    app(RecipeEditingService::class)->acquire($recipe, $workspace->owner, $token);

    Livewire::test(RecipeWorkbench::class, ['recipe' => $recipe])
        ->call($action, ...$arguments)
        ->assertReturned(fn (array $response): bool => ! $response['ok'] && isset($response['errors']['editing_lease']))
        ->assertSet('editingToken', null);

    $this->assertDatabaseHas('recipe_edit_leases', ['recipe_id' => $recipe->id, 'token_hash' => hash('sha256', $token)]);
    $this->assertDatabaseCount('recipe_edit_leases', 1);
})->with([
    ['beginEditing', [null]],
    ['takeoverEditing', [null, 'Recover editing']],
]);
