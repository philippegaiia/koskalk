<?php

use App\Actions\FormulaSharing\SendFormulaShare;
use App\Enums\OwnerType;
use App\Enums\ProductionOutputType;
use App\Enums\WorkspaceMemberRole;
use App\Models\FormulaShare;
use App\Models\Ingredient;
use App\Models\IngredientSapProfile;
use App\Models\Plan;
use App\Models\ProductFamily;
use App\Models\ProductType;
use App\Models\ProductTypeIfraCategory;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\RecipePhase;
use App\Models\RecipeVersion;
use App\Models\RegulatoryRegime;
use App\Models\User;
use App\Models\UserEntitlement;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\FormulaShareBudget;
use App\Services\FormulaShareContentSanitizer;
use App\Services\FormulaShareSnapshotBuilder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['workspaces.formula_sharing.enabled' => true]);
});

/** @return array{owner: User, source: Workspace, recipient: Workspace, recipe: Recipe, saved: RecipeVersion, ingredient: Ingredient} */
function formulaShareIssueContext(): array
{
    $owner = User::factory()->create();
    $source = Workspace::factory()->for($owner, 'owner')->create();
    $recipient = Workspace::factory()->create();
    RegulatoryRegime::factory()->create(['code' => 'eu', 'status' => 'active']);
    Ingredient::factory()->create(['catalog_key' => 'CH1', 'owner_type' => null, 'owner_id' => null, 'workspace_id' => null]);
    Ingredient::factory()->create(['catalog_key' => 'CH3', 'owner_type' => null, 'owner_id' => null, 'workspace_id' => null]);
    $recipe = Recipe::factory()->create(['workspace_id' => $source->id, 'owner_type' => OwnerType::Workspace, 'owner_id' => $source->id, 'product_family_id' => ProductFamily::factory()->create(['slug' => 'soap'])->id, 'description' => '<p>Product description</p>']);
    $saved = RecipeVersion::factory()->for($recipe)->create([
        'workspace_id' => $source->id, 'owner_type' => OwnerType::Workspace, 'owner_id' => $source->id,
        'is_current' => false, 'ifra_category_selection_mode' => 'automatic', 'saved_at' => now(), 'manufacturing_instructions' => '<p>Saved procedure</p><img src="SECRET_MEDIA">',
        'water_settings' => ['mode' => 'percent_of_oils', 'value' => 38, 'secret' => 'SECRET_WATER'],
        'calculation_context' => ['editing_mode' => 'percentage', 'lye_type' => 'dual', 'koh_purity_percentage' => 90, 'dual_lye_koh_percentage' => 40, 'superfat' => 5, 'oil_weight' => 1000, 'oil_unit' => 'g', 'mass_grams' => '1000.000000000', 'secret' => 'SECRET_CALCULATION'],
    ]);
    $ingredient = Ingredient::factory()->create(['owner_type' => null, 'owner_id' => null, 'workspace_id' => null]);
    $phase = RecipePhase::factory()->for($saved, 'recipeVersion')->create(['workspace_id' => $source->id, 'slug' => 'saponified_oils', 'name' => 'Saponified oils', 'phase_type' => 'saponified_oils', 'sort_order' => 1]);
    RecipeItem::factory()->for($saved, 'recipeVersion')->for($phase, 'recipePhase')->create(['workspace_id' => $source->id, 'ingredient_id' => $ingredient->id, 'percentage' => '100.0000', 'weight' => '1000.0000', 'note' => 'Saved note']);

    return compact('owner', 'source', 'recipient', 'recipe', 'saved', 'ingredient');
}

it('issues an immutable recipient bound offer from Saved formula and selected plain text', function (): void {
    $this->freezeTime();
    $context = formulaShareIssueContext();
    extract($context);
    RecipeVersion::factory()->for($recipe)->create(['is_current' => true, 'version_number' => 2, 'manufacturing_instructions' => 'SECRET_DRAFT']);
    $options = ['include_procedure' => true, 'include_description' => true, 'include_line_notes' => true];
    $builder = app(FormulaShareSnapshotBuilder::class);
    $preview = $builder->build($owner, $recipe, $options);
    $hash = $builder->previewHash($preview, $recipient);
    $this->travel(10)->seconds();

    $share = app(SendFormulaShare::class)->handle($owner, $recipe, $recipient, $options, $hash, (string) Str::uuid());
    $ingredient->forceFill(['inci_name' => 'Changed source'])->save();

    expect($share->recipient_workspace_id)->toBe($recipient->id)
        ->and($share->source_version_id)->toBe($saved->id)
        ->and(data_get($share->snapshot, 'formula.manufacturing_instructions'))->toBe('Saved procedure')
        ->and(data_get($share->snapshot, 'product.description'))->toBe('Product description')
        ->and(data_get($share->snapshot, 'formula.phases.0.items.0.percentage'))->toBe('100.0000')
        ->and($share->expires_at->equalTo($share->sent_at->addDays(14)))->toBeTrue()
        ->and(json_encode($share->snapshot))->not->toContain('SECRET')
        ->and($share->snapshot['ingredients']['nodes']['n1']['technical']['inci_name'])->not->toBe('Changed source');
});

