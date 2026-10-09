<?php

use App\Enums\IngredientCategory;
use App\Enums\IngredientIdentifierScheme;
use App\Enums\OwnerType;
use App\Enums\Visibility;
use App\Models\Allergen;
use App\Models\FattyAcid;
use App\Models\FormulaShare;
use App\Models\IfraAmendment;
use App\Models\IfraProductCategory;
use App\Models\Ingredient;
use App\Models\IngredientShareMapping;
use App\Models\Plan;
use App\Models\Substance;
use App\Models\User;
use App\Models\UserEntitlement;
use App\Models\Workspace;
use App\Services\FormulaShareTransaction;
use App\Services\IngredientShareGraph;
use App\Services\IngredientShareImporter;
use App\Services\IngredientShareResolver;
use Database\Seeders\SupportedLocaleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['workspaces.formula_sharing.enabled' => true]);
});

/** @return array<string, mixed> */
function ingredientImportContext(?int $limit = 100): array
{
    $owner = User::factory()->create();
    $source = Workspace::factory()->for($owner, 'owner')->create();
    $destination = Workspace::factory()->create();
    UserEntitlement::factory()->for($destination->owner)->create(['plan_id' => Plan::factory()->hasLimit('private_ingredients', $limit)->create()->id]);
    $ingredient = Ingredient::factory()->create(['workspace_id' => $source->id, 'owner_type' => OwnerType::Workspace, 'owner_id' => $source->id]);

    return compact('owner', 'source', 'destination', 'ingredient');
}

function ingredientImportGrant(User $owner, Workspace $source, Workspace $destination, array $ids): FormulaShare
{
    $graph = app(IngredientShareGraph::class)->capture($owner, $source, $ids);
    unset($graph['source_keys']);

    return FormulaShare::factory()->create(['source_workspace_id' => $source->id, 'recipient_workspace_id' => $destination->id, 'snapshot' => ['schema_version' => 1, 'ingredients' => $graph]]);
}

/** @return array<string, int> */
function importIngredientGrant(Workspace $destination, FormulaShare $share, array $decisions = []): array
{
    return app(FormulaShareTransaction::class)->run($destination->owner, [$destination->id], function (User $actor, array $workspaces) use ($share, $decisions): array {
        $destination = $workspaces[$share->recipient_workspace_id];
        $resolved = app(IngredientShareResolver::class)->resolve($destination, $share->snapshot['ingredients'], $decisions);

        return app(IngredientShareImporter::class)->import($actor, $destination, $share, $resolved);
    });
}

