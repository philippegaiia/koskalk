<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

trait HasPublicId
{
    public function initializeHasPublicId(): void
    {
        if ($this->getAttribute('public_id') === null) {
            $this->setAttribute('public_id', (string) Str::uuid());
        }
    }

    protected static function bootHasPublicId(): void
    {
        static::creating(function (Model $model): void {
            if ($model->getAttribute('public_id') === null) {
                $model->setAttribute('public_id', (string) Str::uuid());
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
