<?php

use App\Enums\IngredientCategory;
use App\Enums\IngredientSubcategory;
use Tests\TestCase;

// Booted so lang_path() and resource_path() resolve; no database is needed.
uses(TestCase::class);

it('exposes the canonical ingredient taxonomy', function () {
    expect(IngredientCategory::cases())->toHaveCount(22)
        ->and(IngredientCategory::cases())->toContain(IngredientCategory::Silicones)
        ->and(IngredientCategory::cases())->toContain(IngredientCategory::FunctionalPolymers)
        ->and(IngredientCategory::cases())->toContain(IngredientCategory::Other)
        ->and(method_exists(IngredientCategory::class, 'canonicalCases'))->toBeFalse()
        ->and(method_exists(IngredientCategory::class, 'canonical'))->toBeFalse()
        ->and(method_exists(IngredientCategory::class, 'isLegacy'))->toBeFalse()
        ->and(method_exists(IngredientCategory::class, 'valuesForCanonical'))->toBeFalse();
});

it('does not retain legacy values after the migration bridge', function () {
    expect(IngredientCategory::tryFrom('carrier_oil'))->toBeNull()
        ->and(IngredientCategory::tryFrom('essential_oil'))->toBeNull()
        ->and(IngredientCategory::tryFrom('additive'))->toBeNull();
});

it('maps every subcategory to exactly one canonical category', function () {
    $subcategories = IngredientSubcategory::cases();

    expect($subcategories)->not->toBeEmpty();

    foreach ($subcategories as $subcategory) {
        expect(IngredientCategory::cases())->toContain($subcategory->category());
    }
});

it('keeps conditioning as a functional polymer facet while silicones remain a material family', function () {
    expect(IngredientSubcategory::ConditioningPolymers->category())
        ->toBe(IngredientCategory::FunctionalPolymers)
        ->and(IngredientSubcategory::VolatileSilicones->category())
        ->toBe(IngredientCategory::Silicones);
});

it('does not offer a subcategory for other', function () {
    expect(IngredientSubcategory::forCategory(IngredientCategory::Other))->toBe([]);
});

it('maps every category to a badge tone that has a matching stylesheet rule', function () {
    $stylesheet = file_get_contents(resource_path('css/app.css'));
    $tones = [];

    foreach (IngredientCategory::cases() as $category) {
        $tone = $category->badgeTone();
        $tones[$category->value] = $tone;

        // A misspelled tone would silently render a colourless badge, so the
        // enum and the stylesheet are checked against each other here.
        expect($stylesheet)->toContain('.sk-badge-'.$tone.' {');
    }

    expect(array_unique(array_values($tones)))->toHaveCount(5);
});

it('derives badge tones from the filament colour without repurposing it', function () {
    // Representative of each family, so an edit to getColor() surfaces here.
    expect(IngredientCategory::Lipids->badgeTone())->toBe('botanical')
        ->and(IngredientCategory::BotanicalsExtracts->badgeTone())->toBe('botanical')
        ->and(IngredientCategory::Surfactants->badgeTone())->toBe('functional')
        ->and(IngredientCategory::Silicones->badgeTone())->toBe('functional')
        ->and(IngredientCategory::AromaticMaterials->badgeTone())->toBe('fragrance')
        ->and(IngredientCategory::SoapmakingAlkalis->badgeTone())->toBe('hazard')
        ->and(IngredientCategory::Colourants->badgeTone())->toBe('inert');

    // getColor() implements Filament's HasColor and must keep returning
    // Filament palette names, which is why badgeTone() is a separate method.
    expect(IngredientCategory::Lipids->getColor())->toBe('success')
        ->and(IngredientCategory::AromaticMaterials->getColor())->toBe('warning');
});

it('declares a distinct short label for every category', function () {
    // Read the English source directly: this stays a DB-free unit test, and the
    // catalogue test separately proves every key has six reviewed locales.
    $source = require lang_path('en/ingredients.php');
    $labels = [];

    foreach (IngredientCategory::cases() as $category) {
        $short = $source['categories'][$category->value]['short_label'] ?? null;
        $full = $source['categories'][$category->value]['label'] ?? null;

        expect($short)->toBeString();
        expect(trim((string) $short))->not->toBe('');
        // A short label that is not shorter defeats the point of the badge.
        expect(strlen((string) $short) <= strlen((string) $full))->toBeTrue();

        $labels[$category->value] = $short;
    }

    // Two categories sharing badge wording would be indistinguishable.
    expect(array_unique($labels))->toHaveCount(count(IngredientCategory::cases()));
});
