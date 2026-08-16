<?php

use App\Enums\IngredientCategory;
use App\Enums\OwnerType;
use App\Enums\Visibility;
use App\Models\Allergen;
use App\Models\FattyAcid;
use App\Models\IfraProductCategory;
use App\Models\Ingredient;
use App\Models\IngredientFunction;
use App\Models\IngredientTranslation;
use App\Models\Substance;
use App\Models\User;
use App\Services\UserIngredientAuthoringService;
use Database\Seeders\SupportedLocaleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('duplicates a platform ingredient into a workspace-owned copy with all data except images', function () {
    $user = User::factory()->create();
    $function = IngredientFunction::factory()->create(['is_active' => true]);
    $allergen = Allergen::factory()->create();
    $ifraCategory = IfraProductCategory::factory()->create(['is_active' => true]);

    $source = Ingredient::factory()->create([
        'category' => IngredientCategory::AromaticMaterials,
        'display_name' => 'Lavender 40/42',
        'inci_name' => 'LAVANDULA ANGUSTIFOLIA OIL',
        'notes' => 'Supplier-neutral catalogue note',
        'owner_type' => null,
        'owner_id' => null,
        'visibility' => Visibility::Public,
        'is_soap_saponification_trusted' => false,
        'featured_image_path' => 'ingredients/featured-images/lavender.webp',
        'featured_image_original_name' => 'Lavender portrait.webp',
        'icon_image_path' => 'ingredients/icons/lavender.webp',
        'icon_image_original_name' => 'Lavender icon.webp',
        'info_markdown' => 'A popular essential oil.',
        'is_active' => true,
    ]);
    $source->identifiers()->createMany([
        ['scheme' => 'cas', 'value' => '8000-28-0', 'normalized_value' => '8000-28-0', 'is_primary' => true],
        ['scheme' => 'ec', 'value' => '289-995-2', 'normalized_value' => '289-995-2', 'is_primary' => true],
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

    $workspace = $user->fresh()->company();

    expect($copy->owner_type)->toBe(OwnerType::Workspace);
    expect($copy->owner_id)->toBe($workspace->id);
    expect($copy->workspace_id)->toBe($workspace->id);
    expect($copy->visibility)->toBe(Visibility::Private);
    expect($copy->display_name)->toBe('Lavender 40/42');
    expect($copy->inci_name)->toBe('LAVANDULA ANGUSTIFOLIA OIL');
    expect($copy->notes)->toBe('Supplier-neutral catalogue note');
    expect($copy->identifiers->where('scheme', 'cas')->value('value'))->toBe('8000-28-0');
    expect($copy->featured_image_path)->toBeNull();
    expect($copy->featured_image_original_name)->toBeNull();
    expect($copy->icon_image_path)->toBeNull();
    expect($copy->icon_image_original_name)->toBeNull();
    expect($copy->info_markdown)->toBe('A popular essential oil.');
    expect($copy->is_active)->toBeTrue();
    expect($copy->catalog_key)->toStartWith('USR-');
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

it('duplicates localized identity and substance data into an independent workspace copy', function (): void {
    $this->seed(SupportedLocaleSeeder::class);
    $user = User::factory()->create(['locale' => 'fr']);
    $substance = Substance::factory()->create(['name' => 'Linalool']);

    $source = Ingredient::factory()->create([
        'display_name' => 'Lavender oil',
        'saponification_name' => 'Lavender',
        'info_markdown' => 'English guidance',
        'category' => IngredientCategory::AromaticMaterials,
        'owner_type' => null,
        'owner_id' => null,
        'is_active' => true,
    ]);
    $source->translations()->create([
        'locale' => 'fr',
        'display_name' => 'Huile de lavande',
        'saponification_name' => 'Lavande',
        'info_markdown' => 'Conseils en français',
    ]);
    $source->translations()->create([
        'locale' => 'de',
        'display_name' => 'Lavendelöl',
        'saponification_name' => 'Lavendel',
        'info_markdown' => 'Deutsche Hinweise',
    ]);
    $source->identifiers()->createMany([
        ['scheme' => 'cas', 'value' => '8000-28-0', 'normalized_value' => '8000-28-0', 'is_primary' => true],
        ['scheme' => 'ec', 'value' => '289-995-2', 'normalized_value' => '289-995-2', 'is_primary' => true],
        ['scheme' => 'unii', 'value' => 'EXAMPLE123', 'normalized_value' => 'example123', 'is_primary' => true],
    ]);
    $source->aliases()->createMany([
        ['locale' => 'fr', 'name' => 'Lavande vraie', 'normalized_name' => 'lavande vraie', 'kind' => 'common'],
        ['locale' => 'und', 'name' => 'Lavandula angustifolia', 'normalized_name' => 'lavandula angustifolia', 'kind' => 'botanical'],
        ['locale' => 'en', 'name' => 'English lavender', 'normalized_name' => 'english lavender', 'kind' => 'common'],
    ]);
    $source->substanceEntries()->create([
        'substance_id' => $substance->id,
        'concentration_percent' => 0.42,
        'concentration_source' => 'supplier_coa',
        'source_notes' => 'Supplier declaration',
        'source_data' => ['document' => 'coa.pdf'],
    ]);

    $copy = app(UserIngredientAuthoringService::class)->duplicate($source, $user);

    expect($copy->display_name)->toBe('Huile de lavande')
        ->and($copy->saponification_name)->toBe('Lavande')
        ->and($copy->info_markdown)->toBe('Conseils en français')
        ->and($copy->translations)->toBeEmpty()
        ->and($copy->identifiers)->toHaveCount(3)
        ->and($copy->identifiers->where('scheme', 'cas')->where('is_primary', true)->value('value'))->toBe('8000-28-0')
        ->and($copy->aliases->pluck('name')->all())->toBe([
            'Lavande vraie',
            'Lavandula angustifolia',
        ])
        ->and($copy->substanceEntries)->toHaveCount(1)
        ->and($copy->substanceEntries->first()->source_notes)->toBe('Supplier declaration')
        ->and($copy->substanceEntries->first()->source_data)->toBe(['document' => 'coa.pdf']);

    $copy->identifiers->first()->update(['value' => 'changed']);
    $copy->substanceEntries->first()->update(['concentration_percent' => 0.8]);

    expect($source->fresh()->identifiers->first()->value)->toBe('8000-28-0')
        ->and((float) $source->fresh()->substanceEntries->first()->concentration_percent)->toBe(0.42)
        ->and(IngredientTranslation::query()->where('ingredient_id', $copy->id)->exists())->toBeFalse();
});

it('duplicates a carrier oil with SAP profile and fatty acids', function () {
    $user = User::factory()->create();
    $oleic = FattyAcid::factory()->create(['key' => 'oleic', 'name' => 'Oleic']);
    $palmitic = FattyAcid::factory()->create(['key' => 'palmitic', 'name' => 'Palmitic']);

    $source = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'display_name' => 'Olive Oil',
        'saponification_name' => 'Olive',
        'owner_type' => null,
        'owner_id' => null,
        'is_soap_saponification_trusted' => true,
        'is_active' => true,
    ]);
    $source->identifiers()->createMany([
        ['scheme' => 'cas', 'value' => '8001-25-00', 'normalized_value' => '8001-25-00', 'is_primary' => true],
        ['scheme' => 'ec', 'value' => '232-277-00', 'normalized_value' => '232-277-00', 'is_primary' => true],
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

    expect($copy->is_soap_saponification_trusted)->toBeTrue();
    expect($copy->saponification_name)->toBe('Olive');
    expect($copy->identifiers->where('scheme', 'cas')->value('value'))->toBe('8001-25-00');
    expect($copy->identifiers->where('scheme', 'ec')->value('value'))->toBe('232-277-00');
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
        'category' => IngredientCategory::Lipids,
        'display_name' => 'Olive Oil',
        'owner_type' => null,
        'owner_id' => null,
        'is_soap_saponification_trusted' => true,
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
        'category' => IngredientCategory::Lipids->value,
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
        'category' => IngredientCategory::Lipids,
        'display_name' => 'Incomplete platform oil',
        'owner_type' => null,
        'is_soap_saponification_trusted' => true,
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
        'category' => IngredientCategory::Lipids,
        'display_name' => 'Trusted oil',
        'owner_type' => null,
        'is_soap_saponification_trusted' => true,
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
        ->toThrow(ValidationException::class, 'outside its allowed range');

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
        'category' => IngredientCategory::Lipids,
        'is_active' => true,
    ]);

    $source = Ingredient::factory()->create([
        'display_name' => 'Soap base blend',
        'category' => IngredientCategory::Lipids,
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
        'category' => IngredientCategory::AromaticMaterials,
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
