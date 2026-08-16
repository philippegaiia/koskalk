<?php

namespace App\Services;

use App\Enums\IngredientLabelMarket;
use App\Models\Ingredient;
use App\Models\IngredientMarketLabel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

class IngredientDeclarationNameResolver
{
    public function resolve(
        Ingredient $ingredient,
        string $marketCode,
        ?CarbonImmutable $onDate = null,
        bool $allowLegacyEuFallback = false,
    ): ?string {
        $normalizedMarketCode = Str::lower(trim($marketCode));
        $market = IngredientLabelMarket::tryFrom($normalizedMarketCode);

        if (! $market instanceof IngredientLabelMarket) {
            throw ValidationException::withMessages([
                "market_labels.{$normalizedMarketCode}" => __('ingredient_admin.market_labels.validation.unsupported_market', [
                    'market' => $normalizedMarketCode,
                ]),
            ]);
        }

        if (! $ingredient->relationLoaded('marketLabels')) {
            throw new LogicException('IngredientDeclarationNameResolver requires the marketLabels relation to be eager loaded.');
        }

        $date = $onDate ?? CarbonImmutable::today();
        $marketLabel = $ingredient->marketLabels
            ->filter(fn (mixed $label): bool => $label instanceof IngredientMarketLabel)
            ->filter(fn (IngredientMarketLabel $label): bool => $label->market_code === $market)
            ->filter(fn (IngredientMarketLabel $label): bool => $this->isEffective($label, $date))
            ->sortByDesc(fn (IngredientMarketLabel $label): string => $label->effective_from?->toDateString() ?? '')
            ->first();

        $declarationName = $marketLabel instanceof IngredientMarketLabel
            ? $this->normalize($marketLabel->declaration_name)
            : null;

        if ($declarationName !== null) {
            if ($market === IngredientLabelMarket::Us && $this->isBareCi($declarationName)) {
                $this->throwInvalidUsDeclaration($ingredient);
            }

            return $declarationName;
        }

        $canonicalName = $this->normalize($ingredient->inci_name);

        if ($market === IngredientLabelMarket::Eu
            && ($this->isBareCi($canonicalName) || ($allowLegacyEuFallback && $canonicalName !== null))) {
            return $canonicalName;
        }

        $this->throwMissingDeclaration($ingredient, $market);
    }

    private function isEffective(IngredientMarketLabel $label, CarbonImmutable $onDate): bool
    {
        $effectiveFrom = $label->effective_from?->toDateString();
        $effectiveUntil = $label->effective_until?->toDateString();
        $date = $onDate->toDateString();

        return ($effectiveFrom === null || $effectiveFrom <= $date)
            && ($effectiveUntil === null || $effectiveUntil >= $date);
    }

    private function normalize(?string $value): ?string
    {
        $normalized = trim((string) $value);

        if ($normalized === '') {
            return null;
        }

        return Str::upper(Str::squish($normalized));
    }

    private function isBareCi(?string $value): bool
    {
        return $value !== null && preg_match('/^CI\s*[0-9]{5}$/i', $value) === 1;
    }

    private function throwInvalidUsDeclaration(Ingredient $ingredient): never
    {
        throw ValidationException::withMessages([
            'market_labels.us' => __('ingredient_admin.market_labels.validation.invalid_us_declaration', [
                'ingredient' => $ingredient->display_name,
            ]),
        ]);
    }

    private function throwMissingDeclaration(Ingredient $ingredient, IngredientLabelMarket $market): never
    {
        throw ValidationException::withMessages([
            "market_labels.{$market->value}" => __('ingredient_admin.market_labels.validation.missing_declaration', [
                'market' => $market->label(),
                'ingredient' => $ingredient->display_name,
            ]),
        ]);
    }
}