it('round trips precise essential oil identity all certificates nullable substances provenance and market declarations', function (): void {
    $this->seed(SupportedLocaleSeeder::class);
    extract(ingredientImportContext());
    $ingredient->forceFill(['category' => IngredientCategory::AromaticMaterials, 'requires_aromatic_compliance' => true, 'notes' => 'SECRET', 'source_data' => ['secret' => 'SECRET'], 'featured_image_path' => 'SECRET'])->save();
    $ingredient->identifiers()->create(['scheme' => 'cas', 'value' => '8000-28-0', 'normalized_value' => '8000-28-0', 'is_primary' => true]);
    $ingredient->identifiers()->create(['scheme' => 'ec', 'value' => '232-433-8', 'normalized_value' => '232-433-8', 'is_primary' => false]);
    $ingredient->aliases()->create(['locale' => 'en', 'name' => 'Oil alias', 'normalized_name' => 'oil alias', 'kind' => 'common']);
    $ingredient->translations()->create(['locale' => 'fr', 'display_name' => 'Huile reçue', 'saponification_name' => 'Savon', 'info_markdown' => 'SECRET']);
    $ingredient->allergenEntries()->create(['allergen_id' => Allergen::factory()->create()->id, 'concentration_percent' => '1.23456', 'source_notes' => 'SECRET']);
    foreach ([null, '0.00000', '1.23456'] as $value) {
        $ingredient->substanceEntries()->create(['substance_id' => Substance::factory()->create()->id, 'concentration_percent' => $value, 'concentration_source' => $value === null ? 'unknown' : 'inferred', 'source_data' => ['secret' => 'SECRET']]);
    }
    $ingredient->marketLabels()->create(['market_code' => 'ca', 'declaration_name' => 'Canadian declaration', 'effective_from' => '2026-01-01', 'effective_until' => '2027-01-01', 'source_name' => 'SECRET', 'source_url' => 'SECRET']);
    $amendment = IfraAmendment::factory()->create();
    $category = IfraProductCategory::factory()->create();
    foreach ([true, false] as $current) {
        $ingredient->ifraCertificates()->create(['certificate_name' => $current ? 'Current certificate' : 'Other certificate', 'ifra_amendment_id' => $amendment->id, 'source_amendment_label' => '51', 'published_at' => '2026-01-01', 'valid_from' => '2026-02-01', 'peroxide_value' => '1.234', 'is_current' => $current, 'document_path' => 'SECRET', 'issuer' => 'SECRET'])->limits()->create(['ifra_product_category_id' => $category->id, 'max_percentage' => '5.12345', 'restriction_note' => 'SECRET']);
    }
    $share = ingredientImportGrant($owner, $source, $destination, [$ingredient->id]);
    $map = importIngredientGrant($destination, $share);
    $received = Ingredient::withoutGlobalScopes()->findOrFail($map['n1']);
    $received->load(['substanceEntries', 'ifraCertificates.limits', 'marketLabels', 'translations', 'identifiers', 'aliases']);

    expect($received->workspace_id)->toBe($destination->id)->and($received->owner_type)->toBe(OwnerType::Workspace)->and($received->owner_id)->toBe($destination->id)
        ->and($received->visibility)->toBe(Visibility::Private)->and($received->requires_admin_review)->toBeFalse()->and($received->is_manufactured)->toBeFalse()
        ->and($received->public_id)->not->toBe($ingredient->public_id)->and($received->catalog_key)->toStartWith('USR')->and($received->share_lineage_key)->toBe($ingredient->public_id)
        ->and($received->allergenEntries()->first()->concentration_percent)->toBe('1.23456')
        ->and($received->substanceEntries->pluck('concentration_percent')->all())->toBe([null, '0.00000', '1.23456'])
        ->and($received->substanceEntries->pluck('concentration_source')->all())->toBe(['unknown', 'inferred', 'inferred'])
        ->and($received->ifraCertificates)->toHaveCount(2)->and($received->ifraCertificates->first()->limits->first()->max_percentage)->toBe('5.12345')
        ->and($received->ifraCertificates->first()->peroxide_value)->toBe('1.234')->and($received->marketLabels->first()->declaration_name)->toBe('Canadian declaration')
        ->and($received->translations->first()->display_name)->toBe('Huile reçue')->and($received->identifiers->first()->is_primary)->toBeTrue()->and($received->aliases->first()->name)->toBe('Oil alias')
        ->and($received->identifiers->firstWhere('scheme', IngredientIdentifierScheme::Ec)->is_primary)->toBeFalse()
        ->and(json_encode($received->toArray()))->not->toContain('SECRET')->and($received->source_data)->toBeNull()
        ->and(IngredientShareMapping::query()->count())->toBe(1);
});

