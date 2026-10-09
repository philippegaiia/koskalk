<?php

use App\Actions\FormulaSharing\AcceptFormulaShare;
use App\Actions\FormulaSharing\SendFormulaShare;
use App\Enums\IngredientCategory;
use App\Enums\MediaAssetUsageRole;
use App\Enums\OwnerType;
use App\Livewire\Dashboard\FormulaShareReview;
use App\Models\Allergen;
use App\Models\Brand;
use App\Models\CurrentMaterialPrice;
use App\Models\FattyAcid;
use App\Models\FormulaShare;
use App\Models\IfraAmendment;
use App\Models\IfraProductCategory;
use App\Models\Ingredient;
use App\Models\IngredientIdentifierEvidence;
use App\Models\MediaAsset;
use App\Models\MediaAssetUsage;
use App\Models\ProductionBatch;
use App\Models\ProductionBatchPreset;
use App\Models\ProductionLocation;
use App\Models\ProductionTaskSet;
use App\Models\ProductTypeIfraCategory;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\RecipeVersionCosting;
use App\Models\RecipeVersionPackagingItem;
use App\Models\StockLot;
use App\Models\Substance;
use App\Models\Supplier;
use App\Models\SupplierListing;
use App\Models\User;
use App\Models\UserEntitlement;
use App\Models\Workspace;
use App\Models\WorkspaceIngredientCode;
use App\Models\WorkspaceIngredientGuidance;
use App\Models\WorkspaceMaterialSetting;
use App\Services\FormulaSharePreview;
use App\Services\FormulaShareSnapshotBuilder;
use App\Services\RecipeWorkbenchService;
use Database\Seeders\SupportedLocaleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\FormulaSharingFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['workspaces.formula_sharing.enabled' => true, 'workspaces.collaboration_enabled' => true]);
    $this->seed(SupportedLocaleSeeder::class);
});

function roundTripSend(User $actor, Recipe $product, Workspace $destination): FormulaShare
{
    $builder = app(FormulaShareSnapshotBuilder::class);
    $snapshot = $builder->build($actor, $product, []);

    return app(SendFormulaShare::class)->handle($actor, $product, $destination, [], $builder->previewHash($snapshot, $destination), (string) Str::uuid());
}

function roundTripAccept(Workspace $destination, FormulaShare $share, array $decisions = []): Recipe
{
    $preview = app(FormulaSharePreview::class)->build($destination->owner, $share, $decisions);

    return app(AcceptFormulaShare::class)->handle($destination->owner, $share, $decisions, $preview['expected_hash']);
}

function roundTripCopy(Workspace $workspace, Ingredient $source): Ingredient
{
    return Ingredient::withoutGlobalScopes()->where('workspace_id', $workspace->id)->where('share_lineage_key', $source->sharingLineageKey())->firstOrFail();
}

