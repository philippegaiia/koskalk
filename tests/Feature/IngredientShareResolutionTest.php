<?php

use App\Enums\IngredientCategory;
use App\Enums\OwnerType;
use App\Models\Allergen;
use App\Models\FormulaShare;
use App\Models\IfraAmendment;
use App\Models\IfraAmendmentMilestone;
use App\Models\IfraProductCategory;
use App\Models\Ingredient;
use App\Models\IngredientShareMapping;
use App\Models\ProductFamily;
use App\Models\ProductType;
use App\Models\ProductTypeIfraCategory;
use App\Models\RegulatoryRegime;
use App\Models\RegulatoryRegimeAllergen;
use App\Models\User;
use App\Models\Workspace;
use App\Services\FormulaSharePreview;
use App\Services\IngredientShareGraph;
use App\Services\IngredientShareResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['workspaces.formula_sharing.enabled' => true, 'workspaces.formula_sharing.rate_limits.preview.actor_per_minute' => 100, 'workspaces.formula_sharing.rate_limits.preview.workspace_per_minute' => 100]);
});

/** @return array<string, mixed> */
function shareResolutionContext(): array
{
    $owner = User::factory()->create();
    $source = Workspace::factory()->for($owner, 'owner')->create();
    $destination = Workspace::factory()->create();
    $ingredient = Ingredient::factory()->create(['workspace_id' => $source->id, 'owner_type' => OwnerType::Workspace, 'owner_id' => $source->id, 'inci_name' => 'Incoming INCI']);
    $graph = app(IngredientShareGraph::class)->capture($owner, $source, [$ingredient->id]);
    unset($graph['source_keys']);

    return compact('owner', 'source', 'destination', 'ingredient', 'graph');
}

function resolutionCopy(Ingredient $incoming, Workspace $destination, array $attributes = []): Ingredient
{
    $copy = Ingredient::factory()->create(array_replace([
        'workspace_id' => $destination->id, 'owner_type' => OwnerType::Workspace, 'owner_id' => $destination->id,
        'inci_name' => $incoming->inci_name, 'soap_inci_naoh_name' => $incoming->soap_inci_naoh_name, 'soap_inci_koh_name' => $incoming->soap_inci_koh_name,
        'category' => $incoming->category, 'subcategory' => $incoming->subcategory, 'unit' => $incoming->unit,
        'requires_aromatic_compliance' => $incoming->requires_aromatic_compliance, 'is_soap_saponification_trusted' => $incoming->is_soap_saponification_trusted,
    ], $attributes));
    $copy->forceFill(['share_lineage_key' => $incoming->sharingLineageKey()])->save();

    return $copy;
}

it('reuses unchanged ancestry across repeat onward and return paths without writing mappings', function (): void {
    extract(shareResolutionContext());
    $copy = resolutionCopy($ingredient, $destination, ['display_name' => 'Local display']);
    $resolver = app(IngredientShareResolver::class);
    $resolved = $resolver->resolve($destination, $graph, []);
    expect($resolved['nodes']['n1']['mode'])->toBe('reuse')->and($resolved['nodes']['n1']['ingredient_id'])->toBe($copy->id)->and($resolved['import_count'])->toBe(0);
    $owner->forceFill(['active_workspace_id' => $destination->id])->save();
    $destination->forceFill(['owner_user_id' => $owner->id])->save();
    $onward = app(IngredientShareGraph::class)->capture($owner, $destination, [$copy->id]);
    $third = Workspace::factory()->create();
    $thirdCopy = resolutionCopy($copy, $third);
    expect($resolver->resolve($third, $graph, [])['nodes']['n1']['ingredient_id'])->toBe($thirdCopy->id)
        ->and($resolver->resolve($source, $onward, [])['nodes']['n1']['ingredient_id'])->toBe($ingredient->id)
        ->and(IngredientShareMapping::query()->count())->toBe(0)->and($copy->fresh()->display_name)->toBe('Local display');
});

