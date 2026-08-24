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

    public function canAutoConvert(string $baseCurrency, string $quoteCurrency): bool
    {
        $baseCurrency = $this->currency($baseCurrency);
        $quoteCurrency = $this->currency($quoteCurrency);

        return $baseCurrency === $quoteCurrency
            || $this->provider->supports($baseCurrency, $quoteCurrency);
    }

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

        if (! $this->canAutoConvert($baseCurrency, $quoteCurrency)) {
            throw new InvalidArgumentException("No automatic exchange rate is available for {$baseCurrency} → {$quoteCurrency}.");
        }

        $cacheKey = "exchange-rate:frankfurter:{$rateDate}:{$baseCurrency}:{$quoteCurrency}";
        $loadPayload = fn (): array => $this->payload(
            $this->provider->rate($baseCurrency, $quoteCurrency, $rateDate),
        );
        $payload = Cache::remember($cacheKey, now()->addDay(), $loadPayload);

        if (! is_array($payload) || ! $this->isValidPayload($payload)) {
            Cache::forget($cacheKey);
            $payload = Cache::remember($cacheKey, now()->addDay(), $loadPayload);
        }

        if (! is_array($payload) || ! $this->isValidPayload($payload)) {
            throw new InvalidArgumentException('The cached exchange-rate payload is invalid.');
        }

        return new ExchangeRateSnapshot(
            baseCurrency: $payload['base_currency'],
            quoteCurrency: $payload['quote_currency'],
            rate: $payload['rate'],
            rateDate: $payload['rate_date'],
            provider: $payload['provider'],
            isManual: $payload['is_manual'],
        );
    }

    /**
     * @return array{base_currency: string, quote_currency: string, rate: string, rate_date: string, provider: string, is_manual: bool}
     */
    private function payload(ExchangeRateSnapshot $snapshot): array
    {
        return [
            'base_currency' => $snapshot->baseCurrency,
            'quote_currency' => $snapshot->quoteCurrency,
            'rate' => $snapshot->rate,
            'rate_date' => $snapshot->rateDate,
            'provider' => $snapshot->provider,
            'is_manual' => $snapshot->isManual,
        ];
    }

    /**
     * @param  array<mixed>  $payload
     */
    private function isValidPayload(array $payload): bool
    {
        return is_string($payload['base_currency'] ?? null)
            && is_string($payload['quote_currency'] ?? null)
            && is_string($payload['rate'] ?? null)
            && is_string($payload['rate_date'] ?? null)
            && is_string($payload['provider'] ?? null)
            && is_bool($payload['is_manual'] ?? null);
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