/** @return array<string, mixed> */
function roundTripSoapFixture(): array
{
    $fixture = FormulaSharingFixtures::offer();
    $workspace = $fixture['source'];
    $acid = FattyAcid::factory()->create(['key' => 'oleic']);
    $fixture['oil']->forceFill(['display_name' => 'Modified trusted oil', 'inci_name' => 'Private Lipid', 'source_data' => ['user_authoring' => ['trusted_koh_sap_value' => '0.188000', 'trusted_fatty_acid_profile' => [$acid->id => '80.20000']]]])->save();
    $fixture['oil']->fattyAcidEntries()->create(['fatty_acid_id' => $acid->id, 'percentage' => '81.23456']);
    $platformOil = Ingredient::factory()->create(['catalog_key' => 'PLATFORM-ROUNDTRIP-OIL', 'display_name' => 'Platform olive oil', 'inci_name' => 'Olea Europaea Fruit Oil', 'soap_inci_naoh_name' => 'Sodium Olivate', 'soap_inci_koh_name' => 'Potassium Olivate', 'is_soap_saponification_trusted' => true]);
    $platformOil->sapProfile()->create(['koh_sap_value' => '0.189000']);
    $essentialOil = Ingredient::factory()->create(['workspace_id' => $workspace->id, 'owner_type' => OwnerType::Workspace, 'owner_id' => $workspace->id, 'category' => IngredientCategory::AromaticMaterials, 'display_name' => 'Private essential oil', 'inci_name' => 'Lavandula Angustifolia Oil', 'requires_aromatic_compliance' => true]);
    $allergen = Allergen::factory()->create(['inci_name' => 'Linalool']);
    $essentialOil->allergenEntries()->create(['allergen_id' => $allergen->id, 'concentration_percent' => '1.23456']);
    foreach ([null, '0.00000', '1.23456'] as $concentration) {
        $essentialOil->substanceEntries()->create(['substance_id' => Substance::factory()->create()->id, 'concentration_percent' => $concentration, 'concentration_source' => $concentration === null ? 'unknown' : 'supplier']);
    }
    $essentialOil->identifiers()->create(['scheme' => 'cas', 'value' => '8000-28-0', 'normalized_value' => '8000-28-0', 'is_primary' => true]);
    $essentialOil->translations()->create(['locale' => 'fr', 'display_name' => 'Huile essentielle privée']);
    $essentialOil->aliases()->create(['locale' => 'en', 'name' => 'Lavender identity', 'normalized_name' => 'lavender identity', 'kind' => 'common']);
    $amendment = IfraAmendment::factory()->create(['code' => 'RT-51', 'status' => 'notified', 'notification_date' => today()->subDay()]);
    $category = IfraProductCategory::factory()->create(['code' => 'RT-9']);
    ProductTypeIfraCategory::factory()->create(['product_type_id' => $fixture['type']->id, 'ifra_amendment_id' => $amendment->id, 'ifra_product_category_id' => $category->id, 'is_default' => true]);
    foreach ([true, false] as $current) {
        $essentialOil->ifraCertificates()->create(['certificate_name' => $current ? 'Current structured certificate' : 'Earlier structured certificate', 'ifra_amendment_id' => $amendment->id, 'source_amendment_label' => '51', 'published_at' => '2026-01-01', 'valid_from' => '2026-02-01', 'peroxide_value' => '1.234', 'is_current' => $current])->limits()->create(['ifra_product_category_id' => $category->id, 'max_percentage' => '5.12345']);
    }
    $inner = Ingredient::factory()->create(['workspace_id' => $workspace->id, 'owner_type' => OwnerType::Workspace, 'owner_id' => $workspace->id, 'display_name' => 'Private inner blend']);
    $outer = Ingredient::factory()->create(['workspace_id' => $workspace->id, 'owner_type' => OwnerType::Workspace, 'owner_id' => $workspace->id, 'display_name' => 'Private outer blend']);
    $inner->components()->create(['component_ingredient_id' => $essentialOil->id, 'percentage_in_parent' => '100.00000', 'sort_order' => 0]);
    $outer->components()->create(['component_ingredient_id' => $inner->id, 'percentage_in_parent' => '50.00000', 'sort_order' => 0]);
    $outer->components()->create(['component_ingredient_id' => $essentialOil->id, 'percentage_in_parent' => '50.00000', 'sort_order' => 1]);
    $payload = $fixture['payload'];
    $payload['phase_items']['saponified_oils'] = [['ingredient_id' => $fixture['oil']->id, 'percentage' => '70.1234'], ['ingredient_id' => $platformOil->id, 'percentage' => '29.8766']];
    $payload['phase_items']['fragrance'] = [['ingredient_id' => $outer->id, 'percentage' => '1.2345'], ['ingredient_id' => $essentialOil->id, 'percentage' => '0.1234']];
    $current = app(RecipeWorkbenchService::class)->publish($fixture['owner'], $fixture['family'], $payload, $fixture['recipe']);
    $saved = RecipeVersion::withoutGlobalScopes()->where('recipe_id', $fixture['recipe']->id)->where('is_current', false)->latest('version_number')->firstOrFail();

    return array_replace($fixture, compact('platformOil', 'essentialOil', 'inner', 'outer', 'acid', 'current', 'saved'));
}

