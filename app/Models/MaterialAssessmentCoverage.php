<?php

namespace App\Models;

use App\Enums\AssessmentReviewState;
use Database\Factories\MaterialAssessmentCoverageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * What the safety assessment of one material (an ingredient or a final material such as a soap salt) rests on.
 * Platform-owned when workspace_id is null; owned by the workspace of a private ingredient otherwise.
 */
#[Fillable([
    'coverable_type',
    'coverable_id',
    'workspace_id',
    'review_state',
    'insufficient_reason',
    'covers_whole_material',
    'covers_constituents',
    'note',
    'reviewed_by_user_id',
    'reviewed_at',
])]
class MaterialAssessmentCoverage extends Model
{
    /** @use HasFactory<MaterialAssessmentCoverageFactory> */
    use HasFactory;

    public function coverable(): MorphTo
    {
        return $this->morphTo();
    }

    public function supportingSubstances(): BelongsToMany
    {
        return $this->belongsToMany(Substance::class, 'material_assessment_coverage_substance');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'review_state' => AssessmentReviewState::class,
            'covers_whole_material' => 'bool',
            'covers_constituents' => 'bool',
            'reviewed_at' => 'datetime',
        ];
    }
}
