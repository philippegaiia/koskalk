<?php

namespace App\Contracts;

use App\Data\ExchangeRateSnapshot;

interface ExchangeRateProvider
{
    public function rate(string $baseCurrency, string $quoteCurrency, string $date): ExchangeRateSnapshot;
}
