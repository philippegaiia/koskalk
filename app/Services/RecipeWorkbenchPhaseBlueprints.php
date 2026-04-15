<?php

namespace App\Services;

use App\Models\ProductFamily;

class RecipeWorkbenchPhaseBlueprints
{
    /**
     * @var array<string, array<string, mixed>>|null
     */
    private ?array $indexedPhaseBlueprints = null;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(?ProductFamily $productFamily = null): array
    {
        if ($this->isCosmeticFamily($productFamily)) {
            return [
                [
                    'key' => 'phase_a',
                    'name' => 'Phase A',
                    'phase_group' => 'cosmetic_formula',
                    'phase_type' => 'cosmetic_phase',
                    'description' => 'Default cosmetic formula phase.',
                    'is_system' => false,
                ],
            ];
        }

        return [
            [
                'key' => 'saponified_oils',
                'name' => 'Saponified Oils',
                'phase_group' => 'reaction_core',
                'phase_type' => 'reaction_core',
                'description' => 'Carrier oils and butters that drive the soap calculation itself.',
                'is_system' => true,
            ],
            [
                'key' => 'lye_water',
                'name' => 'Lye Water',
                'phase_group' => 'reaction_core',
                'phase_type' => 'reaction_medium',
                'description' => 'The reaction medium: alkali, water mode, and superfat settings.',
                'is_system' => true,
            ],
            [
                'key' => 'additives',
                'name' => 'Additives',
                'phase_group' => 'post_reaction',
                'phase_type' => 'post_reaction',
                'description' => 'Colorants, preservatives, and functional additions added after the core soap calculation.',
                'is_system' => true,
            ],
            [
                'key' => 'fragrance',
                'name' => 'Fragrance And Aromatics',
                'phase_group' => 'post_reaction',
                'phase_type' => 'post_reaction',
                'description' => 'Essential oils, aromatic extracts, and later user-authored fragrance oils.',
                'is_system' => true,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $key): ?array
    {
        return $this->indexedPhaseBlueprints()[$key] ?? null;
    }

    public function isCosmeticFamily(?ProductFamily $productFamily): bool
    {
        return $productFamily?->slug === 'cosmetic'
            || $productFamily?->calculation_basis === 'total_formula';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function indexedPhaseBlueprints(): array
    {
        if (is_array($this->indexedPhaseBlueprints)) {
            return $this->indexedPhaseBlueprints;
        }

        return $this->indexedPhaseBlueprints = array_column($this->all(), null, 'key');
    }
}