it('replays one issued request after time advances and rejects another intent for that key', function (): void {
    $this->freezeTime();
    extract(formulaShareIssueContext());
    $builder = app(FormulaShareSnapshotBuilder::class);
    $preview = $builder->build($owner, $recipe, []);
    $hash = $builder->previewHash($preview, $recipient);
    $key = (string) Str::uuid();
    $action = app(SendFormulaShare::class);
    $first = $action->handle($owner, $recipe, $recipient, [], $hash, $key);
    $this->travel(2)->minutes();

    expect($action->handle($owner, $recipe, $recipient, [], $hash, $key)->id)->toBe($first->id);
    expect(fn () => $action->handle($owner, $recipe, $recipient, ['include_description' => true], $hash, $key))->toThrow(ValidationException::class);
    expect(FormulaShare::query()->count())->toBe(1);
});

it('rejects changed technical source state and writes no grant', function (): void {
    extract(formulaShareIssueContext());
    $builder = app(FormulaShareSnapshotBuilder::class);
    $hash = $builder->previewHash($builder->build($owner, $recipe, []), $recipient);
    $ingredient->forceFill(['inci_name' => 'Changed identity'])->save();

    expect(fn () => app(SendFormulaShare::class)->handle($owner, $recipe, $recipient, [], $hash, (string) Str::uuid()))->toThrow(ValidationException::class);
    expect(FormulaShare::query()->count())->toBe(0);
});

it('rejects another Product reusing a successful request key and original preview hash', function (): void {
    extract(formulaShareIssueContext());
    $builder = app(FormulaShareSnapshotBuilder::class);
    $hash = $builder->previewHash($builder->build($owner, $recipe, []), $recipient);
    $key = (string) Str::uuid();
    app(SendFormulaShare::class)->handle($owner, $recipe, $recipient, [], $hash, $key);
    $other = Recipe::factory()->create(['workspace_id' => $source->id]);

    expect(fn () => app(SendFormulaShare::class)->handle($owner, $other, $recipient, [], $hash, $key))->toThrow(ValidationException::class);
    expect(FormulaShare::query()->count())->toBe(1);
});

it('replays the original offer even if its source Ingredient changed after successful issuance', function (): void {
    extract(formulaShareIssueContext());
    $builder = app(FormulaShareSnapshotBuilder::class);
    $hash = $builder->previewHash($builder->build($owner, $recipe, []), $recipient);
    $key = (string) Str::uuid();
    $first = app(SendFormulaShare::class)->handle($owner, $recipe, $recipient, [], $hash, $key);
    $ingredient->forceFill(['inci_name' => 'Changed after sending'])->save();

    expect(app(SendFormulaShare::class)->handle($owner, $recipe, $recipient, [], $hash, $key)->id)->toBe($first->id);
});

it('rejects invalid source state without falling back to the draft', function (string $case): void {
    extract(formulaShareIssueContext());
    if ($case === 'no_saved') {
        $saved->delete();
        RecipeVersion::factory()->for($recipe)->create(['is_current' => true]);
    } elseif ($case === 'manufactured') {
        $recipe->forceFill(['production_output_type' => ProductionOutputType::ManufacturedIngredient])->save();
    } elseif ($case === 'unknown_mode') {
        $saved->forceFill(['manufacturing_mode' => 'unknown'])->save();
    } elseif ($case === 'missing_setting') {
        $saved->forceFill(['calculation_context' => []])->save();
    } else {
        $ingredient->forceFill(['is_active' => false])->save();
    }

    expect(fn () => app(FormulaShareSnapshotBuilder::class)->build($owner, $recipe, []))->toThrow(ValidationException::class);
})->with(['no_saved', 'manufactured', 'unknown_mode', 'missing_setting', 'inactive_ingredient']);

