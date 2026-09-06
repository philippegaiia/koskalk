<?php

use App\Enums\IngredientCategory;
use App\Enums\IngredientFunctionSource;
use App\Enums\OwnerType;
use App\Livewire\Dashboard\IngredientEditor;
use App\Models\Allergen;
use App\Models\FattyAcid;
use App\Models\IfraAmendment;
use App\Models\IfraCertificate;
use App\Models\IfraCertificateLimit;
use App\Models\IfraProductCategory;
use App\Models\Ingredient;
use App\Models\IngredientAlias;
use App\Models\IngredientAllergenEntry;
use App\Models\IngredientComponent;
use App\Models\IngredientFattyAcid;
use App\Models\IngredientFunction;
use App\Models\IngredientIdentifier;
use App\Models\IngredientSapProfile;
use App\Models\IngredientSubstanceEntry;
use App\Models\IngredientTranslation;
use App\Models\InterfaceTranslation;
use App\Models\MediaAsset;
use App\Models\MediaAssetUsage;
use App\Models\Substance;
use App\Models\SupportedLocale;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceIngredientCode;
use App\Models\WorkspaceIngredientGuidance;
use App\Models\WorkspaceMember;
use App\Services\UserIngredientAuthoringService;
use Database\Seeders\SupportedLocaleSeeder;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(SupportedLocaleSeeder::class);
});

it('keeps the complete platform reference available as plain technical values', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create(['name' => 'North Star Soapworks']);
    $user->forceFill(['active_workspace_id' => $workspace->id])->save();
    $user->forgetAccessibleWorkspaceIds();

    $platform = Ingredient::factory()->create([
        'display_name' => 'Rosehip blend',
        'inci_name' => 'ROSA CANINA FRUIT OIL',
        'category' => IngredientCategory::AromaticMaterials,
        'requires_aromatic_compliance' => true,
        'is_soap_saponification_trusted' => true,
        'info_markdown' => 'Platform formulation guidance',
        'composition_source_notes' => 'Platform composition source',
        'allergen_source_notes' => 'Platform allergen source',
        'owner_type' => null,
        'owner_id' => null,
        'workspace_id' => null,
    ]);
    $component = Ingredient::factory()->create([
        'display_name' => 'Neroli oil',
        'inci_name' => 'CITRUS AURANTIUM FLOWER OIL',
    ]);

    IngredientIdentifier::factory()->createMany([
        [
            'ingredient_id' => $platform->id,
            'scheme' => 'cas',
            'value' => 'PLATFORM-CAS',
            'normalized_value' => 'PLATFORM-CAS',
            'is_primary' => true,
        ],
        [
            'ingredient_id' => $platform->id,
            'scheme' => 'ec',
            'value' => 'PLATFORM-EC',
            'normalized_value' => 'PLATFORM-EC',
            'is_primary' => true,
        ],
        [
            'ingredient_id' => $platform->id,
            'scheme' => 'pubchem_cid',
            'value' => 'AUX-42',
            'normalized_value' => 'AUX-42',
            'is_primary' => false,
        ],
    ]);
    IngredientAlias::factory()->create([
        'ingredient_id' => $platform->id,
        'name' => 'Rosehip seed oil',
        'normalized_name' => 'rosehip seed oil',
    ]);
    IngredientComponent::factory()->create([
        'ingredient_id' => $platform->id,
        'component_ingredient_id' => $component->id,
        'percentage_in_parent' => 70,
        'source_notes' => 'Neroli source note',
    ]);
    $function = IngredientFunction::factory()->create([
        'name' => 'Emollient',
        'key' => 'emollient-reference',
    ]);
    $platform->functions()->attach($function, ['source' => IngredientFunctionSource::Manual->value]);

    $sapProfile = IngredientSapProfile::factory()->create([
        'ingredient_id' => $platform->id,
        'koh_sap_value' => 0.185,
        'iodine_value' => 81.25,
        'ins_value' => 98.5,
        'source_notes' => 'SAP source note',
    ]);
    $fattyAcid = FattyAcid::factory()->create(['name' => 'Oleic acid', 'key' => 'oleic-reference']);
    IngredientFattyAcid::factory()->create([
        'ingredient_id' => $platform->id,
        'fatty_acid_id' => $fattyAcid->id,
        'percentage' => 42,
        'source_notes' => 'Fatty acid source note',
    ]);
    $allergen = Allergen::factory()->create(['inci_name' => 'LIMONENE']);
    IngredientAllergenEntry::factory()->create([
        'ingredient_id' => $platform->id,
        'allergen_id' => $allergen->id,
        'concentration_percent' => 0.25,
        'source_notes' => 'Allergen source note',
    ]);
    $substance = Substance::factory()->create(['name' => 'Linalool']);
    IngredientSubstanceEntry::factory()->create([
        'ingredient_id' => $platform->id,
        'substance_id' => $substance->id,
        'concentration_percent' => 1.75,
        'source_notes' => 'Substance source note',
    ]);
    $amendment = IfraAmendment::factory()->create(['code' => '51']);
    $certificate = IfraCertificate::factory()->create([
        'ingredient_id' => $platform->id,
        'ifra_amendment_id' => $amendment->id,
        'certificate_name' => 'Rosehip IFRA certificate',
        'source_amendment_label' => 'Amendment 51',
        'peroxide_value' => 2.5,
        'source_notes' => 'IFRA source note',
    ]);
    $ifraCategory = IfraProductCategory::factory()->create(['code' => '4', 'name' => 'Category 4']);
    IfraCertificateLimit::factory()->create([
        'ifra_certificate_id' => $certificate->id,
        'ifra_product_category_id' => $ifraCategory->id,
        'max_percentage' => 12.5,
        'restriction_note' => 'IFRA limit note',
    ]);
    $document = MediaAsset::factory()->pdf()->ready()->create([
        'workspace_id' => $workspace->id,
        'uploaded_by_user_id' => $user->id,
        'original_filename' => 'platform-safety.pdf',
        'display_name' => 'Platform safety sheet',
    ]);
    MediaAssetUsage::factory()->create([
        'media_asset_id' => $document->id,
        'usable_type' => $platform->getMorphClass(),
        'usable_id' => $platform->id,
        'role' => 'ingredient_document',
    ]);

    $this->actingAs($user);

    $component = Livewire::test(IngredientEditor::class, ['ingredient' => $platform]);

    $component
        ->assertSeeText('Rosehip blend')
        ->assertSeeText('ROSA CANINA FRUIT OIL')
        ->assertSeeText('PLATFORM-CAS')
        ->assertSeeText('PLATFORM-EC')
        ->assertSeeText('AUX-42')
        ->assertSeeText('Rosehip seed oil')
        ->assertSeeText('Neroli oil')
        ->assertSeeText('70%')
        ->assertSeeText('Emollient')
        ->assertSeeText('0.185')
        ->assertSeeText('Oleic acid')
        ->assertSeeText('42%')
        ->assertSeeText('LIMONENE')
        ->assertSeeText('0.25%')
        ->assertSeeText('Linalool')
        ->assertSeeText('1.75%')
        ->assertSeeText('Rosehip IFRA certificate')
        ->assertSeeText('Amendment 51')
        ->assertSeeText('Category 4')
        ->assertSeeText('12.5%')
        ->assertSeeText('Platform safety sheet')
        ->assertDontSeeHtml('<input disabled="disabled"')
        ->assertDontSeeText('Save changes');

    expect($component->instance()->referenceData)->toHaveKey('documents')
        ->and($component->instance()->data)->toBe([])
        ->and($component->instance()->workspaceMaterialCode)->toBeNull();
});

it('places platform workspace controls before technical reference and avoids duplicate guidance', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create(['name' => 'North Star Soapworks']);
    $owner->forceFill(['active_workspace_id' => $workspace->id])->save();
    $owner->forgetAccessibleWorkspaceIds();
    $platform = Ingredient::factory()->create([
        'display_name' => 'Platform reference ingredient',
        'owner_type' => null,
        'owner_id' => null,
        'workspace_id' => null,
        'info_markdown' => 'Platform guidance fallback.',
    ]);
    WorkspaceIngredientGuidance::factory()->create([
        'workspace_id' => $workspace->id,
        'ingredient_id' => $platform->id,
        'guidance_html' => '<p>Workspace guidance preview.</p>',
        'is_active' => true,
    ]);
    WorkspaceIngredientCode::factory()->create([
        'workspace_id' => $workspace->id,
        'ingredient_id' => $platform->id,
        'material_code' => 'NORTH-STAR-01',
    ]);

    $this->actingAs($owner);

    $component = Livewire::test(IngredientEditor::class, ['ingredient' => $platform]);
    $html = $component->html();

    $identityPosition = strpos($html, 'ingredient-reference-identity');
    $guidancePosition = strpos($html, 'workspace-guidance-heading');
    $materialCodePosition = strpos($html, 'platform-material-code-heading');
    $technicalPosition = strpos($html, 'ingredient-reference-classification');

    expect($identityPosition)->toBeInt()
        ->and(strpos($html, 'ingredient-workspace-context'))->toBeInt()
        ->and($guidancePosition)->toBeInt()
        ->and($materialCodePosition)->toBeInt()
        ->and($technicalPosition)->toBeInt()
        ->and($identityPosition)->toBeLessThan(strpos($html, 'ingredient-workspace-context'))
        ->and(strpos($html, 'ingredient-workspace-context'))->toBeLessThan($guidancePosition)
        ->and($guidancePosition)->toBeLessThan($materialCodePosition)
        ->and($materialCodePosition)->toBeLessThan($technicalPosition)
        ->and($html)->toContain('In North Star Soapworks')
        ->and($html)->toContain('Manage workspace-specific guidance and material code for this ingredient.')
        ->and($html)->toContain('aria-labelledby="ingredient-workspace-context"')
        ->and($html)->toContain('<h2 id="ingredient-workspace-context"')
        ->and($html)->toContain('<h3 id="workspace-guidance-heading"')
        ->and($html)->toContain('<h3 id="platform-material-code-heading"')
        ->and($html)->toContain('data-ingredient-guidance-preview')
        ->and($html)->toContain('data-ingredient-editor-status="material-code"')
        ->and($html)->not->toContain('ingredient-reference-guidance');

    $component->call('startWorkspaceGuidanceCustomization');

    expect($component->html())
        ->toContain('data-ingredient-scope="guidance"')
        ->toContain('data-ingredient-editor-status="guidance"')
        ->not->toContain('data-ingredient-guidance-preview');
});

