<?php

namespace App\Services;

use App\Models\Ingredient;

class IngredientTranslationSourceFingerprint
{
    public function forIngredient(Ingredient $ingredient): string
    {
        return hash('sha256', json_encode([
            'display_name' => $this->normalize($ingredient->display_name),
            'saponification_name' => $this->normalize($ingredient->saponification_name),
            'info_markdown' => $this->normalize($ingredient->info_markdown),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function normalize(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = str_replace(["\r\n", "\r"], "\n", trim($value));

        return $value === '' ? null : $value;
    }
}
