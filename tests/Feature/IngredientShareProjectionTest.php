<?php

use App\Enums\IngredientCategory;
use App\Enums\OwnerType;
use App\Models\Allergen;
use App\Models\FattyAcid;
use App\Models\IfraAmendment;
use App\Models\IfraProductCategory;
use App\Models\Ingredient;
use App\Models\IngredientIdentifierEvidence;
use App\Models\Substance;
use App\Models\User;
use App\Models\Workspace;
use App\Services\IngredientShareGraph;
use App\Services\IngredientShareProjector;
use App\Services\UserIngredientAuthoringService;
use Database\Seeders\SupportedLocaleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('projects full precision technical relations and excludes evidence private notes and files', function (): void {
    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::AromaticMaterials,
        'notes' => 'SECRET_NOTES', 'info_markdown' => 'SECRET_GUIDANCE',
        'featured_image_path' => 'SECRET_IMAGE', 'composition_source_notes' => 'SECRET_COMPOSITION',
        'source_data' => ['secret' => 'SECRET_SOURCE'],
    ]);
    $identifier = $ingredient->identifiers()->create(['scheme' => 'cas', 'value' => '8000-28-0', 'normalized_value' => '8000-28-0', 'is_primary' => true]);
    IngredientIdentifierEvidence::factory()->for($identifier, 'identifier')->create(['source_name' => 'SECRET_IDENTIFIER', 'source_url' => 'SECRET_EVIDENCE_URL']);
    $ingredient->aliases()->create(['locale' => 'en', 'name' => 'Identity alias', 'normalized_name' => 'identity alias', 'kind' => 'common']);
    $allergen = Allergen::factory()->create(['inci_name' => 'LIMONENE', 'source_file' => 'SECRET_ALLERGEN_FILE']);
    $substance = Substance::factory()->create(['name' => 'Constituent', 'source_url' => 'SECRET_SUBSTANCE_URL']);
    $ingredient->allergenEntries()->create(['allergen_id' => $allergen->id, 'concentration_percent' => '1.23456', 'source_notes' => 'SECRET_ALLERGEN_NOTES']);
    $ingredient->substanceEntries()->create(['substance_id' => $substance->id, 'concentration_percent' => null, 'concentration_source' => 'inferred', 'source_data' => ['secret' => 'SECRET_SUBSTANCE']]);
    $ingredient->marketLabels()->create(['market_code' => 'ca', 'declaration_name' => 'Market declaration', 'source_name' => 'SECRET_SUPPLIER', 'source_url' => 'SECRET_LINK', 'effective_from' => '2026-01-01']);
    $category = IfraProductCategory::factory()->create();
    $amendment = IfraAmendment::factory()->create();
    foreach ([true, false] as $isCurrent) {
        $ingredient->ifraCertificates()->create([
            'certificate_name' => 'Visible reference label', 'ifra_amendment_id' => $amendment->id,
            'peroxide_value' => '1.234', 'is_current' => $isCurrent,
            'issuer' => 'SECRET_ISSUER', 'document_path' => 'SECRET_PDF_PATH',
            'source_data' => ['secret' => 'SECRET_IFRA'],
        ])->limits()->create(['ifra_product_category_id' => $category->id, 'max_percentage' => '5.12345', 'restriction_note' => 'SECRET_LIMIT_NOTE']);
    }

    $projection = app(IngredientShareProjector::class)->project($ingredient);

    expect(data_get($projection, 'technical.allergens.0.concentration_percent'))->toBe('1.23456')
        ->and(data_get($projection, 'technical.substances.0.concentration_percent'))->toBeNull()
        ->and(data_get($projection, 'technical.substances.0.concentration_source'))->toBe('inferred')
        ->and(data_get($projection, 'display.aliases.0.name'))->toBe('Identity alias')
        ->and(data_get($projection, 'technical.identifiers.0.value'))->toBe('8000-28-0')
        ->and(data_get($projection, 'technical.ifra_certificates'))->toHaveCount(2)
        ->and(data_get($projection, 'technical.ifra_certificates.0.limits.0.max_percentage'))->toBe('5.12345')
        ->and(data_get($projection, 'technical.market_labels.0.effective_from'))->toBe('2026-01-01')
        ->and(json_encode($projection))->not->toContain('SECRET');
});

it('preserves modified trusted oil chemistry and its first baseline separately', function (): void {
    $acid = FattyAcid::factory()->create(['key' => 'oleic']);
    $ingredient = Ingredient::factory()->create([
        'owner_type' => OwnerType::Workspace, 'is_soap_saponification_trusted' => true,
        'source_data' => ['secret' => 'SECRET', 'user_authoring' => ['trusted_koh_sap_value' => '0.188', 'trusted_fatty_acid_profile' => [$acid->id => '80.20000']]],
    ]);
    $ingredient->sapProfile()->create(['koh_sap_value' => '0.190123', 'iodine_value' => '86.123', 'ins_value' => '102.456']);
    $ingredient->fattyAcidEntries()->create(['fatty_acid_id' => $acid->id, 'percentage' => '81.23456']);

    $projection = app(IngredientShareProjector::class)->project($ingredient);

    expect(data_get($projection, 'technical.sap_profile.koh_sap_value'))->toBe('0.190123')
        ->and(data_get($projection, 'technical.baseline.koh_sap_value'))->toBe('0.188000')
        ->and(data_get($projection, 'technical.baseline.fatty_acid_profile.oleic'))->toBe('80.20000')
        ->and(json_encode($projection))->not->toContain('SECRET');
});

