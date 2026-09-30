<?php

namespace App\Services;

use App\Models\FattyAcid;
use App\Models\Ingredient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class IngredientShareProjector
{
    public const RELATIONS = ['sapProfile', 'translations', 'identifiers', 'aliases', 'marketLabels', 'fattyAcidEntries.fattyAcid', 'components', 'allergenEntries.allergen', 'substanceEntries.substance', 'ifraCertificates.ifraAmendment', 'ifraCertificates.limits.ifraProductCategory'];

    public function __construct(
        private readonly IngredientSoapTrustValidator $soapTrust,
        private readonly IngredientShareFingerprint $fingerprint,
    ) {}

    /** @return array<string, mixed> */
    public function project(Ingredient $ingredient): array
    {
        $this->loadBoundedRelations($ingredient);
        $this->soapTrust->assertTransferable($ingredient);
        $isPlatform = $ingredient->owner_type === null && $ingredient->owner_id === null && $ingredient->workspace_id === null;
        $sap = $ingredient->sapProfile;
        $baseline = null;
        if (! $isPlatform && $ingredient->is_soap_saponification_trusted) {
            $stored = $this->soapTrust->storedBaseline($ingredient);
            $acidKeys = FattyAcid::query()->whereIn('id', array_keys($stored['fatty_acid_profile']))->pluck('key', 'id');
            if ($acidKeys->count() !== count($stored['fatty_acid_profile'])) {
                throw ValidationException::withMessages(['ingredient' => __('sharing.validation.missing_trusted_baseline')]);
            }
            $baseline = [
                'koh_sap_value' => $this->fingerprint->decimal($stored['koh_sap_value'], 6),
                'fatty_acid_profile' => collect($stored['fatty_acid_profile'])->mapWithKeys(fn (mixed $value, int|string $id): array => [$acidKeys[$id] => $this->fingerprint->decimal($value, 5)])->all(),
            ];
        }

        return [
            'schema_version' => IngredientShareFingerprint::VERSION,
            'kind' => $isPlatform ? 'platform' : 'private',
            'platform_reference' => $isPlatform ? ['public_id' => $ingredient->public_id, 'catalog_key' => $ingredient->catalog_key] : null,
            'lineage_key' => $isPlatform ? null : $ingredient->sharingLineageKey(),
            'display' => [
                'display_name' => $ingredient->display_name,
                'saponification_name' => $ingredient->saponification_name,
                'translations' => $ingredient->translations->map(fn (Model $row): array => ['locale' => $row->locale, 'display_name' => $row->display_name, 'saponification_name' => $row->saponification_name])->all(),
                'aliases' => $ingredient->aliases->map(fn (Model $row): array => ['locale' => $row->locale, 'name' => $row->name, 'kind' => $row->kind->value])->all(),
                'ifra_labels' => $ingredient->ifraCertificates->map(fn (Model $row): array => ['certificate_name' => $row->certificate_name])->all(),
            ],
            'technical' => [
                'inci_name' => $ingredient->inci_name,
                'soap_inci_naoh_name' => $ingredient->soap_inci_naoh_name,
                'soap_inci_koh_name' => $ingredient->soap_inci_koh_name,
                'declaration_fallback' => filled($ingredient->inci_name) ? null : [
                    'display_name' => $ingredient->display_name,
                    'translations' => $ingredient->translations->map(fn (Model $row): array => ['locale' => $row->locale, 'display_name' => $row->display_name])->all(),
                ],
                'category' => $ingredient->category?->value,
                'subcategory' => $ingredient->subcategory?->value,
                'unit' => $ingredient->unit,
                'is_soap_saponification_trusted' => $ingredient->is_soap_saponification_trusted,
                'requires_aromatic_compliance' => $ingredient->requires_aromatic_compliance,
                'identifiers' => $ingredient->identifiers->map(fn (Model $row): array => ['scheme' => $row->scheme->value, 'value' => $row->value, 'is_primary' => $row->is_primary])->all(),
                'sap_profile' => $sap === null ? null : [
                    'koh_sap_value' => $sap->koh_sap_value,
                    'iodine_value' => $sap->iodine_value,
                    'ins_value' => $sap->ins_value,
                ],
                'baseline' => $baseline,
                'fatty_acids' => $ingredient->fattyAcidEntries->map(fn (Model $row): array => [
                    'reference' => $this->reference($row->fattyAcid, ['key']), 'percentage' => $row->percentage,
                ])->all(),
                'components' => $ingredient->components->map(fn (Model $row): array => ['component_ingredient_id' => $row->component_ingredient_id, 'percentage_in_parent' => $row->percentage_in_parent, 'sort_order' => (int) $row->sort_order])->all(),
                'allergens' => $ingredient->allergenEntries->map(fn (Model $row): array => [
                    'reference' => $this->reference($row->allergen, ['inci_name', 'cas_number', 'ec_number']),
                    'concentration_percent' => $row->concentration_percent,
                ])->all(),
                'substances' => $ingredient->substanceEntries->map(fn (Model $row): array => [
                    'reference' => $this->reference($row->substance, ['name', 'entity_type', 'inci_name', 'cas_number', 'ec_number']),
                    'concentration_percent' => $row->concentration_percent,
                    'concentration_source' => $row->concentration_source,
                ])->all(),
                'market_labels' => $ingredient->marketLabels->map(fn (Model $row): array => [
                    'market_code' => $row->market_code->value, 'declaration_name' => $row->declaration_name,
                    'effective_from' => $row->effective_from?->toDateString(), 'effective_until' => $row->effective_until?->toDateString(),
                ])->all(),
                'ifra_certificates' => $ingredient->ifraCertificates->map(fn (Model $row): array => [
                    'amendment' => $row->ifra_amendment_id === null ? null : $this->reference($row->ifraAmendment, ['code']),
                    'ifra_amendment' => $row->ifra_amendment,
                    'source_amendment_label' => $row->source_amendment_label,
                    'published_at' => $row->published_at?->toDateString(), 'valid_from' => $row->valid_from?->toDateString(),
                    'peroxide_value' => $row->peroxide_value, 'is_current' => $row->is_current,
                    'limits' => $row->limits->map(fn (Model $limit): array => [
                        'reference' => $this->reference($limit->ifraProductCategory, ['code']), 'max_percentage' => $limit->max_percentage,
                    ])->all(),
                ])->all(),
            ],
        ];
    }

    public function relationCount(Ingredient $ingredient): int
    {
        return collect(['sapProfile', 'translations', 'identifiers', 'aliases', 'marketLabels', 'fattyAcidEntries', 'components', 'allergenEntries', 'substanceEntries', 'ifraCertificates'])
            ->sum(fn (string $relation): int => $relation === 'sapProfile' ? (int) ($ingredient->sapProfile !== null) : $ingredient->getRelation($relation)->count())
            + $ingredient->ifraCertificates->sum(fn (Model $certificate): int => $certificate->limits->count());
    }

    private function loadBoundedRelations(Ingredient $ingredient): void
    {
        $cap = (int) config('workspaces.formula_sharing.limits.relation_rows', 10000);
        $count = 0;
        foreach (['sapProfile', 'translations', 'identifiers', 'aliases', 'marketLabels', 'fattyAcidEntries', 'components', 'allergenEntries', 'substanceEntries', 'ifraCertificates'] as $relation) {
            $ingredient->loadMissing([$relation => fn ($query) => $query->limit(max(0, $cap - $count) + 1)]);
            $count += $relation === 'sapProfile' ? (int) ($ingredient->sapProfile !== null) : $ingredient->getRelation($relation)->count();
            if ($count > $cap) {
                throw ValidationException::withMessages(['ingredient' => __('sharing.validation.graph_limit')]);
            }
        }
        $ingredient->loadMissing(['fattyAcidEntries.fattyAcid', 'allergenEntries.allergen', 'substanceEntries.substance', 'ifraCertificates.ifraAmendment']);
        foreach ($ingredient->ifraCertificates as $certificate) {
            $certificate->loadMissing(['limits' => fn ($query) => $query->limit(max(0, $cap - $count) + 1)]);
            $count += $certificate->limits->count();
            if ($count > $cap) {
                throw ValidationException::withMessages(['ingredient' => __('sharing.validation.graph_limit')]);
            }
        }
        $ingredient->loadMissing('ifraCertificates.limits.ifraProductCategory');
    }

    /** @param list<string> $fields @return array{id: int, identity: array<string, mixed>} */
    private function reference(?Model $record, array $fields): array
    {
        if ($record === null) {
            throw ValidationException::withMessages(['ingredient' => __('sharing.validation.reference_unavailable')]);
        }

        return ['id' => (int) $record->getKey(), 'identity' => collect($fields)->mapWithKeys(fn (string $field): array => [$field => $record->getAttribute($field)])->all()];
    }
}