it('shows a sparse reference without inventing composition or chemistry', function (): void {
    $user = User::factory()->create();
    $platform = Ingredient::factory()->create([
        'display_name' => 'Sparse platform ingredient',
        'inci_name' => null,
        'owner_type' => null,
        'owner_id' => null,
        'workspace_id' => null,
    ]);

    $this->actingAs($user);

    $component = Livewire::test(IngredientEditor::class, ['ingredient' => $platform]);

    $component
        ->assertSeeText('Sparse platform ingredient')
        ->assertSeeText('Not available')
        ->assertDontSeeText('Composition')
        ->assertDontSeeText('Soap chemistry')
        ->assertDontSeeText('Fatty acids');

    expect($component->instance()->referenceData['components'])->toBe([])
        ->and($component->instance()->referenceData['soap'])->toBeNull();
});

it('keeps a non-member platform reference read-only without exposing workspace data', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $platform = Ingredient::factory()->create([
        'display_name' => 'Public platform reference',
        'owner_type' => null,
        'owner_id' => null,
        'workspace_id' => null,
        'info_markdown' => 'Public platform guidance.',
    ]);
    WorkspaceIngredientGuidance::factory()->create([
        'workspace_id' => $workspace->id,
        'ingredient_id' => $platform->id,
        'guidance_html' => '<p>PRIVATE WORKSPACE GUIDANCE</p>',
        'is_active' => true,
    ]);
    WorkspaceIngredientCode::factory()->create([
        'workspace_id' => $workspace->id,
        'ingredient_id' => $platform->id,
        'material_code' => 'PRIVATE-WORKSPACE-CODE',
    ]);
    $nonMember = User::factory()->create();

    $this->actingAs($nonMember);

    $component = Livewire::test(IngredientEditor::class, ['ingredient' => $platform]);

    $component
        ->assertSeeText('Soapkraft ingredient')
        ->assertSeeText('Public platform guidance.')
        ->assertDontSeeText('PRIVATE WORKSPACE GUIDANCE')
        ->assertDontSeeText('PRIVATE-WORKSPACE-CODE')
        ->assertDontSeeHtml('<input id="workspace-material-code"')
        ->assertDontSeeText('Edit workspace guidance')
        ->assertDontSeeHtml('ingredient-workspace-context')
        ->assertDontSeeHtml('workspace-guidance-heading')
        ->assertDontSeeHtml('platform-material-code-heading')
        ->assertDontSeeText('Only workspace owners, admins, and editors can change this guidance.')
        ->assertDontSeeText('Only workspace owners, admins, and editors can change this reference.');

    expect(substr_count($component->html(), '>Public platform guidance.<'))->toBe(1);
});

it('clarifies platform reference terminology and localizes empty identity values', function (): void {
    $user = User::factory()->create();
    $platform = Ingredient::factory()->create([
        'display_name' => 'Terminology reference',
        'inci_name' => '',
        'owner_type' => null,
        'owner_id' => null,
        'workspace_id' => null,
        'requires_aromatic_compliance' => true,
        'info_markdown' => 'Ingredient guidance reference.',
        'notes' => 'Supplier source note.',
    ]);
    IngredientIdentifier::factory()->createMany([
        [
            'ingredient_id' => $platform->id,
            'scheme' => 'cas',
            'value' => '',
            'normalized_value' => '',
            'is_primary' => true,
        ],
        [
            'ingredient_id' => $platform->id,
            'scheme' => 'ec',
            'value' => '',
            'normalized_value' => '',
            'is_primary' => true,
        ],
        [
            'ingredient_id' => $platform->id,
            'scheme' => 'pubchem_cid',
            'value' => '0',
            'normalized_value' => '0',
            'is_primary' => false,
        ],
    ]);
    IngredientAlias::factory()->create([
        'ingredient_id' => $platform->id,
        'name' => 'Terminology alias',
        'normalized_name' => 'terminology alias',
    ]);

    $this->actingAs($user);

    $component = Livewire::test(IngredientEditor::class, ['ingredient' => $platform]);
    $html = $component->html();
    $identityPosition = strpos($html, 'ingredient-reference-identity');
    $classificationPosition = strpos($html, 'ingredient-reference-classification');
    $identitySection = substr($html, $identityPosition, $classificationPosition - $identityPosition);

    expect($identitySection)->toContain('Alternative names')
        ->and($identitySection)->toContain('Source notes')
        ->and($identitySection)->toMatch('/PubChem CID<\/span>:\s*0/')
        ->and(substr_count($identitySection, '>Not available<'))->toBe(3)
        ->and($html)->toContain('Aromatic handling')
        ->and($html)->toContain('Enabled')
        ->and($html)->toContain('Ingredient guidance')
        ->and($html)->not->toContain('Approved guidance')
        ->and($html)->not->toContain('Aromatic compliance')
        ->and($html)->not->toContain('Required')
        ->and($html)->not->toContain('Not required');
});

it('does not render a workspace context when the resolved workspace name is blank', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create(['name' => '']);
    $user->forceFill(['active_workspace_id' => $workspace->id])->save();
    $platform = Ingredient::factory()->create([
        'display_name' => 'Blank workspace name reference',
        'owner_type' => null,
        'owner_id' => null,
        'workspace_id' => null,
        'info_markdown' => 'Blank workspace platform guidance.',
    ]);

    $this->actingAs($user);

    $component = Livewire::test(IngredientEditor::class, ['ingredient' => $platform]);

    $component
        ->assertDontSeeHtml('ingredient-workspace-context')
        ->assertDontSeeHtml('workspace-guidance-heading')
        ->assertDontSeeHtml('platform-material-code-heading')
        ->assertSeeText('Blank workspace platform guidance.');

    expect(substr_count($component->html(), '>Blank workspace platform guidance.<'))->toBe(1);
});

it('renders zero chemistry and IFRA values instead of treating them as unavailable', function (): void {
    $user = User::factory()->create();
    $platform = Ingredient::factory()->create([
        'display_name' => 'Zero value platform ingredient',
        'owner_type' => null,
        'owner_id' => null,
        'workspace_id' => null,
        'is_soap_saponification_trusted' => true,
        'requires_aromatic_compliance' => true,
    ]);
    IngredientSapProfile::factory()->create([
        'ingredient_id' => $platform->id,
        'koh_sap_value' => 0.2,
        'iodine_value' => 0,
        'ins_value' => 0,
    ]);
    $certificate = IfraCertificate::factory()->create([
        'ingredient_id' => $platform->id,
        'certificate_name' => 'Zero value IFRA certificate',
        'is_current' => true,
        'peroxide_value' => 0,
    ]);
    $category = IfraProductCategory::factory()->create([
        'code' => 'ZERO',
        'name' => 'Zero value category',
    ]);
    IfraCertificateLimit::factory()->create([
        'ifra_certificate_id' => $certificate->id,
        'ifra_product_category_id' => $category->id,
        'max_percentage' => 0,
    ]);

    $this->actingAs($user);

    $component = Livewire::test(IngredientEditor::class, ['ingredient' => $platform]);
    $component->assertSeeHtml('<dd class="mt-1 tabular-nums text-sm text-[var(--color-ink-strong)]">0</dd>');

    expect($component->instance()->referenceData['soap']['iodine_value'])->toBe(0.0)
        ->and($component->instance()->referenceData['soap']['ins_value'])->toBe(0.0)
        ->and($component->instance()->referenceData['ifra']['peroxide_value'])->toBe(0.0)
        ->and($component->instance()->referenceData['ifra']['limits'][0]['max_percentage'])->toBe(0.0);
});

