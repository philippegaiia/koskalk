<?php

namespace Database\Factories;

use App\Enums\AssessmentReviewState;
use App\Models\Ingredient;
use App\Models\MaterialAssessmentCoverage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaterialAssessmentCoverage>
 */
class MaterialAssessmentCoverageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'coverable_type' => Ingredient::class,
            'coverable_id' => Ingredient::factory(),
            'workspace_id' => null,
            'review_state' => AssessmentReviewState::NotReviewed,
            'insufficient_reason' => null,
            'covers_whole_material' => false,
            'covers_constituents' => false,
            'note' => null,
            'reviewed_by_user_id' => null,
            'reviewed_at' => null,
        ];
    }
}
