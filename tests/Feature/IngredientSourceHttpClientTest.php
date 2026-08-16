<?php

use App\Services\IngredientEnrichment\IngredientSourceException;
use App\Services\IngredientEnrichment\Sources\CachedIngredientSourceHttpClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    cache()->flush();
    config()->set('ingredient-enrichment.source_transport', [
        'connect_timeout_seconds' => 3,
        'timeout_seconds' => 10,
        'response_shape_version' => 'v1',
    ]);
    config()->set('ingredient-enrichment.sources.cosing_checker.base_url', 'https://cosingchecker.test/api/v1');
});

it('caches source responses by declared source version', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'cosingchecker.test/*' => Http::response(['results' => [['inci_name' => 'ARGANIA SPINOSA KERNEL OIL']]]),
    ]);

    $client = app(CachedIngredientSourceHttpClient::class);
    $first = $client->json(
        source: 'cosing_checker',
        url: 'https://cosingchecker.test/api/v1/ingredients/',
        query: ['q' => 'ARGAN OIL', 'per_page' => 20],
        version: 'inventory-2026-03-21',
        ttl: now()->addDays(30),
    );
    $second = $client->json(
        source: 'cosing_checker',
        url: 'https://cosingchecker.test/api/v1/ingredients/',
        query: ['per_page' => 20, 'q' => 'ARGAN OIL'],
        version: 'inventory-2026-03-21',
        ttl: now()->addDays(30),
    );
    $changedVersion = $client->json(
        source: 'cosing_checker',
        url: 'https://cosingchecker.test/api/v1/ingredients/',
        query: ['q' => 'ARGAN OIL', 'per_page' => 20],
        version: 'inventory-2026-04-01',
        ttl: now()->addDays(30),
    );

    Http::assertSentCount(2);
    expect($first->payload)->toBe(['results' => [['inci_name' => 'ARGANIA SPINOSA KERNEL OIL']]])
        ->and($first->status)->toBe(200)
        ->and($first->url)->toContain('q=ARGAN%20OIL')
        ->and($first->retrievedAt)->toBeInstanceOf(CarbonImmutable::class)
        ->and($first->cacheHit)->toBeFalse()
        ->and($first->sourceCalls)->toBe(1)
        ->and($second->cacheHit)->toBeTrue()
        ->and($second->sourceCalls)->toBe(0)
        ->and($changedVersion->cacheHit)->toBeFalse();
});

it('retries transient source errors but never caches failures', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'cosingchecker.test/*' => Http::sequence()
            ->push(['error' => 'rate_limited'], 429)
            ->push(['results' => []], 200),
    ]);

    $response = app(CachedIngredientSourceHttpClient::class)->json(
        source: 'cosing_checker',
        url: 'https://cosingchecker.test/api/v1/ingredients/',
        query: ['q' => 'ARGAN OIL'],
        version: 'inventory-2026-03-21',
        ttl: now()->addDays(30),
    );

    Http::assertSentCount(2);
    expect($response->payload)->toBe(['results' => []])
        ->and($response->sourceCalls)->toBe(1);
});

it('returns a safe exception for a failed configured source', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'cosingchecker.test/*' => Http::response(['sensitive' => 'third-party body'], 404),
    ]);

    expect(fn () => app(CachedIngredientSourceHttpClient::class)->json(
        source: 'cosing_checker',
        url: 'https://cosingchecker.test/api/v1/ingredients/',
        query: ['q' => 'ARGAN OIL'],
        version: 'inventory-2026-03-21',
        ttl: now()->addDays(30),
    ))->toThrow(IngredientSourceException::class, 'could not provide usable data');
});
