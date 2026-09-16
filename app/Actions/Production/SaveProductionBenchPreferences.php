<?php

namespace App\Actions\Production;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Production\FlashProductionLimits;
use App\Services\ProductionBenchAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveProductionBenchPreferences
{
    public function __construct(private readonly ProductionBenchAccess $access) {}

    public function handle(
        User $actor,
        Workspace $workspace,
        bool $usesProductionLocations,
        bool $usesStorageLocations,
        int|string $productionDailyLimit,
    ): Workspace {
        $this->access->assertCanConfigure($actor, $workspace);
        $productionDailyLimit = $this->normalizeProductionDailyLimit($productionDailyLimit);

        return DB::transaction(function () use (
            $actor,
            $productionDailyLimit,
            $usesProductionLocations,
            $usesStorageLocations,
            $workspace,
        ): Workspace {
            $lockedWorkspace = Workspace::withoutGlobalScopes()
                ->lockForUpdate()
                ->findOrFail($workspace->id);
            $this->access->assertCanConfigure($actor, $lockedWorkspace);

            $lockedWorkspace->update([
                'uses_production_locations' => $usesProductionLocations,
                'uses_storage_locations' => $usesStorageLocations,
                'production_daily_limit' => $productionDailyLimit,
            ]);

            return $lockedWorkspace->fresh();
        }, attempts: 5);
    }

    private function normalizeProductionDailyLimit(int|string $value): int
    {
        $normalized = trim((string) $value);
        $maximum = FlashProductionLimits::MAX_BATCHES_PER_SUBMISSION;

        if (
            preg_match('/^[1-9]\d*$/', $normalized) !== 1
            || strlen($normalized) > strlen((string) $maximum)
            || (strlen($normalized) === strlen((string) $maximum) && strcmp($normalized, (string) $maximum) > 0)
        ) {
            throw ValidationException::withMessages([
                'production_daily_limit' => __('locations.validation.production_daily_limit'),
            ]);
        }

        return (int) $normalized;
    }
}
