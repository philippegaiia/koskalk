<?php

namespace App\Services;

use App\Models\ProductFamily;

class RecipeWorkbenchPhaseBlueprints
{
    /** Liquid soap dilution steps whose rows are a % of that step's water. */
    public const DILUTION_PHASES = ['first_dilution', 'final_dilution'];

    /** Additions made after the reaction; on a liquid soap they are a share of the finished batch. */
    public const ADDITION_PHASES = ['additives', 'fragrance'];

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

        return $this->soapPhases($productFamily?->usesLiquidSoapPasteBench() ?? false);
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
     * Soap phases in bench order. Liquid soap paste families add the first
     * dilution and neutralization after the alkali solution and the final
     * dilution last; bar soap never has them.
     *
     * @return array<int, array<string, mixed>>
     */
    private function soapPhases(bool $withNeutralization): array
    {
        $phases = [
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

        if (! $withNeutralization) {
            return $phases;
        }

        $dilution = fn (string $key, string $name, string $description): array => [
            'key' => $key,
            'name' => $name,
            'phase_group' => 'post_reaction',
            'phase_type' => 'post_reaction',
            'description' => $description,
            'is_system' => true,
        ];

        array_splice($phases, 2, 0, [
            $dilution('first_dilution', 'First dilution', 'Liquids that replace part of the first dilution water of a liquid soap paste.'),
            [
                'key' => 'neutralization',
                'name' => 'Neutralization',
                'phase_group' => 'post_reaction',
                'phase_type' => 'post_reaction',
                'description' => 'The acid that neutralizes free KOH left in a liquid soap paste.',
                'is_system' => true,
            ],
        ]);
        $phases[] = $dilution('final_dilution', 'Final dilution', 'Liquids that replace part of the final dilution water of a liquid soap.');

        return $phases;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function indexedPhaseBlueprints(): array
    {
        if (is_array($this->indexedPhaseBlueprints)) {
            return $this->indexedPhaseBlueprints;
        }

        return $this->indexedPhaseBlueprints = array_column($this->soapPhases(withNeutralization: true), null, 'key');
    }
}