it('rejects forged options request keys same recipient and disabled feature', function (string $case): void {
    extract(formulaShareIssueContext());
    $builder = app(FormulaShareSnapshotBuilder::class);
    $hash = $builder->previewHash($builder->build($owner, $recipe, []), $recipient);
    $options = $case === 'options' ? ['snapshot' => ['secret' => 'forged']] : [];
    $requestKey = $case === 'key' ? 'invalid' : (string) Str::uuid();
    if ($case === 'same_recipient') {
        $recipient = $source;
    } elseif ($case === 'disabled') {
        config(['workspaces.formula_sharing.enabled' => false]);
    }

    expect(fn () => app(SendFormulaShare::class)->handle($owner, $recipe, $recipient, $options, $hash, $requestKey))->toThrow($case === 'disabled' ? AuthorizationException::class : ValidationException::class);
    expect(FormulaShare::query()->count())->toBe(0);
})->with(['options', 'key', 'same_recipient', 'disabled']);

it('resolves exact external workspace UUID without membership and rejects missing addresses', function (): void {
    extract(formulaShareIssueContext());
    $this->actingAs($owner);
    $builder = app(FormulaShareSnapshotBuilder::class);

    expect($builder->resolveRecipient($owner, $recipe, $recipient->public_id)->id)->toBe($recipient->id);
    expect(fn () => $builder->resolveRecipient($owner, $recipe, (string) Str::uuid()))->toThrow(ValidationException::class);
    expect(fn () => $builder->resolveRecipient($owner, $recipe, $source->public_id))->toThrow(ValidationException::class);
});

it('shares service budgets across actors in a workspace and keeps operation and workspace isolation', function (): void {
    extract(formulaShareIssueContext());
    config(['workspaces.formula_sharing.rate_limits.preview.workspace_per_minute' => 1, 'workspaces.collaboration_enabled' => true]);
    $plan = Plan::factory()->create(['allows_collaboration' => true]);
    UserEntitlement::factory()->for($owner)->for($plan)->create();
    $admin = User::factory()->create(['active_workspace_id' => $source->id]);
    WorkspaceMember::factory()->for($source, 'workspace')->for($admin, 'user')->create(['role' => WorkspaceMemberRole::Admin]);
    $budget = app(FormulaShareBudget::class);
    $budget->consume($owner, $source->id, 'preview');
    expect(fn () => $budget->consume($admin, $source->id, 'preview'))->toThrow(ValidationException::class);
    $budget->consume($owner, $source->id, 'send');
    $budget->consume($recipient->owner, $recipient->id, 'preview');
});

it('enforces pending pair and source caps before writing another offer', function (string $limit): void {
    extract(formulaShareIssueContext());
    $builder = app(FormulaShareSnapshotBuilder::class);
    $hash = $builder->previewHash($builder->build($owner, $recipe, []), $recipient);
    config(["workspaces.formula_sharing.$limit" => 0]);

    expect(fn () => app(SendFormulaShare::class)->handle($owner, $recipe, $recipient, [], $hash, (string) Str::uuid()))->toThrow(ValidationException::class);
    expect(FormulaShare::query()->count())->toBe(0);
})->with(['maximum_pending_outgoing', 'maximum_pending_per_pair']);

it('sanitizes rich content without file identifiers link targets scripts or embedded objects', function (): void {
    $sanitizer = app(FormulaShareContentSanitizer::class);
    $text = $sanitizer->plainText('<p>First &amp; second <a href="SECRET_URL">readable</a></p><ul><li>Step one</li><li>Step two</li></ul><img data-id="SECRET_ID"><script>SECRET_SCRIPT</script><iframe srcdoc="SECRET_EMBED">SECRET_IFRAME</iframe><style>SECRET_STYLE</style>');

    expect($text)->toBe("First & second readable\n\nStep one\nStep two")
        ->not->toContain('SECRET');
    expect($sanitizer->plainText(['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Narrative', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'SECRET']]]], ['type' => 'image', 'attrs' => ['id' => 'SECRET']]]]]]))->toBe('Narrative');
});

