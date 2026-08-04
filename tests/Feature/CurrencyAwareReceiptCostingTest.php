<?php

use App\Services\ExchangeRateService;
use Illuminate\Support\Facades\Http;

it('uses an identity rate when the transaction and workspace currencies match', function (): void {
    $snapshot = app(ExchangeRateService::class)->snapshot('EUR', 'EUR', '2026-08-04');

    expect($snapshot->baseCurrency)->toBe('EUR')
        ->and($snapshot->quoteCurrency)->toBe('EUR')
        ->and($snapshot->rate)->toBe('1.000000000000')
        ->and($snapshot->provider)->toBe('identity')
        ->and($snapshot->rateDate)->toBe('2026-08-04');
});

it('fetches and caches a cross-currency rate snapshot', function (): void {
    Http::fake([
        'https://api.frankfurter.dev/v2/rate/USD/EUR*' => Http::response([
            'date' => '2026-08-04',
            'base' => 'USD',
            'quote' => 'EUR',
            'rate' => 0.91,
        ]),
    ]);

    $service = app(ExchangeRateService::class);
    $first = $service->snapshot('USD', 'EUR', '2026-08-04');
    $second = $service->snapshot('USD', 'EUR', '2026-08-04');

    expect($first->baseCurrency)->toBe('USD')
        ->and($first->quoteCurrency)->toBe('EUR')
        ->and($first->rate)->toBe('0.910000000000')
        ->and($first->provider)->toBe('frankfurter')
        ->and($first->rateDate)->toBe('2026-08-04')
        ->and($second)->toEqual($first);

    Http::assertSentCount(1);
});

it('uses a manual rate when the exchange-rate provider is unavailable', function (): void {
    Http::fake([
        'https://api.frankfurter.dev/v2/rate/USD/EUR*' => Http::failedConnection('provider unavailable'),
    ]);

    $snapshot = app(ExchangeRateService::class)->snapshot(
        baseCurrency: 'USD',
        quoteCurrency: 'EUR',
        date: '2026-08-05',
        manualRate: '0.91',
    );

    expect($snapshot->rate)->toBe('0.910000000000')
        ->and($snapshot->provider)->toBe('manual')
        ->and($snapshot->isManual)->toBeTrue();

    Http::assertNothingSent();
});

it('surfaces connection failures as an invalid exchange-rate error', function (): void {
    Http::fake([
        'https://api.frankfurter.dev/v2/rate/USD/EUR*' => Http::failedConnection('provider unavailable'),
    ]);

    expect(fn () => app(ExchangeRateService::class)->snapshot('USD', 'EUR', '2026-08-06'))
        ->toThrow(InvalidArgumentException::class);

    expect(Http::recorded(fn ($request): bool => $request->url() === 'https://api.frankfurter.dev/v2/rate/USD/EUR?date=2026-08-06'))
        ->toHaveCount(1);
});
