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
        $imageCount = RichContentAttachmentPaths::extract($value)
            ->filter(fn (string $path): bool => $this->isImagePath($path))
            ->count();

        if ($imageCount > $this->max) {
            $fail(__($this->messageKey, ['max' => $this->max]));
        }
    }

    private function isImagePath(string $path): bool
    {
        $pathWithoutQuery = parse_url($path, PHP_URL_PATH);
        $extension = pathinfo(is_string($pathWithoutQuery) ? $pathWithoutQuery : $path, PATHINFO_EXTENSION);

        return in_array(strtolower($extension), ['jpeg', 'jpg', 'png', 'webp'], true);
    }
}
