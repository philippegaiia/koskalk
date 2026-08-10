<?php

namespace App\Services\Production;

use App\Models\ProductionRun;
use App\Models\ProductionRunNumberIssuance;
use App\Models\ProductionRunNumberSetting;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductionRunNumberService
{
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
        $lockedWorkspace = Workspace::withoutGlobalScopes()
            ->lockForUpdate()
            ->findOrFail($workspace->id);

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
    public function permanentCandidates(ProductionRunNumberSetting $settings, int $count): array
    {
        $candidates = [];

        for ($offset = 0; $offset < $count; $offset++) {
            $candidates[] = $this->formatPermanentNumber(
                $settings->permanent_prefix,
                $settings->next_permanent_serial + $offset,
                $settings->permanent_suffix,
                $settings->permanent_padding,
            );
        }

        return $candidates;
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
        return ProductionRun::query()
            ->where('workspace_id', $workspaceId)
            ->where(function ($query) use ($identities): void {
                $query->whereIn('planning_batch_number', $identities)
                    ->orWhereIn('batch_number', $identities);
            })
            ->exists()
            || ProductionRunNumberIssuance::query()
                ->where('workspace_id', $workspaceId)
                ->whereIn('batch_number', $identities)
                ->exists();
    }
}
