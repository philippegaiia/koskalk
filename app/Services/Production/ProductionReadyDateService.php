<?php

namespace App\Services\Production;

use App\Enums\ProductionOutputType;
use App\Models\Ingredient;
use App\Models\ProductionOutputSetting;
use App\Models\Recipe;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class ProductionReadyDateService
{
    /**
     * @return array{production_output_type: ProductionOutputType, output_ingredient_id: int|null, output_ready_delay_days: int, estimated_ready_on: string|null}
     */
    public function snapshot(Recipe $recipe, Workspace $workspace, ?string $plannedFor): array
    {
        $outputType = $recipe->production_output_type ?? ProductionOutputType::FinishedProduct;
        $outputIngredientId = $recipe->output_ingredient_id;

        if ($outputType === ProductionOutputType::ManufacturedIngredient) {
            $outputIngredient = Ingredient::query()
                ->withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->where('is_active', true)
                ->where('is_manufactured', true)
                ->find($outputIngredientId);

            if (! $outputIngredient instanceof Ingredient) {
                throw ValidationException::withMessages([
                    'output_ingredient_id' => 'The manufactured output ingredient must be an active in-house ingredient from this workspace.',
                ]);
            }
        } elseif ($outputIngredientId !== null) {
            throw ValidationException::withMessages([
                'output_ingredient_id' => 'Finished product output cannot reference an ingredient.',
            ]);
        }

        $delayDays = $this->delayDays($recipe, $workspace);

        return [
            'production_output_type' => $outputType,
            'output_ingredient_id' => $outputType === ProductionOutputType::ManufacturedIngredient
                ? (int) $outputIngredientId
                : null,
            'output_ready_delay_days' => $delayDays,
            'estimated_ready_on' => $plannedFor === null
                ? null
                : $this->estimatedReadyOn($plannedFor, $delayDays),
        ];
    }

    public function delayDays(Recipe $recipe, Workspace $workspace): int
    {
        if ($recipe->ready_delay_days !== null) {
            return (int) $recipe->ready_delay_days;
        }

        $setting = $workspace->productionOutputSetting;

        if (! $setting instanceof ProductionOutputSetting) {
            $setting = ProductionOutputSetting::query()
                ->where('workspace_id', $workspace->id)
                ->first();
        }

        if ($recipe->productFamily?->calculation_basis === 'total_formula') {
            return (int) ($setting?->cosmetic_ready_delay_days ?? 3);
        }

        return (int) ($setting?->soap_ready_delay_days ?? 21);
    }

    public function estimatedReadyOn(string $baseDate, int $delayDays): string
    {
        return CarbonImmutable::parse($baseDate)
            ->addDays($delayDays)
            ->toDateString();
    }
}