it('requires a choice for multiple exact copies and never merges unrelated same name materials', function (): void {
    extract(shareResolutionContext());
    $unrelated = Ingredient::factory()->create(['workspace_id' => $destination->id, 'owner_type' => OwnerType::Workspace, 'owner_id' => $destination->id, 'display_name' => $ingredient->display_name, 'inci_name' => $ingredient->inci_name]);
    $resolver = app(IngredientShareResolver::class);
    expect($resolver->resolve($destination, $graph, [])['import_count'])->toBe(1);
    expect($resolver->resolve($destination, $graph, [])['nodes']['n1']['candidates'][0]['public_id'])->toBe($unrelated->public_id);
    $copies = [resolutionCopy($ingredient, $destination), resolutionCopy($ingredient, $destination)];
    $resolved = $resolver->resolve($destination, $graph, []);
    expect($resolved['remaining_keys'])->toBe(['n1'])->and($resolved['nodes']['n1']['mode'])->toBe('decision');
    $chosen = $resolver->resolve($destination, $graph, [['key' => 'n1', 'mode' => 'reuse', 'ingredient_public_id' => $copies[1]->public_id]]);
    expect($chosen['nodes']['n1']['ingredient_id'])->toBe($copies[1]->id);
    expect(fn () => $resolver->resolve($destination, $graph, [['key' => 'n1', 'mode' => 'reuse', 'ingredient_public_id' => $unrelated->public_id]]))->toThrow(ValidationException::class);
});

it('checks remembered local fingerprints and requires stale deleted or substitution mappings to be reviewed', function (string $state): void {
    extract(shareResolutionContext());
    $copy = resolutionCopy($ingredient, $destination);
    $mapping = IngredientShareMapping::factory()->create(['workspace_id' => $destination->id, 'lineage_key' => $ingredient->public_id, 'incoming_fingerprint' => $graph['nodes']['n1']['fingerprint'], 'local_fingerprint' => $graph['nodes']['n1']['fingerprint'], 'ingredient_id' => $copy->id, 'resolution' => $state === 'substitution' ? 'substitution' : 'exact']);
    if ($state === 'changed') {
        $copy->forceFill(['inci_name' => 'Modified branch'])->save();
    } elseif ($state === 'deleted') {
        $copy->delete();
    }
    $resolver = app(IngredientShareResolver::class);
    $result = $resolver->resolve($destination, $graph, []);
    if ($state === 'exact') {
        expect($result['nodes']['n1']['ingredient_id'])->toBe($copy->id)->and($result['remaining_keys'])->toBe([]);
    } else {
        expect($result['remaining_keys'])->toBe(['n1'])->and($result['nodes']['n1']['warning'])->toBe($state === 'substitution' ? 'remembered_substitution' : 'stale_mapping');
        expect($resolver->resolve($destination, $graph, [['key' => 'n1', 'mode' => 'import']])['import_count'])->toBe(1);
    }
    expect($mapping->fresh()->resolution)->toBe($state === 'substitution' ? 'substitution' : 'exact');
})->with(['exact', 'changed', 'deleted', 'substitution']);

it('detects different incoming branches and nested child edits rather than reusing a parent', function (): void {
    extract(shareResolutionContext());
    $child = Ingredient::factory()->create(['workspace_id' => $source->id, 'owner_type' => OwnerType::Workspace, 'owner_id' => $source->id]);
    $ingredient->components()->create(['component_ingredient_id' => $child->id, 'percentage_in_parent' => '100', 'sort_order' => 1]);
    $graph = app(IngredientShareGraph::class)->capture($owner, $source, [$ingredient->id]);
    $copy = resolutionCopy($ingredient, $destination);
    $childCopy = resolutionCopy($child, $destination);
    $copy->components()->create(['component_ingredient_id' => $childCopy->id, 'percentage_in_parent' => '100', 'sort_order' => 1]);
    $resolver = app(IngredientShareResolver::class);
    expect($resolver->resolve($destination, $graph, [])['nodes']['n1']['mode'])->toBe('reuse');
    $childCopy->forceFill(['inci_name' => 'Changed child'])->save();
    $result = $resolver->resolve($destination, $graph, []);
    expect($result['nodes']['n1']['mode'])->toBe('decision')->and($result['remaining_keys'])->toContain('n1', 'n2');
    $child->forceFill(['inci_name' => 'Other incoming branch'])->save();
    $changed = app(IngredientShareGraph::class)->capture($owner, $source, [$ingredient->id]);
    expect($resolver->resolve($destination, $changed, [])['remaining_keys'])->toContain('n1');
});

