<?php

use App\Models\IngredientFunction;
use App\Services\IngredientEnrichment\Sources\CosingCheckerClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    cache()->flush();
    config()->set('ingredient-enrichment.sources.cosing_checker', [
        'base_url' => 'https://cosingchecker.test/api/v1',
        'inventory_version' => 'inventory-2026-03-21',
        'ttl_days' => 30,
    ]);
});

it('normalizes an argan CosIng Checker candidate with every published identifier', function (): void {
    IngredientFunction::factory()->create(['key' => 'skin_conditioning', 'name' => 'Skin conditioning']);
    IngredientFunction::factory()->create(['key' => 'emollient', 'name' => 'Emollient']);
    Http::preventStrayRequests();
    Http::fake([
        'cosingchecker.test/*' => Http::response(fixtureJson('cosing-argan.json')),
    ]);

    $result = app(CosingCheckerClient::class)->lookup([
        'display_name' => 'Argan oil',
        'inci_name' => null,
        'identifiers' => [],
    ]);

    expect($result->data['candidates'][0])->toMatchArray([
        'cosing_ref' => '54495',
        'inci_name' => 'ARGANIA SPINOSA KERNEL OIL',
        'cas' => ['223747-87-3', '299184-75-1'],
        'ec' => [],
        'functions' => ['skin_conditioning', 'emollient'],
        'confidence' => 'supported',
    ])->and($result->evidence[0])->toMatchArray([
        'source_tier' => 'structured_mirror',
        'confidence' => 'supported',
        'source_version' => 'inventory-2026-03-21',
    ]);
});

it('splits apricot identifiers and keeps only active known function keys', function (): void {
    IngredientFunction::factory()->create(['key' => 'perfuming', 'name' => 'Perfuming']);
    IngredientFunction::factory()->create(['key' => 'skin_conditioning', 'name' => 'Skin conditioning']);
    Http::preventStrayRequests();
    Http::fake([
        'cosingchecker.test/*' => Http::response(fixtureJson('cosing-apricot.json')),
    ]);

    $result = app(CosingCheckerClient::class)->lookup([
        'display_name' => 'Apricot oil',
        'inci_name' => null,
        'identifiers' => [],
    ]);

    expect($result->data['candidates'][0])->toMatchArray([
        'cosing_ref' => '78931',
        'cas' => ['68650-44-2', '72869-69-3'],
        'ec' => ['272-046-1'],
        'functions' => ['perfuming', 'skin_conditioning'],
    ])->and($result->warnings)->toBe([]);
});

it('maps CosIng compound and legacy function labels to the canonical vocabulary', function (): void {
    IngredientFunction::factory()->create(['key' => 'perfuming', 'name' => 'Perfuming']);
    IngredientFunction::factory()->create(['key' => 'skin_conditioning', 'name' => 'Skin conditioning']);
    IngredientFunction::factory()->create(['key' => 'emollient', 'name' => 'Emollient']);
    IngredientFunction::factory()->create(['key' => 'skin_protecting', 'name' => 'Skin protecting']);
    Http::preventStrayRequests();
    Http::fake([
        'cosingchecker.test/*' => Http::response([
            'count' => 1,
            'results' => [[
                'slug' => '60291-theobroma-cacao-seed-butter',
                'ref_number' => '60291',
                'inci_name' => 'THEOBROMA CACAO SEED BUTTER',
                'cas_number' => '84649-99-0 / 8002-31-1',
                'ec_number' => '283-480-6',
                'description' => 'Cocoa seed butter.',
                'restriction' => null,
                'function' => 'FRAGRANCE, SKIN CONDITIONING, SKIN CONDITIONING - EMOLLIENT, SKIN PROTECTING',
                'update_date' => '15/10/2010',
            ]],
        ]),
    ]);

    $result = app(CosingCheckerClient::class)->lookup([
        'display_name' => 'Cocoa butter',
        'inci_name' => 'THEOBROMA CACAO SEED BUTTER',
        'identifiers' => [],
    ]);

    expect($result->data['candidates'][0]['functions'])->toBe([
        'perfuming',
        'skin_conditioning',
        'emollient',
        'skin_protecting',
    ])->and($result->warnings)->toBe([]);
});

it('normalizes CosIng Checker day-first update dates to ISO format', function (): void {
    $payload = fixtureJson('cosing-argan.json');
    data_set($payload, 'results.0.update_date', '15/10/2010');
    Http::preventStrayRequests();
    Http::fake([
        'cosingchecker.test/*' => Http::response($payload),
    ]);

    $result = app(CosingCheckerClient::class)->lookup([
        'display_name' => 'Argan oil',
        'inci_name' => 'ARGANIA SPINOSA KERNEL OIL',
        'identifiers' => [],
    ]);

    expect($result->data['candidates'][0]['source_updated_at'])->toBe('2010-10-15')
        ->and($result->evidence[0]['source_updated_at'])->toBe('2010-10-15');
});

