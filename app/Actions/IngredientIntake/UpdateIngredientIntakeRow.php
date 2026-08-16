<?php

namespace App\Actions\IngredientIntake;

use App\Enums\IngredientIntakeItemStatus;
use App\Models\IngredientIntakeBatch;
use App\Models\IngredientIntakeItem;
use App\Models\User;
use App\Services\IngredientIntake\IngredientDuplicateDetector;
use App\Services\IngredientIntake\IngredientIntakeParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class UpdateIngredientIntakeRow
{
    public function __construct(
        private readonly IngredientIntakeParser $parser,
        private readonly IngredientDuplicateDetector $duplicates,
    ) {}

    public function handle(
        User $actor,
        IngredientIntakeItem $item,
        ?string $currentName,
        ?string $inciName,
    ): IngredientIntakeItem {
        Gate::forUser($actor)->authorize('update', $item->batch);

        $originalCurrentName = $this->submittedValue($currentName);
        $originalInciName = $this->submittedValue($inciName);
        $normalizedCurrentName = $this->parser->normalizeIdentityValue($originalCurrentName);
        $normalizedInciName = $this->parser->normalizeIdentityValue($originalInciName);

        if ($normalizedCurrentName === null && $normalizedInciName === null) {
            throw ValidationException::withMessages([
                'identity' => __('ingredient_intake_admin.validation.identity_required'),
            ]);
        }

        $updated = DB::transaction(function () use (
            $item,
            $originalCurrentName,
            $originalInciName,
            $normalizedCurrentName,
            $normalizedInciName,
        ): IngredientIntakeItem {
            $lockedItem = IngredientIntakeItem::query()
                ->with('batch')
                ->lockForUpdate()
                ->findOrFail($item->id);

            if (! $lockedItem->batch instanceof IngredientIntakeBatch) {
                throw ValidationException::withMessages([
                    'item' => __('ingredient_intake_admin.validation.batch_rows_required'),
                ]);
            }

            $lockedItem->update([
                'original_current_name' => $originalCurrentName,
                'normalized_current_name' => $normalizedCurrentName,
                'original_inci_name' => $originalInciName,
                'normalized_inci_name' => $normalizedInciName,
                'duplicate_candidates' => [],
                'duplicate_resolution' => null,
                'existing_ingredient_id' => null,
                'status' => IngredientIntakeItemStatus::Draft,
                'failure_code' => null,
                'failure_message' => null,
            ]);

            return $lockedItem->refresh();
        }, attempts: 5);

        return $this->duplicates->refresh($updated);
    }

    private function submittedValue(?string $value): ?string
    {
        return $value !== null && trim($value) !== '' ? $value : null;
    }
}