it('prunes substituted parent dependencies while retaining a child used by another imported blend and dilution root', function (): void {
    extract(shareResolutionContext());
    $child = Ingredient::factory()->create(['workspace_id' => $source->id, 'owner_type' => OwnerType::Workspace, 'owner_id' => $source->id]);
    $other = Ingredient::factory()->create(['workspace_id' => $source->id, 'owner_type' => OwnerType::Workspace, 'owner_id' => $source->id]);
    foreach ([$ingredient, $other] as $parent) {
        $parent->components()->create(['component_ingredient_id' => $child->id, 'percentage_in_parent' => '100', 'sort_order' => 1]);
    }
    $graph = app(IngredientShareGraph::class)->capture($owner, $source, [$ingredient->id, $other->id, $child->id]);
    $substitute = Ingredient::factory()->create(['workspace_id' => $destination->id, 'owner_type' => OwnerType::Workspace, 'owner_id' => $destination->id]);
    $decision = [['key' => 'n1', 'mode' => 'substitute', 'ingredient_public_id' => $substitute->public_id]];
    $resolved = app(IngredientShareResolver::class)->resolve($destination, $graph, $decision);
    expect($resolved['active_keys'])->toContain('n1', 'n2', 'n3')->and($resolved['import_count'])->toBe(2)->and($resolved['nodes']['n1']['mode'])->toBe('substitute');
    $graph['root_keys'] = ['n1'];
    $resolved = app(IngredientShareResolver::class)->resolve($destination, $graph, $decision);
    expect($resolved['active_keys'])->toBe(['n1'])->and($resolved['import_count'])->toBe(0)->and($substitute->fresh()->share_lineage_key)->toBeNull();
    $graph['nodes']['n2']['kind'] = 'platform';
    $graph['nodes']['n2']['platform_reference'] = ['public_id' => '00000000-0000-4000-8000-000000000001', 'catalog_key' => 'MISSING'];
    expect(app(IngredientShareResolver::class)->resolve($destination, $graph, $decision)['import_count'])->toBe(0);
});

it('rejects a real foreign workspace UUID and never presents foreign lineage candidates', function (): void {
    extract(shareResolutionContext());
    $foreign = resolutionCopy($ingredient, Workspace::factory()->create());
    $resolver = app(IngredientShareResolver::class);
    expect($resolver->resolve($destination, $graph, [])['nodes']['n1']['candidates'])->toBe([]);
    expect(fn () => $resolver->resolve($destination, $graph, [['key' => 'n1', 'mode' => 'substitute', 'ingredient_public_id' => $foreign->public_id]]))->toThrow(ValidationException::class);
});

it('rejects forged duplicate unknown and foreign workspace decisions', function (array $decisions): void {
    extract(shareResolutionContext());
    expect(fn () => app(IngredientShareResolver::class)->resolve($destination, $graph, $decisions))->toThrow(ValidationException::class);
})->with([
    [[['key' => 'missing', 'mode' => 'import']]],
    [[['key' => 'n1', 'mode' => 'import'], ['key' => 'n1', 'mode' => 'import']]],
    [[['key' => 'n1', 'mode' => 'import', 'baseline' => 'forged']]],
    [[['key' => 'n1', 'mode' => 'substitute', 'ingredient_public_id' => '00000000-0000-4000-8000-000000000001']]],
]);

