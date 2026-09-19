<?php

namespace App\Services\IngredientEnrichment;

use App\Enums\IngredientEnrichmentItemStatus;
use App\Models\Ingredient;
use App\Models\IngredientEnrichmentBatchItem;
use App\Models\IngredientIntakeItem;

class IngredientEnrichmentSubjectAvailability
{
    public function rejectUnavailable(IngredientEnrichmentBatchItem $item, Ingredient|IngredientIntakeItem|null $subject): bool
    {
        if ($subject instanceof IngredientIntakeItem
            || ($subject instanceof Ingredient && $subject->owner_type === null && $subject->owner_id === null)) {
            return false;
        }

        $item->update([
            'status' => IngredientEnrichmentItemStatus::Failed,
            'failure_code' => IngredientEnrichmentBatchItem::SUBJECT_UNAVAILABLE,
            'failure_message' => $subject === null
                ? __('ingredient_enrichment_admin.validation.subject_unavailable')
                : __('ingredient_enrichment.validation.platform_only_apply'),
        ]);

        return true;
    }
}
