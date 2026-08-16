<?php

namespace App\Services\IngredientEnrichment;

use App\Data\IngredientEnrichmentSubject;
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
        private readonly IngredientEnrichmentSubjectBuilder $subjectBuilder,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Ingredient $ingredient): array
    {
        return $this->buildRecord($this->subjectBuilder->forIngredient($ingredient), includeSubjectIdentity: false);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildForSubject(IngredientEnrichmentSubject $subject): array
    {
        return $this->buildRecord($subject, includeSubjectIdentity: true);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRecord(IngredientEnrichmentSubject $subject, bool $includeSubjectIdentity): array
    {
        $snapshot = $subject->currentSnapshot;
        $canonical = is_array($snapshot['canonical'] ?? null) ? $snapshot['canonical'] : [];
        $category = IngredientCategory::tryFrom((string) ($canonical['category'] ?? ''));

        $record = [
            'format' => config('ingredient-enrichment.input_format'),
            'schema_version' => config('ingredient-enrichment.schema_version'),
            'catalog_key' => $subject->catalogKey,
            'source_fingerprint' => $subject->fingerprint,
            'current' => $snapshot,
            'vocabulary' => [
                'category' => [
                    'selected' => $category?->value,
                    'allowed' => collect(IngredientCategory::cases())->map->value->all(),
                ],
                'subcategories' => collect(
                    $category instanceof IngredientCategory
                        ? IngredientSubcategory::forCategory($category)
                        : IngredientSubcategory::cases(),
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
                    'soap_inci_naoh_name',
                    'soap_inci_koh_name',
                    'info_markdown',
                    'soapmaking_relevant',
                    'aliases',
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
                'research_family' => $subject->researchFamily->value,
                'duplicate_context' => $subject->duplicateContext,
                'duplicate_resolution' => $subject->duplicateResolution?->value,
                'allow_gap_research' => $subject->allowGapResearch,
            ],
        ];

        if ($includeSubjectIdentity) {
            $record = [
                'format' => $record['format'],
                'schema_version' => $record['schema_version'],
                'subject_type' => $subject->subjectType,
                'subject_public_id' => $subject->subjectPublicId,
                'subject_identity' => [
                    'current_name' => $subject->currentName,
                    'inci_name' => $subject->inciName,
                ],
                ...collect($record)->except(['format', 'schema_version'])->all(),
            ];
        }

        return $record;
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
