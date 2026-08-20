<?php

use App\Enums\IngredientCategory;
use App\Enums\OwnerType;
use App\Enums\Visibility;
use App\Livewire\Dashboard\IngredientEditor;
use App\Models\Allergen;
use App\Models\FattyAcid;
use App\Models\IfraAmendment;
use App\Models\IfraProductCategory;
use App\Models\Ingredient;
use App\Models\IngredientFunction;
use App\Models\Plan;
use App\Models\Substance;
use App\Models\SupportedLocale;
use App\Models\User;
use App\Services\MediaStorage;
use App\Services\UserIngredientAuthoringService;
use Database\Seeders\SupportedLocaleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('generates a classification prompt from the latest ingredient identity', function (): void {
    $user = User::factory()->create();
    IngredientFunction::factory()->create(['key' => 'humectant', 'name' => 'Humectant', 'is_active' => true]);

    $this->actingAs($user);

    $component = Livewire::test(IngredientEditor::class)
        ->set('data.name', 'Vegetable glycerin')
        ->set('data.inci_name', 'GLYCERIN')
        ->set('data.cas_number', '56-81-5');

    $component
        ->call('generateClassificationPrompt')
        ->assertSet('generatedClassificationPrompt', function (?string $prompt): bool {
            return is_string($prompt)
                && str_contains($prompt, '"name": "Vegetable glycerin"')
                && str_contains($prompt, '"inci_name": "GLYCERIN"')
                && str_contains($prompt, '"cas_number": "56-81-5"')
                && ! str_contains($prompt, '"name": null');
        })
        ->assertSeeText('Current ingredient:')
        ->assertSeeText('Copy prompt')
        ->assertDontSee('data-classification-prompt-copy disabled', escape: false);

    expect($component->get('generatedClassificationPrompt'))
        ->toContain('Answer in: English (en).');
});

it('requires a name or INCI before generating the classification prompt', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test(IngredientEditor::class)
        ->call('generateClassificationPrompt')
        ->assertDispatched('app-notification', function (string $event, array $payload): bool {
            return $event === 'app-notification'
                && $payload['type'] === 'error'
                && $payload['message'] === 'Enter an ingredient name or INCI before generating the prompt.';
        })
        ->assertSet('generatedClassificationPrompt', null);
});

beforeEach(function () {
    Storage::fake(MediaStorage::publicDisk());
    Storage::fake(MediaStorage::userDisk());
});

it('creates a minimal private user ingredient from the public editor', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $component = Livewire::test(IngredientEditor::class);

    expect(method_exists($component->instance(), 'mountAction'))->toBeTrue();

    $component->assertDontSee('Soap chemistry');

    $component
        ->set('data.is_soap_saponification_trusted', true)
        ->assertDontSee('Soap chemistry');

    $component
        ->set('data.name', 'French Green Clay')
        ->set('data.category', IngredientCategory::MineralsSaltsPowders->value)
        ->set('data.inci_name', 'ILLITE')
        ->set('data.cas_number', '1332-58-7')
        ->set('data.ec_number', '310-194-1')
        ->set('data.notes', 'Fine cosmetic-grade green clay')
        ->call('save');

    $workspace = $user->refresh()->company();
    $ingredient = Ingredient::query()
        ->where('owner_type', OwnerType::Workspace)
        ->where('owner_id', $workspace?->id)
        ->first();

    $ingredient?->load('identifiers');

    expect($ingredient)->not->toBeNull()
        ->and($ingredient->visibility)->toBe(Visibility::Private)
        ->and($ingredient->is_soap_saponification_trusted)->toBeFalse()
        ->and($ingredient->display_name)->toBe('French Green Clay')
        ->and($ingredient->inci_name)->toBe('ILLITE')
        ->and($ingredient->identifiers->where('scheme', 'cas')->value('value'))->toBe('1332-58-7')
        ->and($ingredient->identifiers->where('scheme', 'ec')->value('value'))->toBe('310-194-1')
        ->and($ingredient->notes)->toBe('Fine cosmetic-grade green clay')
        ->and($ingredient->is_active)->toBeTrue();
});