it('captures cosmetic phases exact persisted values and supported settings without implicit alkalis', function (): void {
    extract(formulaShareIssueContext());
    $recipe->productFamily()->first()->forceFill(['slug' => 'cosmetic', 'calculation_basis' => 'total_formula'])->save();
    $saved->forceFill([
        'manufacturing_mode' => 'blend_only', 'batch_size' => '12.346', 'batch_mass_grams' => '12.345678901',
        'water_settings' => ['secret' => 'SECRET_WATER'],
        'calculation_context' => ['editing_mode' => 'weight', 'oil_weight' => 12.345678901, 'oil_unit' => 'g', 'mass_grams' => '12.345678901', 'calculation_basis' => 'total_formula', 'totals' => 'SECRET_DERIVED'],
    ])->save();
    $phase = RecipePhase::withoutGlobalScopes()->where('recipe_version_id', $saved->id)->first();
    $phase->forceFill(['slug' => 'phase_a', 'phase_type' => 'cosmetic_phase'])->save();
    $item = RecipeItem::withoutGlobalScopes()->where('recipe_version_id', $saved->id)->first();
    $item->forceFill(['percentage' => '12.3456', 'weight' => '0.1234'])->save();

    $snapshot = app(FormulaShareSnapshotBuilder::class)->build($owner, $recipe, []);
    expect($snapshot['formula']['batch_mass_grams'])->toBe('12.345678901')
        ->and($snapshot['formula']['calculation_context']['calculation_basis'])->toBe('total_formula')
        ->and($snapshot['formula']['phases'][0]['items'][0]['percentage'])->toBe('12.3456')
        ->and($snapshot['formula']['phases'][0]['items'][0]['weight'])->toBe('0.1234')
        ->and($snapshot['formula']['water_settings'])->toBe([])
        ->and($snapshot['formula']['canonical_alkalis'])->toBe([])
        ->and(count($snapshot['ingredients']['nodes']))->toBe(1)
        ->and(json_encode($snapshot))->not->toContain('SECRET');
});

it('excludes optional and operational data and leaves all source records untouched during preview', function (): void {
    extract(formulaShareIssueContext());
    $recipe->forceFill(['description' => 'SECRET_DESCRIPTION', 'product_reference' => 'SECRET_REFERENCE'])->save();
    $saved->forceFill(['notes' => 'SECRET_NOTES', 'final_ingredient_list' => 'SECRET_FINAL', 'final_plain_ingredient_list' => 'SECRET_PLAIN'])->save();
    $ingredient->forceFill(['source_data' => ['secret' => 'SECRET_SOURCE_DATA']])->save();
    $count = Ingredient::withoutGlobalScopes()->count();
    $builder = app(FormulaShareSnapshotBuilder::class);
    $first = $builder->build($owner, $recipe, []);
    $recipe->forceFill(['product_reference' => 'OTHER_SECRET_REFERENCE', 'description' => 'OTHER_SECRET_DESCRIPTION'])->save();
    $second = $builder->build($owner, $recipe, []);

    expect($builder->previewHash($first, $recipient))->toBe($builder->previewHash($second, $recipient))
        ->and($first['options'])->toBe(['include_procedure' => false, 'include_description' => false, 'include_line_notes' => false])
        ->and($first['formula']['manufacturing_instructions'])->toBeNull()
        ->and($first['product']['description'])->toBeNull()
        ->and($first['formula']['phases'][0]['items'][0]['note'])->toBeNull()
        ->and(json_encode($first))->not->toContain('SECRET')
        ->and(Ingredient::withoutGlobalScopes()->count())->toBe($count)
        ->and(FormulaShare::query()->count())->toBe(0)
        ->and($ingredient->fresh()->share_lineage_key)->toBeNull();
});

it('rejects unknown or incomplete saved calculation fields rather than silently defaulting them', function (string $field, mixed $value): void {
    extract(formulaShareIssueContext());
    $context = $saved->calculation_context;
    $context[$field] = $value;
    $saved->forceFill(['calculation_context' => $context])->save();

    expect(fn () => app(FormulaShareSnapshotBuilder::class)->build($owner, $recipe, []))->toThrow(ValidationException::class);
})->with([
    ['editing_mode', 'unknown'], ['lye_type', 'unknown'], ['oil_unit', 'unknown'],
    ['koh_purity_percentage', 90.5], ['dual_lye_koh_percentage', null], ['dual_lye_koh_percentage', 101],
    ['superfat', '1e999'], ['mass_grams', null], ['oil_weight', 0],
]);

it('captures canonical alkalis and invalidates the preview when one changes', function (): void {
    extract(formulaShareIssueContext());
    $builder = app(FormulaShareSnapshotBuilder::class);
    $first = $builder->build($owner, $recipe, []);
    $alkali = Ingredient::withoutGlobalScopes()->where('catalog_key', 'CH3')->first();
    $alkali->forceFill(['inci_name' => 'Changed canonical identity'])->save();
    $second = $builder->build($owner, $recipe, []);

    expect(array_keys($first['formula']['canonical_alkalis']))->toBe(['naoh', 'koh'])
        ->and($builder->previewHash($first, $recipient))->not->toBe($builder->previewHash($second, $recipient));
});

