<?php

namespace App\Models;

use App\Enums\IfraAmendmentStatus;
use Database\Factories\IfraAmendmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'code',
    'status',
    'notification_date',
    'source_url',
    'notes',
])]
class IfraAmendment extends Model
{
    /** @use HasFactory<IfraAmendmentFactory> */
    use HasFactory;

    public function milestones(): HasMany
    {
        return $this->hasMany(IfraAmendmentMilestone::class);
    }

    public function productTypeMappings(): HasMany
    {
        return $this->hasMany(ProductTypeIfraCategory::class);
    }

    protected function casts(): array
    {
        return [
            'status' => IfraAmendmentStatus::class,
            'notification_date' => 'date',
        ];
    }
}