it('lets a workspace manage bounded identity aliases and declared substances', function (): void {
    $this->seed(SupportedLocaleSeeder::class);
    SupportedLocale::query()->where('code', 'fr')->update(['is_active' => true]);
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $substance = Substance::factory()->create(['name' => 'Linalool']);
    $service = app(UserIngredientAuthoringService::class);

    $ingredient = $service->create([
        'name' => 'Lavender oil',
        'category' => IngredientCategory::AromaticMaterials->value,
        'inci_name' => 'LAVANDULA ANGUSTIFOLIA OIL',
        'cas_number' => '8000-28-0',
        'ec_number' => '289-995-2',
        'additional_identifiers' => [[
            'scheme' => 'unii',
            'value' => 'EXAMPLE123',
            'is_primary' => true,
        ]],
        'aliases' => [[
            'locale' => 'fr',
            'name' => 'Huile de lavande',
            'kind' => 'common',
        ]],
        'substance_entries' => [[
            'substance_id' => $substance->id,
            'concentration_percent' => 0.8,
        ]],
    ], $user);

    expect($service->formData($ingredient)['additional_identifiers'][0]['value'])->toBe('EXAMPLE123')
        ->and($ingredient->aliases->first()->locale)->toBe('fr')
        ->and((float) $ingredient->substanceEntries->first()->concentration_percent)->toBe(0.8);

    $ingredient->substanceEntries()->first()->update([
        'source_notes' => 'Supplier declaration',
        'source_data' => ['document' => 'supplier-sds.pdf'],
    ]);

    $state = $service->formData($ingredient->fresh());
    $state['additional_identifiers'][] = [
        'scheme' => 'echa_list',
        'value' => 'REACH-EXAMPLE',
        'is_primary' => false,
    ];
    $state['aliases'][0]['name'] = 'Huile essentielle de lavande';
    $state['substance_entries'][0]['concentration_percent'] = 1.2;

    $updated = $service->update($ingredient, $state, $user);

    expect($updated->identifiers)->toHaveCount(4)
        ->and($updated->aliases->first()->name)->toBe('Huile essentielle de lavande')
        ->and((float) $updated->substanceEntries->first()->concentration_percent)->toBe(1.2)
        ->and($updated->substanceEntries->first()->source_notes)->toBe('Supplier declaration')
        ->and($updated->substanceEntries->first()->source_data)->toBe(['document' => 'supplier-sds.pdf']);

    $service->update($updated, [
        ...$service->formData($updated),
        'cas_number' => null,
        'ec_number' => null,
        'additional_identifiers' => [],
        'aliases' => [],
        'substance_entries' => [],
    ], $user);

    expect($updated->fresh()->identifiers)->toHaveCount(0)
        ->and($updated->fresh()->aliases)->toHaveCount(0)
        ->and($updated->fresh()->substanceEntries)->toHaveCount(0);

    expect(fn () => $service->update($updated, $service->formData($updated), $otherUser))
        ->toThrow(ValidationException::class, 'cannot be edited');

    $platformIngredient = Ingredient::factory()->create(['owner_type' => null, 'owner_id' => null]);

    expect(fn () => $service->update($platformIngredient, $service->formData($platformIngredient), $user))
        ->toThrow(ValidationException::class, 'cannot be edited');
});

it('shows composition only when the user chooses a blend', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test(IngredientEditor::class)
        ->assertSet('data.ingredient_structure', 'ingredient')
        ->assertDontSee('Search by name or INCI')
        ->set('data.ingredient_structure', 'blend')
        ->assertSee('Search by name or INCI')
        ->assertSeeHtml('data-search-combobox="composition-ingredient-search"')
        ->assertSee('sk-combobox-control', false)
        ->assertSee('aria-autocomplete="list"', false)
        ->assertSee(':aria-activedescendant=', false)
        ->assertSee('Add a new ingredient')
        ->assertSee('quickComponentName', false)
        ->assertSee('quickComponentCategory', false)
        ->assertSee('Add ingredient');
});

it('does not let a manually created ingredient enable soap chemistry', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(IngredientEditor::class)
        ->set('data.is_soap_saponification_trusted', true)
        ->assertDontSee('Soap chemistry')
        ->assertSee('Compliance')
        ->set('data.requires_aromatic_compliance', true)
        ->assertSee('Compliance')
        ->assertDontSee('Soap chemistry');
});

