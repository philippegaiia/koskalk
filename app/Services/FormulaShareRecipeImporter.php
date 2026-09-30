<?php

namespace App\Services;

use App\Enums\FormulaShareStatus;
use App\Enums\ProductionOutputType;
use App\Models\FormulaShare;
use App\Models\ProductFamily;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class FormulaShareRecipeImporter
{
    public function __construct(
        private readonly RecipeWorkbenchService $workbench,
        private readonly RecipeContentUpdater $content,
        private readonly FormulaShareContentSanitizer $sanitizer,
        private readonly WorkspaceAuthorization $authorization,
    ) {}

    /** @param array<string, int> $ingredientMap */
    public function import(User $actor, Workspace $destination, FormulaShare $share, array $ingredientMap): Recipe
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('Received Product publication requires the acceptance transaction.');
        }
        $share = FormulaShare::query()->findOrFail($share->id);
        Gate::forUser($actor)->authorize('accept', $share);
        if ($share->recipient_workspace_id !== $destination->id || $share->status !== FormulaShareStatus::Pending || $share->snapshot === null || $share->schema_version !== 1) {
            $this->invalid();
        }
        if (! $this->authorization->canManage($actor, $destination->id)) {
            throw new AuthorizationException;
        }
        $snapshot = $share->snapshot;
        $formula = $snapshot['formula'];
        $context = $formula['calculation_context'];
        $family = ProductFamily::query()->findOrFail($snapshot['product']['family']['id']);
        $phases = [];
        $items = [];
        foreach ($formula['phases'] as $phase) {
            if (isset($items[$phase['slug']])) {
                $this->invalid();
            }
            $phases[] = ['key' => $phase['slug'], 'name' => $phase['name']];
            $items[$phase['slug']] = collect($phase['items'])->map(function (array $item) use ($ingredientMap): array {
                if (! isset($ingredientMap[$item['ingredient_key']])) {
                    $this->invalid();
                }

                return ['ingredient_id' => $ingredientMap[$item['ingredient_key']], 'percentage' => $item['percentage'], 'weight' => $item['weight'], 'note' => $item['note']];
            })->all();
        }
        foreach ($formula['canonical_alkalis'] as $key) {
            if (! isset($ingredientMap[$key])) {
                $this->invalid();
            }
        }
        $procedure = $this->sanitizer->richText($formula['manufacturing_instructions']);
        $payload = [
            'name' => $snapshot['product']['name'], 'product_type_id' => $snapshot['product']['type']['id'] ?? null,
            'oil_weight' => $context['oil_weight'], 'oil_unit' => $context['oil_unit'], 'editing_mode' => $context['editing_mode'],
            'manufacturing_mode' => $formula['manufacturing_mode'], 'exposure_mode' => $formula['exposure_mode'], 'regulatory_regime' => $formula['regulatory_regime']['code'],
            'ifra_category_selection_mode' => $formula['ifra']['selection_mode'], 'ifra_product_category_id' => $formula['ifra']['category']['id'] ?? null,
            'lye_type' => $context['lye_type'] ?? 'naoh', 'koh_purity_percentage' => $context['koh_purity_percentage'] ?? 90,
            'dual_lye_koh_percentage' => $context['dual_lye_koh_percentage'] ?? 40, 'superfat' => $context['superfat'] ?? 5,
            'water_mode' => $formula['water_settings']['mode'] ?? 'percent_of_oils', 'water_value' => $formula['water_settings']['value'] ?? 38,
            'phases' => $phases, 'phase_items' => $items, 'manufacturing_instructions' => $procedure,
            'packaging_items' => [], 'production_output_type' => ProductionOutputType::FinishedProduct->value,
        ];
        $saved = $this->workbench->publish($actor, $family, $payload);
        $recipe = Recipe::withoutGlobalScopes()->findOrFail($saved->recipe_id);
        if ($recipe->workspace_id !== $destination->id) {
            throw new AuthorizationException;
        }
        RecipeVersion::withoutGlobalScopes()->where('recipe_id', $recipe->id)->update([
            'catalog_reviewed_at' => null, 'final_ingredient_list' => null, 'final_ingredient_list_basis_hash' => null,
            'final_plain_ingredient_list' => null, 'final_plain_ingredient_list_basis_hash' => null,
        ]);

        return $this->content->update($recipe, [
            'description' => $this->sanitizer->richText($snapshot['product']['description']),
            'manufacturing_instructions' => $procedure,
        ]);
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['sharing' => __('sharing.validation.formula')]);
    }
}