it('clears platform workspace state when its destination loses authorization', function (): void {
    $workspaceOwner = User::factory()->create();
    $user = User::factory()->create();
    $otherOwner = User::factory()->create();
    $workspace = Workspace::factory()->for($workspaceOwner, 'owner')->create([
        'name' => 'Original workspace',
    ]);
    $otherWorkspace = Workspace::factory()->for($otherOwner, 'owner')->create([
        'name' => 'Replacement workspace',
    ]);
    WorkspaceMember::factory()->for($workspace)->for($user)->create(['role' => 'editor']);
    WorkspaceMember::factory()->for($otherWorkspace)->for($user)->create(['role' => 'viewer']);
    $user->forceFill(['active_workspace_id' => $workspace->id])->save();
    $user->forgetAccessibleWorkspaceIds();
    $platform = Ingredient::factory()->create([
        'display_name' => 'Platform state ingredient',
        'info_markdown' => 'Platform guidance',
        'owner_type' => null,
        'owner_id' => null,
        'workspace_id' => null,
    ]);
    WorkspaceIngredientCode::factory()->create([
        'workspace_id' => $workspace->id,
        'ingredient_id' => $platform->id,
        'material_code' => 'PRIVATE-CODE-SENTINEL',
    ]);
    WorkspaceIngredientGuidance::factory()->create([
        'workspace_id' => $workspace->id,
        'ingredient_id' => $platform->id,
        'guidance_html' => '<p>PRIVATE GUIDANCE SENTINEL</p>',
    ]);

    $this->actingAs($user);
    $component = Livewire::test(IngredientEditor::class, ['ingredient' => $platform]);

    expect($component->instance()->workspaceMaterialCode)->toBe('PRIVATE-CODE-SENTINEL')
        ->and(json_encode($component->instance()->workspaceGuidance))->toContain('PRIVATE GUIDANCE SENTINEL');

    WorkspaceMember::query()
        ->where('workspace_id', $workspace->id)
        ->where('user_id', $user->id)
        ->delete();
    $user->forceFill(['active_workspace_id' => $otherWorkspace->id])->save();
    $user->forgetAccessibleWorkspaceIds();

    $component
        ->refresh()
        ->assertDontSeeText('PRIVATE-CODE-SENTINEL')
        ->assertDontSeeText('PRIVATE GUIDANCE SENTINEL');

    expect($component->instance()->workspaceMaterialCode)->toBeNull()
        ->and(json_encode($component->instance()->workspaceGuidance))->not->toContain('PRIVATE GUIDANCE SENTINEL')
        ->and(json_encode($component->instance()->workspaceGuidanceForm->getState()))->not->toContain('PRIVATE GUIDANCE SENTINEL')
        ->and($component->html())->not->toContain('PRIVATE-CODE-SENTINEL')
        ->and($component->html())->not->toContain('PRIVATE GUIDANCE SENTINEL');
});

it('keeps a single ingredient reference separate from blend composition', function (): void {
    $user = User::factory()->create();
    $platform = Ingredient::factory()->create([
        'display_name' => 'Single platform ingredient',
        'owner_type' => null,
        'owner_id' => null,
        'workspace_id' => null,
    ]);

    $this->actingAs($user);

    $component = Livewire::test(IngredientEditor::class, ['ingredient' => $platform]);

    $component
        ->assertSeeText('Single platform ingredient')
        ->assertDontSeeText('Composition');

    expect($component->instance()->referenceData['ingredient_structure'])->toBe('ingredient');
});

it('filters workspace overrides and media links independently for a public non-member', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    WorkspaceMember::factory()->for($workspace)->for($member)->create(['role' => 'viewer']);
    $ingredient = Ingredient::factory()->create([
        'display_name' => 'Public workspace blend',
        'inci_name' => 'PUBLIC TECHNICAL INCI',
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => 'public',
        'notes' => 'PRIVATE SOURCE SENTINEL',
        'composition_source_notes' => 'PRIVATE COMPOSITION SENTINEL',
        'allergen_source_notes' => 'PRIVATE ALLERGEN SENTINEL',
    ]);
    WorkspaceIngredientCode::factory()->create([
        'workspace_id' => $workspace->id,
        'ingredient_id' => $ingredient->id,
        'material_code' => 'PRIVATE-CODE-SENTINEL',
    ]);
    WorkspaceIngredientGuidance::factory()->create([
        'workspace_id' => $workspace->id,
        'ingredient_id' => $ingredient->id,
        'guidance_html' => '<p>PRIVATE GUIDANCE SENTINEL</p>',
    ]);
    $document = MediaAsset::factory()->pdf()->ready()->create([
        'workspace_id' => $workspace->id,
        'uploaded_by_user_id' => $owner->id,
        'original_filename' => 'private-workspace-document.pdf',
        'display_name' => 'Private workspace document',
    ]);
    MediaAssetUsage::factory()->create([
        'media_asset_id' => $document->id,
        'usable_type' => $ingredient->getMorphClass(),
        'usable_id' => $ingredient->id,
        'role' => 'ingredient_document',
    ]);

    $member->forceFill(['active_workspace_id' => $workspace->id])->save();
    $member->forgetAccessibleWorkspaceIds();
    $this->actingAs($member);
    $authorized = Livewire::test(IngredientEditor::class, ['ingredient' => $ingredient]);

    $authorized
        ->assertSeeText('PUBLIC TECHNICAL INCI')
        ->assertSeeText('Private workspace document')
        ->assertSee(route('media.download', $document));

    $nonMember = User::factory()->create();
    $this->actingAs($nonMember);
    $public = Livewire::test(IngredientEditor::class, ['ingredient' => $ingredient]);

    $public
        ->assertSeeText('PUBLIC TECHNICAL INCI')
        ->assertDontSeeText('PRIVATE-CODE-SENTINEL')
        ->assertDontSeeText('PRIVATE GUIDANCE SENTINEL')
        ->assertDontSeeText('PRIVATE SOURCE SENTINEL')
        ->assertDontSeeText('PRIVATE COMPOSITION SENTINEL')
        ->assertDontSeeText('PRIVATE ALLERGEN SENTINEL')
        ->assertDontSeeText('Private workspace document')
        ->assertDontSee(route('media.download', $document))
        ->set('data.notes', 'PRIVATE SOURCE SENTINEL')
        ->set('referenceData.notes', 'PRIVATE SOURCE SENTINEL')
        ->set('workspaceMaterialCode', 'PRIVATE-CODE-SENTINEL')
        ->set('workspaceGuidance.html', '<p>PRIVATE GUIDANCE SENTINEL</p>')
        ->assertSet('data', [])
        ->assertSet('referenceData.notes', null)
        ->assertSet('workspaceMaterialCode', null)
        ->assertSet('workspaceGuidance', ['html' => null]);

    expect($public->instance()->data)->toBe([])
        ->and($public->instance()->workspaceMaterialCode)->toBeNull()
        ->and($public->instance()->workspaceGuidance)->toBe(['html' => null]);
});

it('retains public technical chemistry and IFRA limits without private source notes', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $ingredient = Ingredient::factory()->create([
        'display_name' => 'Public technical oil',
        'category' => IngredientCategory::Lipids,
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => 'public',
        'is_soap_saponification_trusted' => true,
        'requires_aromatic_compliance' => true,
        'source_data' => [
            'user_authoring' => [
                'trusted_koh_sap_value' => 0.187,
            ],
        ],
    ]);
    IngredientSapProfile::factory()->create([
        'ingredient_id' => $ingredient->id,
        'koh_sap_value' => 0.187,
        'iodine_value' => 83.4,
        'ins_value' => 99.2,
        'source_notes' => 'PRIVATE SAP SOURCE SENTINEL',
    ]);
    $fattyAcid = FattyAcid::factory()->create(['name' => 'Public oleic acid']);
    IngredientFattyAcid::factory()->create([
        'ingredient_id' => $ingredient->id,
        'fatty_acid_id' => $fattyAcid->id,
        'percentage' => 44.5,
        'source_notes' => 'PRIVATE FATTY ACID SOURCE SENTINEL',
    ]);
    $amendment = IfraAmendment::factory()->create(['code' => '52']);
    $certificate = IfraCertificate::factory()->create([
        'ingredient_id' => $ingredient->id,
        'ifra_amendment_id' => $amendment->id,
        'certificate_name' => 'Public technical IFRA certificate',
        'source_amendment_label' => 'Amendment 52',
        'peroxide_value' => 1.8,
        'source_notes' => 'PRIVATE IFRA SOURCE SENTINEL',
    ]);
    $ifraCategory = IfraProductCategory::factory()->create([
        'code' => '10A',
        'name' => 'Public IFRA category',
    ]);
    IfraCertificateLimit::factory()->create([
        'ifra_certificate_id' => $certificate->id,
        'ifra_product_category_id' => $ifraCategory->id,
        'max_percentage' => 8.75,
        'restriction_note' => 'PRIVATE IFRA LIMIT SOURCE SENTINEL',
    ]);

    $publicUser = User::factory()->create();
    $this->actingAs($publicUser);

    $component = Livewire::test(IngredientEditor::class, ['ingredient' => $ingredient]);

    $component
        ->assertSeeText('0.187')
        ->assertSeeText('83.4')
        ->assertSeeText('Public oleic acid')
        ->assertSeeText('44.5%')
        ->assertSeeText('Public technical IFRA certificate')
        ->assertSeeText('8.75%')
        ->assertDontSeeText('PRIVATE SAP SOURCE SENTINEL')
        ->assertDontSeeText('PRIVATE FATTY ACID SOURCE SENTINEL')
        ->assertDontSeeText('PRIVATE IFRA SOURCE SENTINEL')
        ->assertDontSeeText('PRIVATE IFRA LIMIT SOURCE SENTINEL');

    expect($component->instance()->referenceData['soap']['koh_sap_value'])->toBe(0.187)
        ->and($component->instance()->referenceData['soap']['fatty_acids'][0]['source_notes'])->toBeNull()
        ->and($component->instance()->referenceData['ifra']['limits'][0]['max_percentage'])->toBe(8.75)
        ->and($component->instance()->referenceData['ifra']['source_notes'])->toBeNull()
        ->and($component->instance()->referenceData['ifra']['limits'][0]['restriction_note'])->toBeNull();
});

