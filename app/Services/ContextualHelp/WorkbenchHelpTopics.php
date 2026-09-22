<?php

namespace App\Services\ContextualHelp;

final class WorkbenchHelpTopics
{
    /** @return array{keys: list<string>, tabs: array<string, list<string>>} */
    public function forSurface(string $family, bool $canPersist): array
    {
        if (! in_array($family, ['soap', 'cosmetic'], true)) {
            return ['keys' => [], 'tabs' => []];
        }
        $formula = ['shared.formula_basics', 'shared.ingredient_selection', 'shared.quantities_and_units', 'shared.ifra_context'];
        $formula = [...$formula, ...($family === 'soap' ? [
            'soap.reaction_core', 'soap.alkali_and_purity', 'soap.water_mode', 'soap.dilution_liquids', 'soap.superfat', 'soap.post_reaction_additions', 'soap.fatty_acids', 'soap.qualities', 'soap.qualities.cure', 'soap.qualities.dos', 'soap.qualities.liquid',
        ] : ['cosmetic.formula_basis', 'cosmetic.phases', 'cosmetic.ingredient_functions', 'cosmetic.application_context', 'cosmetic.preservation_and_ph'])];
        if ($canPersist) {
            $formula = [...$formula, 'shared.saving_and_history', 'shared.formula_lock'];
        }
        $tabs = ['formula' => $formula, 'output' => [$family.'.output_basis', $family.'.labeling', 'shared.compliance_guidance', 'shared.ifra_context']];
        if ($canPersist) {
            $tabs['output'][] = 'shared.ingredient_change_review';
            $tabs['packaging'] = ['shared.packaging'];
            $tabs['costing'] = ['shared.costing'];
            $tabs['instructions'] = ['shared.manufacturing_procedure', 'shared.media'];
        }

        return ['keys' => array_values(array_unique(array_merge(...array_values($tabs)))), 'tabs' => $tabs];
    }

    /** @return list<string> */
    public function locations(string $key): array
    {
        $locations = [];
        foreach (['soap', 'cosmetic'] as $family) {
            foreach ($this->forSurface($family, true)['tabs'] as $tab => $keys) {
                if (in_array($key, $keys, true)) {
                    $locations[] = ucfirst($family).' · '.ucfirst($tab);
                }
            }
        }

        return $locations;
    }
}