it('deduplicates shared children and propagates child changes to both parent fingerprints', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $child = Ingredient::factory()->create(['workspace_id' => $workspace->id, 'owner_type' => OwnerType::Workspace, 'owner_id' => $workspace->id]);
    $parents = Ingredient::factory()->count(2)->create(['workspace_id' => $workspace->id, 'owner_type' => OwnerType::Workspace, 'owner_id' => $workspace->id]);
    foreach ($parents as $parent) {
        $parent->components()->create(['component_ingredient_id' => $child->id, 'percentage_in_parent' => '100', 'sort_order' => 1, 'source_notes' => 'SECRET_CHILD_NOTE']);
    }
    $graph = app(IngredientShareGraph::class)->capture($owner, $workspace, $parents->modelKeys());
    $child->sapProfile()->create(['koh_sap_value' => '0.188']);
    $changed = app(IngredientShareGraph::class)->capture($owner, $workspace, $parents->modelKeys());

    expect($graph['nodes'])->toHaveCount(3)
        ->and(json_encode($graph))->not->toContain('SECRET_CHILD_NOTE');
    foreach ($graph['root_keys'] as $key) {
        expect($changed['nodes'][$key]['fingerprint'])->not->toBe($graph['nodes'][$key]['fingerprint']);
    }
});

it('rejects inaccessible inactive missing and cyclic component paths', function (string $case): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $parent = Ingredient::factory()->create(['workspace_id' => $workspace->id, 'owner_type' => OwnerType::Workspace, 'owner_id' => $workspace->id]);
    $child = Ingredient::factory()->create(['workspace_id' => $workspace->id, 'owner_type' => OwnerType::Workspace, 'owner_id' => $workspace->id]);
    if ($case === 'inaccessible') {
        $child->forceFill(['workspace_id' => Workspace::factory()->create()->id])->save();
    } elseif ($case === 'inactive') {
        $child->forceFill(['is_active' => false])->save();
    }
    $parent->components()->create(['component_ingredient_id' => $child->id, 'percentage_in_parent' => '100', 'sort_order' => 1]);
    if ($case === 'missing') {
        $child->delete();
    } elseif ($case === 'cycle') {
        $child->components()->create(['component_ingredient_id' => $parent->id, 'percentage_in_parent' => '100', 'sort_order' => 1]);
    }

    expect(fn () => app(IngredientShareGraph::class)->capture($owner, $workspace, [$parent->id]))->toThrow(ValidationException::class);
})->with(['inaccessible', 'inactive', 'missing', 'cycle']);

it('enforces node edge depth and relation budgets during graph capture', function (string $limit): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $parent = Ingredient::factory()->create();
    $child = Ingredient::factory()->create();
    $parent->components()->create(['component_ingredient_id' => $child->id, 'percentage_in_parent' => '100', 'sort_order' => 1]);
    config(["workspaces.formula_sharing.limits.$limit" => 0]);

    expect(fn () => app(IngredientShareGraph::class)->capture($owner, $workspace, [$parent->id]))->toThrow(ValidationException::class);
})->with(['nodes', 'edges', 'depth', 'relation_rows']);

it('projects localized identity without guidance and keeps unchanged platform references', function (): void {
    $this->seed(SupportedLocaleSeeder::class);
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $platform = Ingredient::factory()->create(['owner_type' => null, 'owner_id' => null, 'workspace_id' => null, 'inci_name' => 'OLEA EUROPAEA FRUIT OIL']);
    $platform->translations()->create(['locale' => 'fr', 'display_name' => 'Huile d’olive', 'saponification_name' => 'Olive', 'info_markdown' => 'SECRET_LOCALIZED_GUIDANCE']);

    $graph = app(IngredientShareGraph::class)->capture($owner, $workspace, [$platform->id]);
    $projection = $graph['nodes'][$graph['root_keys'][0]];

    expect($projection['kind'])->toBe('platform')
        ->and($projection['platform_reference']['public_id'])->toBe($platform->public_id)
        ->and($projection['lineage_key'])->toBeNull()
        ->and(data_get($projection, 'display.translations.0.display_name'))->toBe('Huile d’olive')
        ->and(json_encode($projection))->not->toContain('SECRET');
});

it('canonicalizes tiny original fatty acid percentages from server stored duplicate baselines', function (): void {
    $owner = User::factory()->create();
    $trace = FattyAcid::factory()->create(['key' => 'trace']);
    $oleic = FattyAcid::factory()->create(['key' => 'oleic']);
    $platform = Ingredient::factory()->create(['owner_type' => null, 'owner_id' => null, 'workspace_id' => null, 'category' => IngredientCategory::Lipids, 'is_soap_saponification_trusted' => true]);
    $platform->sapProfile()->create(['koh_sap_value' => '0.188']);
    $platform->fattyAcidEntries()->createMany([['fatty_acid_id' => $trace->id, 'percentage' => '0.00001'], ['fatty_acid_id' => $oleic->id, 'percentage' => '80.20000']]);
    $copy = app(UserIngredientAuthoringService::class)->duplicate($platform, $owner);

    $projection = app(IngredientShareProjector::class)->project($copy);

    expect(data_get($projection, 'technical.baseline.fatty_acid_profile.trace'))->toBe('0.00001');
});
