<?php

namespace App\Services\Production;

use App\Models\ProductionOutputSetting;
use App\Models\Recipe;
use App\Models\Workspace;
use Carbon\CarbonImmutable;

class ProductionReadyDateService
{
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