it('binds preview only to used technical references and keeps trusted lineage and source ids out of display', function (): void {
    extract(shareResolutionContext());
    $receiver = $destination->owner;
    $copy = resolutionCopy($ingredient, $destination);
    $share = FormulaShare::factory()->create(['source_workspace_id' => $source->id, 'recipient_workspace_id' => $destination->id, 'snapshot' => [
        'schema_version' => 1, 'product' => ['name' => 'Shared product', 'family' => null, 'type' => null, 'description' => null],
        'formula' => ['phases' => [], 'regulatory_regime' => null, 'ifra' => [], 'manufacturing_mode' => 'blend_only'], 'ingredients' => $graph, 'warnings' => [],
    ]]);
    $preview = app(FormulaSharePreview::class);
    $first = $preview->build($receiver, $share, []);
    Ingredient::factory()->create(['inci_name' => 'Unrelated catalogue edit']);
    expect($preview->build($receiver, $share, [])['expected_hash'])->toBe($first['expected_hash']);
    $copy->forceFill(['inci_name' => 'Changed local'])->save();
    $changed = $preview->build($receiver, $share, []);
    expect($changed['expected_hash'])->not->toBe($first['expected_hash'])->and(json_encode($changed))->not->toContain($ingredient->public_id, 'lineage_key', 'baseline', 'ingredient_id', 'source_keys');
});

it('binds used regulatory child rows and current platform child chemistry but ignores unrelated rules', function (): void {
    extract(shareResolutionContext());
    $ingredient->forceFill(['workspace_id' => null, 'owner_id' => null, 'owner_type' => null])->save();
    $allergen = Allergen::factory()->create();
    $ingredient->allergenEntries()->create(['allergen_id' => $allergen->id, 'concentration_percent' => '1.23456']);
    $amendment = IfraAmendment::factory()->create();
    $milestone = IfraAmendmentMilestone::factory()->create(['ifra_amendment_id' => $amendment->id]);
    $ingredient->ifraCertificates()->create(['certificate_name' => 'Structured certificate', 'ifra_amendment_id' => $amendment->id]);
    $regime = RegulatoryRegime::factory()->create();
    $rule = RegulatoryRegimeAllergen::factory()->create(['regulatory_regime_id' => $regime->id, 'allergen_id' => $allergen->id]);
    $graph = app(IngredientShareGraph::class)->capture($owner, $source, [$ingredient->id]);
    unset($graph['source_keys']);
    $share = FormulaShare::factory()->create(['source_workspace_id' => $source->id, 'recipient_workspace_id' => $destination->id, 'snapshot' => ['schema_version' => 1, 'product' => ['name' => 'Product'], 'formula' => ['phases' => [], 'regulatory_regime' => ['id' => $regime->id], 'manufacturing_mode' => 'blend_only'], 'ingredients' => $graph]]);
    $preview = app(FormulaSharePreview::class);
    $first = $preview->build($destination->owner, $share, []);
    RegulatoryRegimeAllergen::factory()->create(['regulatory_regime_id' => $regime->id]);
    expect($preview->build($destination->owner, $share, [])['expected_hash'])->toBe($first['expected_hash']);
    $rule->forceFill(['rinse_off_threshold_percent' => '0.12345'])->save();
    $second = $preview->build($destination->owner, $share, []);
    expect($second['expected_hash'])->not->toBe($first['expected_hash']);
    $ingredient->allergenEntries()->first()->update(['concentration_percent' => '2.34567']);
    $third = $preview->build($destination->owner, $share, []);
    expect($third['expected_hash'])->not->toBe($second['expected_hash'])->and($third['warnings'])->toContain('platform_changed');
    $milestone->update(['effective_on' => '2030-01-01']);
    expect($preview->build($destination->owner, $share, [])['expected_hash'])->not->toBe($third['expected_hash']);
});