it('shows an owning workspace scope while keeping its locked destination after a workspace switch', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create([
        'name' => 'Owning workspace',
    ]);
    $otherWorkspace = Workspace::factory()->create(['name' => 'Active workspace']);
    WorkspaceMember::factory()->for($otherWorkspace)->for($owner)->create(['role' => 'viewer']);
    $owner->forceFill(['active_workspace_id' => $workspace->id])->save();
    $ingredient = Ingredient::factory()->create([
        'display_name' => 'Workspace owned ingredient',
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => 'private',
    ]);

    $this->actingAs($owner);

    $component = Livewire::test(IngredientEditor::class, ['ingredient' => $ingredient])
        ->assertSet('destinationWorkspaceId', $workspace->id)
        ->assertSeeText('Changes are shared with everyone in Owning workspace.');

    $owner->forceFill(['active_workspace_id' => $otherWorkspace->id])->save();
    $owner->forgetAccessibleWorkspaceIds();

    $component
        ->refresh()
        ->assertSet('destinationWorkspaceId', $workspace->id)
        ->assertSeeText('Changes are shared with everyone in Owning workspace.')
        ->assertDontSeeText('Changes are shared with everyone in Active workspace.');
});

it('shows a workspace material code as plain text to a workspace viewer', function (): void {
    $owner = User::factory()->create();
    $viewer = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    WorkspaceMember::factory()->for($workspace)->for($viewer)->create(['role' => 'viewer']);
    $ingredient = Ingredient::factory()->create([
        'display_name' => 'Viewer workspace ingredient',
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => 'private',
    ]);
    WorkspaceIngredientCode::factory()->create([
        'workspace_id' => $workspace->id,
        'ingredient_id' => $ingredient->id,
        'material_code' => 'VIEWER-MATERIAL-CODE',
    ]);
    $viewer->forceFill(['active_workspace_id' => $workspace->id])->save();
    $viewer->forgetAccessibleWorkspaceIds();

    $this->actingAs($viewer);

    $component = Livewire::test(IngredientEditor::class, ['ingredient' => $ingredient]);

    $component
        ->assertSeeText('VIEWER-MATERIAL-CODE')
        ->assertDontSeeHtml('<input id="workspace-material-code"');

    expect($component->instance()->referenceData['material_code'])->toBe('VIEWER-MATERIAL-CODE');
});

it('shows localized platform content while preserving authored workspace names', function (): void {
    SupportedLocale::query()->where('code', 'fr')->update(['is_active' => true]);
    $user = User::factory()->create(['locale' => 'fr']);
    Workspace::factory()->for($user, 'owner')->create();
    $platform = Ingredient::factory()->create([
        'display_name' => 'Coconut oil',
        'info_markdown' => 'English guidance',
        'owner_type' => null,
        'owner_id' => null,
    ]);
    IngredientTranslation::factory()->create([
        'ingredient_id' => $platform->id,
        'locale' => 'fr',
        'display_name' => 'Huile de coco',
        'info_markdown' => 'Conseils en français',
    ]);

    App::setLocale('fr');
    $this->actingAs($user);

    $component = Livewire::test(IngredientEditor::class, ['ingredient' => $platform]);

    $component
        ->assertSet('referenceData.name', 'Huile de coco')
        ->call('startWorkspaceGuidanceCustomization')
        ->assertSet('isEditingWorkspaceGuidance', true)
        ->tap(fn ($test) => expect($test->instance()->workspaceGuidanceForm->getState()['html'])->toBe('<p>Conseils en français</p>'))
        ->assertSeeText('Huile de coco')
        ->assertDontSeeText('Coconut oil');
});

it('uses the approved task-focused copy on the add ingredient page', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->get(route('ingredients.create'))
        ->assertSuccessful()
        ->assertSeeHtml('data-workflow-action-bar')
        ->assertSeeHtml('data-ingredient-save-bar')
        ->assertSeeHtml('sk-workflow-action-bar')
        ->assertSeeHtml('type="submit"')
        ->assertSeeHtml('class="sk-btn sk-btn-ghost"')
        ->assertSeeHtml('class="sk-btn sk-btn-primary"')
        ->assertSeeText('Add ingredient')
        ->assertSee('Not created yet')
        ->assertSeeText('Enter a name and choose a category. Add an INCI name and supporting details when available.')
        ->assertSeeText('Overview')
        ->assertSeeText('Guidance & files')
        ->assertSeeText('Basics')
        ->assertSeeText('Start with the name used in your workspace and the INCI when known.')
        ->assertSeeText('Ingredient name')
        ->assertSeeText('Category')
        ->assertSeeText('Ingredient type')
        ->assertSeeText('Single ingredient')
        ->assertSeeText('Blend')
        ->assertSeeText('Choose Blend when this material contains other ingredients.')
        ->assertSeeText('INCI name (optional)')
        ->assertSeeText('Use the INCI name supplied for this ingredient, when available.')
        ->assertSeeText('Internal material code (optional)')
        ->assertSeeText('Classification')
        ->assertSeeText('Subcategory')
        ->assertSeeText('Treat as an aromatic ingredient')
        ->assertSeeText('Uses the fragrance phase in the formula workbench and enables allergen and IFRA records.')
        ->assertSeeText('Identifiers and alternative names')
        ->assertSeeText('Help classify this ingredient')
        ->assertDontSeeText('Certified organic')
        ->assertDontSeeText('Verified COSING functions')
        ->assertSeeText('Workspace functions (optional)')
        ->assertSeeText('AI research helper')
        ->assertSeeText('Generate a prompt to research classification, identifiers, COSING functions, and concise professional notes. It will not change this form.')
        ->assertSeeText('Generate prompt')
        ->assertSeeText('Copy prompt')
        ->assertSee('data-classification-prompt-copy', escape: false)
        ->assertSee('disabled', escape: false)
        ->assertDontSeeText('Classify the cosmetic or soapmaking ingredient below.')
        ->assertDontSeeText('"name": null')
        ->assertSeeText('Ingredient guidance')
        ->assertSeeText('Add practical formulation and storage guidance for your workspace. Up to 10000 visible characters.')
        ->assertSeeText('Source notes')
        ->assertSeeText('Add supplier or source details that may help identify and classify this ingredient.')
        ->assertSeeText('Images & documents')
        ->assertSeeText('Certificates and technical documents')
        ->assertSeeText('Attach up to 8 PDFs, such as certificates of analysis, safety data sheets or technical data sheets.')
        ->assertDontSeeText('Identifiers and functions')
        ->assertDontSeeText('Trusted for soap saponification')
        ->assertSeeText('Add ingredient')
        ->assertDontSeeText('Create a personal ingredient')
        ->assertDontSeeText('Catalog item type')
        ->assertDontSeeText('Optional workspace context')
        ->assertDontSeeText('Create ingredient');

    $html = $response->getContent();

    expect(strpos($html, 'data-ingredient-classification-section'))
        ->toBeLessThan(strpos($html, 'data-ingredient-identity-section'))
        ->and(strpos($html, 'data-ingredient-identity-section'))
        ->toBeLessThan(strpos($html, 'classification-prompt-title'));
});

it('uses the clarified editor headings and keeps the classification helper secondary', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    $create = $this->get(route('ingredients.create'))
        ->assertSuccessful()
        ->assertSeeText('Add ingredient')
        ->assertSeeText('Enter a name and choose a category. Add an INCI name and supporting details when available.')
        ->assertSeeText('Your ingredient library')
        ->assertSeeText('Overview')
        ->assertSeeText('Guidance & files')
        ->assertSeeText('Regulatory data')
        ->assertSeeText('Help classify this ingredient')
        ->assertSeeText('Images & documents');

    $html = $create->getContent();

    expect(strpos($html, 'data-ingredient-identity-section'))
        ->toBeLessThan(strpos($html, 'Help classify this ingredient'))
        ->and($html)->toContain('<details')
        ->and($html)->toContain('classification-prompt-title');
});

