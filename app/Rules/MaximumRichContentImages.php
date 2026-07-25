<?php

namespace App\Rules;

use App\Support\RichContentAttachmentPaths;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class MaximumRichContentImages implements ValidationRule
{
    public function __construct(
        protected int $max,
        protected string $messageKey,
    ) {}

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->max === 0 && $this->containsImage($value)) {
            $fail(__($this->messageKey, ['max' => $this->max]));

            return;
        }

        $imageCount = RichContentAttachmentPaths::countImageOccurrences($value);

        if ($imageCount > $this->max) {
            $fail(__($this->messageKey, ['max' => $this->max]));
        }
    }

    private function containsImage(mixed $value): bool
    {
        if (is_string($value)) {
            return preg_match('/<img\b/i', $value) === 1;
        }

        if (! is_array($value)) {
            return false;
        }

        if (($value['type'] ?? null) === 'image') {
            return true;
        }

        foreach ($value as $child) {
            if ($this->containsImage($child)) {
                return true;
            }
        }

        return false;
    }
}
