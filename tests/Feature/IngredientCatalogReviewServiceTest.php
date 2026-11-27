<?php

use App\Actions\Ingredients\MarkIngredientsCatalogReviewed;
use App\Enums\IngredientCatalogReviewStep;
use App\Enums\OwnerType;
use App\Models\Ingredient;
use App\Models\IngredientTranslation;
use App\Models\User;
use App\Services\IngredientCatalogReviewService;
use App\Services\IngredientTranslationSourceFingerprint;
use Database\Seeders\SupportedLocaleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('interface-translations.catalogue_locales', ['de', 'fr', 'it']);
    $this->seed(SupportedLocaleSeeder::class);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function catalogReviewIngredient(array $attributes = [], int $currentTranslations = 3, int $staleTranslations = 0): Ingredient
{
    $ingredient = Ingredient::factory()->create([
        'owner_type' => null,
        'owner_id' => null,
        'workspace_id' => null,
        'info_markdown' => "## Overview\n\nEnglish guidance.",
        ...$attributes,
    ]);
    $fingerprint = app(IngredientTranslationSourceFingerprint::class)->forIngredient($ingredient);

    collect(['de', 'fr', 'it'])
        ->take($currentTranslations + $staleTranslations)
        ->values()
        ->each(fn (string $locale, int $index) => IngredientTranslation::factory()->create([
            'ingredient_id' => $ingredient->id,
            'locale' => $locale,
            'info_markdown' => "## Guidance {$locale}",
            'source_fingerprint' => $index < $currentTranslations ? $fingerprint : 'fingerprint-of-older-english',
        ]));

    return $ingredient->fresh();
}

it('places an unreviewed ingredient at its first unfinished step', function (array $attributes, int $current, int $stale, IngredientCatalogReviewStep $expected): void {
    $ingredient = catalogReviewIngredient($attributes, $current, $stale);

    expect(app(IngredientCatalogReviewService::class)->progress($ingredient)['step'])->toBe($expected);
})->with([
    'no English guidance' => [['info_markdown' => '  '], 0, 0, IngredientCatalogReviewStep::MissingGuidance],
    'a missing translation' => [[], 2, 0, IngredientCatalogReviewStep::TranslationsIncomplete],
    'a translation of older English' => [[], 2, 1, IngredientCatalogReviewStep::TranslationsIncomplete],
    'everything current' => [[], 3, 0, IngredientCatalogReviewStep::AwaitingReview],
    'signed off with work left' => [['requires_admin_review' => false, 'info_markdown' => null], 0, 0, IngredientCatalogReviewStep::Reviewed],
]);

it('reports enrichment and translation counts for the list columns', function (): void {
    $ingredient = catalogReviewIngredient(['source_data' => ['enrichment' => ['core' => ['schema_version' => 1]]]], 1, 1);

    expect(app(IngredientCatalogReviewService::class)->progress($ingredient))->toMatchArray([
        'enriched' => true,
        'has_guidance' => true,
        'translated' => 2,
        'stale' => 1,
        'expected' => 3,
    ]);
});

it('filters platform ingredients to exactly one review step each', function (): void {
    $missingGuidance = catalogReviewIngredient(['info_markdown' => null], 0);
    $incomplete = catalogReviewIngredient([], 1);
    $awaiting = catalogReviewIngredient();
    $reviewed = catalogReviewIngredient(['requires_admin_review' => false]);
    $service = app(IngredientCatalogReviewService::class);
    $idsAt = fn (IngredientCatalogReviewStep $step): array => $service
        ->constrainToStep(Ingredient::query(), $step)
        ->pluck('id')
        ->all();

    expect($idsAt(IngredientCatalogReviewStep::MissingGuidance))->toBe([$missingGuidance->id])
        ->and($idsAt(IngredientCatalogReviewStep::TranslationsIncomplete))->toBe([$incomplete->id])
        ->and($idsAt(IngredientCatalogReviewStep::AwaitingReview))->toBe([$awaiting->id])
        ->and($idsAt(IngredientCatalogReviewStep::Reviewed))->toBe([$reviewed->id]);
});

it('signs off selected ingredients and keeps the original date of those already reviewed', function (): void {
    $this->travelTo('2026-11-27 10:00:00');
    $admin = User::factory()->admin()->create();
    $earlierReviewer = User::factory()->admin()->create();
    $pending = catalogReviewIngredient();
    $alreadyReviewed = catalogReviewIngredient([
        'requires_admin_review' => false,
        'catalog_reviewed_at' => '2026-10-01 08:00:00',
        'catalog_reviewed_by_user_id' => $earlierReviewer->id,
    ]);

    $marked = app(MarkIngredientsCatalogReviewed::class)->handle($admin, collect([$pending, $alreadyReviewed]));

    expect($marked)->toBe(1);
    expect($pending->fresh())
        ->requires_admin_review->toBeFalse()
        ->catalog_reviewed_at->toDateTimeString()->toBe('2026-11-27 10:00:00')
        ->catalog_reviewed_by_user_id->toBe($admin->id);
    expect($alreadyReviewed->fresh())
        ->catalog_reviewed_at->toDateTimeString()->toBe('2026-10-01 08:00:00')
        ->catalog_reviewed_by_user_id->toBe($earlierReviewer->id);
});

it('refuses sign-off from a non-administrator', function (): void {
    $member = User::factory()->create();
    $ingredient = catalogReviewIngredient();

    expect(fn () => app(MarkIngredientsCatalogReviewed::class)->handle($member, collect([$ingredient])))
        ->toThrow(AuthorizationException::class);
    expect($ingredient->fresh()->requires_admin_review)->toBeTrue();
});

it('refuses sign-off of a workspace-owned ingredient', function (): void {
    $admin = User::factory()->admin()->create();
    $owned = Ingredient::factory()->create([
        'owner_type' => OwnerType::User,
        'owner_id' => $admin->id,
    ]);

    expect(fn () => app(MarkIngredientsCatalogReviewed::class)->handle($admin, collect([$owned])))
        ->toThrow(AuthorizationException::class);
});

it('stamps the signed-in administrator when Needs review is switched off in the editor', function (): void {
    $this->travelTo('2026-11-27 11:30:00');
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $ingredient = catalogReviewIngredient();

    $ingredient->update(['requires_admin_review' => false]);

    expect($ingredient->fresh())
        ->catalog_reviewed_at->toDateTimeString()->toBe('2026-11-27 11:30:00')
        ->catalog_reviewed_by_user_id->toBe($admin->id);
});

it('clears the sign-off when Needs review is switched back on', function (): void {
    $admin = User::factory()->admin()->create();
    $ingredient = catalogReviewIngredient([
        'requires_admin_review' => false,
        'catalog_reviewed_at' => '2026-10-01 08:00:00',
        'catalog_reviewed_by_user_id' => $admin->id,
    ]);

    $ingredient->update(['requires_admin_review' => true]);

    expect($ingredient->fresh())
        ->catalog_reviewed_at->toBeNull()
        ->catalog_reviewed_by_user_id->toBeNull();
});
