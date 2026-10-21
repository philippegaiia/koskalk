<?php

namespace App\Models;

use App\Enums\LegalAnnex;
use App\Enums\LegalEntryProductScope;
use App\Enums\LegalEntryReviewStatus;
use Database\Factories\RegulatoryLegalEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use LogicException;

/**
 * One entry/clause of an annex of Regulation (EC) No 1223/2009, transcribed from the consolidated EUR-Lex text.
 * Reviewed entries are frozen; an amendment ends one entry (effective_until) and adds a new one.
 */
#[Fillable([
    'regulatory_regime_id', 'annex', 'entry_reference', 'clause', 'substance_description',
    'product_scope', 'product_type_body_parts', 'max_concentration_percent', 'other_limitations', 'label_warnings_fr',
    'effective_from', 'effective_until', 'placing_on_market_until', 'making_available_until',
    'source_reference', 'review_status', 'reviewed_by_user_id', 'reviewed_at',
])]
class RegulatoryLegalEntry extends Model
{
    /** @use HasFactory<RegulatoryLegalEntryFactory> */
    use HasFactory;

    private const EDITABLE_AFTER_REVIEW = ['effective_until', 'updated_at'];

    protected static function booted(): void
    {
        static::updating(function (RegulatoryLegalEntry $entry): void {
            $storedStatus = static::query()->whereKey($entry->getKey())->toBase()->value('review_status');

            if ($storedStatus !== LegalEntryReviewStatus::Reviewed->value) {
                return;
            }

            if (array_diff(array_keys($entry->getDirty()), self::EDITABLE_AFTER_REVIEW) !== []) {
                throw new LogicException('Reviewed legal entries are frozen; only their end date can change.');
            }
        });

        static::deleting(function (RegulatoryLegalEntry $entry): void {
            if ($entry->isReviewed()) {
                throw new LogicException('Reviewed legal entries cannot be deleted.');
            }
        });
    }

    public function regulatoryRegime(): BelongsTo
    {
        return $this->belongsTo(RegulatoryRegime::class);
    }

    public function substances(): BelongsToMany
    {
        return $this->belongsToMany(Substance::class, 'regulatory_legal_entry_substance');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function isReviewed(): bool
    {
        return $this->review_status === LegalEntryReviewStatus::Reviewed;
    }

    protected function casts(): array
    {
        return [
            'annex' => LegalAnnex::class,
            'product_scope' => LegalEntryProductScope::class,
            'review_status' => LegalEntryReviewStatus::class,
            'max_concentration_percent' => 'decimal:6',
            'effective_from' => 'date',
            'effective_until' => 'date',
            'placing_on_market_until' => 'date',
            'making_available_until' => 'date',
            'reviewed_at' => 'datetime',
        ];
    }
}
