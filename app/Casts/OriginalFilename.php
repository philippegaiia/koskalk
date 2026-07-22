<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class OriginalFilename implements CastsAttributes
{
    /**
     * Cast the given value.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return is_string($value) ? $value : null;
    }

    /**
     * Prepare the given value for storage.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if (! is_string($value) || blank($value)) {
            return null;
        }

        $name = basename(str_replace('\\', '/', $value));
        $name = preg_replace('/\p{C}+/u', '', $name) ?? '';
        $name = trim($name);

        if ($name === '') {
            return null;
        }

        return Str::substr($name, 0, 255);
    }
}
