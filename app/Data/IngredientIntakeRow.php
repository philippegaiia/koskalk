<?php

namespace App\Data;

final readonly class IngredientIntakeRow
{
    public function __construct(
        public int $rowNumber,
        public ?string $originalCurrentName,
        public ?string $originalInciName,
        public ?string $normalizedCurrentName,
        public ?string $normalizedInciName,
    ) {}

    /**
     * @return array{
     *     row_number: int,
     *     original_current_name: string|null,
     *     original_inci_name: string|null,
     *     normalized_current_name: string|null,
     *     normalized_inci_name: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'row_number' => $this->rowNumber,
            'original_current_name' => $this->originalCurrentName,
            'original_inci_name' => $this->originalInciName,
            'normalized_current_name' => $this->normalizedCurrentName,
            'normalized_inci_name' => $this->normalizedInciName,
        ];
    }
}
