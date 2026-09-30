<?php

namespace App\Services;

use App\Enums\FormulaShareStatus;
use App\Enums\OwnerType;
use App\Enums\Visibility;
use App\Models\FattyAcid;
use App\Models\FormulaShare;
use App\Models\Ingredient;
use App\Models\IngredientShareMapping;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class IngredientShareImporter
{
    public function __construct(
        private readonly IngredientShareResolver $resolver,
        private readonly IngredientShareGraph $graphs,
        private readonly IngredientSoapTrustValidator $soapTrust,
        private readonly IngredientIdentitySynchronizer $identity,
        private readonly IngredientDataEntryService $dataEntry,
        private readonly FormulaShareReferences $references,
        private readonly EntitlementService $entitlements,
    ) {}

    /** Accepts a server resolution only; technical facts always come from the persisted grant.
     * @param  array<string, mixed>  $resolution  @return array<string, int>
     */
    public function import(User $actor, Workspace $destination, FormulaShare $share, array $resolution): array
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('Shared Ingredient import requires the authorized acceptance transaction.');
        }
        $share = FormulaShare::query()->findOrFail($share->id);
        Gate::forUser($actor)->authorize('accept', $share);
        if ($share->recipient_workspace_id !== $destination->id || $share->status !== FormulaShareStatus::Pending || $share->snapshot === null || $share->schema_version !== 1) {
            $this->invalid();
        }
        $snapshot = $share->snapshot;
        $resolution = $this->resolver->resolve($destination, $snapshot['ingredients'], $resolution['decisions'] ?? []);
        if ($resolution['remaining_keys'] !== []) {
            $this->invalid();
        }
        $this->references->state($snapshot, $resolution);
        $map = [];
        $walk = function (string $key) use (&$walk, &$map, $destination, $snapshot, $resolution): int {
            if (isset($map[$key])) {
                return $map[$key];
            }
            $row = $resolution['nodes'][$key];
            if ($row['mode'] !== 'import') {
                return $map[$key] = $row['ingredient_id'];
            }
            $node = $snapshot['ingredients']['nodes'][$key];
            $technical = $node['technical'];
            $components = collect($technical['components'])->map(fn (array $component): array => [
                'component_ingredient_id' => $walk($component['key']),
                'percentage_in_parent' => $component['percentage_in_parent'], 'sort_order' => $component['sort_order'],
            ])->all();
            $baseline = $this->baseline($technical);
            $this->entitlements->assertCanCreatePrivateIngredientInWorkspace($destination);
            $ingredient = new Ingredient;
            $ingredient->forceFill(array_merge(Arr::only($technical, ['inci_name', 'soap_inci_naoh_name', 'soap_inci_koh_name', 'category', 'subcategory', 'unit', 'is_soap_saponification_trusted', 'requires_aromatic_compliance']), [
                'display_name' => $node['display']['display_name'], 'saponification_name' => $node['display']['saponification_name'],
                'catalog_key' => $this->dataEntry->generateCatalogKey('USR'), 'share_lineage_key' => $node['lineage_key'],
                'workspace_id' => $destination->id, 'owner_type' => OwnerType::Workspace, 'owner_id' => $destination->id,
                'visibility' => Visibility::Private, 'is_active' => true, 'requires_admin_review' => false, 'is_manufactured' => false,
                'taxonomy_source' => 'workspace_user', 'source_data' => $baseline === null ? null : ['user_authoring' => ['trusted_koh_sap_value' => $baseline['koh_sap_value'], 'trusted_fatty_acid_profile' => $baseline['fatty_acid_profile']]],
            ]))->save();
            $this->identity->sync($ingredient, ['additional_identifiers' => $technical['identifiers'], 'aliases' => $node['display']['aliases']]);
            foreach ($technical['identifiers'] as $identifier) {
                $ingredient->identifiers()->where('scheme', $identifier['scheme'])->where('value', trim($identifier['value']))
                    ->update(['value' => $identifier['value'], 'is_primary' => $identifier['is_primary']]);
            }
            $ingredient->translations()->createMany($node['display']['translations']);
            if ($technical['sap_profile'] !== null) {
                $ingredient->sapProfile()->create($technical['sap_profile']);
            }
            $ingredient->fattyAcidEntries()->createMany(collect($technical['fatty_acids'])->map(fn (array $entry): array => ['fatty_acid_id' => $entry['reference']['id'], 'percentage' => $entry['percentage']])->all());
            $ingredient->components()->createMany($components);
            $ingredient->allergenEntries()->createMany(collect($technical['allergens'])->map(fn (array $entry): array => ['allergen_id' => $entry['reference']['id'], 'concentration_percent' => $entry['concentration_percent']])->all());
            $ingredient->substanceEntries()->createMany(collect($technical['substances'])->map(fn (array $entry): array => ['substance_id' => $entry['reference']['id'], 'concentration_percent' => $entry['concentration_percent'], 'concentration_source' => $entry['concentration_source']])->all());
            $ingredient->marketLabels()->createMany(collect($technical['market_labels'])->map(fn (array $entry): array => $entry + ['source_name' => 'formula_sharing', 'source_url' => ''])->all());
            foreach ($technical['ifra_certificates'] as $index => $certificate) {
                $record = $ingredient->ifraCertificates()->create(array_merge(Arr::only($certificate, ['ifra_amendment', 'source_amendment_label', 'published_at', 'valid_from', 'peroxide_value', 'is_current']), [
                    'certificate_name' => $node['display']['ifra_labels'][$index]['certificate_name'], 'ifra_amendment_id' => $certificate['amendment']['id'] ?? null,
                ]));
                $record->limits()->createMany(collect($certificate['limits'])->map(fn (array $limit): array => ['ifra_product_category_id' => $limit['reference']['id'], 'max_percentage' => $limit['max_percentage']])->all());
            }
            $this->soapTrust->assertTransferable($ingredient->fresh());

            return $map[$key] = $ingredient->id;
        };
        foreach ($resolution['active_keys'] as $key) {
            $walk($key);
        }
        foreach ($resolution['nodes'] as $key => $row) {
            $incoming = $snapshot['ingredients']['nodes'][$key];
            if ($incoming['kind'] === 'platform') {
                continue;
            }
            $local = $this->graphs->current($destination, [$map[$key]]);
            $fingerprint = $local['nodes'][$local['root_keys'][0]]['fingerprint'];
            IngredientShareMapping::query()->updateOrCreate([
                'workspace_id' => $destination->id, 'lineage_key' => $incoming['lineage_key'],
                'fingerprint_version' => IngredientShareFingerprint::VERSION, 'incoming_fingerprint' => $incoming['fingerprint'],
            ], [
                'ingredient_id' => $map[$key], 'local_fingerprint' => $fingerprint,
                'resolution' => $row['mode'] === 'substitute' || $fingerprint !== $incoming['fingerprint'] ? 'substitution' : 'exact',
            ]);
        }
        ksort($map, SORT_NATURAL);

        return $map;
    }

    /** @param array<string, mixed> $technical @return array{koh_sap_value: mixed, fatty_acid_profile: array<int, mixed>}|null */
    private function baseline(array $technical): ?array
    {
        if (! $technical['is_soap_saponification_trusted']) {
            return null;
        }
        $stored = $technical['baseline'];
        if (! is_array($stored) || ! is_numeric($stored['koh_sap_value'] ?? null) || ! is_finite((float) $stored['koh_sap_value']) || $stored['koh_sap_value'] <= 0 || ! is_array($stored['fatty_acid_profile'] ?? null)) {
            throw ValidationException::withMessages(['ingredient' => __('sharing.validation.missing_trusted_baseline')]);
        }
        $ids = FattyAcid::query()->whereIn('key', array_keys($stored['fatty_acid_profile']))->pluck('id', 'key');
        if ($ids->count() !== count($stored['fatty_acid_profile'])) {
            $this->invalid();
        }
        $baseline = ['koh_sap_value' => $stored['koh_sap_value'], 'fatty_acid_profile' => collect($stored['fatty_acid_profile'])->mapWithKeys(fn (mixed $value, string $key): array => [$ids[$key] => $value])->all()];
        foreach ($baseline['fatty_acid_profile'] as $value) {
            if (! is_numeric($value) || ! is_finite((float) $value) || $value < 0 || $value > 100) {
                $this->invalid();
            }
        }
        $state = [
            'sap_profile' => $technical['sap_profile'],
            'fatty_acid_entries' => collect($technical['fatty_acids'])->map(fn (array $entry): array => ['fatty_acid_id' => $entry['reference']['id'], 'percentage' => $entry['percentage']])->all(),
        ];
        $this->soapTrust->validateKohSapValue($baseline, $state);
        $this->soapTrust->validateFattyAcidProfile($baseline, $state);

        return $baseline;
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['decisions' => __('sharing.validation.decisions')]);
    }
}
