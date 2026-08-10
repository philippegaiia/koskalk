<?php

namespace App\Actions\Production;

use App\Models\ProductionOutputSetting;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveProductionOutputSettings
{
    public function __construct(private readonly ProductionBenchAccess $access) {}

    public function handle(
        User $actor,
        Workspace $workspace,
        int|string $soapReadyDelayDays,
        int|string $cosmeticReadyDelayDays,
    ): ProductionOutputSetting {
        $this->access->assertWritable($actor, $workspace);

        $soapReadyDelayDays = $this->normalizeDays($soapReadyDelayDays, 'soap_ready_delay_days');
        $cosmeticReadyDelayDays = $this->normalizeDays($cosmeticReadyDelayDays, 'cosmetic_ready_delay_days');

        return DB::transaction(function () use (
            $actor,
            $cosmeticReadyDelayDays,
            $soapReadyDelayDays,
            $workspace,
        ): ProductionOutputSetting {
            $lockedWorkspace = Workspace::withoutGlobalScopes()
                ->lockForUpdate()
                ->findOrFail($workspace->id);
            $this->access->assertWritable($actor, $lockedWorkspace);

            $setting = ProductionOutputSetting::query()
                ->where('workspace_id', $lockedWorkspace->id)
                ->lockForUpdate()
                ->first();

            if (! $setting instanceof ProductionOutputSetting) {
                $setting = ProductionOutputSetting::query()->create([
                    'workspace_id' => $lockedWorkspace->id,
                    'soap_ready_delay_days' => 21,
                    'cosmetic_ready_delay_days' => 3,
                ]);
            }

            $setting->update([
                'soap_ready_delay_days' => $soapReadyDelayDays,
                'cosmetic_ready_delay_days' => $cosmeticReadyDelayDays,
            ]);

            return $setting->fresh();
        }, attempts: 5);
    }

    private function normalizeDays(int|string $value, string $field): int
    {
        $normalized = trim((string) $value);

        if (preg_match('/^\d+$/', $normalized) !== 1) {
            throw ValidationException::withMessages([
                $field => __('production_bench.settings.ready_delay_whole_number'),
            ]);
        }

        return (int) $normalized;
    }
}
