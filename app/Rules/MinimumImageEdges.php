<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Translation\PotentiallyTranslatedString;

class MinimumImageEdges implements ValidationRule
{
    public function __construct(
        protected int $shortEdge,
        protected int $longEdge,
    ) {}

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail($this->message());

            return;
        }

        $path = $value->getRealPath();
        $dimensions = is_string($path) ? @getimagesize($path) : false;

        if ($dimensions === false) {
            $fail($this->message());

            return;
        }

        $edges = [$dimensions[0], $dimensions[1]];
        sort($edges, SORT_NUMERIC);

        if ($edges[0] < $this->shortEdge || $edges[1] < $this->longEdge) {
            $fail($this->message());
        }
    }

    private function message(): string
    {
        return __('workbench.instructions.minimum_image_edges', [
            'short' => $this->shortEdge,
            'long' => $this->longEdge,
        ]);
    }
}
