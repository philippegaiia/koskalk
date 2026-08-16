<?php

namespace App\Actions\IngredientIntake;

use App\Models\IngredientIntakeBatch;
use App\Models\User;
use App\Services\IngredientIntake\IngredientIntakeBatchDeletionService;
use Illuminate\Support\Facades\Gate;

final class DeleteIngredientIntakeBatch
{
    public function __construct(
        private readonly IngredientIntakeBatchDeletionService $batches,
    ) {}

    public function handle(User $actor, IngredientIntakeBatch $batch): bool
    {
        Gate::forUser($actor)->authorize('delete', $batch);

        return $this->batches->delete($batch);
    }
}