it('saves a blend composition and its source from the custom editor rows', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $componentIngredient = Ingredient::factory()->create([
        'display_name' => 'Base Oil',
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
        'is_active' => true,
    ]);

    Livewire::test(IngredientEditor::class)
        ->set('data.name', 'My Blend')
        ->set('data.category', IngredientCategory::Lipids->value)
        ->set('data.ingredient_structure', 'blend')
        ->call('addComponent', $componentIngredient->id)
        ->set('data.components.0.percentage_in_parent', '100,0')
        ->set('data.composition_source_notes', 'Supplier blend spec')
        ->call('save')
        ->assertHasNoErrors();

    $workspace = $user->refresh()->company();
    $blend = Ingredient::query()
        ->where('owner_type', OwnerType::Workspace)
        ->where('owner_id', $workspace?->id)
        ->where('display_name', 'My Blend')
        ->first();

    expect($blend)->not->toBeNull()
        ->and($blend->components)->toHaveCount(1)
        ->and($blend->components->first()->component_ingredient_id)->toBe($componentIngredient->id)
        ->and((float) $blend->components->first()->percentage_in_parent)->toBe(100.0)
        ->and($blend->composition_source_notes)->toBe('Supplier blend spec');
});

it('shows an immediate error when a component share is outside the allowed range', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $componentIngredient = Ingredient::factory()->create([
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
        'is_active' => true,
    ]);

    Livewire::test(IngredientEditor::class)
        ->set('data.ingredient_structure', 'blend')
        ->call('addComponent', $componentIngredient->id)
        ->set('data.components.0.percentage_in_parent', '100,1')
        ->assertHasErrors(['data.components.0.percentage_in_parent'])
        ->set('data.components.0.percentage_in_parent', '100')
        ->assertHasNoErrors(['data.components.0.percentage_in_parent']);
});

it('calculates composition totals with the server locale parser', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $component = Livewire::test(IngredientEditor::class)
        ->set('data.components', [
            ['percentage_in_parent' => '4 0,5'],
            ['percentage_in_parent' => '59,5'],
        ]);

    expect($component->instance()->componentPercentageTotal())->toBe(100.0);
});

it('quick creates an active private ingredient and immediately adds it to the composition', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(IngredientEditor::class)
        ->set('data.ingredient_structure', 'blend')
        ->set('quickComponentName', 'Calendula Flowers')
        ->set('quickComponentCategory', IngredientCategory::BotanicalsExtracts->value)
        ->call('createAndAddComponent')
        ->assertHasNoErrors()
        ->assertSet('quickComponentName', '')
        ->assertSet('quickComponentCategory', null)
        ->assertSet('data.components.0.percentage_in_parent', null);

    $component = Ingredient::query()
        ->where('display_name', 'Calendula Flowers')
        ->sole();

    expect($component->owner_id)->toBe($user->id)
        ->and($component->visibility)->toBe(Visibility::Private)
        ->and($component->is_active)->toBeTrue();
});

it('keeps quick create values when required data is missing', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(IngredientEditor::class)
        ->set('quickComponentName', 'Calendula Flowers')
        ->call('createAndAddComponent')
        ->assertHasErrors(['quickComponentCategory' => 'required'])
        ->assertSet('quickComponentName', 'Calendula Flowers');

    expect(Ingredient::query()->where('display_name', 'Calendula Flowers')->exists())->toBeFalse();
});

it('shows the plan limit when quick ingredient creation is rejected', function () {
    $user = User::factory()->create();
    $plan = Plan::factory()
        ->hasLimit('private_ingredients', 20)
        ->create(['is_default' => true]);

    $user->entitlements()->create([
        'plan_id' => $plan->id,
        'status' => 'active',
        'starts_at' => now(),
    ]);

    Ingredient::factory()
        ->count(20)
        ->create([
            'owner_type' => OwnerType::User,
            'owner_id' => $user->id,
            'visibility' => Visibility::Private,
        ]);

    $this->actingAs($user);

    Livewire::test(IngredientEditor::class)
        ->set('data.ingredient_structure', 'blend')
        ->set('quickComponentName', 'Calendula Flowers')
        ->set('quickComponentCategory', IngredientCategory::BotanicalsExtracts->value)
        ->call('createAndAddComponent')
        ->assertHasErrors(['plan'])
        ->assertSee('Your current plan allows 20 private ingredients.');

    expect(Ingredient::query()->where('display_name', 'Calendula Flowers')->exists())->toBeFalse();
});

