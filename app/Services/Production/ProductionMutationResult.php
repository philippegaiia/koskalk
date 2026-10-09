<?php

namespace App\Services\Production;

final readonly class ProductionMutationResult
{
    /** @param list<int> $changedProductionIds */
    public function __construct(public mixed $value, public array $changedProductionIds) {}
}