it('round trips a real soap Formula through three Workspaces with precise chemistry, shared children and an actual dilution substitute', function (): void {
    $fixture = roundTripSoapFixture();
    $third = Workspace::factory()->create();
    UserEntitlement::factory()->create(['user_id' => $third->owner_user_id, 'plan_id' => $fixture['owner']->entitlements()->first()->plan_id]);
    $substitute = Ingredient::factory()->create(['workspace_id' => $fixture['recipient']->id, 'owner_type' => OwnerType::Workspace, 'owner_id' => $fixture['recipient']->id, 'category' => IngredientCategory::WaterSolventsCarriers, 'display_name' => 'Recipient dilution liquid', 'inci_name' => 'Recipient Aqua']);
    $ab = roundTripSend($fixture['owner'], $fixture['recipe'], $fixture['recipient']);
    $liquidKey = collect($ab->snapshot['ingredients']['nodes'])->search(fn (array $node): bool => $node['lineage_key'] === $fixture['liquid']->public_id);
    $decisions = [['key' => $liquidKey, 'mode' => 'substitute', 'ingredient_public_id' => $substitute->public_id]];
    $bProduct = roundTripAccept($fixture['recipient'], $ab, $decisions);
    $bc = roundTripSend($fixture['recipient']->owner, $bProduct, $third);
    expect(collect($bc->snapshot['ingredients']['nodes'])->pluck('lineage_key'))->toContain($substitute->public_id)->not->toContain($fixture['liquid']->public_id);
    $cProduct = roundTripAccept($third, $bc);
    foreach ([$fixture['recipient'], $third] as $workspace) {
        $oil = roundTripCopy($workspace, $fixture['oil']);
        $eo = roundTripCopy($workspace, $fixture['essentialOil']);
        $outer = roundTripCopy($workspace, $fixture['outer']);
        $inner = roundTripCopy($workspace, $fixture['inner']);
        expect($oil->sapProfile->koh_sap_value)->toBe('0.190123')
            ->and(data_get($oil->source_data, 'user_authoring.trusted_koh_sap_value'))->toBe('0.188000')
            ->and(data_get($oil->source_data, 'user_authoring.trusted_fatty_acid_profile.'.$fixture['acid']->id))->toBe('80.20000')
            ->and($oil->fattyAcidEntries->first()->percentage)->toBe('81.23456')
            ->and($eo->allergenEntries->first()->concentration_percent)->toBe('1.23456')
            ->and($eo->substanceEntries->pluck('concentration_percent')->all())->toBe([null, '0.00000', '1.23456'])
            ->and($eo->substanceEntries->pluck('concentration_source')->all())->toBe(['unknown', 'supplier', 'supplier'])
            ->and($eo->ifraCertificates)->toHaveCount(2)->and($eo->ifraCertificates->first()->limits->first()->max_percentage)->toBe('5.12345')
            ->and($outer->components->pluck('component_ingredient_id')->all())->toBe([$inner->id, $eo->id])
            ->and($inner->components->first()->component_ingredient_id)->toBe($eo->id);
    }
    $ca = roundTripSend($third->owner, $cProduct, $fixture['source']);
    $returnPreview = app(FormulaSharePreview::class)->build($fixture['owner'], $ca, []);
    expect($returnPreview['import_count'])->toBe(1)->and($returnPreview['remaining_keys'])->toBe([]);
    $aProduct = roundTripAccept($fixture['source'], $ca);
    $aCurrent = $aProduct->versions()->withoutGlobalScopes()->where('is_current', true)->firstOrFail();
    expect($aCurrent->items()->withoutGlobalScopes()->pluck('ingredient_id'))->toContain($fixture['oil']->id, $fixture['platformOil']->id, $fixture['essentialOil']->id, $fixture['outer']->id)
        ->and($aCurrent->items()->withoutGlobalScopes()->pluck('ingredient_id'))->not->toContain($fixture['liquid']->id);
    foreach ([$bProduct, $cProduct, $aProduct] as $product) {
        expect($product->versions()->withoutGlobalScopes()->whereNotNull('catalog_reviewed_at')->count())->toBe(0)
            ->and($product->versions()->withoutGlobalScopes()->whereNotNull('final_ingredient_list')->count())->toBe(0);
    }
    $cOil = roundTripCopy($third, $fixture['oil']);
    $cOil->sapProfile()->update(['koh_sap_value' => '0.191500']);
    $modified = roundTripSend($third->owner, $cProduct, $fixture['source']);
    $changed = app(FormulaSharePreview::class)->build($fixture['owner'], $modified, []);
    $oilKey = collect($modified->snapshot['ingredients']['nodes'])->search(fn (array $node): bool => $node['lineage_key'] === $fixture['oil']->public_id);
    expect($changed['remaining_keys'])->toContain($oilKey);
    $fork = roundTripAccept($fixture['source'], $modified, [['key' => $oilKey, 'mode' => 'import']]);
    expect($fixture['oil']->sapProfile()->first()->koh_sap_value)->toBe('0.190123');
    $next = roundTripSend($third->owner, $cProduct, $fixture['source']);
    expect(app(FormulaSharePreview::class)->build($fixture['owner'], $next, [])['import_count'])->toBe(0);
});

