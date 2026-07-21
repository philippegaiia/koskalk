<?php

namespace App\Services;

use App\Models\Recipe;
use App\Models\RecipeVersion;

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
        $document = $viewData['formulaDocument'];

        return [
            'document' => $document,
            'ingredientRows' => collect($document['sections'])
                ->flatMap(fn (array $section) => collect($section['rows'])->map(fn (array $row): array => [
                    'section' => $section['label'],
                    'ingredient' => $row['name'],
                    'percentage_basis' => $document['percentage_basis'],
                    'percentage' => $row['percentage'],
                    'weight' => $row['weight'],
                    'unit' => $document['unit'],
                    'note' => $row['note'] ?? '',
                ]))
                ->values()
                ->all(),
        ];
    }
}
