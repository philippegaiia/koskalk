<?php

use App\Services\IngredientEnrichment\Sources\OpenFdaSubstanceClient;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    cache()->flush();
    config()->set('ingredient-enrichment.sources.open_fda', [
        'base_url' => 'https://openfda.test/other/substance.json',
        'source_version' => 'gsrs-v1',
        'ttl_days' => 30,
    ]);
});

it('keeps the FDA argan identity separate from unverified US labelling', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'openfda.test/*' => Http::response(openFdaFixture('openfda-argan.json')),
    ]);

    $candidate = app(OpenFdaSubstanceClient::class)->lookup([
        'display_name' => 'Argan oil',
        'inci_name' => 'ARGANIA SPINOSA KERNEL OIL',
        'identifiers' => [],
    ])->data['candidates'][0];

    expect($candidate['unii'])->toBe('4V59G5UW9X')
        ->and($candidate['common_name'])->toBe('ARGAN OIL')
        ->and($candidate['inci_names'])->toContain('ARGANIA SPINOSA KERNEL OIL')
        ->and($candidate['names'])->toContain('ARGAN OIL', 'ARGANIA SPINOSA KERNEL OIL')
        ->and($candidate['cas'])->toBe([]);
});

it('retains exact GSRS names so source backed synonyms can be proposed', function (): void {
    Http::preventStrayRequests();
    $fixture = openFdaFixture('openfda-argan.json');
    $fixture['results'][0]['names'][] = [
        'name' => 'MOROCCAN ARGAN OIL',
        'display_name' => false,
        'preferred' => false,
        'name_orgs' => [['name_org' => 'FDA']],
    ];
    Http::fake(['openfda.test/*' => Http::response($fixture)]);

    $candidate = app(OpenFdaSubstanceClient::class)->lookup([
        'display_name' => 'Argan oil',
        'inci_name' => 'ARGANIA SPINOSA KERNEL OIL',
        'identifiers' => [],
    ])->data['candidates'][0];

    expect($candidate['names'])->toBe([
        'ARGAN OIL',
        'ARGANIA SPINOSA KERNEL OIL',
        'MOROCCAN ARGAN OIL',
    ]);
});

it('retains an FDA CAS only when the response explicitly provides one', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'openfda.test/*' => Http::response(openFdaFixture('openfda-apricot.json')),
    ]);

    $candidate = app(OpenFdaSubstanceClient::class)->lookup([
        'display_name' => 'Apricot oil',
        'inci_name' => 'PRUNUS ARMENIACA KERNEL OIL',
        'identifiers' => [],
    ])->data['candidates'][0];

    expect($candidate['unii'])->toBe('54JB35T06A')
        ->and($candidate['cas'])->toBe(['72869-69-3']);
});

it('continues after an FDA no-match response and normalizes exact name searches to uppercase', function (): void {
    Http::preventStrayRequests();
    Http::fakeSequence()
        ->push(['error' => ['code' => 'NOT_FOUND', 'message' => 'No matches found!']], 404)
        ->push(openFdaFixture('openfda-argan.json'));

    $result = app(OpenFdaSubstanceClient::class)->lookup([
        'display_name' => 'Argan oil',
        'inci_name' => 'Unknown botanical oil',
        'identifiers' => [],
    ]);

    $searches = Http::recorded()
        ->map(fn (array $recorded): string => (string) $recorded[0]->data()['search'])
        ->all();

    expect($result->data['candidates'][0]['unii'])->toBe('4V59G5UW9X')
        ->and($result->sourceCalls)->toBe(2)
        ->and($searches)->toBe([
            'names.name:"UNKNOWN BOTANICAL OIL"',
            'names.name:"ARGAN OIL"',
        ]);
});

it('keeps searching when the first non-empty batch only contains a sibling-form phrase hit', function (): void {
    Http::preventStrayRequests();
    Http::fakeSequence()
        ->push(['results' => [[
            'unii' => 'SIBLING-PHRASE-001',
            'names' => [[
                'name' => 'HYDROGENATED ARGANIA SPINOSA KERNEL OIL',
                'preferred' => true,
                'display_name' => true,
                'name_orgs' => [['name_org' => 'FDA']],
            ]],
            'codes' => [],
        ]]])
        ->push(openFdaFixture('openfda-argan.json'))
        ->push(openFdaFixture('openfda-argan.json'));

    $result = app(OpenFdaSubstanceClient::class)->lookup([
        'display_name' => 'Argan oil',
        'inci_name' => 'ARGANIA SPINOSA KERNEL OIL',
        'identifiers' => [],
    ]);

    $searches = Http::recorded()
        ->map(fn (array $recorded): string => (string) $recorded[0]->data()['search'])
        ->all();

    expect($result->sourceCalls)->toBe(3)
        ->and(collect($result->data['candidates'])->pluck('unii')->all())
        ->toBe(['SIBLING-PHRASE-001', '4V59G5UW9X'])
        ->and($searches)->toBe([
            'names.name:"ARGANIA SPINOSA KERNEL OIL"',
            'names.name:"ARGANIA SPINOSA SEED OIL"',
            'names.name:"ARGAN OIL"',
        ]);
});

/**
 * @return array<string, mixed>
 */
function openFdaFixture(string $name): array
{
    return json_decode((string) file_get_contents(base_path("tests/Fixtures/IngredientEnrichment/{$name}")), true, flags: JSON_THROW_ON_ERROR);
}