it('carries actual cosmetic substitute ancestry onward and demands explicit confirmation on every repeated original offer', function (): void {
    $fixture = FormulaSharingFixtures::offer('cosmetic', options: []);
    $third = Workspace::factory()->create();
    UserEntitlement::factory()->create(['user_id' => $third->owner_user_id, 'plan_id' => $fixture['owner']->entitlements()->first()->plan_id]);
    $substitute = Ingredient::factory()->create(['workspace_id' => $fixture['recipient']->id, 'owner_type' => OwnerType::Workspace, 'owner_id' => $fixture['recipient']->id, 'display_name' => 'Actual B cosmetic substitute', 'inci_name' => 'Different Local INCI']);
    $key = collect($fixture['share']->snapshot['ingredients']['nodes'])->search(fn (array $node): bool => $node['lineage_key'] === $fixture['oil']->public_id);
    $this->actingAs($fixture['recipient']->owner);
    Livewire::test(FormulaShareReview::class, ['share' => $fixture['share']])->set('choices.'.$key.'.mode', 'substitute')->set('choices.'.$key.'.ingredient_public_id', $substitute->public_id)
        ->set('choices.'.$key.'.confirmed', true)->call('refreshPreview')->assertHasNoErrors()->set('confirmed', true)->call('accept')->assertHasNoErrors();
    $bProduct = $fixture['share']->refresh()->acceptedRecipe;
    $bc = roundTripSend($fixture['recipient']->owner, $bProduct, $third);
    expect(collect($bc->snapshot['ingredients']['nodes'])->pluck('lineage_key'))->toContain($substitute->public_id)->not->toContain($fixture['oil']->public_id);
    $cProduct = roundTripAccept($third, $bc);
    $ca = roundTripSend($third->owner, $cProduct, $fixture['source']);
    expect(app(FormulaSharePreview::class)->build($fixture['owner'], $ca, [])['import_count'])->toBe(1);
    $aProduct = roundTripAccept($fixture['source'], $ca);
    expect($aProduct->versions()->withoutGlobalScopes()->where('is_current', true)->first()->items()->withoutGlobalScopes()->pluck('ingredient_id'))->not->toContain($fixture['oil']->id);
    $again = roundTripSend($fixture['owner'], $fixture['recipe'], $fixture['recipient']);
    $review = app(FormulaSharePreview::class)->build($fixture['recipient']->owner, $again, []);
    expect($review['remaining_keys'])->toContain($key)->and($review['warnings'])->toContain('remembered_substitution');
    expect($substitute->fresh()->sharingLineageKey())->toBe($substitute->public_id);
});

