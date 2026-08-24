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
    public const FALLBACK_US_BARE_CI = 'us_bare_ci';

    public const FALLBACK_MISSING_DECLARATION = 'missing_declaration';

    public function resolve(
        Ingredient $ingredient,
        string $marketCode,
        ?CarbonImmutable $onDate = null,
        bool $allowLegacyEuFallback = false,
    ): ?string {
        $resolved = $this->resolveWithFallback(
            $ingredient,
            $marketCode,
            $onDate,
            $allowLegacyEuFallback,
        );
        $declarationName = $resolved['declaration_name'];
        $fallback = $resolved['fallback'];

        if ($fallback === self::FALLBACK_US_BARE_CI) {
            $this->throwInvalidUsDeclaration($ingredient);
        }

        if ($fallback === self::FALLBACK_MISSING_DECLARATION) {
            $this->throwMissingDeclaration(
                $ingredient,
                IngredientLabelMarket::from(Str::lower(trim($marketCode))),
            );
        }

        return $declarationName;
    }

    /**
     * @return array{declaration_name: string|null, fallback: string|null}
     */
    public function resolveWithFallback(
        Ingredient $ingredient,
        string $marketCode,
        ?CarbonImmutable $onDate = null,
        bool $allowLegacyEuFallback = false,
    ): array {
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
        $marketLabel = $this->effectiveMarketLabel($ingredient, $market, $date);

        $declarationName = $marketLabel instanceof IngredientMarketLabel
            ? $this->normalize($marketLabel->declaration_name)
            : null;

        if ($declarationName !== null) {
            if ($market === IngredientLabelMarket::Us && $this->isBareCi($declarationName)) {
                return [
                    'declaration_name' => $this->normalize($ingredient->inci_name),
                    'fallback' => self::FALLBACK_US_BARE_CI,
                ];
            }

            return ['declaration_name' => $declarationName, 'fallback' => null];
        }

        // Markets may inherit another market's provisioned names outright
        // (Canada accepts EU declaration names as-is).
        $inheritedMarket = $market->fallbackMarket();

        if ($inheritedMarket instanceof IngredientLabelMarket) {
            $inheritedLabel = $this->effectiveMarketLabel($ingredient, $inheritedMarket, $date);

            $inheritedName = $inheritedLabel instanceof IngredientMarketLabel
                ? $this->normalize($inheritedLabel->declaration_name)
                : null;

            if ($inheritedName !== null) {
                return ['declaration_name' => $inheritedName, 'fallback' => null];
            }
        }

        $canonicalName = $this->normalize($ingredient->inci_name);

        if (($market === IngredientLabelMarket::Eu || $market === IngredientLabelMarket::Ca)
            && ($this->isBareCi($canonicalName) || $allowLegacyEuFallback)) {
            return ['declaration_name' => $canonicalName, 'fallback' => null];
        }

        return ['declaration_name' => $canonicalName, 'fallback' => self::FALLBACK_MISSING_DECLARATION];
    }

    private function effectiveMarketLabel(
        Ingredient $ingredient,
        IngredientLabelMarket $market,
        CarbonImmutable $date,
    ): ?IngredientMarketLabel {
        return $ingredient->marketLabels
            ->filter(fn (mixed $label): bool => $label instanceof IngredientMarketLabel)
            ->filter(fn (IngredientMarketLabel $label): bool => $label->market_code === $market)
            ->filter(fn (IngredientMarketLabel $label): bool => $this->isEffective($label, $date))
            ->sortByDesc(fn (IngredientMarketLabel $label): string => $label->effective_from?->toDateString() ?? '')
            ->first();
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
