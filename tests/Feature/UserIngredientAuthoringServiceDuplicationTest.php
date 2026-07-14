<?php

use App\IngredientCategory;
use App\Models\Allergen;
use App\Models\FattyAcid;
use App\Models\IfraProductCategory;
use App\Models\Ingredient;
use App\Models\IngredientFunction;
use App\Models\User;
use App\OwnerType;
use App\Services\UserIngredientAuthoringService;
use App\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('duplicates a platform ingredient into a user-owned copy with all data except images', function () {
    $user = User::factory()->create();
    $function = IngredientFunction::factory()->create(['is_active' => true]);
    $allergen = Allergen::factory()->create();
    $ifraCategory = IfraProductCategory::factory()->create(['is_active' => true]);

    $source = Ingredient::factory()->create([
        'category' => IngredientCategory::EssentialOil,
        'display_name' => 'Lavender 40/42',
        'inci_name' => 'LAVANDULA ANGUSTIFOLIA OIL',
        'supplier_name' => 'Supplier A',
        'supplier_reference' => 'REF-123',
        'cas_number' => '8000-28-0',
        'ec_number' => '289-995-2',
        'is_organic' => true,
        'owner_type' => null,
        'owner_id' => null,
        'visibility' => Visibility::Public,
        'is_potentially_saponifiable' => false,
        'featured_image_path' => 'ingredients/featured-images/lavender.webp',
        'icon_image_path' => 'ingredients/icons/lavender.webp',
        'info_markdown' => 'A popular essential oil.',
        'is_active' => true,
    ]);
    $source->sapProfile()->create(['koh_sap_value' => 0.188]);

    $source->functions()->sync([$function->id]);
    $source->allergenEntries()->create([
        'allergen_id' => $allergen->id,
        'concentration_percent' => 2.5,
        'source_notes' => 'Supplier spec',
    ]);
    $source->ifraCertificates()->create([
        'certificate_name' => 'Lavender IFRA',
        'ifra_amendment' => '50th',
        'peroxide_value' => 12.0,
        'source_notes' => 'Certificate data',
        'is_current' => true,
    ])->limits()->create([
        'ifra_product_category_id' => $ifraCategory->id,
        'max_percentage' => 5.0,
        'restriction_note' => 'Standard limit',
    ]);

    $service = app(UserIngredientAuthoringService::class);
    $copy = $service->duplicate($source, $user);

    expect($copy->owner_type)->toBe(OwnerType::User);
    expect($copy->owner_id)->toBe($user->id);
    expect($copy->visibility)->toBe(Visibility::Private);
    expect($copy->display_name)->toBe('Lavender 40/42');
    expect($copy->inci_name)->toBe('LAVANDULA ANGUSTIFOLIA OIL');
    expect($copy->supplier_name)->toBe('Supplier A');
    expect($copy->cas_number)->toBe('8000-28-0');
    expect($copy->is_organic)->toBeTrue();
    expect($copy->featured_image_path)->toBeNull();
    expect($copy->icon_image_path)->toBeNull();
    expect($copy->info_markdown)->toBe('A popular essential oil.');
    expect($copy->is_active)->toBeTrue();
    expect($copy->source_file)->toBe('user');
    expect($copy->id)->not->toBe($source->id);

    $copy->load(['functions', 'allergenEntries', 'ifraCertificates.limits']);
    expect($copy->functions)->toHaveCount(1);
    expect($copy->functions->first()->id)->toBe($function->id);
    expect($copy->allergenEntries)->toHaveCount(1);
    expect($copy->allergenEntries->first()->allergen_id)->toBe($allergen->id);
    expect((float) $copy->allergenEntries->first()->concentration_percent)->toBe(2.5);
    expect($copy->ifraCertificates)->toHaveCount(1);
    expect($copy->ifraCertificates->first()->limits)->toHaveCount(1);

    // Original is unchanged
    expect($source->fresh()->owner_type)->toBeNull();
    expect(Ingredient::query()->count())->toBe(2);
});