/** @param array<string, mixed> $fixture @return list<string> */
function roundTripSourceSentinels(array $fixture): array
{
    $product = $fixture['recipe'];
    $source = $fixture['source'];
    $saved = $fixture['saved'];
    $ingredient = $fixture['essentialOil'];
    $sentinels = ['PRIVATE-NOTES', 'PRIVATE-SOURCE-JSON', 'PRIVATE-INFO', 'PRIVATE-COMPOSITION-NOTES', 'PRIVATE-ALLERGEN-NOTES', 'PRIVATE-IMAGE-PATH', 'PRIVATE-IMAGE-NAME', 'PRIVATE-ICON-PATH', 'PRIVATE-ICON-NAME', 'PRIVATE-TRANSLATION-INFO', 'PRIVATE-ALLERGEN-SOURCE', 'PRIVATE-SUBSTANCE-SOURCE', 'PRIVATE-CERTIFICATE-FILE', 'PRIVATE-CERTIFICATE-ISSUER', 'PRIVATE-CERTIFICATE-METADATA', 'PRIVATE-LIMIT-NOTE', 'PRIVATE-MARKET-SOURCE', 'PRIVATE-MARKET-URL', 'PRIVATE-PRODUCT-REFERENCE', 'PRIVATE-BRAND', 'PRIVATE-LOCATION', 'PRIVATE-TASK-SET', 'PRIVATE-MATERIAL-CODE', 'PRIVATE-GUIDANCE', 'PRIVATE-SUPPLIER', 'PRIVATE-SKU', 'PRIVATE-SUPPLIER-LINK', 'PRIVATE-STOCK-LOT', 'PRIVATE-STOCK-NOTES', 'PRIVATE-PRODUCTION-NUMBER', 'PRIVATE-PRODUCTION-NOTES', 'PRIVATE-FINAL-DECLARATION', 'PRIVATE-FINAL-HASH', 'PRIVATE-VERSION-NOTES', 'PRIVATE-PACKAGING', '987654.32'];
    $sentinels = array_merge($sentinels, ['PRIVATE-IDENTIFIER-EVIDENCE', 'PRIVATE-EVIDENCE-URL', 'PRIVATE-COMPONENT-NOTES', 'PRIVATE-MEDIA-FILENAME', 'PRIVATE-PROCEDURE-MEDIA', 'PRIVATE-PACKAGING-PLAN', 'PRIVATE-BATCH-PRESET']);
    $ingredient->forceFill(['notes' => 'PRIVATE-NOTES', 'source_data' => ['secret' => 'PRIVATE-SOURCE-JSON'], 'info_markdown' => 'PRIVATE-INFO', 'composition_source_notes' => 'PRIVATE-COMPOSITION-NOTES', 'allergen_source_notes' => 'PRIVATE-ALLERGEN-NOTES', 'featured_image_path' => 'PRIVATE-IMAGE-PATH', 'featured_image_original_name' => 'PRIVATE-IMAGE-NAME', 'icon_image_path' => 'PRIVATE-ICON-PATH', 'icon_image_original_name' => 'PRIVATE-ICON-NAME', 'requires_admin_review' => true, 'is_manufactured' => true])->save();
    IngredientIdentifierEvidence::factory()->create(['ingredient_identifier_id' => $ingredient->identifiers()->firstOrFail()->id, 'source_name' => 'PRIVATE-IDENTIFIER-EVIDENCE', 'source_url' => 'https://example.test/PRIVATE-EVIDENCE-URL']);
    $fixture['outer']->components()->update(['source_notes' => 'PRIVATE-COMPONENT-NOTES', 'source_data' => ['secret' => 'PRIVATE-COMPONENT-NOTES']]);
    $fixture['inner']->components()->update(['source_notes' => 'PRIVATE-COMPONENT-NOTES', 'source_data' => ['secret' => 'PRIVATE-COMPONENT-NOTES']]);
    $media = MediaAsset::factory()->ready()->create(['workspace_id' => $source->id, 'uploaded_by_user_id' => $fixture['owner']->id, 'original_filename' => 'PRIVATE-MEDIA-FILENAME.jpg']);
    MediaAssetUsage::factory()->create(['media_asset_id' => $media->id, 'usable_type' => Recipe::class, 'usable_id' => $product->id]);
    MediaAssetUsage::factory()->create(['media_asset_id' => $media->id, 'usable_type' => Ingredient::class, 'usable_id' => $ingredient->id, 'role' => MediaAssetUsageRole::IngredientMain]);
    $sentinels[] = $media->public_id;
    $ingredient->translations()->update(['info_markdown' => 'PRIVATE-TRANSLATION-INFO']);
    $ingredient->allergenEntries()->update(['source_notes' => 'PRIVATE-ALLERGEN-SOURCE']);
    $ingredient->substanceEntries()->update(['source_notes' => 'PRIVATE-SUBSTANCE-SOURCE', 'source_data' => ['secret' => 'PRIVATE-SUBSTANCE-SOURCE']]);
    $ingredient->ifraCertificates()->update(['document_name' => 'PRIVATE-CERTIFICATE-FILE', 'document_path' => 'PRIVATE-CERTIFICATE-FILE', 'issuer' => 'PRIVATE-CERTIFICATE-ISSUER', 'reference_code' => 'PRIVATE-CERTIFICATE-METADATA', 'source_notes' => 'PRIVATE-CERTIFICATE-METADATA', 'source_data' => ['secret' => 'PRIVATE-CERTIFICATE-METADATA']]);
    foreach ($ingredient->ifraCertificates()->get() as $certificate) {
        $certificate->limits()->update(['restriction_note' => 'PRIVATE-LIMIT-NOTE']);
    }
    $ingredient->marketLabels()->create(['market_code' => 'ca', 'declaration_name' => 'Canadian technical declaration', 'source_name' => 'PRIVATE-MARKET-SOURCE', 'source_url' => 'PRIVATE-MARKET-URL']);
    $brand = Brand::query()->create(['workspace_id' => $source->id, 'name' => 'PRIVATE-BRAND']);
    $location = ProductionLocation::factory()->create(['workspace_id' => $source->id, 'name' => 'PRIVATE-LOCATION']);
    $taskSet = ProductionTaskSet::factory()->create(['workspace_id' => $source->id, 'name' => 'PRIVATE-TASK-SET']);
    $product->productionTaskSets()->attach($taskSet, ['is_default' => true]);
    $product->forceFill(['product_reference' => 'PRIVATE-PRODUCT-REFERENCE', 'brand_id' => $brand->id, 'default_production_location_id' => $location->id, 'ready_delay_days' => 999, 'nominal_content_value' => '987654.32', 'featured_image_path' => 'PRIVATE-IMAGE-PATH', 'featured_image_original_name' => 'PRIVATE-IMAGE-NAME', 'output_ingredient_id' => $ingredient->id, 'locked_at' => now(), 'locked_by' => $fixture['owner']->id])->save();
    $saved->forceFill(['notes' => 'PRIVATE-VERSION-NOTES', 'manufacturing_instructions' => '<p>PRIVATE-PROCEDURE-MEDIA</p><img src="/media/'.$media->public_id.'">', 'final_ingredient_list' => 'PRIVATE-FINAL-DECLARATION', 'final_ingredient_list_basis_hash' => 'PRIVATE-FINAL-HASH', 'final_plain_ingredient_list' => 'PRIVATE-FINAL-DECLARATION', 'final_plain_ingredient_list_basis_hash' => 'PRIVATE-FINAL-HASH', 'catalog_reviewed_at' => now()])->save();
    $saved->packagingItems()->create(['name' => 'PRIVATE-PACKAGING-PLAN', 'components_per_unit' => 1, 'notes' => 'PRIVATE-PACKAGING-PLAN', 'position' => 1]);
    WorkspaceIngredientCode::factory()->create(['workspace_id' => $source->id, 'ingredient_id' => $ingredient->id, 'material_code' => 'PRIVATE-MATERIAL-CODE']);
    WorkspaceIngredientGuidance::factory()->create(['workspace_id' => $source->id, 'ingredient_id' => $ingredient->id, 'guidance_html' => '<p>PRIVATE-GUIDANCE</p>']);
    WorkspaceMaterialSetting::factory()->create(['workspace_id' => $source->id, 'ingredient_id' => $ingredient->id, 'buffer_quantity' => '987654.320000000']);
    ProductionBatchPreset::factory()->create(['workspace_id' => $source->id, 'name' => 'PRIVATE-BATCH-PRESET']);
    CurrentMaterialPrice::factory()->create(['workspace_id' => $source->id, 'ingredient_id' => $ingredient->id, 'price_per_canonical_unit' => '987654.32']);
    $supplier = Supplier::factory()->create(['workspace_id' => $source->id, 'name' => 'PRIVATE-SUPPLIER']);
    SupplierListing::factory()->create(['workspace_id' => $source->id, 'supplier_id' => $supplier->id, 'ingredient_id' => $ingredient->id, 'supplier_sku' => 'PRIVATE-SKU', 'notes' => 'PRIVATE-SUPPLIER-LINK', 'price_amount' => '987654.32']);
    StockLot::factory()->create(['workspace_id' => $source->id, 'ingredient_id' => $ingredient->id, 'internal_lot_code' => 'PRIVATE-STOCK-LOT', 'notes' => 'PRIVATE-STOCK-NOTES', 'historical_unit_cost' => '987654.32']);
    ProductionBatch::factory()->create(['workspace_id' => $source->id, 'user_id' => $fixture['owner']->id, 'recipe_id' => $product->id, 'recipe_version_id' => $saved->id, 'production_batch_number' => 'PRIVATE-PRODUCTION-NUMBER', 'production_notes' => 'PRIVATE-PRODUCTION-NOTES', 'total_cost' => '987654.32']);
    $costing = $saved->costings()->create(['user_id' => $fixture['owner']->id, 'oil_weight_for_costing' => 1000, 'oil_unit_for_costing' => 'g', 'units_produced' => 987654, 'currency' => 'EUR']);
    $costing->items()->create(['ingredient_id' => $ingredient->id, 'phase_key' => 'fragrance', 'position' => 1, 'price_per_kg' => '987654.32']);
    $costing->packagingItems()->create(['name' => 'PRIVATE-PACKAGING', 'unit_cost' => '987654.32', 'quantity' => 1]);

    return $sentinels;
}

