<?php

namespace App\Actions\Production;

use App\MassUnit;
use App\Models\ProductionRun;
use App\Models\Recipe;
use App\Models\User;
use App\Models\Workspace;
use App\ProductionRunSource;
use App\ProductionRunStatus;

class PlanProduction
{
    public function __construct(private readonly CreateProductionDraft $createProductionDraft) {}

    public function handle(
        User $actor,
        Workspace $workspace,
        Recipe $recipe,
        string $basisInputValue,
        MassUnit|string $basisInputUnit,
        int|string|float $expectedUnits,
        string $idempotencyKey,
        ?string $plannedFor = null,
        ?string $notes = null,
        ProductionRunSource $source = ProductionRunSource::Direct,
    ): ProductionRun {
        return $this->createProductionDraft->handle(
            actor: $actor,
            workspace: $workspace,
            recipe: $recipe,
            basisInputValue: $basisInputValue,
            basisInputUnit: $basisInputUnit,
            expectedUnits: $expectedUnits,
            idempotencyKey: $idempotencyKey,
            plannedFor: $plannedFor,
            notes: $notes,
            source: $source,
            status: ProductionRunStatus::Scheduled,
        );
    }
}
