<?php

namespace App\Contracts;

use App\Data\ExchangeRateSnapshot;

interface ExchangeRateProvider
{
    /**
     * @return list<string>
     */
    public function supportedCurrencies(): array;

    public function supports(string $baseCurrency, string $quoteCurrency): bool;

    public function rate(string $baseCurrency, string $quoteCurrency, string $date): ExchangeRateSnapshot;
}