it('excludes structured private source data from immutable offers, HTML, Livewire, accepted Product, print, export and sender status', function (): void {
    $fixture = roundTripSoapFixture();
    $sentinels = roundTripSourceSentinels($fixture);
    $offer = roundTripSend($fixture['owner'], $fixture['recipe'], $fixture['recipient']);
    $this->actingAs($fixture['owner']);
    $senderPage = $this->get(route('formula-shares.show', $offer))->assertOk()->getContent();
    $senderState = Livewire::test(FormulaShareReview::class, ['share' => $offer])->html(false);
    $this->actingAs($fixture['recipient']->owner);
    $recipientPage = $this->get(route('formula-shares.show', $offer))->assertOk()->getContent();
    $component = Livewire::test(FormulaShareReview::class, ['share' => $offer]);
    $recipientState = $component->html(false).json_encode($component->get('display'), JSON_THROW_ON_ERROR);
    $component->set('confirmed', true)->call('accept')->assertHasNoErrors();
    $received = $offer->refresh()->acceptedRecipe;
    $privateCopies = Ingredient::withoutGlobalScopes()->where('workspace_id', $fixture['recipient']->id)->get();
    $accepted = $received->toJson().$received->versions()->withoutGlobalScopes()->with(['items', 'phases'])->get()->toJson();
    foreach ($privateCopies as $ingredient) {
        $accepted .= $ingredient->load(['sapProfile', 'fattyAcidEntries', 'allergenEntries', 'substanceEntries', 'ifraCertificates.limits', 'marketLabels', 'translations', 'identifiers.evidence', 'aliases', 'components', 'mediaAssetUsages'])->toJson();
    }
    $printed = $this->get(route('recipes.print.production', $received))->assertOk()->getContent();
    $technicalPrint = $this->get(route('recipes.print.technical', $received))->assertOk()->getContent();
    $csv = $this->get(route('recipes.export.csv', $received))->assertOk()->streamedContent();
    $this->actingAs($fixture['owner']);
    $senderStatus = $this->get(route('formula-shares.show', $offer))->assertOk()->getContent();
    $surfaces = [$offer->snapshot, $senderPage, $senderState, $recipientPage, $recipientState, $accepted, $printed, $technicalPrint, $csv, $senderStatus];
    foreach ($surfaces as $surface) {
        $text = is_array($surface) ? json_encode($surface, JSON_THROW_ON_ERROR) : $surface;
        foreach ($sentinels as $sentinel) {
            expect($text)->not->toContain($sentinel);
        }
    }
    expect($received->brand_id)->toBeNull()->and($received->default_production_location_id)->toBeNull()->and($received->locked_at)->toBeNull()
        ->and($received->productionTaskSets()->count())->toBe(0)->and($received->mediaAssetUsages()->count())->toBe(0)
        ->and($received->product_reference)->toBeNull()->and($received->productionBatches()->count())->toBe(0)
        ->and($received->output_ingredient_id)->toBeNull()
        ->and(RecipeVersionPackagingItem::query()->whereIn('recipe_version_id', $received->versions()->withoutGlobalScopes()->pluck('id'))->count())->toBe(0)
        ->and($privateCopies->where('is_manufactured', true))->toHaveCount(0)
        ->and(WorkspaceMaterialSetting::query()->where('workspace_id', $fixture['recipient']->id)->count())->toBe(0)
        ->and(ProductionBatchPreset::query()->where('workspace_id', $fixture['recipient']->id)->count())->toBe(0)
        ->and(RecipeVersionCosting::query()->whereIn('recipe_version_id', $received->versions()->withoutGlobalScopes()->pluck('id'))->count())->toBe(0);
    config(['workspaces.formula_sharing.enabled' => false]);
    $this->actingAs($fixture['recipient']->owner)->get(route('recipes.saved', $received))->assertOk();
    $this->get(route('formula-shares.show', $offer))->assertNotFound();
});

