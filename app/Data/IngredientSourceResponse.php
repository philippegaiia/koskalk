<?php

namespace App\Data;

use Carbon\CarbonImmutable;

final readonly class IngredientSourceResponse
{
    /**
     * @param  array<string, mixed>|string  $payload
     */
    public function __construct(
        public array|string $payload,
        public int $status,
        public string $url,
        public CarbonImmutable $retrievedAt,
        public bool $cacheHit,
        public int $sourceCalls,
    ) {}
}
