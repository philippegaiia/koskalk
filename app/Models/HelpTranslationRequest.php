<?php

namespace App\Models;

use App\Enums\HelpTranslationRequestStatus;
use App\Models\Concerns\HasPublicId;
use Database\Factories\HelpTranslationRequestFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'public_id',
    'help_topic_locale_id',
    'source_english_revision_id',
    'expected_target_lock_version',
    'status',
    'result',
    'accepted_revision_id',
    'requested_by',
    'model',
    'response_model',
    'prompt_version',
    'reasoning_effort',
    'processing_token',
    'response_id',
    'request_id',
    'input_tokens',
    'output_tokens',
    'error_code',
    'error_message',
    'started_at',
    'completed_at',
])]
class HelpTranslationRequest extends Model
{
    /** @use HasFactory<HelpTranslationRequestFactory> */
    use HasFactory;

    use HasPublicId;

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new DomainException('Help audit records cannot be deleted.');
        });
    }

    public function topicLocale(): BelongsTo
    {
        return $this->belongsTo(HelpTopicLocale::class, 'help_topic_locale_id');
    }

    public function sourceEnglishRevision(): BelongsTo
    {
        return $this->belongsTo(HelpTopicRevision::class, 'source_english_revision_id');
    }

    public function acceptedRevision(): BelongsTo
    {
        return $this->belongsTo(HelpTopicRevision::class, 'accepted_revision_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    protected function casts(): array
    {
        return [
            'expected_target_lock_version' => 'integer',
            'status' => HelpTranslationRequestStatus::class,
            'result' => 'array',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