it('preserves the first trusted baseline independently of valid modified current chemistry', function (): void {
    extract(ingredientImportContext());
    $acid = FattyAcid::factory()->create(['key' => 'oleic']);
    $ingredient->forceFill(['is_soap_saponification_trusted' => true, 'source_data' => ['secret' => 'SECRET', 'user_authoring' => ['trusted_koh_sap_value' => '0.188000', 'trusted_fatty_acid_profile' => [$acid->id => '80.20000']]]])->save();
    $ingredient->sapProfile()->create(['koh_sap_value' => '0.190123', 'iodine_value' => '86.123', 'ins_value' => '102.456']);
    $ingredient->fattyAcidEntries()->create(['fatty_acid_id' => $acid->id, 'percentage' => '81.23456']);
    $share = ingredientImportGrant($owner, $source, $destination, [$ingredient->id]);
    $ingredient->sapProfile()->update(['koh_sap_value' => '0.188000']);
    $map = importIngredientGrant($destination, $share);
    $received = Ingredient::withoutGlobalScopes()->findOrFail($map['n1']);
    expect($received->sapProfile->koh_sap_value)->toBe('0.190123')->and($received->sapProfile->iodine_value)->toBe('86.123')
        ->and($received->fattyAcidEntries->first()->percentage)->toBe('81.23456')
        ->and(data_get($received->source_data, 'user_authoring.trusted_koh_sap_value'))->toBe('0.188000')
        ->and(data_get($received->source_data, 'user_authoring.trusted_fatty_acid_profile.'.$acid->id))->toBe('80.20000')
        ->and(json_encode($received->source_data))->not->toContain('SECRET');
    expect(importIngredientGrant($destination, $share)['n1'])->toBe($received->id)->and(IngredientShareMapping::query()->count())->toBe(1);
});

it('creates nested blends bottom up deduplicates children and rolls back every creation if final quota is exceeded', function (): void {
    extract(ingredientImportContext(2));
    $child = Ingredient::factory()->create(['workspace_id' => $source->id, 'owner_type' => OwnerType::Workspace, 'owner_id' => $source->id]);
    $other = Ingredient::factory()->create(['workspace_id' => $source->id, 'owner_type' => OwnerType::Workspace, 'owner_id' => $source->id]);
    foreach ([$ingredient, $other] as $parent) {
        $parent->components()->create(['component_ingredient_id' => $child->id, 'percentage_in_parent' => '99.12345', 'sort_order' => 7]);
    }
    $share = ingredientImportGrant($owner, $source, $destination, [$ingredient->id, $other->id]);
    expect(fn () => importIngredientGrant($destination, $share))->toThrow(ValidationException::class);
    expect(Ingredient::withoutGlobalScopes()->where('workspace_id', $destination->id)->count())->toBe(0)->and(IngredientShareMapping::query()->count())->toBe(0);
    $destination->owner->entitlements()->first()->plan->limits()->where('key', 'private_ingredients')->update(['value' => 3]);
    $map = importIngredientGrant($destination, $share);
    expect($map)->toHaveCount(3)->and(Ingredient::withoutGlobalScopes()->find($map['n1'])->components->first()->component_ingredient_id)->toBe($map['n2'])
        ->and(Ingredient::withoutGlobalScopes()->find($map['n3'])->components->first()->component_ingredient_id)->toBe($map['n2'])
        ->and(Ingredient::withoutGlobalScopes()->find($map['n1'])->components->first()->percentage_in_parent)->toBe('99.12345');
});

it('ignores forged caller material state reads the persisted grant and preserves explicit substitutes without rewriting ancestry', function (): void {
    extract(ingredientImportContext());
    $share = ingredientImportGrant($owner, $source, $destination, [$ingredient->id]);
    $substitute = Ingredient::factory()->create(['workspace_id' => $destination->id, 'owner_type' => OwnerType::Workspace, 'owner_id' => $destination->id, 'notes' => 'Keep local notes']);
    $map = importIngredientGrant($destination, $share, [['key' => 'n1', 'mode' => 'substitute', 'ingredient_public_id' => $substitute->public_id]]);
    expect($map['n1'])->toBe($substitute->id)->and($substitute->fresh()->share_lineage_key)->toBeNull()->and($substitute->fresh()->notes)->toBe('Keep local notes')
        ->and(IngredientShareMapping::query()->first()->resolution)->toBe('substitution')
        ->and(app(IngredientShareResolver::class)->resolve($destination, $share->snapshot['ingredients'], [])['remaining_keys'])->toBe(['n1']);
});

