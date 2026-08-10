<?php

namespace App\Actions\Ingredients;

use App\Enums\IngredientCategory;
use App\Enums\OwnerType;
use App\Enums\Visibility;
use App\Models\Ingredient;
use App\Models\User;
use App\Models\Workspace;
use App\Services\EntitlementService;
use App\Services\IngredientDataEntryService;
use Illuminate\Validation\ValidationException;

class CreateManufacturedIngredient
{
    public function __construct(
        private readonly EntitlementService $entitlementService,
        private readonly IngredientDataEntryService $ingredientDataEntryService,
    ) {}

    public function handle(User $user, string $name): Ingredient
    {
        $name = trim($name);

        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => __('production_bench.production.validation.manufactured_ingredient_name_required'),
            ]);
        }

        if (mb_strlen($name) > 255) {
            throw ValidationException::withMessages([
                'name' => __('production_bench.production.validation.manufactured_ingredient_name_invalid'),
            ]);
        }

        return $this->entitlementService->withinCompanyQuotaLock(
            $user,
            function (Workspace $workspace) use ($name): Ingredient {
                $this->entitlementService->assertCanCreatePrivateIngredientInWorkspace($workspace);

                $ingredient = new Ingredient([
                    'catalog_key' => $this->ingredientDataEntryService->generateCatalogKey('USR'),
                    'category' => IngredientCategory::Additive,
                    'display_name' => $name,
                    'owner_type' => OwnerType::Workspace,
                    'owner_id' => $workspace->id,
                    'workspace_id' => $workspace->id,
                    'visibility' => Visibility::Private,
                    'requires_admin_review' => false,
                    'is_active' => true,
                    'is_potentially_saponifiable' => false,
                    'is_manufactured' => true,
                ]);
                $ingredient->save();

                return $this->ingredientDataEntryService->syncCurrentData($ingredient, [
                    'current_version' => [
                        'display_name' => $name,
                        'is_active' => true,
                        'is_manufactured' => true,
                    ],
                    'sap_profile' => [],
                    'fatty_acid_entries' => [],
                    'allergen_entries' => [],
                    'function_ids' => [],
                    'components' => [],
                ]);
            },
            attempts: 1,
        );
    }
}
