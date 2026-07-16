<?php

use App\IngredientCategory;
use App\Livewire\Dashboard\IngredientEditor;
use App\Models\Allergen;
use App\Models\FattyAcid;
use App\Models\IfraProductCategory;
use App\Models\Ingredient;
use App\Models\IngredientFunction;
use App\Models\Plan;
use App\Models\User;
use App\OwnerType;
use App\Services\UserIngredientAuthoringService;
use App\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('creates a minimal private user ingredient from the public editor', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $component = Livewire::test(IngredientEditor::class);

    expect(method_exists($component->instance(), 'mountAction'))->toBeTrue();

    $component->assertSee('Carrier oils and soap calculation');

    $component
        ->set('data.name', 'French Green Clay')
        ->set('data.category', IngredientCategory::Clay->value)
        ->set('data.inci_name', 'ILLITE')
        ->set('data.cas_number', '1332-58-7')
        ->set('data.ec_number', '310-194-1')
        ->set('data.is_organic', true)
        ->call('save');

    $workspace = $user->refresh()->company();
    $ingredient = Ingredient::query()
        ->where('owner_type', OwnerType::Workspace)
        ->where('owner_id', $workspace?->id)
        ->first();

    expect($ingredient)->not->toBeNull()
        ->and($ingredient->visibility)->toBe(Visibility::Private)
        ->and($ingredient->is_potentially_saponifiable)->toBeFalse()
        ->and($ingredient->display_name)->toBe('French Green Clay')
        ->and($ingredient->inci_name)->toBe('ILLITE')
        ->and($ingredient->cas_number)->toBe('1332-58-7')
        ->and($ingredient->ec_number)->toBe('310-194-1')
        ->and($ingredient->is_organic)->toBeTrue()
        ->and($ingredient->is_active)->toBeTrue();
});

it('shows composition only when the user chooses a blend', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test(IngredientEditor::class)
        ->assertSet('data.ingredient_structure', 'ingredient')
        ->assertDontSee('Search ingredient by name or INCI')
        ->set('data.ingredient_structure', 'blend')
        ->assertSee('Search ingredient by name or INCI')
        ->assertSeeHtml('data-search-combobox="composition-ingredient-search"')
        ->assertSee('sk-combobox-control', false)
        ->assertSee('aria-autocomplete="list"', false)
        ->assertSee(':aria-activedescendant=', false)
        ->assertSee('Create ingredient')
        ->assertSee('quickComponentName', false)
        ->assertSee('quickComponentCategory', false)
        ->assertSee('Create and add');
});

it('shows category-specific tabs while creating an ingredient', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(IngredientEditor::class)
        ->set('data.category', IngredientCategory::CarrierOil->value)
        ->assertSee('Soap Chemistry')
        ->assertDontSee('Compliance')
        ->set('data.category', IngredientCategory::EssentialOil->value)
        ->assertSee('Compliance')
        ->assertDontSee('Soap Chemistry');
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
        ->set('data.category', IngredientCategory::CarrierOil->value)
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
        ->set('quickComponentCategory', IngredientCategory::BotanicalExtract->value)
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
        ->set('quickComponentCategory', IngredientCategory::BotanicalExtract->value)
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
        ->set('quickComponentCategory', IngredientCategory::Additive->value)
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
        'category' => IngredientCategory::Additive->value,
        'ingredient_structure' => 'blend',
        'components' => [],
    ], $user))->toThrow(ValidationException::class, 'Add at least one component');

    expect(fn () => $service->create([
        'name' => 'Tampered Blend',
        'category' => IngredientCategory::Additive->value,
        'ingredient_structure' => 'blend',
        'components' => [[
            'component_ingredient_id' => $privateIngredient->id,
            'percentage_in_parent' => 100,
        ]],
    ], $user))->toThrow(ValidationException::class, 'not available to you');
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
        'category' => IngredientCategory::Additive->value,
        'ingredient_structure' => 'blend',
        'components' => [[
            'component_ingredient_id' => $inactiveIngredient->id,
            'percentage_in_parent' => 100,
        ]],
    ], $user))->toThrow(ValidationException::class, 'not available to you');
});

it('persists the parent allergen declaration source for aromatic user ingredients', function () {
    $user = User::factory()->create();
    $allergen = Allergen::factory()->create(['inci_name' => 'LINALOOL']);

    $ingredient = app(UserIngredientAuthoringService::class)->create([
        'name' => 'Lavender EO',
        'category' => IngredientCategory::EssentialOil->value,
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
        'category' => IngredientCategory::Clay->value,
        'featured_image_path' => 'ingredients/featured-images/green-clay.webp',
        'icon_image_path' => 'ingredients/icons/green-clay-icon.webp',
    ], $user);

    expect($ingredient->featured_image_path)->toBe('ingredients/featured-images/green-clay.webp')
        ->and($ingredient->icon_image_path)->toBe('ingredients/icons/green-clay-icon.webp');
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

    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::EssentialOil,
        'display_name' => 'Rose Essential Oil',
        'inci_name' => 'ROSA DAMASCENA FLOWER OIL',
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
        'source_file' => 'user',
        'source_key' => 'USR-EO',
    ]);

    Livewire::test(IngredientEditor::class, ['ingredient' => $ingredient])
        ->set('data.name', 'Rose Essential Oil')
        ->set('data.category', IngredientCategory::EssentialOil->value)
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
        ->set('data.ifra.ifra_amendment', '51')
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
        ->and($currentIfra?->ifra_amendment)->toBe('51')
        ->and((float) $currentIfra?->peroxide_value)->toBe(2.5)
        ->and($currentIfra?->limits)->toHaveCount(1)
        ->and((float) $currentIfra?->limits->first()->max_percentage)->toBe(0.8);
});

