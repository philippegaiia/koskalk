<?php

namespace App\Data;

final readonly class IngredientGuidanceEvidenceValidationResult
{
    /**
     * @param  list<array<string, mixed>>  $accepted
     * @param  list<array{index:int,code:string,host:?string}>  $rejected
     */
    public function __construct(
        public array $accepted,
        public array $rejected,
    ) {}
}
