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
        $imageCount = RichContentAttachmentPaths::extractImageIdentities($value)->count();

        if ($imageCount > $this->max) {
            $fail(__($this->messageKey, ['max' => $this->max]));
        }
    }
}
