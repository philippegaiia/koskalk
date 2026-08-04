<?php

namespace App\Data;

final readonly class ExchangeRateSnapshot
{
    public function __construct(
        public string $baseCurrency,
        public string $quoteCurrency,
        public string $rate,
        public string $rateDate,
        public string $provider,
        public bool $isManual = false,
    ) {}
}
