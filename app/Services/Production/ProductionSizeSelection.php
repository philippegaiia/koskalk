<?php

namespace App\Services\Production;

use App\Models\ProductSize;

final readonly class ProductionSizeSelection
{
    public function __construct(public ?ProductSize $size, private bool $includesLegacyPackaging) {}

    public function includesPackaging(?int $productSizeId): bool
    {
        return $productSizeId === null ? $this->includesLegacyPackaging : $productSizeId === $this->size?->id;
    }
}
