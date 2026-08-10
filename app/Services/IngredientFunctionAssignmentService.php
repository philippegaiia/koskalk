<?php

namespace App\Services;

use App\Enums\IngredientFunctionSource;
use App\Models\Ingredient;
use App\Models\IngredientFunction;
use App\Models\User;
use Carbon\CarbonImmutable;

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

        $this->syncSource($ingredient, IngredientFunctionSource::Manual, $validIds, $assignedBy?->id);
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
        $validIds = IngredientFunction::query()
            ->where('is_active', true)
            ->whereIn('key', collect($functionKeys)->filter(fn (mixed $key): bool => is_string($key) && $key !== '')->unique())
            ->pluck('id')
            ->map(fn (int|string $id): int => (int) $id)
            ->all();

        $this->syncSource(
            $ingredient,
            IngredientFunctionSource::CosIng,
            $validIds,
            null,
            $sourceReference,
            $checkedAt,
        );
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
                    'assigned_by_user_id' => $function->pivot->assigned_by_user_id,
                ],
            ])
            ->all();

        $target->functions()->sync($payload);
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
            ->withPivot(['source', 'source_reference', 'source_checked_at', 'assigned_by_user_id'])
            ->get()
            ->mapWithKeys(fn (IngredientFunction $function): array => [$function->id => [
                'source' => $function->pivot->source ?? IngredientFunctionSource::Manual->value,
                'source_reference' => $function->pivot->source_reference,
                'source_checked_at' => $function->pivot->source_checked_at,
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
                'assigned_by_user_id' => $assignedByUserId ?? $existing['assigned_by_user_id'] ?? null,
            ];
        }

        $ingredient->functions()->sync($assignments);
    }
}