it('keeps captured offers usable after normal saved-history pruning and surfaces platform drift before acceptance', function (): void {
    $fixture = roundTripSoapFixture();
    $offer = roundTripSend($fixture['owner'], $fixture['recipe'], $fixture['recipient']);
    $sourceVersionId = $offer->source_version_id;
    $fixture['owner']->entitlements()->first()->plan->limits()->where('key', 'saved_formula_history')->update(['value' => 1]);
    app(RecipeWorkbenchService::class)->publish($fixture['owner'], $fixture['family'], $fixture['payload'], $fixture['recipe']);
    app(RecipeWorkbenchService::class)->publish($fixture['owner'], $fixture['family'], $fixture['payload'], $fixture['recipe']);
    expect(RecipeVersion::withoutGlobalScopes()->find($sourceVersionId))->toBeNull()
        ->and($offer->refresh()->source_version_id)->toBeNull()->and($offer->snapshot)->not->toBeNull();
    $fixture['platformOil']->sapProfile()->update(['koh_sap_value' => '0.200000']);
    $review = app(FormulaSharePreview::class)->build($fixture['recipient']->owner, $offer, []);
    expect($review['warnings'])->toContain('platform_changed');
    $received = roundTripAccept($fixture['recipient'], $offer);
    $oil = roundTripCopy($fixture['recipient'], $fixture['oil']);
    expect($oil->sapProfile->koh_sap_value)->toBe('0.190123')
        ->and($received->versions()->withoutGlobalScopes()->whereNotNull('catalog_reviewed_at')->count())->toBe(0);
});

