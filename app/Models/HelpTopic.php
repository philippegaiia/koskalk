<?php

namespace App\Models;

use App\Enums\HelpTopicDomain;
use App\Models\Concerns\HasPublicId;
use Database\Factories\HelpTopicFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'public_id',
    'key',
    'domain',
    'archived_at',
])]
class HelpTopic extends Model
{
    /** @use HasFactory<HelpTopicFactory> */
    use HasFactory;

    use HasPublicId;

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new DomainException('Help topics must be archived instead of deleted.');
        });
    }

    public function locales(): HasMany
    {
        return $this->hasMany(HelpTopicLocale::class);
    }

    protected function casts(): array
    {
        return [
            'domain' => HelpTopicDomain::class,
            'archived_at' => 'immutable_datetime',
        ];
    }
}
