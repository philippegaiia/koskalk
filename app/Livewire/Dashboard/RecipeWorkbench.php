<?php

namespace App\Livewire\Dashboard;

use App\IngredientCategory;
use App\Models\IfraProductCategory;
use App\Models\IngredientVersion;
use App\Models\ProductFamily;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;

class RecipeWorkbench extends Component
{
    public function render(): View
    {
        $soapFamily = ProductFamily::query()
            ->where('slug', 'soap')
            ->first();

        return view('livewire.dashboard.recipe-workbench', [
            'workbench' => [
                'productFamily' => $soapFamily === null ? null : [
                    'id' => $soapFamily->id,
                    'name' => $soapFamily->name,
                    'slug' => $soapFamily->slug,
                    'calculation_basis' => $soapFamily->calculation_basis,
                ],
                'phases' => $this->phaseBlueprints(),
                'ingredients' => $this->ingredientCatalog(),
                'ifraProductCategories' => IfraProductCategory::query()
                    ->where('is_active', true)
                    ->orderBy('code')
                    ->get()
                    ->map(fn (IfraProductCategory $category): array => [
                        'id' => $category->id,
                        'code' => $category->code,
                        'name' => $category->name,
                    ])
                    ->all(),
            ],
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function ingredientCatalog(): array
    {
        return IngredientVersion::query()
            ->with(['ingredient', 'sapProfile'])
            ->where('is_current', true)
            ->where('is_active', true)
            ->whereHas('ingredient', function (Builder $query): void {
                $query->where('is_active', true)
                    ->whereIn('category', [
                        IngredientCategory::CarrierOil->value,
                        IngredientCategory::EssentialOil->value,
                        IngredientCategory::BotanicalExtract->value,
                        IngredientCategory::Co2Extract->value,
                        IngredientCategory::Colorant->value,
                        IngredientCategory::Preservative->value,
                        IngredientCategory::Additive->value,
                    ]);
            })
            ->get()
            ->filter(function (IngredientVersion $version): bool {
                $category = $version->ingredient?->category;

                if ($category === IngredientCategory::CarrierOil) {
                    return $version->ingredient?->isAvailableForInitialSoapCalculation() ?? false;
                }

                return $category !== null;
            })
            ->map(function (IngredientVersion $version): array {
                $category = $version->ingredient?->category;
                $sapProfile = $version->sapProfile;

                return [
                    'id' => $version->id,
                    'ingredient_id' => $version->ingredient_id,
                    'name' => $version->display_name,
                    'inci_name' => $version->inci_name,
                    'category' => $category?->value,
                    'category_label' => $category?->getLabel(),
                    'soap_inci_naoh_name' => $version->soap_inci_naoh_name,
                    'soap_inci_koh_name' => $version->soap_inci_koh_name,
                    'needs_compliance' => $category !== null && in_array($category->value, IngredientCategory::aromaticValues(), true),
                    'koh_sap_value' => $sapProfile?->koh_sap_value === null ? null : (float) $sapProfile->koh_sap_value,
                    'naoh_sap_value' => $sapProfile?->naoh_sap_value,
                    'fatty_acid_profile' => $sapProfile?->fattyAcidProfile() ?? [],
                ];
            })
            ->sortBy('name')
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function phaseBlueprints(): array
    {
        return [
            [
                'key' => 'saponified_oils',
                'name' => 'Saponified Oils',
                'phase_group' => 'reaction_core',
                'description' => 'Carrier oils and butters that drive the soap calculation itself.',
            ],
            [
                'key' => 'lye_water',
                'name' => 'Lye Water',
                'phase_group' => 'reaction_core',
                'description' => 'The reaction medium: alkali, water mode, and superfat settings.',
            ],
            [
                'key' => 'additives',
                'name' => 'Additives',
                'phase_group' => 'post_reaction',
                'description' => 'Colorants, preservatives, and functional additions added after the core soap calculation.',
            ],
            [
                'key' => 'fragrance',
                'name' => 'Fragrance And Aromatics',
                'phase_group' => 'post_reaction',
                'description' => 'Essential oils, aromatic extracts, and later user-authored fragrance oils.',
            ],
        ];
    }
}