it('groups guidance and files into ordered editor sections', function (): void {
    $user = User::factory()->create();

    $html = $this->actingAs($user)
        ->get(route('ingredients.create'))
        ->assertSuccessful()
        ->getContent();

    $guidancePosition = strpos($html, 'data-ingredient-guidance-section');
    $sourcePosition = strpos($html, 'data-ingredient-source-notes-section');
    $mediaPosition = strpos($html, 'data-ingredient-media-section');

    expect($guidancePosition)->toBeInt()
        ->and($sourcePosition)->toBeInt()
        ->and($mediaPosition)->toBeInt()
        ->and($guidancePosition)->toBeLessThan($sourcePosition)
        ->and($sourcePosition)->toBeLessThan($mediaPosition);

    $guidanceSection = substr($html, $guidancePosition, $sourcePosition - $guidancePosition);
    $sourceSection = substr($html, $sourcePosition, $mediaPosition - $sourcePosition);
    $mediaSection = substr($html, $mediaPosition);

    expect($guidanceSection)
        ->toContain('Ingredient guidance')
        ->toContain('Add practical formulation and storage guidance for your workspace.')
        ->toContain('data.guidance_html')
        ->and($sourceSection)
        ->toContain('Source notes')
        ->toContain('Add supplier or source details that may help identify and classify this ingredient.')
        ->toContain('data.notes')
        ->and($mediaSection)
        ->toContain('Images &amp; documents')
        ->toContain('data.featured_media_asset_id')
        ->toContain('data.icon_media_asset_id')
        ->toContain('data.document_media_asset_ids')
        ->toContain('Certificates and technical documents')
        ->toContain('Attach up to 8 PDFs, such as certificates of analysis, safety data sheets or technical data sheets.');
});

it('shows the destination workspace on create and edit pages', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create(['name' => 'North Star Soapworks']);
    $user->forceFill(['active_workspace_id' => $workspace->id])->save();
    $user->forgetAccessibleWorkspaceIds();

    $this->actingAs($user);

    $this->get(route('ingredients.create'))
        ->assertSuccessful()
        ->assertSeeText('North Star Soapworks');

    $ingredient = Ingredient::factory()->create([
        'display_name' => 'North Star Olive Oil',
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
    ]);

    $this->get(route('ingredients.edit', $ingredient))
        ->assertSuccessful()
        ->assertSeeText('Edit North Star Olive Oil')
        ->assertSeeText('North Star Soapworks');
});

it('exposes stable tab identifiers with the existing ingredient query key', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    $component = Livewire::test(IngredientEditor::class);
    $tabs = collect($component->instance()->form->getComponents(withHidden: true))
        ->first(fn (mixed $component): bool => $component instanceof Tabs);

    expect($tabs)->toBeInstanceOf(Tabs::class)
        ->and($tabs->getTabQueryStringKey())->toBe('ingredient-tab');

    /** @var Tabs $tabs */
    $tabComponents = $tabs->getChildSchema()->getComponents(withHidden: true);

    expect(collect($tabComponents)->map(fn (Tab $tab): string => (string) $tab->getLabel())->all())
        ->toBe(['Overview', 'Composition', 'Guidance & files', 'Soap chemistry', 'Regulatory data'])
        ->and(collect($tabComponents)->map(fn (Tab $tab): ?string => $tab->getId())->all())
        ->toBe(['overview', 'composition', 'guidance-files', 'soap-chemistry', 'regulatory-data'])
        ->and(collect($tabComponents)->map(fn (Tab $tab): ?string => $tab->getKey(isAbsolute: false))->all())
        ->toBe(['overview', 'composition', 'guidance-files', 'soap-chemistry', 'regulatory-data']);
});

it('resolves legacy ingredient tab query values against visible tabs', function (): void {
    $user = User::factory()->create();

    $activeTabLabel = static function (mixed $component): string {
        $tabs = collect($component->instance()->form->getComponents(withHidden: true))
            ->first(fn (mixed $candidate): bool => $candidate instanceof Tabs);

        $visibleTabs = collect($tabs->getChildSchema()->getComponents(withHidden: true))
            ->filter(fn (mixed $tab): bool => $tab instanceof Tab && $tab->isVisible())
            ->values();

        return (string) $visibleTabs->get($tabs->getActiveTab() - 1)->getLabel();
    };

    $this->actingAs($user);

    expect($activeTabLabel(
        Livewire::withQueryParams(['ingredient-tab' => 'documents::tab'])
            ->test(IngredientEditor::class),
    ))->toBe('Guidance & files')
        ->and($activeTabLabel(
            Livewire::withQueryParams(['ingredient-tab' => 'compliance::tab'])
                ->test(IngredientEditor::class),
        ))->toBe('Regulatory data');

    expect($activeTabLabel(
        Livewire::withQueryParams(['ingredient-tab' => 'documents::tab'])
            ->test(IngredientEditor::class)
            ->set('data.ingredient_structure', 'blend'),
    ))->toBe('Guidance & files')
        ->and($activeTabLabel(
            Livewire::withQueryParams(['ingredient-tab' => 'compliance::tab'])
                ->test(IngredientEditor::class)
                ->set('data.ingredient_structure', 'blend'),
        ))->toBe('Regulatory data');

    $workspace = Workspace::factory()->for($user, 'owner')->create();
    $user->forceFill(['active_workspace_id' => $workspace->id])->save();
    $user->forgetAccessibleWorkspaceIds();
    $trustedIngredient = Ingredient::factory()->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'is_soap_saponification_trusted' => true,
        'source_data' => [
            'user_authoring' => [
                'trusted_koh_sap_value' => 0.187,
            ],
        ],
    ]);

    expect($activeTabLabel(
        Livewire::withQueryParams(['ingredient-tab' => 'documents::tab'])
            ->test(IngredientEditor::class, ['ingredient' => $trustedIngredient]),
    ))->toBe('Guidance & files')
        ->and($activeTabLabel(
            Livewire::withQueryParams(['ingredient-tab' => 'compliance::tab'])
                ->test(IngredientEditor::class, ['ingredient' => $trustedIngredient]),
        ))->toBe('Regulatory data');
});

it('starts with a single ingredient and renders the editor groups in task order', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    $component = Livewire::test(IngredientEditor::class);

    $html = $component->html();

    expect($component->get('data.ingredient_structure'))->toBe('ingredient')
        ->and(strpos($html, 'wire:model="data.name"'))->toBeLessThan(strpos($html, 'wire:model="data.inci_name"'))
        ->and(strpos($html, 'wire:model="data.name"'))->toBeLessThan(strpos($html, 'wire:model.live="data.category"'))
        ->and(strpos($html, 'wire:model.live="data.category"'))->toBeLessThan(strpos($html, 'wire:model.live="data.ingredient_structure"'))
        ->and(strpos($html, 'wire:model="data.ingredient_structure"'))->toBeLessThan(strpos($html, 'wire:model="data.inci_name"'))
        ->and(strpos($html, 'wire:model="data.inci_name"'))->toBeLessThan(strpos($html, 'wire:model="data.material_code"'))
        ->and(strpos($html, 'Subcategory'))->toBeLessThan(strpos($html, 'Treat as an aromatic ingredient'))
        ->and(strpos($html, 'Treat as an aromatic ingredient'))->toBeLessThan(strpos($html, 'Workspace functions (optional)'))
        ->and(strpos($html, 'data-ingredient-basics-section'))->toBeLessThan(strpos($html, 'data-ingredient-classification-section'))
        ->and(strpos($html, 'data-ingredient-classification-section'))->toBeLessThan(strpos($html, 'data-ingredient-identity-section'))
        ->and(strpos($html, 'data-ingredient-identity-section'))->toBeLessThan(strpos($html, 'classification-prompt-title'));

    $component
        ->assertDontSeeText('Trusted for soap saponification')
        ->assertDontSeeText('Composition');
});

it('orders every classification field for a trusted duplicate with verified functions', function (): void {
    $user = User::factory()->create();
    $verifiedFunction = IngredientFunction::factory()->create([
        'name' => 'Verified emollient',
        'is_active' => true,
    ]);
    $source = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'display_name' => 'Trusted source oil',
        'owner_type' => null,
        'owner_id' => null,
        'workspace_id' => null,
        'is_soap_saponification_trusted' => true,
        'requires_aromatic_compliance' => true,
    ]);
    $source->sapProfile()->create(['koh_sap_value' => 0.188]);
    $source->functions()->attach($verifiedFunction->id, [
        'source' => IngredientFunctionSource::CosIng->value,
    ]);

    $copy = app(UserIngredientAuthoringService::class)->duplicate($source, $user);

    expect($copy->fresh('functions')->functions->first()?->pivot->source)
        ->toBe(IngredientFunctionSource::Inherited->value);
    expect(app(UserIngredientAuthoringService::class)->formData($copy)['verified_function_names'])
        ->toContain('Verified emollient');

    $this->actingAs($user);

    $component = Livewire::test(IngredientEditor::class, ['ingredient' => $copy]);
    expect($component->get('data.verified_function_names'))->toContain('Verified emollient');

    $html = $component->html();
    $classificationStart = strpos($html, 'data-ingredient-classification-section');
    $identityStart = strpos($html, 'data-ingredient-identity-section');
    $classification = substr($html, $classificationStart, $identityStart - $classificationStart);

    expect($classification)->toContain('Subcategory')
        ->toContain('Treat as an aromatic ingredient')
        ->toContain('Soap data inherited from Soapkraft. Editable values stay within inherited limits.')
        ->toContain('Verified functions')
        ->toContain('Verified emollient')
        ->toContain('Workspace functions (optional)');

    $fieldLabels = [
        'Subcategory',
        'Treat as an aromatic ingredient',
        'Soap data inherited from Soapkraft. Editable values stay within inherited limits.',
        'Verified functions',
        'Workspace functions (optional)',
    ];

    foreach (array_slice($fieldLabels, 0, -1) as $index => $label) {
        expect(strpos($classification, $label))
            ->toBeLessThan(strpos($classification, $fieldLabels[$index + 1]));
    }
});

