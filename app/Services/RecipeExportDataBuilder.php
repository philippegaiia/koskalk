<?php

namespace App\Services;

use App\Models\Recipe;
use App\Models\RecipeVersion;
use Illuminate\Support\Collection;

class RecipeExportDataBuilder
{
    public function __construct(
        private readonly RecipeVersionViewDataBuilder $recipeVersionViewDataBuilder,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Recipe $recipe, RecipeVersion $version, mixed $requestedOilWeight = null, array $batchContext = []): array
    {
        $viewData = $this->recipeVersionViewDataBuilder->build($recipe, $version, $requestedOilWeight, $batchContext);
        $snapshot = $viewData['snapshot'];
        $draft = $snapshot['draft'];
        $isCosmetic = $recipe->productFamily?->calculation_basis === 'total_formula';

        return [
            'recipe' => [
                'name' => $recipe->name,
                'product_family' => $recipe->productFamily?->name,
                'product_type' => $recipe->productType?->name,
                'saved_name' => $version->name,
                'saved_at' => $version->saved_at?->format('Y-m-d H:i'),
                'batch_basis_label' => $isCosmetic ? 'Total batch quantity' : 'Oil quantity',
                'batch_basis' => $viewData['selectedOilWeight'],
                'batch_unit' => $draft['oilUnit'] ?? 'g',
            ],
            'batchContext' => $viewData['batchContext'],
            'summaryRows' => $this->keyValueRows($viewData['summaryCards']),
            'contextRows' => $viewData['contextRows'],
            'formulaRows' => $this->formulaRows($viewData['phaseSections']),
            'phaseSections' => $viewData['phaseSections'],
            'packagingRows' => $viewData['packagingPlanRows'],
            'lyeRows' => $viewData['lyeRows'],
            'declarationRows' => $this->declarationRows($snapshot),
            'costingSummary' => $viewData['costingSummary'],
            'costingIngredientRows' => $viewData['costingIngredientRows'],
            'costingPackagingRows' => $viewData['costingPackagingRows'],
            'costingCurrency' => $viewData['costingCurrency'],
            'hasCostingData' => $viewData['hasCostingData'],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $phaseSections
     * @return array<int, array<string, mixed>>
     */
    private function formulaRows(array $phaseSections): array
    {
        return collect($phaseSections)
            ->flatMap(fn (array $section): Collection => collect($section['rows'] ?? [])
                ->map(fn (array $row): array => [
                    'phase' => $section['label'] ?? '',
                    'ingredient' => $row['name'] ?? '',
                    'inci_name' => $row['inci_name'] ?? '',
                    'percentage' => $row['percentage'] ?? 0,
                    'weight' => $row['weight'] ?? 0,
                    'note' => $row['note'] ?? '',
                ]))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, scalar|null>>  $cards
     * @return array<int, array{label: string, value: string}>
     */
    private function keyValueRows(array $cards): array
    {
        return collect($cards)
            ->map(fn (array $row): array => [
                'label' => (string) ($row['label'] ?? ''),
                'value' => trim((string) ($row['value'] ?? '').' '.(string) ($row['unit'] ?? '')),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<int, array{label: string, value: string}>
     */
    private function declarationRows(array $snapshot): array
    {
        $labeling = is_array($snapshot['labeling'] ?? null) ? $snapshot['labeling'] : [];

        return collect([
            'Ingredient list' => $labeling['ingredient_list'] ?? null,
            'Contains' => $labeling['contains'] ?? null,
            'Allergens' => $labeling['allergens'] ?? null,
            'Warnings' => $labeling['warnings'] ?? null,
        ])
            ->filter(fn (mixed $value): bool => filled($value))
            ->map(fn (mixed $value, string $label): array => [
                'label' => $label,
                'value' => (string) $value,
            ])
            ->values()
            ->all();
    }
}
