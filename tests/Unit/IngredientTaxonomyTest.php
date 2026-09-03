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

it('gives every category a distinct badge variant that has a matching stylesheet rule', function () {
    $stylesheet = file_get_contents(resource_path('css/app.css'));
    $variants = [];

    foreach (IngredientCategory::cases() as $category) {
        $variant = $category->badgeVariant();
        $variants[$category->value] = $variant;

        // A category with no rule renders a colourless badge, which looks like
        // a styling bug rather than a missing palette entry, so the enum and
        // the stylesheet are checked against each other here.
        expect($stylesheet)->toContain('.sk-badge-'.$variant.' {');

        // Half a rule is as broken as none: the badge needs both layers, since
        // inheriting the panel colour would leave the text at 1.1:1.
        preg_match('/\.sk-badge-'.preg_quote($variant, '/').'\s*\{([^}]*)\}/', $stylesheet, $body);
        expect($body)->not->toBeEmpty();
        expect($body[1])->toContain('background-color:');
        expect($body[1])->toMatch('/(?<![\-\w])color\s*:/');
    }

    // One hue per category is the whole point; a shared variant silently
    // reintroduces the old five-family grouping.
    expect(array_unique(array_values($variants)))->toHaveCount(count(IngredientCategory::cases()));
});

it('keeps badge variants independent of the filament colour grouping', function () {
    // The two answers are intentionally unrelated: getColor() collapses
    // categories into broad semantic buckets for Filament, while the badge
    // palette needs one hue each. Pinning that they differ is what stops
    // someone from "simplifying" one back into the other.
    $tones = [];

    foreach (IngredientCategory::cases() as $category) {
        $tones[$category->getColor()][] = $category->badgeVariant();
    }

    // Five categories share `gray` yet must not share a badge colour.
    expect($tones['gray'])->toHaveCount(5)
        ->and(array_unique($tones['gray']))->toHaveCount(5)
        // ...and the three corrosive groups share `danger` for the same reason.
        ->and($tones['danger'])->toHaveCount(3)
        ->and(array_unique($tones['danger']))->toHaveCount(3);
});

it('maps every category to a filament palette name', function () {
    // getColor() implements Filament's HasColor, so it must return names from
    // that palette. Pinned in full: an edit here repaints Filament components,
    // and the intent is to notice rather than have it drift unnoticed.
    expect(IngredientCategory::Lipids->getColor())->toBe('success')
        ->and(IngredientCategory::Waxes->getColor())->toBe('success')
        ->and(IngredientCategory::Hydrocarbons->getColor())->toBe('success')
        ->and(IngredientCategory::Silicones->getColor())->toBe('teal')
        ->and(IngredientCategory::FattyDerivatives->getColor())->toBe('teal')
        ->and(IngredientCategory::Surfactants->getColor())->toBe('info')
        ->and(IngredientCategory::Emulsifiers->getColor())->toBe('info')
        ->and(IngredientCategory::HumectantsPolyols->getColor())->toBe('blue')
        ->and(IngredientCategory::WaterSolventsCarriers->getColor())->toBe('blue')
        ->and(IngredientCategory::RheologyModifiers->getColor())->toBe('primary')
        ->and(IngredientCategory::FunctionalPolymers->getColor())->toBe('primary')
        ->and(IngredientCategory::MineralsSaltsPowders->getColor())->toBe('gray')
        ->and(IngredientCategory::Colourants->getColor())->toBe('gray')
        ->and(IngredientCategory::ExfoliantsAbrasives->getColor())->toBe('gray')
        ->and(IngredientCategory::BasesBlendsPremixes->getColor())->toBe('gray')
        ->and(IngredientCategory::Other->getColor())->toBe('gray')
        ->and(IngredientCategory::Actives->getColor())->toBe('emerald')
        ->and(IngredientCategory::BotanicalsExtracts->getColor())->toBe('emerald')
        ->and(IngredientCategory::AromaticMaterials->getColor())->toBe('warning')
        ->and(IngredientCategory::PreservationStability->getColor())->toBe('danger')
        ->and(IngredientCategory::PhAdjustersBuffers->getColor())->toBe('danger')
        ->and(IngredientCategory::SoapmakingAlkalis->getColor())->toBe('danger');

    foreach (IngredientCategory::cases() as $category) {
        expect($category->getColor())->toBeString()->not->toBe('');
    }
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