it('duplicates a carrier oil with SAP profile and fatty acids', function () {
    $user = User::factory()->create();
    $oleic = FattyAcid::factory()->create(['key' => 'oleic', 'name' => 'Oleic']);
    $palmitic = FattyAcid::factory()->create(['key' => 'palmitic', 'name' => 'Palmitic']);

    $source = Ingredient::factory()->create([
        'category' => IngredientCategory::CarrierOil,
        'display_name' => 'Olive Oil',
        'cas_number' => '8001-25-00',
        'ec_number' => '232-277-00',
        'owner_type' => null,
        'owner_id' => null,
        'is_potentially_saponifiable' => true,
        'is_active' => true,
    ]);

    $source->sapProfile()->create([
        'koh_sap_value' => 0.188,
        'iodine_value' => 86.4,
        'ins_value' => 102.8,
        'source_notes' => 'Trusted average',
    ]);
    $source->fattyAcidEntries()->createMany([
        ['fatty_acid_id' => $oleic->id, 'percentage' => 71.0, 'source_notes' => 'Main'],
        ['fatty_acid_id' => $palmitic->id, 'percentage' => 13.0, 'source_notes' => null],
    ]);

    $service = app(UserIngredientAuthoringService::class);
    $copy = $service->duplicate($source, $user);

    expect($copy->is_potentially_saponifiable)->toBeTrue();
    expect($copy->cas_number)->toBe('8001-25-0');
    expect($copy->ec_number)->toBe('232-277-0');
    expect($copy->sapProfile)->not->toBeNull();
    expect((float) $copy->sapProfile->koh_sap_value)->toBe(0.188);
    expect((float) $copy->sapProfile->iodine_value)->toBe(86.4);
    expect($copy->fattyAcidEntries)->toHaveCount(2);

    // SAP profile is independent
    $copy->sapProfile->update(['koh_sap_value' => 0.195]);
    expect((float) $source->fresh()->sapProfile->koh_sap_value)->toBe(0.188);
});

it('prevents duplicated carrier oil KOH SAP edits outside the trusted range', function () {
    $user = User::factory()->create();

    $source = Ingredient::factory()->create([
        'category' => IngredientCategory::CarrierOil,
        'display_name' => 'Olive Oil',
        'owner_type' => null,
        'owner_id' => null,
        'is_potentially_saponifiable' => true,
        'is_active' => true,
    ]);

    $source->sapProfile()->create([
        'koh_sap_value' => 0.188,
        'iodine_value' => 86.4,
        'ins_value' => 102.8,
    ]);

    $service = app(UserIngredientAuthoringService::class);
    $copy = $service->duplicate($source, $user);

    expect(fn () => $service->update($copy, [
        'name' => 'Olive Oil',
        'category' => IngredientCategory::CarrierOil->value,
        'inci_name' => $copy->inci_name,
        'sap_profile' => [
            'koh_sap_value' => 0.195,
            'iodine_value' => 86.4,
            'ins_value' => 102.8,
        ],
    ], $user))->toThrow(ValidationException::class);
});

it('refuses to duplicate a carrier oil without a KOH SAP value', function () {
    $user = User::factory()->create();
    $source = Ingredient::factory()->create([
        'category' => IngredientCategory::CarrierOil,
        'display_name' => 'Incomplete platform oil',
        'owner_type' => null,
        'is_potentially_saponifiable' => true,
    ]);

    expect(fn () => app(UserIngredientAuthoringService::class)->duplicate($source, $user))
        ->toThrow(ValidationException::class, 'cannot be duplicated until its KOH SAP value is available');
});