it('does not quick create an ingredient when the composition is full', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(IngredientEditor::class)
        ->set('data.components', array_fill(0, 20, [
            'component_ingredient_id' => 1,
            'percentage_in_parent' => 5,
        ]))
        ->set('quickComponentName', 'Overflow Ingredient')
        ->set('quickComponentCategory', IngredientCategory::Other->value)
        ->call('createAndAddComponent')
        ->assertHasErrors(['data.components']);

    expect(Ingredient::query()->where('display_name', 'Overflow Ingredient')->exists())->toBeFalse();
});

it('rejects an empty blend and components inaccessible to the author', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $privateIngredient = Ingredient::factory()->create([
        'owner_type' => OwnerType::User,
        'owner_id' => $otherUser->id,
        'visibility' => Visibility::Private,
    ]);

    $service = app(UserIngredientAuthoringService::class);

    expect(fn () => $service->create([
        'name' => 'Empty Blend',
        'category' => IngredientCategory::Other->value,
        'ingredient_structure' => 'blend',
        'components' => [],
    ], $user))->toThrow(ValidationException::class, 'Add at least one ingredient');

    expect(fn () => $service->create([
        'name' => 'Tampered Blend',
        'category' => IngredientCategory::Other->value,
        'ingredient_structure' => 'blend',
        'components' => [[
            'component_ingredient_id' => $privateIngredient->id,
            'percentage_in_parent' => 100,
        ]],
    ], $user))->toThrow(ValidationException::class, 'no longer available to you');
});

it('rejects inactive blend components during server-side persistence validation', function () {
    $user = User::factory()->create();
    $inactiveIngredient = Ingredient::factory()->create([
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
        'is_active' => false,
    ]);

    expect(fn () => app(UserIngredientAuthoringService::class)->create([
        'name' => 'Inactive Component Blend',
        'category' => IngredientCategory::Other->value,
        'ingredient_structure' => 'blend',
        'components' => [[
            'component_ingredient_id' => $inactiveIngredient->id,
            'percentage_in_parent' => 100,
        ]],
    ], $user))->toThrow(ValidationException::class, 'no longer available to you');
});

it('persists the parent allergen declaration source for aromatic user ingredients', function () {
    $user = User::factory()->create();
    $allergen = Allergen::factory()->create(['inci_name' => 'LINALOOL']);

    $ingredient = app(UserIngredientAuthoringService::class)->create([
        'name' => 'Lavender EO',
        'category' => IngredientCategory::AromaticMaterials->value,
        'requires_aromatic_compliance' => true,
        'inci_name' => 'LAVANDULA ANGUSTIFOLIA OIL',
        'allergen_source_notes' => 'IFRA allergen statement',
        'allergen_entries' => [
            ['allergen_id' => $allergen->id, 'concentration_percent' => 1.0],
        ],
        'ifra' => ['limits' => []],
    ], $user);

    expect($ingredient->allergen_source_notes)->toBe('IFRA allergen statement')
        ->and($ingredient->allergenEntries)->toHaveCount(1);
});