it('recovers in the real review form after an imported local oil changes without overwriting it or making partial copies', function (): void {
    $fixture = FormulaSharingFixtures::offer(options: []);
    roundTripAccept($fixture['recipient'], $fixture['share']);
    $oil = roundTripCopy($fixture['recipient'], $fixture['oil']);
    $offer = roundTripSend($fixture['owner'], $fixture['recipe'], $fixture['recipient']);
    $key = collect($offer->snapshot['ingredients']['nodes'])->search(fn (array $node): bool => $node['lineage_key'] === $fixture['oil']->public_id);
    $this->actingAs($fixture['recipient']->owner);
    $component = Livewire::test(FormulaShareReview::class, ['share' => $offer]);
    expect($component->get('display.import_count'))->toBe(0);
    $ingredientCount = Ingredient::withoutGlobalScopes()->where('workspace_id', $fixture['recipient']->id)->count();
    $oil->sapProfile()->update(['koh_sap_value' => '0.191500']);

    $component->set('confirmed', true)->call('accept')->assertHasErrors(['sharing'])->assertSet('confirmed', false)->assertSet('expectedHash', null);

    expect(Recipe::withoutGlobalScopes()->where('workspace_id', $fixture['recipient']->id)->count())->toBe(1)
        ->and(Ingredient::withoutGlobalScopes()->where('workspace_id', $fixture['recipient']->id)->count())->toBe($ingredientCount)
        ->and($offer->fresh()->isPending())->toBeTrue();
    $component->call('refreshPreview')->assertHasNoErrors();
    expect($component->get('display.remaining_keys'))->toContain($key);
    $component->set('choices.'.$key.'.mode', 'import')->call('refreshPreview')->assertHasNoErrors()
        ->set('confirmed', true)->call('accept')->assertHasNoErrors();
    expect(Recipe::withoutGlobalScopes()->where('workspace_id', $fixture['recipient']->id)->count())->toBe(2)
        ->and(Ingredient::withoutGlobalScopes()->where('workspace_id', $fixture['recipient']->id)->count())->toBe($ingredientCount + 1)
        ->and($oil->sapProfile()->first()->koh_sap_value)->toBe('0.191500');
    $received = $offer->fresh()->acceptedRecipe;
    $receivedOil = $received->currentVersion->phases->firstWhere('slug', 'saponified_oils')->items->first()->ingredient;
    expect($receivedOil->id)->not->toBe($oil->id)->and($receivedOil->sapProfile->koh_sap_value)->toBe('0.190123')
        ->and(data_get($receivedOil->source_data, 'user_authoring.trusted_koh_sap_value'))->toBe('0.188000');
});