it('validates duplicated carrier oil fatty acids against trusted ranges and total', function () {
    $user = User::factory()->create();
    $oleic = FattyAcid::factory()->create(['key' => 'oleic', 'name' => 'Oleic']);
    $trace = FattyAcid::factory()->create(['key' => 'trace', 'name' => 'Trace']);
    $palmitic = FattyAcid::factory()->create(['key' => 'palmitic', 'name' => 'Palmitic']);
    $source = Ingredient::factory()->create([
        'category' => IngredientCategory::CarrierOil,
        'display_name' => 'Trusted oil',
        'owner_type' => null,
        'is_potentially_saponifiable' => true,
    ]);
    $source->sapProfile()->create(['koh_sap_value' => 0.188]);
    $source->fattyAcidEntries()->createMany([
        ['fatty_acid_id' => $oleic->id, 'percentage' => 50],
        ['fatty_acid_id' => $trace->id, 'percentage' => 2],
        ['fatty_acid_id' => $palmitic->id, 'percentage' => 40],
    ]);

    $service = app(UserIngredientAuthoringService::class);
    $copy = $service->duplicate($source, $user);
    $state = $service->formData($copy);
    foreach ($state['fatty_acid_entries'] as &$row) {
        if ($row['fatty_acid_id'] === $oleic->id) {
            $row['percentage'] = 61;
        }

        if ($row['fatty_acid_id'] === $palmitic->id) {
            $row['percentage'] = 35;
        }
    }
    unset($row);

    expect(fn () => $service->update($copy, $state, $user))
        ->toThrow(ValidationException::class, 'must stay between');

    $state = $service->formData($copy);
    foreach ($state['fatty_acid_entries'] as &$row) {
        $row['percentage'] = 20;
    }
    unset($row);

    expect(fn () => $service->update($copy, $state, $user))
        ->toThrow(ValidationException::class, 'must total between 80% and 100%');
});

it('duplicates a composite ingredient with components', function () {
    $user = User::factory()->create();

    $component = Ingredient::factory()->create([
        'display_name' => 'Base oil component',
        'category' => IngredientCategory::CarrierOil,
        'is_active' => true,
    ]);

    $source = Ingredient::factory()->create([
        'display_name' => 'Soap base blend',
        'category' => IngredientCategory::CarrierOil,
        'owner_type' => null,
        'owner_id' => null,
        'is_active' => true,
    ]);
    $source->sapProfile()->create(['koh_sap_value' => 0.188]);

    $source->components()->create([
        'component_ingredient_id' => $component->id,
        'percentage_in_parent' => 100.0,
        'sort_order' => 1,
        'source_notes' => 'Full blend',
    ]);

    $service = app(UserIngredientAuthoringService::class);
    $copy = $service->duplicate($source, $user);

    expect($copy->components)->toHaveCount(1);
    expect($copy->components->first()->component_ingredient_id)->toBe($component->id);
    expect((float) $copy->components->first()->percentage_in_parent)->toBe(100.0);
});

it('refuses to duplicate a user-owned ingredient', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();

    $source = Ingredient::factory()->create([
        'owner_type' => OwnerType::User,
        'owner_id' => $owner->id,
        'visibility' => Visibility::Private,
    ]);

    $service = app(UserIngredientAuthoringService::class);

    expect(fn () => $service->duplicate($source, $otherUser))
        ->toThrow(ValidationException::class);
});

it('duplicates parent-level source notes for composition and allergens', function () {
    $user = User::factory()->create();
    $allergen = Allergen::factory()->create();

    $source = Ingredient::factory()->create([
        'category' => IngredientCategory::EssentialOil,
        'display_name' => 'Aromatic blend',
        'owner_type' => null,
        'owner_id' => null,
        'is_active' => true,
        'composition_source_notes' => 'Composition COA',
        'allergen_source_notes' => 'Allergen SDS',
    ]);
    $source->allergenEntries()->create([
        'allergen_id' => $allergen->id,
        'concentration_percent' => 1.0,
    ]);

    $copy = app(UserIngredientAuthoringService::class)->duplicate($source, $user);

    expect($copy->composition_source_notes)->toBe('Composition COA')
        ->and($copy->allergen_source_notes)->toBe('Allergen SDS');
});
