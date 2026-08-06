<?php

namespace App\Models;

use Database\Factories\ProductionJournalEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'production_run_id',
    'body',
    'created_by_user_id',
])]
class ProductionJournalEntry extends Model
{
    /** @use HasFactory<ProductionJournalEntryFactory> */
    use HasFactory;

    public function productionRun(): BelongsTo
    {
        return $this->belongsTo(ProductionRun::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
