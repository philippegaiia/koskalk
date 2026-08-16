<?php

use App\Services\IngredientEnrichment\Sources\FdaColourAdditiveClient;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    cache()->flush();
    config()->set('ingredient-enrichment.sources.fda_colours', [
        'url' => 'https://fda.test/cosmetics/cosmetic-ingredient-names/color-additives-permitted-use-cosmetics',
        'ttl_days' => 7,
    ]);
});

it('returns a verified FDA colour declaration only after exact name matching', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'fda.test/*' => Http::response(fdaColoursFixture()),
    ]);

    $result = app(FdaColourAdditiveClient::class)->lookup([
        'ci' => 'CI 19140',
        'names' => ['TARTRAZINE', 'YELLOW 5'],
    ]);

    expect($result->data['matches'][0])->toMatchArray([
        'declaration_name' => 'FD&C Yellow No. 5',
        'certification_required' => true,
        'eye_area' => true,
        'generally' => true,
        'external_use' => true,
        'cfr_url' => 'https://www.ecfr.gov/current/title-21/section-74.705',
    ])->and($result->evidence[0]['confidence'])->toBe('verified');
});

it('does not guess a US colour declaration from a CI number alone', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'fda.test/*' => Http::response(fdaColoursFixture()),
    ]);

    $result = app(FdaColourAdditiveClient::class)->lookup([
        'ci' => 'CI 19140',
        'names' => [],
    ]);

    expect($result->data['matches'])->toBe([])
        ->and($result->unresolvedQuestions)->not->toBeEmpty();
});

function fdaColoursFixture(): string
{
    return (string) file_get_contents(base_path('tests/Fixtures/IngredientEnrichment/fda-colours.html'));
}
