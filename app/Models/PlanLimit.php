<?php

namespace App\Models;

use Database\Factories\PlanLimitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['plan_id', 'key', 'value'])]
class PlanLimit extends Model
{
    /** @use HasFactory<PlanLimitFactory> */
    use HasFactory;

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    protected function casts(): array
    {
        return [
            'value' => 'int',
        ];
    }
}
