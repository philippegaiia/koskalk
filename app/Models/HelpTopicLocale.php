<?php

namespace App\Models;

use Database\Factories\HelpTopicLocaleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'help_topic_id',
    'locale',
    'latest_revision_id',
    'published_revision_id',
    'lock_version',
    'published_by',
    'published_at',
])]
class HelpTopicLocale extends Model
{
    /** @use HasFactory<HelpTopicLocaleFactory> */
    use HasFactory;

    public function topic(): BelongsTo
    {
        return $this->belongsTo(HelpTopic::class, 'help_topic_id');
    }

    public function supportedLocale(): BelongsTo
    {
        return $this->belongsTo(SupportedLocale::class, 'locale', 'code');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(HelpTopicRevision::class);
    }

    public function latestRevision(): BelongsTo
    {
        return $this->belongsTo(HelpTopicRevision::class, 'latest_revision_id');
    }

    public function publishedRevision(): BelongsTo
    {
        return $this->belongsTo(HelpTopicRevision::class, 'published_revision_id');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function translationRequests(): HasMany
    {
        return $this->hasMany(HelpTranslationRequest::class);
    }

    protected function casts(): array
    {
        return [
            'lock_version' => 'integer',
            'published_at' => 'immutable_datetime',
        ];
    }
}
