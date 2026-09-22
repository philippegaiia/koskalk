<?php

namespace App\Models;

use App\Enums\HelpContentOrigin;
use App\Models\Concerns\HasPublicId;
use Database\Factories\HelpTopicRevisionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'public_id',
    'help_topic_locale_id',
    'revision_number',
    'title',
    'summary',
    'body_markdown',
    'source_english_revision_id',
    'origin',
    'ai_model',
    'prompt_version',
    'created_by',
    'created_at',
])]
class HelpTopicRevision extends Model
{
    /** @use HasFactory<HelpTopicRevisionFactory> */
    use HasFactory;

    use HasPublicId;

    public const UPDATED_AT = null;

    public function topicLocale(): BelongsTo
    {
        return $this->belongsTo(HelpTopicLocale::class, 'help_topic_locale_id');
    }

    public function sourceEnglishRevision(): BelongsTo
    {
        return $this->belongsTo(HelpTopicRevision::class, 'source_english_revision_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected function casts(): array
    {
        return [
            'revision_number' => 'integer',
            'origin' => HelpContentOrigin::class,
            'created_at' => 'immutable_datetime',
        ];
    }
}