it('rejects a captured invalid baseline or unavailable reference before inserting anything', function (string $failure): void {
    extract(ingredientImportContext());
    $share = ingredientImportGrant($owner, $source, $destination, [$ingredient->id]);
    $snapshot = $share->snapshot;
    if ($failure === 'baseline') {
        $snapshot['ingredients']['nodes']['n1']['technical']['is_soap_saponification_trusted'] = true;
        $snapshot['ingredients']['nodes']['n1']['technical']['sap_profile'] = ['koh_sap_value' => '0.188000'];
    } else {
        $snapshot['ingredients']['nodes']['n1']['technical']['allergens'][] = ['reference' => ['id' => -1, 'identity' => ['inci_name' => 'Missing']], 'concentration_percent' => '1.23456'];
    }
    $share->forceFill(['snapshot' => $snapshot])->save();
    expect(fn () => importIngredientGrant($destination, $share))->toThrow(ValidationException::class);
    expect(Ingredient::withoutGlobalScopes()->where('workspace_id', $destination->id)->count())->toBe(0)->and(IngredientShareMapping::query()->count())->toBe(0);
})->with(['baseline', 'reference']);

it('gives a legacy workspace private Ingredient its native ancestry even with null owner type', function (): void {
    extract(ingredientImportContext());
    $ingredient->forceFill(['owner_type' => null, 'owner_id' => null])->save();
    $share = ingredientImportGrant($owner, $source, $destination, [$ingredient->id]);
    expect($share->snapshot['ingredients']['nodes']['n1']['lineage_key'])->toBe($ingredient->public_id);
    $map = importIngredientGrant($destination, $share);
    expect(Ingredient::withoutGlobalScopes()->findOrFail($map['n1'])->share_lineage_key)->toBe($ingredient->public_id);
});

it('reloads the stored snapshot and discards forged caller snapshot and resolution material facts', function (): void {
    extract(ingredientImportContext());
    $share = ingredientImportGrant($owner, $source, $destination, [$ingredient->id]);
    $share->forceFill(['snapshot' => ['forged' => 'SECRET']]);
    $map = app(FormulaShareTransaction::class)->run($destination->owner, [$destination->id], fn (User $actor, array $workspaces): array => app(IngredientShareImporter::class)->import($actor, $workspaces[$destination->id], $share, ['decisions' => [], 'nodes' => ['n1' => ['ingredient_id' => $ingredient->id, 'source_data' => 'SECRET']]]));
    expect(Ingredient::withoutGlobalScopes()->findOrFail($map['n1'])->inci_name)->toBe($ingredient->inci_name)
        ->and($map['n1'])->not->toBe($ingredient->id)->and(Ingredient::withoutGlobalScopes()->findOrFail($map['n1'])->source_data)->toBeNull();
});

it('rolls back a late identity validation failure including earlier dependency creations', function (): void {
    extract(ingredientImportContext());
    $child = Ingredient::factory()->create(['workspace_id' => $source->id, 'owner_type' => OwnerType::Workspace, 'owner_id' => $source->id]);
    $ingredient->components()->create(['component_ingredient_id' => $child->id, 'percentage_in_parent' => '100', 'sort_order' => 1]);
    $share = ingredientImportGrant($owner, $source, $destination, [$ingredient->id]);
    $snapshot = $share->snapshot;
    $snapshot['ingredients']['nodes']['n1']['display']['aliases'][] = ['locale' => 'xx', 'name' => 'Unsupported locale alias', 'kind' => 'common'];
    $share->forceFill(['snapshot' => $snapshot])->save();
    expect(fn () => importIngredientGrant($destination, $share))->toThrow(ValidationException::class);
    expect(Ingredient::withoutGlobalScopes()->where('workspace_id', $destination->id)->count())->toBe(0)->and(IngredientShareMapping::query()->count())->toBe(0);
});