it('persists an optional ingredient icon separately from the main image', function () {
    $user = User::factory()->create();
    $ingredient = app(UserIngredientAuthoringService::class)->create([
        'name' => 'Green Clay',
        'category' => IngredientCategory::MineralsSaltsPowders->value,
        'featured_image_path' => 'ingredients/featured-images/green-clay.webp',
        'featured_image_original_name' => 'Green clay portrait.webp',
        'icon_image_path' => 'ingredients/icons/green-clay-icon.webp',
        'icon_image_original_name' => 'Green clay icon.webp',
    ], $user);

    expect($ingredient->fresh())
        ->featured_image_path->toBe('ingredients/featured-images/green-clay.webp')
        ->featured_image_original_name->toBe('Green clay portrait.webp')
        ->icon_image_path->toBe('ingredients/icons/green-clay-icon.webp')
        ->icon_image_original_name->toBe('Green clay icon.webp');

    $updated = app(UserIngredientAuthoringService::class)->update($ingredient, [
        'name' => 'French Green Clay',
        'category' => IngredientCategory::MineralsSaltsPowders->value,
        'featured_image_path' => 'ingredients/featured-images/french-green-clay.webp',
        'featured_image_original_name' => 'French green clay portrait.webp',
        'icon_image_path' => 'ingredients/icons/french-green-clay-icon.webp',
        'icon_image_original_name' => 'French green clay icon.webp',
    ], $user);

    expect($updated->fresh())
        ->featured_image_original_name->toBe('French green clay portrait.webp')
        ->icon_image_original_name->toBe('French green clay icon.webp');
});

it('falls back to the main ingredient image when no icon exists for picker surfaces', function () {
    $ingredient = Ingredient::factory()->make([
        'featured_image_path' => 'ingredients/featured-images/green-clay.webp',
        'icon_image_path' => null,
    ]);

    expect($ingredient->pickerImageUrl())->toBe($ingredient->featuredImageUrl());
});

it('persists optional allergen and current ifra data for aromatic user ingredients', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $linalool = Allergen::factory()->create(['inci_name' => 'LINALOOL']);
    $limonene = Allergen::factory()->create(['inci_name' => 'LIMONENE']);
    $perfuming = IngredientFunction::factory()->create([
        'key' => 'perfuming',
        'name' => 'Perfuming',
        'sort_order' => 10,
    ]);
    $skinConditioning = IngredientFunction::factory()->create([
        'key' => 'skin_conditioning',
        'name' => 'Skin conditioning',
        'sort_order' => 20,
    ]);
    $category3 = IfraProductCategory::factory()->create([
        'code' => '3',
        'name' => 'Soap products',
        'is_active' => true,
    ]);
    $amendment = IfraAmendment::factory()->create(['code' => '51']);

    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::AromaticMaterials,
        'display_name' => 'Rose Essential Oil',
        'inci_name' => 'ROSA DAMASCENA FLOWER OIL',
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
        'catalog_key' => 'USR-EO',
        'requires_aromatic_compliance' => true,
    ]);

    Livewire::test(IngredientEditor::class, ['ingredient' => $ingredient])
        ->set('data.name', 'Rose Essential Oil')
        ->set('data.category', IngredientCategory::AromaticMaterials->value)
        ->set('data.inci_name', 'ROSA DAMASCENA FLOWER OIL')
        ->set('data.function_ids', [$skinConditioning->id, $perfuming->id])
        ->set('data.allergen_entries', [
            [
                'allergen_id' => $linalool->id,
                'concentration_percent' => '0,42',
                'source_notes' => null,
            ],
            [
                'allergen_id' => $limonene->id,
                'concentration_percent' => '0,08',
                'source_notes' => 'Trace supplier declaration',
            ],
        ])
        ->set('data.ifra.reference_label', 'Current supplier IFRA')
        ->set('data.ifra.ifra_amendment_id', $amendment->id)
        ->set('data.ifra.peroxide_value', '2,5')
        ->set('data.ifra.source_notes', 'Indicative only')
        ->set('data.ifra.limits', [
            [
                'ifra_product_category_id' => $category3->id,
                'max_percentage' => '0,8',
                'restriction_note' => 'Rinse-off reference',
            ],
        ])
        ->call('save');

    $freshIngredient = $ingredient->fresh(['allergenEntries', 'functions', 'ifraCertificates.limits']);
    $currentIfra = $freshIngredient?->ifraCertificates->first();

    expect($freshIngredient?->allergenEntries)->toHaveCount(2)
        ->and($freshIngredient?->functions)->toHaveCount(2)
        ->and($freshIngredient?->functions->pluck('id')->all())->toEqual([$perfuming->id, $skinConditioning->id])
        ->and($freshIngredient?->allergenEntries->pluck('allergen_id')->all())->toEqualCanonicalizing([$linalool->id, $limonene->id])
        ->and($currentIfra?->ifra_amendment_id)->toBe($amendment->id)
        ->and((float) $currentIfra?->peroxide_value)->toBe(2.5)
        ->and($currentIfra?->limits)->toHaveCount(1)
        ->and((float) $currentIfra?->limits->first()->max_percentage)->toBe(0.8);
});