it('queries the EU botanical variant and finds the base oil beyond derivative results', function (): void {
    Http::preventStrayRequests();
    Http::fake(function (Request $request) {
        $query = $request->data();

        if (($query['q'] ?? null) === 'Cocos Nucifera Oil') {
            return Http::response([
                'count' => 1,
                'results' => [[
                    'slug' => '75444-cocos-nucifera-oil',
                    'ref_number' => '75444',
                    'inci_name' => 'COCOS NUCIFERA OIL',
                    'cas_number' => '8001-31-8',
                    'ec_number' => '232-282-8',
                    'description' => 'Fixed oil obtained from the dried endosperm of Cocos nucifera.',
                    'restriction' => null,
                    'function' => null,
                    'update_date' => '2026-03-21',
                ]],
            ]);
        }

        return Http::response([
            'count' => 20,
            'results' => collect(range(1, 20))->map(fn (int $index): array => [
                'slug' => "derivative-{$index}",
                'ref_number' => (string) $index,
                'inci_name' => "COCONUT OIL PEG-{$index} ESTERS",
                'cas_number' => null,
                'ec_number' => null,
                'description' => 'A manufactured coconut oil derivative.',
                'restriction' => null,
                'function' => null,
                'update_date' => '2026-03-21',
            ])->all(),
        ]);
    });

    $result = app(CosingCheckerClient::class)->lookup([
        'display_name' => 'Coconut Oil',
        'inci_name' => 'Cocos Nucifera (Coconut) Oil',
        'identifiers' => [],
    ]);

    expect(collect($result->data['candidates'])->firstWhere('cosing_ref', '75444'))->toMatchArray([
        'inci_name' => 'COCOS NUCIFERA OIL',
        'cas' => ['8001-31-8'],
        'ec' => ['232-282-8'],
    ]);

    Http::assertSent(fn (Request $request): bool => ($request->data()['q'] ?? null) === 'Cocos Nucifera Oil'
        && ($request->data()['per_page'] ?? null) === 100);
});

it('returns soap salts only when the source description explicitly relates them to the base oil', function (): void {
    Http::preventStrayRequests();
    Http::fake(function (Request $request) {
        $query = $request->data()['q'] ?? null;

        return match ($query) {
            'SODIUM Coconut' => Http::response(['results' => [[
                'slug' => 'sodium-cocoate', 'ref_number' => '1', 'inci_name' => 'SODIUM COCOATE',
                'description' => 'Sodium salts of the fatty acids derived from coconut oil.',
            ]]]),
            'POTASSIUM Coconut' => Http::response(['results' => [[
                'slug' => 'potassium-unrelated', 'ref_number' => '2', 'inci_name' => 'POTASSIUM OLIVATE',
                'description' => 'Potassium salts derived from olive oil.',
            ]]]),
            default => Http::response(fixtureJson('cosing-coconut.json')),
        };
    });

    $result = app(CosingCheckerClient::class)->lookup([
        'display_name' => 'Coconut Oil',
        'inci_name' => 'COCOS NUCIFERA OIL',
        'category' => 'lipids',
        'identifiers' => [],
    ]);

    expect(data_get($result->data, 'soap_salts.naoh.inci_name'))->toBe('SODIUM COCOATE')
        ->and(data_get($result->data, 'soap_salts.koh'))->toBeNull();
});

it('discovers soap salts from an explicit base-material relationship when prefixed searches miss the INCI salt name', function (): void {
    Http::preventStrayRequests();
    Http::fake(function (Request $request) {
        $query = $request->data()['q'] ?? null;

        return match ($query) {
            'COCOS NUCIFERA OIL' => Http::response(fixtureJson('cosing-coconut.json')),
            'SODIUM Coconut', 'POTASSIUM Coconut' => Http::response(['results' => []]),
            'Coconut' => Http::response(['results' => [
                [
                    'slug' => 'sodium-cocoate',
                    'ref_number' => '79001',
                    'inci_name' => 'SODIUM COCOATE',
                    'description' => 'Sodium salts of the fatty acids derived from coconut oil.',
                ],
                [
                    'slug' => 'potassium-cocoate',
                    'ref_number' => '79002',
                    'inci_name' => 'POTASSIUM COCOATE',
                    'description' => 'Potassium salts of the fatty acids derived from coconut oil.',
                ],
                [
                    'slug' => 'sodium-unrelated',
                    'ref_number' => '79003',
                    'inci_name' => 'SODIUM OLIVATE',
                    'description' => 'Sodium salts of the fatty acids derived from olive oil.',
                ],
            ]]),
            default => Http::response(['results' => []]),
        };
    });

    $result = app(CosingCheckerClient::class)->lookup([
        'display_name' => 'Coconut Oil',
        'inci_name' => 'COCOS NUCIFERA OIL',
        'category' => 'lipids',
        'identifiers' => [],
    ]);

    expect(data_get($result->data, 'soap_salts.naoh.inci_name'))->toBe('SODIUM COCOATE')
        ->and(data_get($result->data, 'soap_salts.koh.inci_name'))->toBe('POTASSIUM COCOATE');

    Http::assertSent(fn (Request $request): bool => ($request->data()['q'] ?? null) === 'Coconut');
});

/**
 * @return array<string, mixed>
 */
function fixtureJson(string $name): array
{
    $contents = file_get_contents(base_path("tests/Fixtures/IngredientEnrichment/{$name}"));

    return json_decode((string) $contents, true, flags: JSON_THROW_ON_ERROR);
}