it('keeps the editor baseline mounted when a blend composition re-renders', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    $component = Livewire::test(IngredientEditor::class);
    $parseEditorRoots = static function (string $html): array {
        $previousLibxmlSetting = libxml_use_internal_errors(true);
        $document = new DOMDocument;
        $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previousLibxmlSetting);

        $outerRoot = (new DOMXPath($document))->query('//body/*[1]')->item(0);
        expect($outerRoot)->toBeInstanceOf(DOMElement::class);

        $editorRoot = $outerRoot->firstElementChild;
        expect($editorRoot)->toBeInstanceOf(DOMElement::class);

        return [$outerRoot, $editorRoot];
    };

    [$outerRoot, $editorRoot] = $parseEditorRoots($component->html());

    expect($outerRoot->hasAttribute('x-data'))->toBeFalse()
        ->and($outerRoot->hasAttribute('data-ingredient-editor'))->toBeFalse()
        ->and($outerRoot->hasAttribute('wire:ignore.self'))->toBeFalse()
        ->and($editorRoot->hasAttribute('data-ingredient-editor'))->toBeTrue()
        ->and($editorRoot->hasAttribute('wire:ignore.self'))->toBeTrue()
        ->and($editorRoot->firstElementChild)->toBeInstanceOf(DOMElement::class);

    $component
        ->set('data.ingredient_structure', 'blend')
        ->assertSet('data.ingredient_structure', 'blend')
        ->assertSeeText('Blend composition');

    [$updatedOuterRoot, $updatedEditorRoot] = $parseEditorRoots($component->html());

    expect($updatedOuterRoot->firstElementChild)->toBe($updatedEditorRoot)
        ->and($updatedEditorRoot->hasAttribute('data-ingredient-editor'))->toBeTrue()
        ->and($updatedEditorRoot->hasAttribute('wire:ignore.self'))->toBeTrue()
        ->and($updatedEditorRoot->firstElementChild)->toBeInstanceOf(DOMElement::class);
});

it('explains why manually created lipids cannot use saponification and links to duplication', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    $component = Livewire::test(IngredientEditor::class)
        ->set('data.category', IngredientCategory::Lipids->value)
        ->assertSeeText('This ingredient cannot be used for saponification calculations. To customize soap chemistry, duplicate a platform ingredient with trusted soap chemistry.')
        ->assertSeeText('Duplicate ingredient');

    $warningHtml = $component->html();
    $warningStart = strpos($warningHtml, 'data-ingredient-carrier-oil-warning');
    $warningEnd = strpos($warningHtml, '</aside>', $warningStart);
    $warning = substr($warningHtml, $warningStart, $warningEnd - $warningStart);
    $duplicationLinkMarker = strpos($warning, 'data-ingredient-carrier-oil-duplication-link');
    $duplicationLinkStart = strrpos(substr($warning, 0, $duplicationLinkMarker), '<a');
    $duplicationLinkEnd = strpos($warning, '>', $duplicationLinkMarker);
    $duplicationLink = substr($warning, $duplicationLinkStart, $duplicationLinkEnd - $duplicationLinkStart + 1);

    expect($warning)
        ->toContain('This ingredient cannot be used for saponification calculations. To customize soap chemistry, duplicate a platform ingredient with trusted soap chemistry.')
        ->and($duplicationLink)->toContain('href="'.route('ingredients.index').'"');

    $component
        ->set('data.category', IngredientCategory::Other->value)
        ->assertDontSeeText('This ingredient cannot be used for saponification calculations. To customize soap chemistry, duplicate a platform ingredient with trusted soap chemistry.')
        ->set('data.category', IngredientCategory::Lipids->value)
        ->assertSeeText('This ingredient cannot be used for saponification calculations. To customize soap chemistry, duplicate a platform ingredient with trusted soap chemistry.');
});

it('keeps save and cancel actions sticky when editing a workspace ingredient', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    $ingredient = Ingredient::factory()->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
    ]);

    $this->actingAs($user)
        ->get(route('ingredients.edit', $ingredient))
        ->assertSuccessful()
        ->assertSeeHtml('data-workflow-action-bar')
        ->assertSeeHtml('data-ingredient-save-bar')
        ->assertSeeText('Cancel')
        ->assertSeeText('Save changes');
});

it('uses the approved blend composition copy', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test(IngredientEditor::class)
        ->set('data.ingredient_structure', 'blend')
        ->assertSeeText('Blend composition')
        ->assertSeeText('Add the ingredients in this blend and enter their percentages.')
        ->assertSeeText('Add an ingredient')
        ->assertSee('placeholder="Search by name or INCI"', false)
        ->assertSeeText('Add a new ingredient')
        ->assertSeeText('Create and add ingredient')
        ->assertSeeText('Creates an ingredient in Your ingredient library immediately, then adds it to this blend. It stays there if you cancel this blend.')
        ->assertSeeText('No ingredients added yet')
        ->assertSeeText('Composition source');
});

it('discloses the resolved workspace for composition quick-create', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create(['name' => 'Studio workspace']);
    $user->forceFill(['active_workspace_id' => $workspace->id])->save();

    $this->actingAs($user);

    Livewire::test(IngredientEditor::class)
        ->set('data.ingredient_structure', 'blend')
        ->assertSeeText('Create and add ingredient')
        ->assertSeeText('Creates an ingredient in Studio workspace immediately, then adds it to this blend. It stays there if you cancel this blend.');
});

it('keeps clipboard recovery visible outside the collapsed classification prompt preview', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    $component = Livewire::test(IngredientEditor::class)
        ->set('data.name', 'Test ingredient')
        ->call('generateClassificationPrompt');
    $response = $component->html();
    $messagePosition = strpos($response, 'Could not copy. Open the prompt and copy the text manually.');
    $alertMarkup = substr(
        $response,
        strrpos(substr($response, 0, $messagePosition), '<p'),
        strpos($response, '</p>', $messagePosition) + 4 - strrpos(substr($response, 0, $messagePosition), '<p'),
    );

    expect($response)
        ->toContain('Could not copy. Open the prompt and copy the text manually.')
        ->toContain('role="alert"')
        ->and(strpos($response, 'Could not copy. Open the prompt and copy the text manually.'))
        ->toBeGreaterThan(strpos($response, '</details>'));

    expect($alertMarkup)
        ->toContain('role="alert"')
        ->not->toContain('aria-live="assertive"')
        ->not->toContain('aria-atomic="true"');
});

it('reopens the classification helper when a prompt is generated', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    $initial = Livewire::test(IngredientEditor::class);
    $initialHtml = $initial->html();
    $initialSectionStart = strpos($initialHtml, 'x-data="classificationPrompt()"');
    $initialDetailsStart = strpos($initialHtml, '<details', $initialSectionStart);
    $initialDetailsEnd = strpos($initialHtml, '>', $initialDetailsStart);

    expect($initialDetailsStart)->toBeInt()
        ->and(substr($initialHtml, $initialDetailsStart, $initialDetailsEnd - $initialDetailsStart + 1))
        ->toBe('<details>');

    $generated = $initial
        ->set('data.name', 'Test ingredient')
        ->call('generateClassificationPrompt');
    $generatedHtml = $generated->html();
    $generatedSectionStart = strpos($generatedHtml, 'x-data="classificationPrompt()"');
    $generatedDetailsStart = strpos($generatedHtml, '<details', $generatedSectionStart);
    $generatedDetailsEnd = strpos($generatedHtml, '>', $generatedDetailsStart);

    expect(substr($generatedHtml, $generatedDetailsStart, $generatedDetailsEnd - $generatedDetailsStart + 1))
        ->toBe('<details open>');
});

it('keeps a visible native marker for the classification disclosure', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    $html = Livewire::test(IngredientEditor::class)->html();
    $summaryStart = strpos($html, '<summary id="classification-prompt-title"');
    $summaryEnd = strpos($html, '>', $summaryStart);
    $summary = substr($html, $summaryStart, $summaryEnd - $summaryStart + 1);

    expect($summary)
        ->toContain('<summary id="classification-prompt-title"')
        ->not->toContain('list-none');
});

