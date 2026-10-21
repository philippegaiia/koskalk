<?php

namespace App\Filament\Concerns;

use Closure;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Service validation errors use plain attribute keys; Filament shows errors under `data.<field>`.
 * Nested keys (e.g. `value_references.nesil_ug_cm2`) are shown on their top-level field.
 */
trait MapsServiceValidationToForm
{
    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    protected function withFormValidationErrors(Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(
                collect($exception->errors())
                    ->groupBy(fn (array $messages, string $key): string => 'data.'.Str::before($key, '.'), preserveKeys: true)
                    ->map(fn ($group): array => $group->flatten()->all())
                    ->all(),
            );
        }
    }
}
