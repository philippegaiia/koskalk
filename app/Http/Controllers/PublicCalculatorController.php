<?php

namespace App\Http\Controllers;

use App\Http\Requests\PublicCalculatorRequest;
use App\Services\PublicCalculatorCatalog;
use App\Services\PublicCalculatorService;
use App\Services\RecipeWorkbenchIngredientCatalogBuilder;
use App\Services\RecipeWorkbenchViewDataBuilder;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class PublicCalculatorController extends Controller
{
    public function index(PublicCalculatorCatalog $catalog): View
    {
        return view('calculators.index', ['calculators' => $catalog->calculators()]);
    }

    public function show(string $calculator, PublicCalculatorCatalog $catalog, RecipeWorkbenchViewDataBuilder $builder, RecipeWorkbenchIngredientCatalogBuilder $ingredientBuilder): Response
    {
        $ingredients = $catalog->ingredients();
        $configuration = $catalog->configuration($calculator, $ingredients);
        $family = $catalog->family($calculator);
        $workbench = $builder->build($family, null, null, ingredientCatalog: $ingredientBuilder->build(null, $family, $ingredients));
        $publicIngredients = collect($configuration['catalogue'])->keyBy('id');
        $workbench['ingredients'] = collect($workbench['ingredients'])
            ->filter(fn (array $ingredient): bool => $publicIngredients->has($ingredient['id']))
            ->map(fn (array $ingredient): array => [...$ingredient, 'fingerprint' => $publicIngredients->get($ingredient['id'])['fingerprint']])
            ->values()->all();
        unset($configuration['catalogue']);
        $workbench = [...$workbench,
            'guestCalculator' => [...$configuration, 'csrfToken' => csrf_token()],
            'canPersist' => false, 'canEditRecipe' => true, 'formulaItemLimit' => 100,
            'productTypes' => [], 'manufacturedIngredients' => [], 'packagingCatalog' => [],
            'defaultRegulatoryRegime' => 'none', 'regulatoryRegimes' => [],
            'ifraProductCategories' => [], 'numberLocale' => $configuration['numberLocale'],
        ];

        return response()->view('calculators.show', ['calculator' => $configuration, 'workbench' => $workbench])->header('Cache-Control', 'no-store');
    }

    public function preview(string $calculator, PublicCalculatorRequest $request, PublicCalculatorService $service): JsonResponse
    {
        return response()->json($service->calculate($calculator, $request->validated('draft')))->header('Cache-Control', 'no-store');
    }

    public function print(string $calculator, PublicCalculatorRequest $request, PublicCalculatorService $service, PublicCalculatorCatalog $catalog): Response
    {
        return response()->view('calculators.print', [
            ...$service->calculate($calculator, $request->validated('draft')),
            'calculator' => collect($catalog->calculators())->firstWhere('key', $calculator), 'numberLocale' => $catalog->numberLocale(),
        ])->header('Cache-Control', 'no-store');
    }
}