it('validates substitutions against the union of actual formula roles and permits dilution substitutes', function (): void {
    extract(shareResolutionContext());
    $substitute = Ingredient::factory()->create(['workspace_id' => $destination->id, 'owner_type' => OwnerType::Workspace, 'owner_id' => $destination->id]);
    $phases = [['key' => 'p1', 'slug' => 'lye_water', 'phase_type' => 'reaction_medium', 'items' => [['ingredient_key' => 'n1', 'percentage' => '100.0000']]]];
    $share = FormulaShare::factory()->create(['source_workspace_id' => $source->id, 'recipient_workspace_id' => $destination->id, 'snapshot' => ['schema_version' => 1, 'product' => ['name' => 'Product'], 'formula' => ['phases' => $phases, 'manufacturing_mode' => 'saponify_in_formula'], 'ingredients' => $graph]]);
    $decisions = [['key' => 'n1', 'mode' => 'substitute', 'ingredient_public_id' => $substitute->public_id]];
    $preview = app(FormulaSharePreview::class);
    expect($preview->build($destination->owner, $share, $decisions)['import_count'])->toBe(0);
    $substitute->forceFill(['category' => IngredientCategory::SoapmakingAlkalis])->save();
    expect(fn () => $preview->build($destination->owner, $share, $decisions))->toThrow(ValidationException::class);
    $substitute->forceFill(['category' => IngredientCategory::Other])->save();
    $snapshot = $share->snapshot;
    $snapshot['formula']['phases'][] = ['key' => 'p2', 'slug' => 'saponified_oils', 'phase_type' => 'reaction_core', 'items' => [['ingredient_key' => 'n1']]];
    $share->forceFill(['snapshot' => $snapshot])->save();
    expect(fn () => $preview->build($destination->owner, $share, $decisions))->toThrow(ValidationException::class);
    $substitute->forceFill(['is_soap_saponification_trusted' => true, 'source_data' => ['user_authoring' => ['trusted_koh_sap_value' => '0.188', 'trusted_fatty_acid_profile' => []]]])->save();
    $substitute->sapProfile()->create(['koh_sap_value' => '0.188']);
    expect($preview->build($destination->owner, $share, $decisions)['import_count'])->toBe(0);
});

it('counts a shared local child once and short circuits valid explicit choices before unrelated candidates', function (): void {
    extract(shareResolutionContext());
    $child = Ingredient::factory()->create(['workspace_id' => $source->id, 'owner_type' => OwnerType::Workspace, 'owner_id' => $source->id]);
    $other = Ingredient::factory()->create(['workspace_id' => $source->id, 'owner_type' => OwnerType::Workspace, 'owner_id' => $source->id]);
    foreach ([$ingredient, $other] as $parent) {
        $parent->components()->create(['component_ingredient_id' => $child->id, 'percentage_in_parent' => '100', 'sort_order' => 1]);
    }
    $graph = app(IngredientShareGraph::class)->capture($owner, $source, [$ingredient->id, $other->id]);
    $localChild = resolutionCopy($child, $destination);
    foreach ([$ingredient, $other] as $parent) {
        resolutionCopy($parent, $destination)->components()->create(['component_ingredient_id' => $localChild->id, 'percentage_in_parent' => '100', 'sort_order' => 1]);
    }
    config(['workspaces.formula_sharing.limits.nodes' => 3]);
    expect(app(IngredientShareResolver::class)->resolve($destination, $graph, [])['import_count'])->toBe(0);
    config(['workspaces.formula_sharing.limits.nodes' => 200]);
    $graph = app(IngredientShareGraph::class)->capture($owner, $source, [$child->id]);
    foreach (range(1, 4) as $unused) {
        resolutionCopy($child, $destination);
    }
    config(['workspaces.formula_sharing.limits.nodes' => 1]);
    expect(app(IngredientShareResolver::class)->resolve($destination, $graph, [['key' => 'n1', 'mode' => 'reuse', 'ingredient_public_id' => $localChild->public_id]])['nodes']['n1']['ingredient_id'])->toBe($localChild->id);
    IngredientShareMapping::factory()->create(['workspace_id' => $destination->id, 'lineage_key' => $child->public_id, 'incoming_fingerprint' => $graph['nodes']['n1']['fingerprint'], 'local_fingerprint' => $graph['nodes']['n1']['fingerprint'], 'ingredient_id' => $localChild->id, 'resolution' => 'exact']);
    expect(app(IngredientShareResolver::class)->resolve($destination, $graph, [])['nodes']['n1']['ingredient_id'])->toBe($localChild->id);
});

