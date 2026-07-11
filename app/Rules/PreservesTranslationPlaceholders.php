<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class PreservesTranslationPlaceholders implements ValidationRule
{
    public function __construct(private readonly string $source) {}

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value) || $this->placeholders($value) !== $this->placeholders($this->source)) {
            $expected = implode(', ', array_map(
                fn (string $placeholder): string => ":{$placeholder}",
                $this->placeholders($this->source),
            ));

            $fail("The translation placeholders must match the English source ({$expected}).");
        }
    }

    /**
     * @return list<string>
     */
    private function placeholders(string $value): array
    {
        preg_match_all('/(?<!:):([A-Za-z_][A-Za-z0-9_]*)/', $value, $matches);

        $placeholders = array_values(array_unique($matches[1]));
        sort($placeholders);

        return $placeholders;
    }
}
