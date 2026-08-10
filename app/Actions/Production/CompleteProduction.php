<?php

namespace App\Actions\Production;

use App\Models\ProductionRun;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Production\ProductionCompletionService;
use App\Services\ProductionBenchAccess;
use Illuminate\Validation\ValidationException;

class CompleteProduction
{
    public function __construct(
        private readonly ProductionBenchAccess $access,
        private readonly ProductionCompletionService $completionService,
    ) {}

    public function handle(
        User $actor,
        ProductionRun $production,
        string $actualOutputQuantity,
        string $manufactureDate,
        ?string $estimatedReadyOn = null,
        ?int $outputIngredientId = null,
    ): ProductionRun {
        $workspace = $production->workspace;

        if (! $workspace instanceof Workspace) {
            throw ValidationException::withMessages([
                'production' => __('production_bench.production.workspace_missing'),
            ]);
        }

        $this->access->assertWritable($actor, $workspace);

        return $this->completionService->complete(
            actor: $actor,
            production: $production,
            actualOutputQuantity: $actualOutputQuantity,
            manufactureDate: $manufactureDate,
            estimatedReadyOn: $estimatedReadyOn,
            outputIngredientId: $outputIngredientId,
        );
    }
}
