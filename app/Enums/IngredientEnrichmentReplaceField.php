<?php

namespace App\Enums;

enum IngredientEnrichmentReplaceField: string
{
    case DisplayName = 'display_name';
    case InciName = 'inci_name';
    case Category = 'category';
    case Subcategory = 'subcategory';
    case SaponificationName = 'saponification_name';
    case InfoMarkdown = 'info_markdown';
    case Identifiers = 'identifiers';
    case CosingFunctions = 'cosing_functions';
    case Translations = 'translations';
    case MarketLabels = 'market_labels';

    public static function tryFromMixed(mixed $value): ?self
    {
        return is_string($value) ? self::tryFrom(trim($value)) : null;
    }

    public function label(): string
    {
        return (string) __("ingredient_enrichment_admin.replace.{$this->value}");
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $field): array => [$field->value => $field->label()])->all();
    }
}
