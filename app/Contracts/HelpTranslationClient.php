<?php

namespace App\Contracts;

use App\Data\HelpContentInput;
use App\Data\HelpTranslationResponse;

interface HelpTranslationClient
{
    public function translate(HelpContentInput $source, string $locale, string $model, string $reasoningEffort, string $promptVersion): HelpTranslationResponse;
}