it('accepts comma decimals throughout user soap chemistry fields', function () {
    $user = User::factory()->create();
    $fattyAcid = FattyAcid::factory()->create(['is_active' => true]);
    $source = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'display_name' => 'Platform chemistry oil',
        'owner_type' => null,
        'owner_id' => null,
        'is_soap_saponification_trusted' => true,
    ]);
    $source->sapProfile()->create(['koh_sap_value' => 0.188]);
    $source->fattyAcidEntries()->create([
        'fatty_acid_id' => $fattyAcid->id,
        'percentage' => 80.2,
    ]);
    $ingredient = app(UserIngredientAuthoringService::class)->duplicate($source, $user);

    $this->actingAs($user);

    Livewire::test(IngredientEditor::class, ['ingredient' => $ingredient])
        ->set('data.sap_profile.koh_sap_value', '0,188')
        ->set('data.sap_profile.iodine_value', '86,4')
        ->set('data.sap_profile.ins_value', '102,8')
        ->set('data.fatty_acid_entries', [[
            'fatty_acid_id' => $fattyAcid->id,
            'percentage' => '80,2',
        ]])
        ->call('save')
        ->assertHasNoErrors();

    $freshIngredient = $ingredient->fresh(['sapProfile', 'fattyAcidEntries']);

    expect((float) $freshIngredient->sapProfile->koh_sap_value)->toBe(0.188)
        ->and((float) $freshIngredient->sapProfile->iodine_value)->toBe(86.4)
        ->and((float) $freshIngredient->sapProfile->ins_value)->toBe(102.8)
        ->and((float) $freshIngredient->fattyAcidEntries->first()->percentage)->toBe(80.2);
});

it('derives the same NaOH SAP from decimal and professional KOH notation', function () {
    $user = User::factory()->create(['number_locale' => 'fr_FR']);
    $source = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'owner_type' => null,
        'owner_id' => null,
        'is_soap_saponification_trusted' => true,
    ]);
    $source->sapProfile()->create(['koh_sap_value' => 0.176]);
    $ingredient = app(UserIngredientAuthoringService::class)->duplicate($source, $user);

    $this->actingAs($user);

    Livewire::test(IngredientEditor::class, ['ingredient' => $ingredient])
        ->set('data.sap_profile.koh_sap_value', '0,176')
        ->assertSee('0.125488')
        ->set('data.sap_profile.koh_sap_value', '0.176')
        ->assertSee('0.125488')
        ->set('data.sap_profile.koh_sap_value', '176')
        ->assertSee('0.125488')
        ->call('save')
        ->assertHasNoErrors();

    expect((float) $ingredient->fresh('sapProfile')->sapProfile->koh_sap_value)->toBe(0.176);
});

it('returns professional KOH notation to the canonical decimal scale', function () {
    $user = User::factory()->create();
    $source = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'owner_type' => null,
        'owner_id' => null,
        'is_soap_saponification_trusted' => true,
    ]);
    $source->sapProfile()->create(['koh_sap_value' => 0.18]);
    $ingredient = app(UserIngredientAuthoringService::class)->duplicate($source, $user);

    $this->actingAs($user);

    Livewire::test(IngredientEditor::class, ['ingredient' => $ingredient])
        ->set('data.sap_profile.koh_sap_value', '180')
        ->assertSet('data.sap_profile.koh_sap_value', '0.180');
});

it('keeps invalid KOH input visible so validation can explain it', function () {
    $user = User::factory()->create();
    $source = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'owner_type' => null,
        'owner_id' => null,
        'is_soap_saponification_trusted' => true,
    ]);
    $source->sapProfile()->create(['koh_sap_value' => 0.18]);
    $ingredient = app(UserIngredientAuthoringService::class)->duplicate($source, $user);

    $this->actingAs($user);

    Livewire::test(IngredientEditor::class, ['ingredient' => $ingredient])
        ->set('data.sap_profile.koh_sap_value', 'not-a-number')
        ->assertSet('data.sap_profile.koh_sap_value', 'not-a-number')
        ->call('save')
        ->assertHasErrors(['data.sap_profile.koh_sap_value']);
});

