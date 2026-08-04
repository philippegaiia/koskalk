<?php

namespace App\Services;

use App\Contracts\ExchangeRateProvider;
use App\Data\ExchangeRateSnapshot;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;
use InvalidArgumentException;

class FrankfurterExchangeRateProvider implements ExchangeRateProvider
{
    public function __construct(private readonly HttpFactory $http) {}

    public function rate(string $baseCurrency, string $quoteCurrency, string $date): ExchangeRateSnapshot
    {
        $baseCurrency = $this->currency($baseCurrency);
        $quoteCurrency = $this->currency($quoteCurrency);

        try {
            $response = $this->http
                ->acceptJson()
                ->connectTimeout(3)
                ->timeout(5)
                ->get("https://api.frankfurter.dev/v2/rate/{$baseCurrency}/{$quoteCurrency}", [
                    'date' => $date,
                ])
                ->throw();
        } catch (RequestException|ConnectionException $exception) {
            throw new InvalidArgumentException('The exchange-rate provider did not return a usable rate.', previous: $exception);
        }

        $payload = $response->json();
        $rate = $payload['rate'] ?? null;
        $returnedDate = $payload['date'] ?? null;

        if (
            ! is_numeric($rate)
            || (float) $rate <= 0
            || ! is_string($returnedDate)
            || ! is_string($payload['base'] ?? null)
            || ! is_string($payload['quote'] ?? null)
        ) {
            throw new InvalidArgumentException('The exchange-rate provider returned an invalid response.');
        }

        return new ExchangeRateSnapshot(
            baseCurrency: strtoupper($payload['base']),
            quoteCurrency: strtoupper($payload['quote']),
            rate: bcadd((string) $rate, '0', 12),
            rateDate: $returnedDate,
            provider: 'frankfurter',
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
