<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class FormulaSharePhaseValidator
{
    public function __construct(private readonly RecipeWorkbenchPhaseBlueprints $blueprints) {}

    /** @param list<array<string, mixed>> $phases */
    public function validate(array $phases, bool $cosmetic): void
    {
        $seen = [];
        foreach ($phases as $phase) {
            $slug = $phase['slug'] ?? null;
            $expectedType = $cosmetic ? 'cosmetic_phase' : $this->blueprints->find((string) $slug)['phase_type'] ?? null;
            if (! is_string($slug) || $slug === '' || isset($seen[$slug]) || $expectedType === null || ($phase['phase_type'] ?? null) !== $expectedType) {
                throw ValidationException::withMessages(['sharing' => __('sharing.validation.formula')]);
            }
            $seen[$slug] = true;
        }
    }
}
