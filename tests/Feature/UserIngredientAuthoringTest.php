<?php

use App\IngredientCategory;
use App\Livewire\Dashboard\IngredientEditor;
use App\Models\Allergen;
use App\Models\IfraProductCategory;
use App\Models\Ingredient;
use App\Models\IngredientVersion;
use App\Models\User;
use App\OwnerType;
use App\Services\UserIngredientAuthoringService;
use App\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('creates a minimal private user ingredient from the public editor', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $component = Livewire::test(IngredientEditor::class);

    expect(method_exists($component->instance(), 'mountAction'))->toBeTrue();

    $component
        ->set('data.name', 'French Green Clay')
        ->set('data.category', IngredientCategory::Clay->value)
        ->set('data.inci_name', 'ILLITE')
        ->call('save');

    $ingredient = Ingredient::query()
        ->where('owner_type', OwnerType::User)
        ->where('owner_id', $user->id)
        ->first();

    expect($ingredient)->not->toBeNull()
        ->and($ingredient->visibility)->toBe(Visibility::Private)
        ->and($ingredient->is_potentially_saponifiable)->toBeFalse()
        ->and($ingredient->currentVersion?->display_name)->toBe('French Green Clay')
        ->and($ingredient->currentVersion?->inci_name)->toBe('ILLITE');
});

it('persists optional allergen and current ifra data for aromatic user ingredients', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $linalool = Allergen::factory()->create(['inci_name' => 'LINALOOL']);
    $limonene = Allergen::factory()->create(['inci_name' => 'LIMONENE']);
    $category3 = IfraProductCategory::factory()->create([
        'code' => '3',
        'name' => 'Soap products',
        'is_active' => true,
    ]);

    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::EssentialOil,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
        'source_file' => 'user',
        'source_key' => 'USR-EO',
    ]);

    IngredientVersion::factory()->for($ingredient)->create([
        'display_name' => 'Rose Essential Oil',
        'inci_name' => 'ROSA DAMASCENA FLOWER OIL',
        'is_current' => true,
    ]);

    Livewire::test(IngredientEditor::class, ['ingredient' => $ingredient])
        ->set('data.name', 'Rose Essential Oil')
        ->set('data.category', IngredientCategory::EssentialOil->value)
        ->set('data.inci_name', 'ROSA DAMASCENA FLOWER OIL')
        ->set('data.allergen_entries', [
            [
                'allergen_id' => $linalool->id,
                'concentration_percent' => 0.42,
                'source_notes' => null,
            ],
            [
                'allergen_id' => $limonene->id,
                'concentration_percent' => 0.08,
                'source_notes' => 'Trace supplier declaration',
            ],
        ])
        ->set('data.ifra.reference_label', 'Current supplier IFRA')
        ->set('data.ifra.ifra_amendment', '51')
        ->set('data.ifra.source_notes', 'Indicative only')
        ->set('data.ifra.limits', [
            [
                'ifra_product_category_id' => $category3->id,
                'max_percentage' => 0.8,
                'restriction_note' => 'Rinse-off reference',
            ],
        ])
        ->call('save');

    $freshIngredient = $ingredient->fresh(['currentVersion.allergenEntries', 'currentVersion.ifraCertificates.limits']);
    $currentVersion = $freshIngredient->currentVersion;
    $currentIfra = $currentVersion?->ifraCertificates->first();

    expect($currentVersion?->allergenEntries)->toHaveCount(2)
        ->and($currentVersion?->allergenEntries->pluck('allergen_id')->all())->toEqualCanonicalizing([$linalool->id, $limonene->id])
        ->and($currentIfra?->ifra_amendment)->toBe('51')
        ->and($currentIfra?->limits)->toHaveCount(1)
        ->and((float) $currentIfra?->limits->first()->max_percentage)->toBe(0.8);
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

    expect($component->owner_type)->toBe(OwnerType::User)
        ->and($component->owner_id)->toBe($user->id)
        ->and($component->visibility)->toBe(Visibility::Private)
        ->and($macerate->components)->toHaveCount(1)
        ->and($macerate->components->first()->component_ingredient_id)->toBe($component->id);
});

it('keeps user carrier oils out of the soap saponification lane', function () {
    $user = User::factory()->create();

    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::CarrierOil,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
        'is_potentially_saponifiable' => false,
    ]);

    expect($ingredient->availableWorkbenchPhases())
        ->toContain('additives')
        ->not->toContain('saponified_oils');
});