it('accepts comma decimals throughout user soap chemistry fields', function () {
    $user = User::factory()->create();
    $fattyAcid = FattyAcid::factory()->create(['is_active' => true]);
    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::CarrierOil,
        'display_name' => 'User chemistry oil',
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
        'is_potentially_saponifiable' => true,
    ]);

    $this->actingAs($user);

    Livewire::test(IngredientEditor::class, ['ingredient' => $ingredient])
        ->set('data.sap_profile.koh_sap_value', '0,188')
        ->set('data.sap_profile.iodine_value', '86,4')
        ->set('data.sap_profile.ins_value', '102,8')
        ->set('data.fatty_acid_entries', [[
            'fatty_acid_id' => $fattyAcid->id,
            'percentage' => '0,2',
        ]])
        ->call('save')
        ->assertHasNoErrors();

    $freshIngredient = $ingredient->fresh(['sapProfile', 'fattyAcidEntries']);

    expect((float) $freshIngredient->sapProfile->koh_sap_value)->toBe(0.188)
        ->and((float) $freshIngredient->sapProfile->iodine_value)->toBe(86.4)
        ->and((float) $freshIngredient->sapProfile->ins_value)->toBe(102.8)
        ->and((float) $freshIngredient->fattyAcidEntries->first()->percentage)->toBe(0.2);
});

it('derives the same NaOH SAP from decimal and professional KOH notation', function () {
    $user = User::factory()->create(['number_locale' => 'fr_FR']);
    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::CarrierOil,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
        'is_potentially_saponifiable' => true,
    ]);

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
    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::CarrierOil,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
        'is_potentially_saponifiable' => true,
    ]);

    $this->actingAs($user);

    Livewire::test(IngredientEditor::class, ['ingredient' => $ingredient])
        ->set('data.sap_profile.koh_sap_value', '180')
        ->assertSet('data.sap_profile.koh_sap_value', '0.180');
});

it('keeps invalid KOH input visible so validation can explain it', function () {
    $user = User::factory()->create();
    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::CarrierOil,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
    ]);

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
        'category' => IngredientCategory::CarrierOil,
        'owner_type' => null,
        'is_potentially_saponifiable' => true,
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
        ->assertSee('Target: 80% to 100%')
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
        'category' => IngredientCategory::CarrierOil,
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
        'category' => IngredientCategory::CarrierOil,
        'display_name' => 'Platform olive oil',
        'owner_type' => null,
        'is_potentially_saponifiable' => true,
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
        'category' => IngredientCategory::Additive->value,
        'inci_name' => 'CALENDULA OFFICINALIS FLOWER',
        'supplier_name' => 'Local supplier',
        'supplier_reference' => 'CAL-001',
    ], $user);

    $macerate = $service->create([
        'name' => 'Calendula Macerate',
        'category' => IngredientCategory::CarrierOil->value,
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
        'category' => IngredientCategory::CarrierOil->value,
        'inci_name' => 'EXPERIMENTAL OIL',
        'sap_profile' => [
            'koh_sap_value' => 0.188,
            'iodine_value' => 86.4,
            'ins_value' => 102.8,
        ],
        'fatty_acid_entries' => [],
    ], $user);

    expect($ingredient->is_potentially_saponifiable)->toBeFalse()
        ->and($ingredient->availableWorkbenchPhases())
        ->toContain('additives')
        ->not->toContain('saponified_oils');
});

it('normalizes imported CAS and EC check digit padding when saving a user ingredient', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::CarrierOil,
        'display_name' => 'Olive oil virgin',
        'inci_name' => 'Olea europaea fruit oil',
        'cas_number' => '8001-25-00',
        'ec_number' => '232-277-00',
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
        'source_file' => 'user',
        'source_key' => 'USR-OLIVE',
        'is_potentially_saponifiable' => true,
    ]);

    Livewire::test(IngredientEditor::class, ['ingredient' => $ingredient])
        ->set('data.name', 'Olive oil virgin')
        ->set('data.category', IngredientCategory::CarrierOil->value)
        ->set('data.inci_name', 'Olea europaea fruit oil')
        ->set('data.cas_number', '8001-25-00')
        ->set('data.ec_number', '232-277-00')
        ->call('save')
        ->assertHasNoErrors();

    $ingredient->refresh();

    expect($ingredient->cas_number)->toBe('8001-25-0')
        ->and($ingredient->ec_number)->toBe('232-277-0');
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
        'icon_image_path' => 'ingredients/icons/original-icon.webp',
    ]);

    Storage::disk('public')->put('ingredients/featured-images/original.webp', 'old-image');
    Storage::disk('public')->put('ingredients/icons/original-icon.webp', 'old-icon');

    $updated = app(UserIngredientAuthoringService::class)->update($ingredient, [
        'name' => $ingredient->display_name,
        'category' => $ingredient->category->value,
        'featured_image_path' => null,
        'icon_image_path' => null,
    ], $user);

    expect(Storage::disk('public')->exists('ingredients/featured-images/original.webp'))->toBeFalse()
        ->and(Storage::disk('public')->exists('ingredients/icons/original-icon.webp'))->toBeFalse()
        ->and($updated->featured_image_path)->toBeNull()
        ->and($updated->icon_image_path)->toBeNull();
});
