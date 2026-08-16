<?php

use App\Enums\IngredientCategory;
use App\Enums\IngredientDuplicateResolution;
use App\Enums\IngredientResearchFamily;
use App\Enums\IngredientSubcategory;
use App\Models\Ingredient;
use App\Models\IngredientIntakeBatch;
use App\Models\IngredientIntakeItem;
use App\Services\IngredientEnrichment\IngredientEnrichmentInputBuilder;
use App\Services\IngredientEnrichment\IngredientEnrichmentSubjectBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('builds an existing ingredient subject from the reviewed canonical snapshot', function (): void {
    $ingredient = Ingredient::factory()->create([
        'catalog_key' => 'coconut_oil',
        'display_name' => 'Coconut Oil',
        'inci_name' => 'Cocos Nucifera Oil',
        'category' => IngredientCategory::Lipids,
    ]);

    $subject = app(IngredientEnrichmentSubjectBuilder::class)->forIngredient($ingredient);

    expect($subject->subjectType)->toBe('ingredient')
        ->and($subject->subjectPublicId)->toBe($ingredient->public_id)
        ->and($subject->catalogKey)->toBe('coconut_oil')
        ->and($subject->currentName)->toBe('Coconut Oil')
        ->and($subject->inciName)->toBe('Cocos Nucifera Oil')
        ->and($subject->currentSnapshot['canonical']['display_name'])->toBe('Coconut Oil')
        ->and($subject->researchFamily)->toBe(IngredientResearchFamily::Lipids)
        ->and($subject->duplicateContext)->toBe([])
        ->and($subject->duplicateResolution)->toBeNull()
        ->and($subject->fingerprint)->toBeString()->toHaveLength(64);
});

it('builds a distinct intake subject with entered names and an empty canonical state', function (): void {
    $batch = IngredientIntakeBatch::factory()->create([
        'family_hint' => IngredientResearchFamily::Colourants,
    ]);
    $item = IngredientIntakeItem::factory()->for($batch, 'batch')->create([
        'original_current_name' => 'Red pigment',
        'normalized_current_name' => 'red pigment',
        'original_inci_name' => 'CI 77491',
        'normalized_inci_name' => 'ci 77491',
        'duplicate_candidates' => [['match_type' => 'possible', 'ingredient_id' => 12]],
        'duplicate_resolution' => IngredientDuplicateResolution::DistinctMaterial,
    ]);

    $subject = app(IngredientEnrichmentSubjectBuilder::class)->forIntake($item);

    expect($subject->subjectType)->toBe('intake')
        ->and($subject->subjectPublicId)->toBe($item->public_id)
        ->and($subject->catalogKey)->toBeNull()
        ->and($subject->currentName)->toBe('Red pigment')
        ->and($subject->inciName)->toBe('CI 77491')
        ->and($subject->currentSnapshot['catalog_key'])->toBeNull()
        ->and($subject->currentSnapshot['canonical']['display_name'])->toBeNull()
        ->and($subject->currentSnapshot['canonical']['category'])->toBeNull()
        ->and($subject->duplicateContext)->toHaveCount(1)
        ->and($subject->duplicateResolution)->toBe(IngredientDuplicateResolution::DistinctMaterial)
        ->and($subject->researchFamily)->toBe(IngredientResearchFamily::Colourants);
});

it('keeps an enrich-existing row intake-targeted while using the linked ingredient state', function (): void {
    $linked = Ingredient::factory()->create([
        'catalog_key' => 'argan_oil',
        'display_name' => 'Argan Oil',
        'inci_name' => 'Argania Spinosa Kernel Oil',
        'category' => IngredientCategory::Lipids,
    ]);
    $batch = IngredientIntakeBatch::factory()->create([
        'family_hint' => IngredientResearchFamily::Colourants,
    ]);
    $item = IngredientIntakeItem::factory()->for($batch, 'batch')->create([
        'original_current_name' => 'Argan oil',
        'normalized_current_name' => 'argan oil',
        'existing_ingredient_id' => $linked->id,
        'duplicate_resolution' => IngredientDuplicateResolution::ExistingIngredient,
    ]);

    $subject = app(IngredientEnrichmentSubjectBuilder::class)->forIntake($item);
    $direct = app(IngredientEnrichmentSubjectBuilder::class)->forIngredient($linked);

    expect($subject->subjectType)->toBe('intake')
        ->and($subject->catalogKey)->toBeNull()
        ->and($subject->currentSnapshot['canonical']['inci_name'])->toBe('Argania Spinosa Kernel Oil')
        ->and($subject->researchFamily)->toBe(IngredientResearchFamily::Lipids)
        ->and($subject->duplicateResolution)->toBe(IngredientDuplicateResolution::ExistingIngredient)
        ->and($subject->fingerprint)->not->toBe($direct->fingerprint);
});

it('serializes intake subjects without inventing a catalogue key', function (): void {
    $batch = IngredientIntakeBatch::factory()->create([
        'family_hint' => IngredientResearchFamily::Waxes,
    ]);
    $item = IngredientIntakeItem::factory()->for($batch, 'batch')->create([
        'original_current_name' => 'Beeswax',
        'normalized_current_name' => 'beeswax',
    ]);
    $subject = app(IngredientEnrichmentSubjectBuilder::class)->forIntake($item);
    $record = app(IngredientEnrichmentInputBuilder::class)->buildForSubject($subject);

    expect($record)->toHaveKeys([
        'subject_type',
        'subject_public_id',
        'catalog_key',
        'source_fingerprint',
        'current',
        'vocabulary',
    ])
        ->and($record['subject_type'])->toBe('intake')
        ->and($record['subject_public_id'])->toBe($item->public_id)
        ->and($record['catalog_key'])->toBeNull()
        ->and($record['current']['canonical']['category'])->toBeNull()
        ->and($record['vocabulary']['subcategories'])->toContain(
            IngredientSubcategory::PlantWaxes->value,
            IngredientSubcategory::DyesLakes->value,
        )
        ->and($record['research_rules']['research_family'])->toBe('waxes');
});
