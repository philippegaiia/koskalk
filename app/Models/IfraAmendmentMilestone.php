<?php

namespace App\Models;

use App\Enums\IfraCreationTrack;
use App\Enums\IfraStandardKind;
use Database\Factories\IfraAmendmentMilestoneFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'ifra_amendment_id',
    'standard_kind',
    'creation_track',
    'effective_on',
])]
class IfraAmendmentMilestone extends Model
{
    /** @use HasFactory<IfraAmendmentMilestoneFactory> */
    use HasFactory;

    public function ifraAmendment(): BelongsTo
    {
        return $this->belongsTo(IfraAmendment::class);
    }

    protected function casts(): array
    {
        return [
            'standard_kind' => IfraStandardKind::class,
            'creation_track' => IfraCreationTrack::class,
            'effective_on' => 'date',
        ];
    }
}