it('shows one live fatty acid profile total without repeating the total rule on every row', function () {
    $user = User::factory()->create();
    $oleic = FattyAcid::factory()->create(['name' => 'Oleic', 'is_active' => true]);
    $lauric = FattyAcid::factory()->create(['name' => 'Lauric', 'is_active' => true]);
    $source = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'owner_type' => null,
        'is_soap_saponification_trusted' => true,
    ]);
    $source->sapProfile()->create(['koh_sap_value' => 0.18]);
    $source->fattyAcidEntries()->createMany([
        ['fatty_acid_id' => $oleic->id, 'percentage' => 60],
        ['fatty_acid_id' => $lauric->id, 'percentage' => 20],
    ]);
    $copy = app(UserIngredientAuthoringService::class)->duplicate($source, $user);

    $this->actingAs($user);

    Livewire::test(IngredientEditor::class, ['ingredient' => $copy])
        ->assertSee('Fatty acid total')
        ->assertSee('80.0%')
        ->assertSee('Recommended total: 80–100%')
        ->assertSee('Allowed: 48.0%–72.0%.')
        ->assertDontSee('The complete profile must total 80%–100%.')
        ->set('data.fatty_acid_entries', [
            ['fatty_acid_id' => $oleic->id, 'percentage' => '60,5'],
            ['fatty_acid_id' => $lauric->id, 'percentage' => '24,5'],
        ])
        ->assertSee('85.0%');
});

it('presents fatty acid entries to one decimal without changing untouched stored precision', function () {
    $user = User::factory()->create();
    $fattyAcid = FattyAcid::factory()->create(['is_active' => true]);
    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
    ]);
    $ingredient->fattyAcidEntries()->create([
        'fatty_acid_id' => $fattyAcid->id,
        'percentage' => 0.25,
    ]);

    $this->actingAs($user);

    $component = Livewire::test(IngredientEditor::class, ['ingredient' => $ingredient]);
    $row = collect($component->get('data.fatty_acid_entries'))->first();

    expect((float) $row['percentage'])->toBe(0.3)
        ->and((float) $row['_original_percentage'])->toBe(0.25);

    $component
        ->set('data.name', 'Renamed oil')
        ->call('save')
        ->assertHasNoErrors();

    expect((float) $ingredient->fresh('fattyAcidEntries')->fattyAcidEntries->first()->percentage)->toBe(0.25);
});

it('shows trusted KOH validation errors in the customer ingredient form without partially saving', function () {
    $user = User::factory()->create();
    $source = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'display_name' => 'Platform olive oil',
        'owner_type' => null,
        'is_soap_saponification_trusted' => true,
    ]);
    $source->sapProfile()->create(['koh_sap_value' => 0.188]);
    $copy = app(UserIngredientAuthoringService::class)->duplicate($source, $user);

    $this->actingAs($user);

    Livewire::test(IngredientEditor::class, ['ingredient' => $copy])
        ->set('data.name', 'Should not persist')
        ->set('data.sap_profile.koh_sap_value', '0.195')
        ->call('save')
        ->assertHasErrors(['data.sap_profile.koh_sap_value'])
        ->assertSee('Allowed KOH SAP range');

    expect($copy->fresh()->display_name)->toBe('Platform olive oil');
});

it('creates missing composite components as private ingredients before they are referenced', function () {
    $user = User::factory()->create();
    $service = app(UserIngredientAuthoringService::class);

    $component = $service->createInlineComponent([
        'name' => 'Calendula Flowers',
        'category' => IngredientCategory::Other->value,
        'inci_name' => 'CALENDULA OFFICINALIS FLOWER',
    ], $user);

    $macerate = $service->create([
        'name' => 'Calendula Macerate',
        'category' => IngredientCategory::Lipids->value,
        'inci_name' => 'HELIANTHUS ANNUUS SEED OIL, CALENDULA OFFICINALIS FLOWER',
        'components' => [
            [
                'component_ingredient_id' => $component->id,
                'percentage_in_parent' => 100,
                'source_notes' => 'Botanical fraction',
            ],
        ],
    ], $user);

    $workspace = $user->refresh()->company();

    expect($component->owner_type)->toBe(OwnerType::Workspace)
        ->and($component->owner_id)->toBe($workspace?->id)
        ->and($component->visibility)->toBe(Visibility::Private)
        ->and($component->is_active)->toBeTrue()
        ->and($macerate->components)->toHaveCount(1)
        ->and($macerate->components->first()->component_ingredient_id)->toBe($component->id);
});

