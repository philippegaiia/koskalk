<?php

namespace App\Services\IngredientEnrichment;

use App\Enums\IngredientCategory;
use App\Enums\IngredientIdentifierScheme;
use App\Enums\IngredientLabelMarket;
use App\Enums\IngredientSubcategory;
use App\Models\Ingredient;
use App\Models\IngredientFunction;

class IngredientEnrichmentInputBuilder
{
    /** @var list<array{key: string, name: string, description: string|null}>|null */
    private ?array $cosingFunctions = null;

    public function __construct(
        private readonly IngredientEnrichmentSnapshotBuilder $snapshotBuilder,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Ingredient $ingredient): array
    {
        $snapshot = $this->snapshotBuilder->build($ingredient);
        $category = $ingredient->category instanceof IngredientCategory ? $ingredient->category : null;

        return [
            'format' => config('ingredient-enrichment.input_format'),
            'schema_version' => config('ingredient-enrichment.schema_version'),
            'catalog_key' => $ingredient->catalog_key,
            'source_fingerprint' => $snapshot['fingerprint'],
            'current' => $snapshot['snapshot'],
            'vocabulary' => [
                'category' => [
                    'selected' => $category?->value,
                    'allowed' => collect(IngredientCategory::cases())->map->value->all(),
                ],
                'subcategories' => collect(
                    $category instanceof IngredientCategory
                        ? IngredientSubcategory::forCategory($category)
                        : [],
                )->map->value->all(),
                'cosing_functions' => $this->activeCosingFunctions(),
                'identifier_schemes' => collect(IngredientIdentifierScheme::cases())->map->value->all(),
                'locales' => $this->snapshotBuilder->targetLocales(),
                'markets' => collect(IngredientLabelMarket::cases())->map->value->all(),
            ],
            'requested_output' => [
                'fields' => [
                    'display_name',
                    'inci_name',
                    'category',
                    'subcategory',
                    'saponification_name',
                    'info_markdown',
                    'soapmaking_relevant',
                    'identifiers',
                    'cosing_functions',
                    'translations',
                    'market_labels',
                ],
                'guidance' => config('ingredient-enrichment.guidance'),
            ],
            'research_rules' => [
                'primary_sources_required' => true,
                'official_cosing_hosts' => ['ec.europa.eu', 'single-market-economy.ec.europa.eu'],
                'http_sources_only' => true,
                'deferred_fields' => [
                    'sap',
                    'iodine',
                    'ins',
                    'fatty_acids',
                    'allergens',
                    'ifra',
                    'composition',
                    'authorization',
                    'restrictions',
                ],
            ],
        ];
    }

    /**
     * @return list<array{key: string, name: string, description: string|null}>
     */
    private function activeCosingFunctions(): array
    {
        return $this->cosingFunctions ??= IngredientFunction::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('key')
            ->get(['key', 'name', 'description'])
            ->map(fn (IngredientFunction $function): array => [
                'key' => $function->key,
                'name' => $function->name,
                'description' => $function->description,
            ])
            ->all();
    }
}