it('shows the translated blend removal warning and acknowledgement in the details tab', function (): void {
    $user = User::factory()->create();
    $componentIngredient = Ingredient::factory()->create([
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => 'private',
        'is_active' => true,
    ]);
    $blend = app(UserIngredientAuthoringService::class)->create([
        'name' => 'Saved blend for warning',
        'category' => IngredientCategory::Other->value,
        'ingredient_structure' => 'blend',
        'components' => [[
            'component_ingredient_id' => $componentIngredient->id,
            'percentage_in_parent' => 100,
        ]],
    ], $user);

    $this->actingAs($user);

    Livewire::withQueryParams(['ingredient-tab' => 'composition'])
        ->test(IngredientEditor::class, ['ingredient' => $blend])
        ->set('data.ingredient_structure', 'ingredient')
        ->assertDontSeeText('Blend composition')
        ->assertSeeText('Saving as a single ingredient will remove its blend composition. Switch back to Blend to keep it.')
        ->assertSeeText('Remove blend composition when saving')
        ->assertSeeHtml('wire:model.live="confirmCompositionRemoval"')
        ->set('confirmCompositionRemoval', true)
        ->set('data.ingredient_structure', 'blend')
        ->assertDontSeeText('Saving as a single ingredient will remove its blend composition. Switch back to Blend to keep it.')
        ->assertDontSeeText('Remove blend composition when saving')
        ->assertSet('confirmCompositionRemoval', false);
});

it('does not let a manually created ingredient expose soap chemistry', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test(IngredientEditor::class)
        ->assertDontSeeText('Trusted for soap saponification')
        ->set('data.is_soap_saponification_trusted', true)
        ->assertDontSeeText('Soap calculation data inherited from Soapkraft.')
        ->assertDontSeeText('Saponification values');
});

it('shows inherited soap chemistry for a duplicated platform oil', function () {
    $user = User::factory()->create();
    $source = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'display_name' => 'Platform olive oil',
        'owner_type' => null,
        'owner_id' => null,
        'is_soap_saponification_trusted' => true,
    ]);
    $source->sapProfile()->create(['koh_sap_value' => 0.188]);
    $copy = app(UserIngredientAuthoringService::class)->duplicate($source, $user);

    $this->actingAs($user);

    Livewire::test(IngredientEditor::class, ['ingredient' => $copy])
        ->assertDontSeeText('Trusted for soap saponification')
        ->assertSeeText('Soap data inherited from Soapkraft. Editable values stay within inherited limits.')
        ->assertSeeText('Saponification values')
        ->assertSeeText('Add the values used to calculate this oil in soap formulas.')
        ->assertSeeText('Allowed KOH SAP range');
});

it('shows inherited chemistry limits in the duplicated lipid editor', function (): void {
    $user = User::factory()->create();
    $small = FattyAcid::factory()->create(['name' => 'Trace acid']);
    $major = FattyAcid::factory()->create(['name' => 'Major acid']);
    $source = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'display_name' => 'Boundary source oil',
        'owner_type' => null,
        'owner_id' => null,
        'is_soap_saponification_trusted' => true,
    ]);
    $source->sapProfile()->create(['koh_sap_value' => 0.188]);
    $source->fattyAcidEntries()->createMany([
        ['fatty_acid_id' => $small->id, 'percentage' => 2],
        ['fatty_acid_id' => $major->id, 'percentage' => 60],
    ]);
    $copy = app(UserIngredientAuthoringService::class)->duplicate($source, $user);

    $this->actingAs($user);

    Livewire::test(IngredientEditor::class, ['ingredient' => $copy])
        ->assertSeeText('Soap data inherited from Soapkraft. Editable values stay within inherited limits.')
        ->assertSeeText('Only private copies of eligible Soapkraft oils can use this chemistry.')
        ->assertSeeText('Allowed KOH SAP range: 0.182360–0.193640')
        ->assertSeeText('NaOH SAP')
        ->assertSeeText('Calculated automatically from the KOH SAP.')
        ->assertSeeText('Recommended total: 80–100%')
        ->assertSeeText('Allowed: 0.0%–5.0%.')
        ->assertSeeText('Allowed: 48.0%–72.0%.');
});

it('scopes user editor terminology without changing shared ingredient translations', function (): void {
    $copy = require lang_path('en/ingredients.php');

    expect($copy['editor']['details']['section'])->toBe('Ingredient identity')
        ->and($copy['editor']['details']['name'])->toBe('Name')
        ->and($copy['editor']['details']['type']['helper'])->toBe('Choose Blend when this ingredient is made from several ingredients.')
        ->and($copy['editor']['details']['aromatic_compliance'])->toBe('Requires aromatic compliance')
        ->and($copy['editor']['details']['aromatic_compliance_helper'])->toBe('Enables allergen and IFRA information independently of the material category.')
        ->and($copy['editor']['details']['inci'])->toBe('INCI')
        ->and($copy['editor']['material_code']['label'])->toBe('Internal material code')
        ->and($copy['editor']['identity']['section'])->toBe('Reference identifiers')
        ->and($copy['editor']['supplier']['verified_functions'])->toBe('Verified COSING functions')
        ->and($copy['editor']['supplier']['additional_functions'])->toBe('Functions used in your workspace')
        ->and($copy['editor']['overview']['name'])->toBe('Ingredient name')
        ->and($copy['editor']['overview']['inci'])->toBe('INCI name (optional)')
        ->and($copy['editor']['overview']['inci_helper'])->toBe('Use the INCI name supplied for this ingredient, when available.')
        ->and($copy['editor']['overview']['inherited_soap'])->toBe('Soap data inherited from Soapkraft. Editable values stay within inherited limits.')
        ->and($copy['editor']['overview']['identifiers_section'])->toBe('Identifiers and alternative names');

    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test(IngredientEditor::class)
        ->assertSeeText('Ingredient name')
        ->assertSeeText('INCI name (optional)')
        ->assertSeeText('Use the INCI name supplied for this ingredient, when available.')
        ->assertSeeText('Treat as an aromatic ingredient')
        ->assertSeeText('Identifiers and alternative names');
});

it('loads ingredient editor interface copy from the database', function () {
    SupportedLocale::query()->where('code', 'fr')->update(['is_active' => true]);

    $user = User::factory()->create(['locale' => 'fr']);

    foreach ([
        'editor.create.page_title' => 'Ajouter un ingrédient',
        'editor.create.heading' => 'Ajouter un ingrédient',
        'editor.create.intro' => 'Saisissez un nom et choisissez une catégorie. Ajoutez un nom INCI et des informations complémentaires si disponibles.',
        'editor.tabs.details' => 'Vue d’ensemble',
        'editor.tabs.documents' => 'Conseils et fichiers',
        'editor.overview.basics_section' => 'Informations de base',
        'editor.overview.name' => 'Nom de l’ingrédient',
        'editor.overview.inci' => 'Nom INCI (facultatif)',
        'editor.overview.inci_helper' => 'Utilisez le nom INCI fourni pour cet ingrédient, lorsqu’il est disponible.',
        'editor.overview.type_helper' => 'Choisissez Mélange lorsque ce matériau contient d’autres ingrédients.',
        'editor.overview.aromatic_compliance' => 'Traiter comme un ingrédient aromatique',
        'editor.overview.aromatic_compliance_helper' => 'Utilise la phase parfumée dans l’atelier de formulation et active les enregistrements d’allergènes et IFRA.',
        'editor.overview.material_code' => 'Code matière interne (facultatif)',
        'editor.overview.identifiers_section' => 'Identifiants et noms alternatifs',
        'editor.overview.verified_functions' => 'Fonctions vérifiées',
        'editor.overview.workspace_functions' => 'Fonctions de l’espace de travail (facultatif)',
        'editor.details.type.label' => 'Type d’ingrédient',
        'editor.details.type.single' => 'Ingrédient simple',
        'editor.details.type.blend' => 'Mélange',
        'editor.classification.section' => 'Classification',
        'editor.supplier.none_verified' => 'Aucune fonction vérifiée',
        'editor.supplier.verified_functions_helper' => 'Fonctions officielles en lecture seule.',
        'editor.classification_prompt.eyebrow' => 'Assistant de recherche IA',
        'editor.classification_prompt.heading' => 'Aidez à classer cet ingrédient',
        'editor.classification_prompt.description' => 'Générez un prompt pour rechercher la classification, les identifiants, les fonctions COSING et de brèves notes professionnelles. Il ne modifiera pas ce formulaire.',
        'editor.actions.create' => 'Ajouter l’ingrédient',
    ] as $key => $translation) {
        InterfaceTranslation::query()->create([
            'group' => 'ingredients',
            'key' => $key,
            'text' => ['fr' => $translation],
        ]);
    }

    $this->actingAs($user)
        ->get(route('ingredients.create'))
        ->assertSuccessful()
        ->assertSeeText('Ajouter un ingrédient')
        ->assertSeeText('Saisissez un nom et choisissez une catégorie. Ajoutez un nom INCI et des informations complémentaires si disponibles.')
        ->assertSeeText('Vue d’ensemble')
        ->assertSeeText('Conseils et fichiers')
        ->assertSeeText('Informations de base')
        ->assertSeeText('Nom de l’ingrédient')
        ->assertSeeText('Nom INCI (facultatif)')
        ->assertSeeText('Utilisez le nom INCI fourni pour cet ingrédient, lorsqu’il est disponible.')
        ->assertSeeText('Type d’ingrédient')
        ->assertSeeText('Ingrédient simple')
        ->assertSeeText('Mélange')
        ->assertSeeText('Classification')
        ->assertSeeText('Identifiants et noms alternatifs')
        ->assertSeeText('Traiter comme un ingrédient aromatique')
        ->assertSeeText('Utilise la phase parfumée dans l’atelier de formulation et active les enregistrements d’allergènes et IFRA.')
        ->assertDontSeeText('Fonctions COSING vérifiées')
        ->assertDontSeeText('Fonctions officielles en lecture seule.')
        ->assertSeeText('Fonctions de l’espace de travail (facultatif)')
        ->assertSeeText('Assistant de recherche IA')
        ->assertSeeText('Aidez à classer cet ingrédient')
        ->assertSeeText('Générez un prompt pour rechercher la classification, les identifiants, les fonctions COSING et de brèves notes professionnelles. Il ne modifiera pas ce formulaire.')
        ->assertSeeText('Ajouter l’ingrédient');
});

