<?php

use App\Services\IngredientEnrichment\Sources\EurLexGlossaryClient;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    cache()->flush();
    config()->set('ingredient-enrichment.sources.eur_lex_glossary', [
        'url' => 'https://eur-lex.test/legal-content/EN/TXT/HTML/?uri=CELEX:32025D1175',
        'celex' => '32025D1175',
        'ttl_days' => 30,
    ]);
});

it('upgrades an exact EUR-Lex glossary match to verified', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'eur-lex.test/*' => Http::response(fixtureHtml('eur-lex-glossary.html')),
    ]);

    $result = app(EurLexGlossaryClient::class)->verify([
        'inci_name' => 'ARGANIA SPINOSA KERNEL OIL',
    ]);

    expect($result->data)->toMatchArray([
        'matched' => true,
        'common_ingredient_name' => 'ARGANIA SPINOSA KERNEL OIL',
    ])->and($result->evidence[0])->toMatchArray([
        'source_tier' => 'official',
        'confidence' => 'verified',
        'source_version' => '32025D1175',
    ]);
});

it('does not create official evidence when the glossary has no exact name', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'eur-lex.test/*' => Http::response(fixtureHtml('eur-lex-glossary.html')),
    ]);

    $result = app(EurLexGlossaryClient::class)->verify([
        'inci_name' => 'NOT A GLOSSARY INGREDIENT',
    ]);

    expect($result->data)->toBe([
        'matched' => false,
        'common_ingredient_name' => null,
    ])->and($result->evidence)->toBe([]);
});

it('verifies sodium and potassium soap names independently from the official glossary', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'eur-lex.test/*' => Http::response(fixtureHtml('eur-lex-glossary.html')),
    ]);

    $result = app(EurLexGlossaryClient::class)->verify([
        'inci_name' => 'COCOS NUCIFERA OIL',
        'soap_inci_naoh_name' => 'SODIUM COCOATE',
        'soap_inci_koh_name' => 'NOT VERIFIED',
    ]);

    expect($result->data)->toMatchArray([
        'soap_inci_naoh_name' => 'SODIUM COCOATE',
        'soap_inci_koh_name' => null,
    ])->and(collect($result->evidence)->pluck('field')->all())->toContain('proposal.soap_inci_naoh_name')
        ->not->toContain('proposal.soap_inci_koh_name');
});

function fixtureHtml(string $name): string
{
    return (string) file_get_contents(base_path("tests/Fixtures/IngredientEnrichment/{$name}"));
}