it('keeps user carrier oils out of the soap saponification lane', function () {
    $user = User::factory()->create();

    $ingredient = app(UserIngredientAuthoringService::class)->create([
        'name' => 'My experimental oil',
        'category' => IngredientCategory::Lipids->value,
        'inci_name' => 'EXPERIMENTAL OIL',
        'sap_profile' => [
            'koh_sap_value' => 0.188,
            'iodine_value' => 86.4,
            'ins_value' => 102.8,
        ],
        'fatty_acid_entries' => [],
    ], $user);

    expect($ingredient->is_soap_saponification_trusted)->toBeFalse()
        ->and($ingredient->availableWorkbenchPhases())
        ->toContain('additives')
        ->not->toContain('saponified_oils');
});

it('preserves entered CAS and EC identifier values when saving a user ingredient', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'display_name' => 'Olive oil virgin',
        'inci_name' => 'Olea europaea fruit oil',
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
        'catalog_key' => 'USR-OLIVE',
        'is_soap_saponification_trusted' => true,
    ]);
    $ingredient->identifiers()->createMany([
        ['scheme' => 'cas', 'value' => '8001-25-00', 'normalized_value' => '8001-25-00', 'is_primary' => true],
        ['scheme' => 'ec', 'value' => '232-277-00', 'normalized_value' => '232-277-00', 'is_primary' => true],
    ]);

    Livewire::test(IngredientEditor::class, ['ingredient' => $ingredient])
        ->set('data.name', 'Olive oil virgin')
        ->set('data.category', IngredientCategory::Lipids->value)
        ->set('data.inci_name', 'Olea europaea fruit oil')
        ->set('data.cas_number', '8001-25-00')
        ->set('data.ec_number', '232-277-00')
        ->call('save')
        ->assertHasNoErrors();

    $ingredient->refresh();

    $ingredient->load('identifiers');

    expect($ingredient->identifiers->where('scheme', 'cas')->value('value'))->toBe('8001-25-00')
        ->and($ingredient->identifiers->where('scheme', 'ec')->value('value'))->toBe('232-277-00');
});

it('deletes replaced ingredient media from storage during update', function () {
    Storage::fake('public');

    config([
        'media.disk' => 'public',
        'media.visibility' => 'public',
    ]);

    $user = User::factory()->create();
    $ingredient = Ingredient::factory()->create([
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
        'featured_image_path' => 'ingredients/featured-images/original.webp',
        'featured_image_original_name' => 'Original ingredient portrait.webp',
        'icon_image_path' => 'ingredients/icons/original-icon.webp',
        'icon_image_original_name' => 'Original ingredient icon.webp',
    ]);

    Storage::disk('public')->put('ingredients/featured-images/original.webp', 'old-image');
    Storage::disk('public')->put('ingredients/icons/original-icon.webp', 'old-icon');

    $updated = app(UserIngredientAuthoringService::class)->update($ingredient, [
        'name' => $ingredient->display_name,
        'category' => $ingredient->category->value,
        'featured_image_path' => null,
        'featured_image_original_name' => 'Stale ingredient portrait.webp',
        'icon_image_path' => null,
        'icon_image_original_name' => 'Stale ingredient icon.webp',
    ], $user);

    expect(Storage::disk('public')->exists('ingredients/featured-images/original.webp'))->toBeFalse()
        ->and(Storage::disk('public')->exists('ingredients/icons/original-icon.webp'))->toBeFalse()
        ->and($updated->featured_image_path)->toBeNull()
        ->and($updated->featured_image_original_name)->toBeNull()
        ->and($updated->icon_image_path)->toBeNull()
        ->and($updated->icon_image_original_name)->toBeNull();
});
