<?php

namespace App\Services;

use App\Models\Allergen;
use App\Models\FattyAcid;
use App\Models\IfraAmendment;
use App\Models\IfraAmendmentMilestone;
use App\Models\IfraProductCategory;
use App\Models\ProductFamily;
use App\Models\ProductType;
use App\Models\ProductTypeIfraCategory;
use App\Models\RegulatoryRegime;
use App\Models\RegulatoryRegimeAllergen;
use App\Models\RegulatoryRegimeSubstanceRule;
use App\Models\Substance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class FormulaShareReferences
{
    /** Current technical reference state, restricted to the active material closure.
     * @param  array<string, mixed>  $snapshot  @param array<string, mixed> $resolution @return array<string, mixed>
     */
    public function state(array $snapshot, array $resolution): array
    {
        $state = [];
        $family = data_get($snapshot, 'product.family.id');
        $type = data_get($snapshot, 'product.type.id');
        $regime = data_get($snapshot, 'formula.regulatory_regime.id');
        if ($family !== null) {
            $row = $this->add($state, ProductFamily::class, $family, ['slug', 'calculation_basis', 'is_active']);
            if (! $row->is_active || $row->slug !== data_get($snapshot, 'product.family.slug')) {
                $this->invalid();
            }
        }
        if ($type !== null) {
            $row = $this->add($state, ProductType::class, $type, ['slug', 'is_active']);
            if (! $row->is_active || ! $row->productFamilies()->whereKey($family)->exists()) {
                $this->invalid();
            }
        }
        if ($regime !== null) {
            $row = $this->add($state, RegulatoryRegime::class, $regime, ['code', 'market_code', 'version_label', 'status', 'effective_from', 'effective_until']);
            if (! in_array($row->status, ['active', 'preview'], true)) {
                $this->invalid();
            }
        }
        foreach (['category' => [IfraProductCategory::class, ['code', 'is_active']], 'amendment' => [IfraAmendment::class, ['code', 'status', 'notification_date']], 'mapping' => [ProductTypeIfraCategory::class, ['product_type_id', 'ifra_product_category_id', 'ifra_amendment_id', 'is_active']]] as $key => [$class, $fields]) {
            $id = data_get($snapshot, "formula.ifra.$key.id");
            if ($id !== null) {
                $row = $this->add($state, $class, $id, $fields);
                if (($key !== 'amendment' && ! $row->is_active) || ($key === 'mapping' && ($row->product_type_id !== $type || $row->ifra_product_category_id !== data_get($snapshot, 'formula.ifra.category.id') || $row->ifra_amendment_id !== data_get($snapshot, 'formula.ifra.amendment.id')))) {
                    $this->invalid();
                }
            }
        }
        $allergens = [];
        $substances = [];
        foreach ($resolution['nodes'] as $key => $resolved) {
            $nodes = in_array($resolved['mode'], ['reuse', 'substitute'], true)
                ? $resolved['local_projection']['current_nodes'] : [$snapshot['ingredients']['nodes'][$key]];
            foreach ($nodes as $node) {
                $technical = $node['technical'];
                foreach ($technical['fatty_acids'] as $entry) {
                    $this->add($state, FattyAcid::class, $entry['reference']['id'], ['key', 'chain_length', 'double_bonds', 'saturation_class', 'iodine_factor', 'default_group_key', 'is_active']);
                }
                foreach (array_keys($technical['baseline']['fatty_acid_profile'] ?? []) as $acidKey) {
                    $acid = FattyAcid::query()->where('key', $acidKey)->first();
                    if ($acid === null) {
                        $this->invalid();
                    }
                    $this->add($state, FattyAcid::class, $acid->id, ['key', 'chain_length', 'double_bonds', 'saturation_class', 'iodine_factor', 'default_group_key', 'is_active']);
                }
                foreach ($technical['allergens'] as $entry) {
                    $row = $this->add($state, Allergen::class, $entry['reference']['id'], ['inci_name', 'cas_number', 'ec_number']);
                    $allergens[$row->id] = true;
                }
                foreach ($technical['substances'] as $entry) {
                    $row = $this->add($state, Substance::class, $entry['reference']['id'], ['name', 'entity_type', 'inci_name', 'cas_number', 'ec_number', 'allergen_id']);
                    $substances[$row->id] = true;
                    if ($row->allergen_id !== null) {
                        $allergens[$row->allergen_id] = true;
                        $this->add($state, Allergen::class, $row->allergen_id, ['inci_name', 'cas_number', 'ec_number']);
                    }
                }
                foreach ($technical['ifra_certificates'] as $certificate) {
                    if ($certificate['amendment'] !== null) {
                        $this->add($state, IfraAmendment::class, $certificate['amendment']['id'], ['code', 'status', 'notification_date']);
                    }
                    foreach ($certificate['limits'] as $limit) {
                        $this->add($state, IfraProductCategory::class, $limit['reference']['id'], ['code', 'is_active']);
                    }
                }
            }
        }
        if ($regime !== null) {
            $cap = (int) config('workspaces.formula_sharing.limits.relation_rows', 10000);
            foreach ([RegulatoryRegimeAllergen::class => ['allergen_id', array_keys($allergens), ['allergen_id', 'declaration_label', 'rinse_off_threshold_percent', 'leave_on_threshold_percent', 'threshold_operator', 'group_key', 'group_label', 'is_active', 'effective_from', 'effective_until']], RegulatoryRegimeSubstanceRule::class => ['substance_id', array_keys($substances), ['substance_id', 'rule_type', 'rinse_off_max_percent', 'leave_on_max_percent', 'threshold_operator', 'exposure_scope', 'label_warning_text', 'is_active', 'effective_from', 'effective_until']]] as $class => [$column, $ids, $fields]) {
                $rows = $class::query()->where('regulatory_regime_id', $regime)->whereIn($column, $ids)->orderBy('id')->limit($cap + 1)->get();
                if ($rows->count() > $cap) {
                    $this->invalid();
                }
                foreach ($rows as $row) {
                    $this->add($state, $class, $row->id, $fields);
                }
            }
        }
        ksort($state, SORT_STRING);

        return $state;
    }

    /** @param array<string, mixed> $state @param class-string<Model> $class @param list<string> $fields */
    private function add(array &$state, string $class, int $id, array $fields): Model
    {
        $row = $class::query()->find($id);
        if ($row === null) {
            $this->invalid();
        }
        if ($class === IfraAmendment::class) {
            foreach ($row->milestones()->orderBy('id')->limit(101)->get() as $milestone) {
                $this->add($state, IfraAmendmentMilestone::class, $milestone->id, ['standard_kind', 'creation_track', 'effective_on']);
            }
            if ($row->milestones()->count() > 100) {
                $this->invalid();
            }
        }
        $state[$class.':'.$id] = collect($fields)->mapWithKeys(fn (string $field): array => [$field => $row->getRawOriginal($field)])->all();

        return $row;
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['sharing' => __('sharing.validation.reference')]);
    }
}
