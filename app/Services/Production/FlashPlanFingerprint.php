<?php

namespace App\Services\Production;

use App\Models\ProductionLocation;
use App\Models\Workspace;
use App\Support\NumberLocale;
use Illuminate\Validation\ValidationException;

class FlashPlanFingerprint
{
    /** @param list<array<string, mixed>> $lines */
    public function request(array $lines, string $firstDate, int $limit, bool $usesProductionLocations = true): string
    {
        $normalized = collect($lines)->map(function (mixed $line) use ($usesProductionLocations): array {
            if (! is_array($line)) {
                throw ValidationException::withMessages(['lines' => __('production_bench.production.validation.flash_products_required')]);
            }

            return collect(['recipe_id', 'preset_id', 'desired_units', 'expected_units_per_batch', 'basis_input_value', 'basis_input_unit', 'task_set_id', 'production_location_id'])
                ->mapWithKeys(function (string $key) use ($line, $usesProductionLocations): array {
                    $value = $key === 'production_location_id' && ! $usesProductionLocations ? '' : ($line[$key] ?? '');
                    if (! is_scalar($value) && ! $value instanceof \BackedEnum) {
                        throw ValidationException::withMessages(['lines' => __('production_bench.production.validation.flash_products_required')]);
                    }
                    $value = $value instanceof \BackedEnum ? $value->value : trim((string) $value);
                    if (in_array($key, ['recipe_id', 'preset_id', 'task_set_id'], true) && preg_match('/^\d+$/', (string) $value) === 1) {
                        $value = (string) (int) $value;
                    }
                    if (in_array($key, ['desired_units', 'expected_units_per_batch'], true) && preg_match('/^[1-9]\d*$/', (string) $value) === 1) {
                        $value = (string) (int) $value;
                    }
                    if ($key === 'production_location_id' && $value !== '' && filter_var($value, FILTER_VALIDATE_INT) !== false) {
                        $value = (string) (int) $value;
                    }
                    if ($key === 'basis_input_value') {
                        $number = NumberLocale::normalizeDecimalString($value);
                        $value = $number !== null && is_numeric($number) ? bcadd($number, '0', 9) : $value;
                    }

                    return [$key => $value];
                })->all();
        })->values()->all();

        return hash('sha256', json_encode([$normalized, $firstDate, $limit], JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $simulation
     * @param  list<array<string, mixed>>  $proposals
     */
    public function proposal(array $simulation, array $proposals, Workspace $workspace, int $limit): string
    {
        $lines = collect($simulation['lines'])->map(fn (array $line): array => collect($line)->only([
            'line_index', 'recipe_id', 'recipe_version_id', 'desired_units', 'whole_batches', 'expected_units_per_batch',
            'basis_input_value', 'basis_input_unit', 'task_set_id', 'production_location_id', 'output_ready_delay_days',
        ])->all())->all();
        $requirements = collect($simulation['requirements'])->map(fn (array $row): array => collect($row)->only([
            'ingredient_id', 'packaging_item_id', 'required', 'material_code',
        ])->all())->all();
        $locations = $workspace->uses_production_locations
            ? ProductionLocation::query()->where('workspace_id', $workspace->id)->whereIn('id', collect($proposals)->pluck('production_location_id')->filter())
                ->orderBy('id')->get(['id', 'daily_production_limit', 'is_active'])->toArray()
            : [];
        $proposals = collect($proposals)->map(fn (array $proposal): array => [
            ...collect($proposal)->only(['line_index', 'recipe_id', 'batch_number', 'batch_total', 'production_location_id', 'production_date', 'estimated_ready_on'])->all(),
            'tasks' => collect($proposal['tasks'])->map(fn (array $task): array => collect($task)->only(['scheduled_for', 'days_after_production', 'duration_minutes'])->all())->all(),
        ])->all();

        return hash('sha256', json_encode([$lines, $requirements, $proposals, (bool) $workspace->uses_production_locations, $locations, $limit], JSON_THROW_ON_ERROR));
    }
}