it('binds effective live IFRA selection to default switches and newer applicable amendments only', function (): void {
    extract(shareResolutionContext());
    $type = ProductType::factory()->create();
    $family = ProductFamily::factory()->create();
    $type->productFamilies()->attach($family->id);
    $amendment = IfraAmendment::factory()->create(['status' => 'notified', 'notification_date' => today()->subDays(2)]);
    $category = IfraProductCategory::factory()->create();
    $mapping = ProductTypeIfraCategory::factory()->create(['product_type_id' => $type->id, 'ifra_amendment_id' => $amendment->id, 'ifra_product_category_id' => $category->id, 'is_default' => true]);
    $other = ProductTypeIfraCategory::factory()->create(['product_type_id' => $type->id, 'ifra_amendment_id' => $amendment->id, 'is_default' => false]);
    $share = FormulaShare::factory()->create(['source_workspace_id' => $source->id, 'recipient_workspace_id' => $destination->id, 'snapshot' => ['schema_version' => 1, 'product' => ['name' => 'Product', 'family' => ['id' => $family->id, 'slug' => $family->slug], 'type' => ['id' => $type->id]], 'formula' => ['phases' => [], 'manufacturing_mode' => 'blend_only', 'ifra' => ['selection_mode' => 'automatic', 'amendment' => ['id' => $amendment->id], 'category' => ['id' => $category->id], 'mapping' => ['id' => $mapping->id]]], 'ingredients' => $graph]]);
    $preview = app(FormulaSharePreview::class);
    $first = $preview->build($destination->owner, $share, []);
    ProductTypeIfraCategory::factory()->create(['ifra_amendment_id' => $amendment->id, 'is_default' => true]);
    expect($preview->build($destination->owner, $share, [])['expected_hash'])->toBe($first['expected_hash']);
    $mapping->update(['is_default' => false]);
    $other->update(['is_default' => true]);
    $second = $preview->build($destination->owner, $share, []);
    expect($second['expected_hash'])->not->toBe($first['expected_hash'])->and($second['warnings'])->toContain('ifra_selection_changed');
    $newer = IfraAmendment::factory()->create(['status' => 'notified', 'notification_date' => today()->subDay()]);
    ProductTypeIfraCategory::factory()->create(['product_type_id' => $type->id, 'ifra_amendment_id' => $newer->id, 'is_default' => true]);
    expect($preview->build($destination->owner, $share, [])['expected_hash'])->not->toBe($second['expected_hash']);
});

it('keeps manual IFRA category selection stable across default changes while binding the effective amendment', function (): void {
    extract(shareResolutionContext());
    $type = ProductType::factory()->create();
    $family = ProductFamily::factory()->create();
    $type->productFamilies()->attach($family->id);
    $amendment = IfraAmendment::factory()->create(['status' => 'notified', 'notification_date' => today()->subDays(2)]);
    $category = IfraProductCategory::factory()->create();
    $mapping = ProductTypeIfraCategory::factory()->create(['product_type_id' => $type->id, 'ifra_amendment_id' => $amendment->id, 'ifra_product_category_id' => $category->id, 'is_default' => true]);
    $share = FormulaShare::factory()->create(['source_workspace_id' => $source->id, 'recipient_workspace_id' => $destination->id, 'snapshot' => ['schema_version' => 1, 'product' => ['name' => 'Product', 'family' => ['id' => $family->id, 'slug' => $family->slug], 'type' => ['id' => $type->id]], 'formula' => ['phases' => [], 'manufacturing_mode' => 'blend_only', 'ifra' => ['selection_mode' => 'manual', 'amendment' => ['id' => $amendment->id], 'category' => ['id' => $category->id], 'mapping' => ['id' => $mapping->id]]], 'ingredients' => $graph]]);
    $preview = app(FormulaSharePreview::class);
    $first = $preview->build($destination->owner, $share, []);
    $mapping->update(['is_default' => false]);
    expect($preview->build($destination->owner, $share, [])['expected_hash'])->toBe($first['expected_hash']);
    $newer = IfraAmendment::factory()->create(['status' => 'notified', 'notification_date' => today()->subDay()]);
    ProductTypeIfraCategory::factory()->create(['product_type_id' => $type->id, 'ifra_amendment_id' => $newer->id, 'is_default' => true]);
    $second = $preview->build($destination->owner, $share, []);
    expect($second['expected_hash'])->not->toBe($first['expected_hash'])->and($second['ifra']['category_code'])->toBe($category->code);
});
