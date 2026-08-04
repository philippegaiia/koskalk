<?php

namespace App\Services;

use App\Contracts\ExchangeRateProvider;
use App\Data\ExchangeRateSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

class ExchangeRateService
{
    public function __construct(private readonly ExchangeRateProvider $provider) {}

    public function snapshot(
        string $baseCurrency,
        string $quoteCurrency,
        string $date,
        ?string $manualRate = null,
    ): ExchangeRateSnapshot {
        $baseCurrency = $this->currency($baseCurrency);
        $quoteCurrency = $this->currency($quoteCurrency);
        $rateDate = CarbonImmutable::parse($date)->toDateString();

        if ($baseCurrency === $quoteCurrency) {
            return new ExchangeRateSnapshot(
                baseCurrency: $baseCurrency,
                quoteCurrency: $quoteCurrency,
                rate: '1.000000000000',
                rateDate: $rateDate,
                provider: 'identity',
            );
        }

        if ($manualRate !== null) {
            $rate = trim($manualRate);

            if (preg_match('/^\d+(?:\.\d+)?$/', $rate) !== 1 || bccomp($rate, '0', 12) <= 0) {
                throw new InvalidArgumentException('A manual exchange rate must be a positive decimal value.');
            }

            return new ExchangeRateSnapshot(
                baseCurrency: $baseCurrency,
                quoteCurrency: $quoteCurrency,
                rate: bcadd($rate, '0', 12),
                rateDate: $rateDate,
                provider: 'manual',
                isManual: true,
            );
        }

        return Cache::remember(
            "exchange-rate:frankfurter:{$rateDate}:{$baseCurrency}:{$quoteCurrency}",
            now()->addDay(),
            fn (): ExchangeRateSnapshot => $this->provider->rate($baseCurrency, $quoteCurrency, $rateDate),
        );
    }

    private function currency(string $currency): string
    {
        $currency = strtoupper(trim($currency));

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException('Currencies must use three-letter ISO codes.');
        }

        return $currency;
    }
}
