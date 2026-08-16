<?php

namespace App\Services;

use App\Enums\IngredientFunctionSource;
use App\Models\Ingredient;
use App\Models\IngredientFunction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class IngredientFunctionAssignmentService
{
    /**
     * Replace only manual assignments; imported and inherited assignments are
     * intentionally left untouched.
     *
     * @param  array<int, int|string>  $functionIds
     */
    public function syncManual(Ingredient $ingredient, array $functionIds, ?User $assignedBy = null): void
    {
        $validIds = IngredientFunction::query()
            ->where('is_active', true)
            ->whereIn('id', collect($functionIds)->filter(fn (mixed $id): bool => is_numeric($id))->map(fn (mixed $id): int => (int) $id)->unique())
            ->pluck('id')
            ->map(fn (int|string $id): int => (int) $id)
            ->all();

        DB::transaction(function () use ($ingredient, $validIds, $assignedBy): void {
            $lockedIngredient = Ingredient::withoutGlobalScopes()
                ->lockForUpdate()
                ->findOrFail($ingredient->id);

            $this->syncSource($lockedIngredient, IngredientFunctionSource::Manual, $validIds, $assignedBy?->id);
        }, attempts: 5);
    }

    /**
     * Reconcile the complete administrator-reviewed function selection.
     * Existing assignments retain their provenance; newly selected functions
     * are recorded as manual assignments.
     *
     * @param  array<int, int|string>  $functionIds
     */
    public function syncReviewed(Ingredient $ingredient, array $functionIds, ?User $assignedBy = null): void
    {
        $validIds = IngredientFunction::query()
            ->where('is_active', true)
            ->whereIn('id', collect($functionIds)->filter(fn (mixed $id): bool => is_numeric($id))->map(fn (mixed $id): int => (int) $id)->unique())
            ->pluck('id')
            ->map(fn (int|string $id): int => (int) $id)
            ->all();

        DB::transaction(function () use ($ingredient, $validIds, $assignedBy): void {
            $lockedIngredient = Ingredient::withoutGlobalScopes()
                ->lockForUpdate()
                ->findOrFail($ingredient->id);
            $existingAssignments = $lockedIngredient->functions()
                ->withPivot(['source', 'source_reference', 'source_checked_at', 'source_tier', 'confidence', 'source_version', 'source_updated_at', 'assigned_by_user_id'])
                ->get()
                ->keyBy('id');
            $assignments = [];

            foreach ($validIds as $functionId) {
                $existing = $existingAssignments->get($functionId)?->pivot;
                $assignments[$functionId] = [
                    'source' => $existing?->source ?? IngredientFunctionSource::Manual->value,
                    'source_reference' => $existing?->source_reference,
                    'source_checked_at' => $existing?->source_checked_at,
                    'source_tier' => $existing?->source_tier,
                    'confidence' => $existing?->confidence,
                    'source_version' => $existing?->source_version,
                    'source_updated_at' => $existing?->source_updated_at,
                    'assigned_by_user_id' => $existing?->assigned_by_user_id ?? $assignedBy?->id,
                ];
            }

            $lockedIngredient->functions()->sync($assignments);
        }, attempts: 5);
    }

    /**
     * Replace only CosIng assignments for the supplied stable function keys.
     *
     * @param  array<int, string>  $functionKeys
     */
    public function syncCosIng(
        Ingredient $ingredient,
        array $functionKeys,
        string $sourceReference,
        CarbonImmutable $checkedAt,
    ): void {
        $this->replaceCosIng(
            $ingredient,
            collect($functionKeys)
                ->filter(fn (mixed $key): bool => is_string($key) && trim($key) !== '')
                ->unique()
                ->map(fn (string $key): array => [
                    'key' => trim($key),
                    'source_reference' => $sourceReference,
                    'source_checked_at' => $checkedAt,
                ])
                ->values()
                ->all(),
        );
    }

    /**
     * Merge verified COSING assignments while preserving omitted COSING rows.
     *
     * @param  array<int, array{key:string, source_reference:string, source_checked_at:CarbonImmutable}>  $rows
     */
    public function mergeCosIng(Ingredient $ingredient, array $rows): void
    {
        $this->mutateCosIngRows($ingredient, $rows, replace: false);
    }

    /**
     * Replace verified COSING assignments while preserving manual and inherited rows.
     *
     * @param  array<int, array{key:string, source_reference:string, source_checked_at:CarbonImmutable}>  $rows
     */
    public function replaceCosIng(Ingredient $ingredient, array $rows): void
    {
        $this->mutateCosIngRows($ingredient, $rows, replace: true);
    }

    /**
     * Copy all assignments while recording the workspace copy as inherited.
     */
    public function copyTo(Ingredient $source, Ingredient $target): void
    {
        $payload = $source->functions
            ->mapWithKeys(fn (IngredientFunction $function): array => [
                $function->id => [
                    'source' => IngredientFunctionSource::Inherited->value,
                    'source_reference' => $function->pivot->source_reference,
                    'source_checked_at' => $function->pivot->source_checked_at,
                    'source_tier' => $function->pivot->source_tier,
                    'confidence' => $function->pivot->confidence,
                    'source_version' => $function->pivot->source_version,
                    'source_updated_at' => $function->pivot->source_updated_at,
                    'assigned_by_user_id' => $function->pivot->assigned_by_user_id,
                ],
            ])
            ->all();

        DB::transaction(function () use ($target, $payload): void {
            $lockedTarget = Ingredient::withoutGlobalScopes()
                ->lockForUpdate()
                ->findOrFail($target->id);

            $lockedTarget->functions()->sync($payload);
        }, attempts: 5);
    }

    /**
     * @param  array<int, array{key:string, source_reference:string, source_checked_at:CarbonImmutable}>  $rows
     */
    private function mutateCosIngRows(Ingredient $ingredient, array $rows, bool $replace): void
    {
        DB::transaction(function () use ($ingredient, $rows, $replace): void {
            $lockedIngredient = Ingredient::withoutGlobalScopes()
                ->lockForUpdate()
                ->findOrFail($ingredient->id);

            $this->syncCosIngRows($lockedIngredient, $rows, $replace);
        }, attempts: 5);
    }

    /**
     * @param  array<int, int>  $desiredIds
     */
    private function syncSource(
        Ingredient $ingredient,
        IngredientFunctionSource $source,
        array $desiredIds,
        ?int $assignedByUserId = null,
        ?string $sourceReference = null,
        ?CarbonImmutable $sourceCheckedAt = null,
    ): void {
        $assignments = $ingredient->functions()
            ->withPivot(['source', 'source_reference', 'source_checked_at', 'source_tier', 'confidence', 'source_version', 'source_updated_at', 'assigned_by_user_id'])
            ->get()
            ->mapWithKeys(fn (IngredientFunction $function): array => [$function->id => [
                'source' => $function->pivot->source ?? IngredientFunctionSource::Manual->value,
                'source_reference' => $function->pivot->source_reference,
                'source_checked_at' => $function->pivot->source_checked_at,
                'source_tier' => $function->pivot->source_tier,
                'confidence' => $function->pivot->confidence,
                'source_version' => $function->pivot->source_version,
                'source_updated_at' => $function->pivot->source_updated_at,
                'assigned_by_user_id' => $function->pivot->assigned_by_user_id,
            ]])
            ->all();

        foreach ($assignments as $functionId => $assignment) {
            if ($assignment['source'] === $source->value && ! in_array((int) $functionId, $desiredIds, true)) {
                unset($assignments[$functionId]);
            }
        }

        foreach ($desiredIds as $functionId) {
            $existing = $assignments[$functionId] ?? null;

            if ($existing !== null && $existing['source'] !== $source->value) {
                if ($source !== IngredientFunctionSource::CosIng) {
                    continue;
                }
            }

            $assignments[$functionId] = [
                'source' => $source->value,
                'source_reference' => $sourceReference ?? $existing['source_reference'] ?? null,
                'source_checked_at' => $sourceCheckedAt?->toDateTimeString() ?? $existing['source_checked_at'] ?? null,
                'source_tier' => $existing['source_tier'] ?? null,
                'confidence' => $existing['confidence'] ?? null,
                'source_version' => $existing['source_version'] ?? null,
                'source_updated_at' => $existing['source_updated_at'] ?? null,
                'assigned_by_user_id' => $assignedByUserId ?? $existing['assigned_by_user_id'] ?? null,
            ];
        }

        $ingredient->functions()->sync($assignments);
    }

    /**
     * @param  array<int, array{key:string, source_reference:string, source_checked_at:CarbonImmutable}>  $rows
     */
    private function syncCosIngRows(Ingredient $ingredient, array $rows, bool $replace): void
    {
        $normalizedRows = collect($rows)
            ->filter(fn (mixed $row): bool => is_array($row) && is_string($row['key'] ?? null))
            ->map(fn (array $row): array => [
                'key' => trim((string) $row['key']),
                'source_reference' => trim((string) ($row['source_reference'] ?? '')),
                'source_checked_at' => $row['source_checked_at'] instanceof CarbonImmutable
                    ? $row['source_checked_at']
                    : CarbonImmutable::parse((string) ($row['source_checked_at'] ?? now()->toDateString())),
                'source_tier' => $row['source_tier'] ?? null,
                'confidence' => $row['confidence'] ?? null,
                'source_version' => $row['source_version'] ?? null,
                'source_updated_at' => $row['source_updated_at'] ?? null,
            ])
            ->filter(fn (array $row): bool => $row['key'] !== '')
            ->unique('key')
            ->values();
        $keys = $normalizedRows->pluck('key')->all();
        $functions = IngredientFunction::query()
            ->where('is_active', true)
            ->whereIn('key', $keys)
            ->get(['id', 'key'])
            ->keyBy('key');

        $assignments = $ingredient->functions()
            ->withPivot(['source', 'source_reference', 'source_checked_at', 'source_tier', 'confidence', 'source_version', 'source_updated_at', 'assigned_by_user_id'])
            ->get()
            ->mapWithKeys(fn (IngredientFunction $function): array => [$function->id => [
                'source' => $function->pivot->source ?? IngredientFunctionSource::Manual->value,
                'source_reference' => $function->pivot->source_reference,
                'source_checked_at' => $function->pivot->source_checked_at,
                'source_tier' => $function->pivot->source_tier,
                'confidence' => $function->pivot->confidence,
                'source_version' => $function->pivot->source_version,
                'source_updated_at' => $function->pivot->source_updated_at,
                'assigned_by_user_id' => $function->pivot->assigned_by_user_id,
            ]])
            ->all();

        $desiredIds = [];
        foreach ($normalizedRows as $row) {
            $function = $functions->get($row['key']);
            if (! $function instanceof IngredientFunction) {
                continue;
            }

            $desiredIds[] = $function->id;
            $existing = $assignments[$function->id] ?? null;
            $assignments[$function->id] = [
                'source' => IngredientFunctionSource::CosIng->value,
                'source_reference' => $row['source_reference'] !== ''
                    ? $row['source_reference']
                    : $existing['source_reference'] ?? null,
                'source_checked_at' => $row['source_checked_at']->toDateTimeString(),
                'source_tier' => $row['source_tier'],
                'confidence' => $row['confidence'],
                'source_version' => $row['source_version'],
                'source_updated_at' => $row['source_updated_at'],
                'assigned_by_user_id' => $existing['assigned_by_user_id'] ?? null,
            ];
        }

        if ($replace) {
            foreach ($assignments as $functionId => $assignment) {
                if ($assignment['source'] === IngredientFunctionSource::CosIng->value
                    && ! in_array((int) $functionId, $desiredIds, true)) {
                    unset($assignments[$functionId]);
                }
            }
        }

        $ingredient->functions()->sync($assignments);
    }
}