it('rejects unavailable source references and bounded snapshot overflow', function (string $case): void {
    extract(formulaShareIssueContext());
    if ($case === 'family') {
        $recipe->productFamily()->first()->forceFill(['is_active' => false])->save();
    } elseif ($case === 'regime') {
        RegulatoryRegime::query()->where('code', 'eu')->update(['status' => 'inactive']);
    } elseif ($case === 'alkali') {
        Ingredient::withoutGlobalScopes()->where('catalog_key', 'CH3')->update(['is_active' => false]);
    } else {
        config(['workspaces.formula_sharing.limits.bytes' => 10]);
    }

    expect(fn () => app(FormulaShareSnapshotBuilder::class)->build($owner, $recipe, []))->toThrow(ValidationException::class);
})->with(['family', 'regime', 'alkali', 'size']);

it('includes the procedure media exclusion warning only when selected content contains media', function (): void {
    extract(formulaShareIssueContext());
    $builder = app(FormulaShareSnapshotBuilder::class);
    expect($builder->build($owner, $recipe, ['include_procedure' => true])['warnings'])->toBe(['procedure_media_excluded'])
        ->and($builder->build($owner, $recipe, [])['warnings'])->toBe([]);
});

it('rejects string booleans and forged content at the option boundary', function (array $options): void {
    extract(formulaShareIssueContext());
    expect(fn () => app(FormulaShareSnapshotBuilder::class)->build($owner, $recipe, $options))->toThrow(ValidationException::class);
})->with([[['include_procedure' => 'true']], [['include_description' => 1]], [['include_media' => true]]]);

it('preserves readable rich table cells and escapes the same plain text for later import', function (): void {
    $sanitizer = app(FormulaShareContentSanitizer::class);
    $text = $sanitizer->plainText('<table><tr><td>Heat</td><td>40 &lt; 50</td></tr><tr><td>Stir</td><td>Slowly</td></tr></table>');
    $paragraph = ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Readable']]];
    $cell = ['type' => 'tableCell', 'content' => [$paragraph]];
    $row = ['type' => 'tableRow', 'content' => [$cell]];
    $table = ['type' => 'table', 'content' => [$row]];

    expect($text)->toBe("Heat 40 < 50\nStir Slowly")
        ->and($sanitizer->richText($text))->toBe("<p>Heat 40 &lt; 50<br>\nStir Slowly</p>")
        ->and($sanitizer->plainText(['type' => 'doc', 'content' => [$table]]))->toBe('Readable')
        ->and(fn () => $sanitizer->plainText(['type' => 'doc', 'content' => 'invalid']))->toThrow(ValidationException::class);
});

it('rejects a saved IFRA mapping retargeted to another Product type and writes no grant', function (): void {
    extract(formulaShareIssueContext());
    $type = ProductType::factory()->create();
    $type->productFamilies()->attach($recipe->product_family_id);
    $recipe->forceFill(['product_type_id' => $type->id])->save();
    $mapping = ProductTypeIfraCategory::factory()->create(['product_type_id' => $type->id]);
    $saved->forceFill(['ifra_amendment_id' => $mapping->ifra_amendment_id, 'ifra_product_category_id' => $mapping->ifra_product_category_id, 'product_type_ifra_category_id' => $mapping->id])->save();
    $builder = app(FormulaShareSnapshotBuilder::class);
    expect($builder->build($owner, $recipe, [])['formula']['ifra']['mapping']['product_type_id'])->toBe($type->id);
    $mapping->forceFill(['product_type_id' => ProductType::factory()->create()->id])->save();

    expect(fn () => $builder->build($owner, $recipe, []))->toThrow(ValidationException::class);
    expect(FormulaShare::query()->count())->toBe(0);
});

it('rejects eligible workspace material without a baseline even when its owner type is null', function (): void {
    extract(formulaShareIssueContext());
    $ingredient->forceFill(['workspace_id' => $source->id, 'is_soap_saponification_trusted' => true, 'source_data' => null])->save();
    IngredientSapProfile::factory()->for($ingredient)->create(['koh_sap_value' => '0.188000']);

    expect(fn () => app(FormulaShareSnapshotBuilder::class)->build($owner, $recipe, []))->toThrow(ValidationException::class);
    expect(FormulaShare::query()->count())->toBe(0);
});

it('continues capturing a true eligible platform material without a private baseline', function (): void {
    extract(formulaShareIssueContext());
    $ingredient->forceFill(['is_soap_saponification_trusted' => true, 'source_data' => null])->save();
    IngredientSapProfile::factory()->for($ingredient)->create(['koh_sap_value' => '0.188000']);
    $snapshot = app(FormulaShareSnapshotBuilder::class)->build($owner, $recipe, []);

    expect($snapshot['ingredients']['nodes']['n1']['kind'])->toBe('platform')
        ->and($snapshot['ingredients']['nodes']['n1']['technical']['baseline'])->toBeNull();
});
