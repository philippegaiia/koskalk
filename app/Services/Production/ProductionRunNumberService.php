<?php

namespace App\Services\Production;

use App\Models\ProductionRun;
use App\Models\ProductionRunNumberSetting;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Inventory\StockLotCodeAvailability;
use App\Services\WorkspaceWriteLock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductionRunNumberService
{
    public function __construct(
        private readonly WorkspaceWriteLock $workspaceLock,
        private readonly StockLotCodeAvailability $lotCodes,
    ) {}

    public function formatPermanentNumber(string $prefix, int $serial, string $suffix, int $padding): string
    {
        return $prefix.str_pad((string) $serial, $padding, '0', STR_PAD_LEFT).$suffix;
    }

    public function formatPlanningReference(int $serial): string
    {
        return 'T'.str_pad((string) $serial, 5, '0', STR_PAD_LEFT);
    }

    public function allocatePlanningReference(Workspace $workspace): string
    {
        return DB::transaction(function () use ($workspace): string {
            [, $settings] = $this->lockWorkspaceAndSettings($workspace);
            $reference = $this->formatPlanningReference($settings->next_planning_serial);

            if ($this->identityExists($settings->workspace_id, [$reference])) {
                throw ValidationException::withMessages([
                    'planning_batch_number' => 'The next planning reference is already in use.',
                ]);
            }

            $settings->update([
                'next_planning_serial' => $settings->next_planning_serial + 1,
            ]);

            return $reference;
        }, attempts: 5);
    }

    /**
     * @return array{0: Workspace, 1: ProductionRunNumberSetting}
     */
    public function lockWorkspaceAndSettings(Workspace $workspace): array
    {
        $lockedWorkspace = $this->workspaceLock->acquire($workspace->id);

        ProductionRunNumberSetting::query()->firstOrCreate([
            'workspace_id' => $lockedWorkspace->id,
        ]);

        $settings = ProductionRunNumberSetting::query()
            ->whereBelongsTo($lockedWorkspace)
            ->lockForUpdate()
            ->sole();

        return [$lockedWorkspace, $settings];
    }

    /**
     * @return array<int, string>
     */
    public function permanentCandidates(ProductionRunNumberSetting $settings, int $count, ?int $startingSerial = null): array
    {
        $candidates = [];
        $startingSerial ??= $settings->next_permanent_serial;

        for ($offset = 0; $offset < $count; $offset++) {
            $candidates[] = $this->formatPermanentNumber(
                $settings->permanent_prefix,
                $startingSerial + $offset,
                $settings->permanent_suffix,
                $settings->permanent_padding,
            );
        }

        return $candidates;
    }

    public function nextAvailablePermanentSerial(ProductionRunNumberSetting $settings, int $count): ?int
    {
        for ($serial = $settings->next_permanent_serial + 1; $serial <= PHP_INT_MAX - $count; $serial++) {
            $candidates = $this->permanentCandidates($settings, $count, $serial);
            if (! $this->permanentIdentityExists($settings->workspace_id, $candidates)) {
                return $serial;
            }
        }

        return null;
    }

    /**
     * @return array{batch_number: string, batch_number_serial: int, batch_number_assigned_at: Carbon, batch_number_assigned_by_user_id: int}
     */
    public function permanentAssignmentValues(string $number, int $serial, User $actor): array
    {
        return [
            'batch_number' => $number,
            'batch_number_serial' => $serial,
            'batch_number_assigned_at' => now(),
            'batch_number_assigned_by_user_id' => $actor->id,
        ];
    }

    /**
     * @param  array<int, string>  $identities
     */
    public function identityExists(int $workspaceId, array $identities): bool
    {
        return ProductionRun::query()->where('workspace_id', $workspaceId)
            ->whereIn('planning_batch_number', $identities)->exists()
            || $this->lotCodes->hasIssuedBatchNumbers($workspaceId, $identities);
    }

    /** @param list<string> $identities */
    public function permanentIdentityExists(int $workspaceId, array $identities): bool
    {
        return $this->identityExists($workspaceId, $identities) || $this->lotCodes->hasStockLots($workspaceId, $identities);
    }
}