it('loads the saved ingredient status from the database', function () {
    SupportedLocale::query()->where('code', 'fr')->update(['is_active' => true]);

    $user = User::factory()->create(['locale' => 'fr']);
    $ingredient = Ingredient::factory()->create([
        'display_name' => 'Glycérine',
        'category' => IngredientCategory::Other,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'is_active' => true,
    ]);

    InterfaceTranslation::query()->create([
        'group' => 'ingredients',
        'key' => 'editor.status.saved',
        'text' => ['fr' => 'Modifications enregistrées.'],
    ]);

    App::setLocale('fr');
    $this->actingAs($user);

    Livewire::test(IngredientEditor::class, ['ingredient' => $ingredient])
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('statusMessage', 'Modifications enregistrées.')
        ->assertDispatched(
            'app-notification',
            message: 'Modifications enregistrées.',
            type: 'success',
        );
});

it('shows translated validation feedback and a generic translated save alert', function () {
    SupportedLocale::query()->where('code', 'fr')->update(['is_active' => true]);

    $user = User::factory()->create(['locale' => 'fr']);

    foreach ([
        'editor.status.invalid' => 'Vérifiez les champs signalés.',
        'editor.validation.blend_required' => 'Ajoutez au moins un ingrédient pour enregistrer ce mélange.',
    ] as $key => $translation) {
        InterfaceTranslation::query()->create([
            'group' => 'ingredients',
            'key' => $key,
            'text' => ['fr' => $translation],
        ]);
    }

    App::setLocale('fr');
    $this->actingAs($user);

    Livewire::test(IngredientEditor::class)
        ->set('data.name', 'Mélange test')
        ->set('data.category', IngredientCategory::Other->value)
        ->set('data.ingredient_structure', 'blend')
        ->call('save')
        ->assertHasErrors(['data.components'])
        ->assertSeeText('Ajoutez au moins un ingrédient pour enregistrer ce mélange.')
        ->assertDispatched(
            'app-notification',
            message: 'Vérifiez les champs signalés.',
            type: 'error',
        );
});

it('passes translated dirty-state labels to the ingredient editor bootstrap', function (): void {
    SupportedLocale::query()->where('code', 'fr')->update(['is_active' => true]);

    $translations = [
        'editor.status.all_saved' => 'Sauvegarde terminée',
        'editor.status.unsaved' => 'Modifications non enregistrées',
        'editor.status.saving' => 'Sauvegarde en cours',
        'editor.status.save_failed' => 'Échec de sauvegarde',
        'editor.status.leave_warning' => 'Quitter malgré les modifications ?',
        'editor.status.replace_guidance' => 'Remplacer le brouillon ?',
        'editor.status.cancel_guidance' => 'Supprimer le brouillon ?',
    ];
    $user = User::factory()->create(['locale' => 'fr']);

    foreach ($translations as $key => $translation) {
        InterfaceTranslation::query()->create([
            'group' => 'ingredients',
            'key' => $key,
            'text' => ['fr' => $translation],
        ]);
    }

    App::setLocale('fr');
    $this->actingAs($user);

    $html = Livewire::test(IngredientEditor::class)->html();

    foreach ($translations as $translation) {
        expect($html)->toContain($translation);
    }
});

it('routes workspace ingredient authoring errors through translation keys', function () {
    $authoringSource = file_get_contents(app_path('Services/UserIngredientAuthoringService.php'));
    $dataEntrySource = file_get_contents(app_path('Services/IngredientDataEntryService.php'));

    foreach ([
        'This private ingredient cannot be edited from the public app.',
        'Only platform ingredients can be duplicated.',
        'Choose a subcategory belonging to the selected ingredient category.',
        'Add at least one component to save a blend.',
        'KOH SAP value is required for duplicated carrier oils trusted for soap calculation.',
        'Fatty acid percentages must total between 80% and 100%.',
        'Allergen concentration must not be negative.',
        'Peroxide value must not be negative.',
        'Max concentration must not exceed 100%.',
    ] as $hardCodedMessage) {
        expect($authoringSource)->not->toContain($hardCodedMessage);
    }

    foreach ([
        'Composite components must reference existing catalog ingredients.',
        'A blend can contain at most 20 components.',
        'Composite ingredient percentages must total 100%.',
        'An ingredient cannot include itself as a component.',
        'This component would create a circular ingredient composition.',
    ] as $hardCodedMessage) {
        expect($dataEntrySource)->not->toContain($hardCodedMessage);
    }
});

it('keeps every ingredient editor string in the ingredients translation group', function () {
    $copy = require lang_path('en/ingredients.php');

    expect($copy)->toHaveKeys([
        'editor.workspace_scope',
        'editor.read_only_description',
        'editor.status.all_saved',
        'editor.status.not_created',
        'editor.status.unsaved',
        'editor.status.saving',
        'editor.status.save_failed',
        'editor.status.leave_warning',
        'editor.status.replace_guidance',
        'editor.status.cancel_guidance',
        'editor.create.page_title',
        'editor.create.heading',
        'editor.create.intro',
        'editor.edit.heading',
        'editor.edit.intro',
        'editor.actions.create',
        'editor.actions.save',
        'editor.tabs.details',
        'editor.tabs.composition',
        'editor.tabs.documents',
        'editor.tabs.soap_chemistry',
        'editor.tabs.compliance',
        'editor.details.section',
        'editor.details.notes_helper',
        'editor.classification.section',
        'editor.identity.section',
        'editor.details.type.label',
        'editor.details.type.single',
        'editor.details.type.blend',
        'editor.details.composition_removal_warning',
        'editor.details.composition_removal_confirmation',
        'editor.supplier.section',
        'editor.media.section',
        'editor.guidance_files.guidance_section',
        'editor.guidance_files.guidance_description',
        'editor.guidance_files.guidance',
        'editor.guidance_files.guidance_helper',
        'editor.guidance_files.source_notes_section',
        'editor.guidance_files.source_notes_description',
        'editor.guidance_files.source_notes',
        'editor.guidance_files.source_notes_helper',
        'editor.guidance_files.media_section',
        'editor.guidance_files.media_description',
        'editor.guidance_files.image',
        'editor.guidance_files.image_helper',
        'editor.guidance_files.icon',
        'editor.guidance_files.icon_helper',
        'editor.guidance_files.documents',
        'editor.guidance_files.documents_helper',
        'editor.composition.section',
        'editor.soap.section',
        'editor.compliance.allergens.section',
        'editor.compliance.ifra.section',
        'editor.carrier_oil_warning.heading',
        'editor.carrier_oil_warning.description',
        'editor.status.auth_required',
        'editor.status.invalid',
        'editor.status.created',
        'editor.status.saved',
        'editor.validation.component_unavailable',
        'editor.validation.composition_removal_confirmation',
        'editor.validation.component_limit',
        'editor.validation.component_duplicate',
        'editor.validation.component_share',
        'editor.validation.private_edit_forbidden',
        'editor.validation.duplicate_platform_only',
        'editor.validation.duplicate_soap_profile_required',
        'editor.validation.subcategory_mismatch',
        'editor.validation.blend_required',
        'editor.validation.blend_component_unavailable',
        'editor.validation.soap_koh_required',
        'editor.validation.soap_koh_tolerance',
        'editor.validation.fatty_acid_total',
        'editor.validation.fatty_acid_range',
        'editor.validation.allergen_negative',
        'editor.validation.allergen_maximum',
        'editor.validation.peroxide_negative',
        'editor.validation.ifra_maximum_negative',
        'editor.validation.ifra_maximum',
        'editor.validation.component_reference_required',
        'editor.validation.composition_total',
        'editor.validation.composition_self',
        'editor.validation.composition_cycle',
        'editor.validation.private_ingredient_limit',
        'editor.identity.identifier_schemes.inchikey',
        'editor.identity.identifier_schemes.pubchem_cid',
    ]);

    expect(data_get($copy, 'editor.workspace_scope'))
        ->toBe('Changes are shared with everyone in :workspace.')
        ->and(data_get($copy, 'editor.read_only_description'))
        ->toBe('You can view this ingredient, but you do not have permission to edit it.')
        ->and(data_get($copy, 'editor.workspace_guidance.edit'))
        ->toBe('Edit workspace guidance');
});

it('presents primary identifiers separately from supported additional schemes', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('ingredients.create'))
        ->assertSuccessful()
        ->assertSeeText('Primary CAS number')
        ->assertSeeText('Primary EC / EINECS number')
        ->assertSeeText('InChIKey')
        ->assertSeeText('PubChem CID');
});
