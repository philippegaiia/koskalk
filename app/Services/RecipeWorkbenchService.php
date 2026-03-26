<?php

namespace App\Services;

use App\Models\ProductFamily;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\RecipePhase;
use App\Models\RecipeVersion;
use App\Models\User;
use App\OwnerType;
use App\Visibility;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class RecipeWorkbenchService
{
    public function __construct(
        private readonly RecipeNormalizationService $recipeNormalizationService,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function phaseBlueprints(): array
    {
        return [
            [
                'key' => 'saponified_oils',
                'name' => 'Saponified Oils',
                'phase_group' => 'reaction_core',
                'phase_type' => 'reaction_core',
                'description' => 'Carrier oils and butters that drive the soap calculation itself.',
                'is_system' => true,
            ],
            [
                'key' => 'lye_water',
                'name' => 'Lye Water',
                'phase_group' => 'reaction_core',
                'phase_type' => 'reaction_medium',
                'description' => 'The reaction medium: alkali, water mode, and superfat settings.',
                'is_system' => true,
            ],
            [
                'key' => 'additives',
                'name' => 'Additives',
                'phase_group' => 'post_reaction',
                'phase_type' => 'post_reaction',
                'description' => 'Colorants, preservatives, and functional additions added after the core soap calculation.',
                'is_system' => true,
            ],
            [
                'key' => 'fragrance',
                'name' => 'Fragrance And Aromatics',
                'phase_group' => 'post_reaction',
                'phase_type' => 'post_reaction',
                'description' => 'Essential oils, aromatic extracts, and later user-authored fragrance oils.',
                'is_system' => true,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function draftPayload(?Recipe $recipe): ?array
    {
        if ($recipe === null) {
            return null;
        }

        $version = RecipeVersion::withoutGlobalScopes()
            ->where('recipe_id', $recipe->id)
            ->where('is_draft', true)
            ->with([
                'recipe',
                'phases' => fn ($query) => $query->withoutGlobalScopes()->orderBy('sort_order'),
                'phases.items' => fn ($query) => $query->withoutGlobalScopes()->orderBy('position'),
                'phases.items.ingredientVersion.ingredient',
                'phases.items.ingredientVersion.sapProfile',
                'phases.items.ingredientVersion.fattyAcidEntries.fattyAcid',
            ])
            ->first()
            ?? RecipeVersion::withoutGlobalScopes()
                ->where('recipe_id', $recipe->id)
                ->with([
                    'recipe',
                    'phases' => fn ($query) => $query->withoutGlobalScopes()->orderBy('sort_order'),
                    'phases.items' => fn ($query) => $query->withoutGlobalScopes()->orderBy('position'),
                    'phases.items.ingredientVersion.ingredient',
                    'phases.items.ingredientVersion.sapProfile',
                    'phases.items.ingredientVersion.fattyAcidEntries.fattyAcid',
                ])
                ->orderByDesc('version_number')
                ->first();

        if (! $version instanceof RecipeVersion) {
            return null;
        }

        $phaseRows = collect($this->phaseBlueprints())
            ->keyBy('key')
            ->map(fn (array $phase): array => [$phase['key'] => []])
            ->collapse()
            ->all();

        $version->phases
            ->sortBy('sort_order')
            ->each(function (RecipePhase $phase) use (&$phaseRows): void {
                $phaseRows[$phase->slug] = $phase->items
                    ->sortBy('position')
                    ->map(fn (RecipeItem $item): array => $this->mapItemToWorkbenchRow($item))
                    ->filter(fn (array $row): bool => $row['ingredient_version_id'] !== null)
                    ->values()
                    ->all();
            });

        /** @var array<string, mixed> $waterSettings */
        $waterSettings = $version->water_settings ?? [];
        /** @var array<string, mixed> $calculationContext */
        $calculationContext = $version->calculation_context ?? [];

        return [
            'recipe' => [
                'id' => $version->recipe_id,
                'draft_version_id' => $version->id,
                'version_number' => $version->version_number,
            ],
            'formulaName' => $version->name,
            'oilUnit' => (string) ($calculationContext['oil_unit'] ?? $version->batch_unit),
            'oilWeight' => (float) ($calculationContext['oil_weight'] ?? $version->batch_size),
            'editMode' => $calculationContext['editing_mode'] === 'weight' ? 'weight' : 'percentage',
            'lyeType' => in_array($calculationContext['lye_type'] ?? 'naoh', ['naoh', 'koh', 'dual'], true)
                ? $calculationContext['lye_type']
                : 'naoh',
            'kohPurity' => (float) ($calculationContext['koh_purity_percentage'] ?? 90),
            'dualKohPercentage' => (float) ($calculationContext['dual_lye_koh_percentage'] ?? 40),
            'waterMode' => in_array($waterSettings['mode'] ?? 'percent_of_oils', ['percent_of_oils', 'lye_ratio', 'lye_concentration'], true)
                ? $waterSettings['mode']
                : 'percent_of_oils',
            'waterValue' => (float) ($waterSettings['value'] ?? 38),
            'superfat' => (float) ($calculationContext['superfat'] ?? 5),
            'selectedIfraProductCategoryId' => $version->ifra_product_category_id,
            'phaseItems' => $phaseRows,
        ];
    }

    public function saveDraft(User $user, ProductFamily $productFamily, array $payload, ?Recipe $recipe = null): RecipeVersion
    {
        $normalizedPayload = $this->normalizePayload($payload);

        return DB::transaction(function () use ($normalizedPayload, $productFamily, $recipe, $user): RecipeVersion {
            $recipe ??= $this->createRecipe($user, $productFamily, $normalizedPayload['name']);

            $draftVersion = RecipeVersion::withoutGlobalScopes()
                ->where('recipe_id', $recipe->id)
                ->where('is_draft', true)
                ->first();

            if (! $draftVersion instanceof RecipeVersion) {
                $draftVersion = new RecipeVersion;
                $draftVersion->recipe()->associate($recipe);
                $draftVersion->version_number = $this->nextVersionNumber($recipe);
                $draftVersion->is_draft = true;
            }

            $this->fillVersion($draftVersion, $recipe, $user, $normalizedPayload, true);
            $draftVersion->save();

            $this->syncVersionStructure($draftVersion, $user, $normalizedPayload);

            return $draftVersion->fresh([
                'recipe',
                'phases.items.ingredientVersion.ingredient',
                'phases.items.ingredientVersion.sapProfile',
                'phases.items.ingredientVersion.fattyAcidEntries.fattyAcid',
            ]);
        });
    }

    public function saveAsNewVersion(User $user, ProductFamily $productFamily, array $payload, ?Recipe $recipe = null): RecipeVersion
    {
        $normalizedPayload = $this->normalizePayload($payload);

        return DB::transaction(function () use ($normalizedPayload, $productFamily, $recipe, $user): RecipeVersion {
            $recipe ??= $this->createRecipe($user, $productFamily, $normalizedPayload['name']);

            $draftVersion = RecipeVersion::withoutGlobalScopes()
                ->where('recipe_id', $recipe->id)
                ->where('is_draft', true)
                ->first();

            if ($draftVersion instanceof RecipeVersion) {
                $this->fillVersion($draftVersion, $recipe, $user, $normalizedPayload, false);
                $draftVersion->saved_at = now();
                $draftVersion->save();
                $this->syncVersionStructure($draftVersion, $user, $normalizedPayload);

                $nextDraftVersionNumber = $draftVersion->version_number + 1;
            } else {
                $publishedVersion = new RecipeVersion;
                $publishedVersion->recipe()->associate($recipe);
                $publishedVersion->version_number = $this->nextVersionNumber($recipe);
                $this->fillVersion($publishedVersion, $recipe, $user, $normalizedPayload, false);
                $publishedVersion->saved_at = now();
                $publishedVersion->save();
                $this->syncVersionStructure($publishedVersion, $user, $normalizedPayload);

                $nextDraftVersionNumber = $publishedVersion->version_number + 1;
            }

            $newDraftVersion = new RecipeVersion;
            $newDraftVersion->recipe()->associate($recipe);
            $newDraftVersion->version_number = $nextDraftVersionNumber;
            $this->fillVersion($newDraftVersion, $recipe, $user, $normalizedPayload, true);
            $newDraftVersion->save();
            $this->syncVersionStructure($newDraftVersion, $user, $normalizedPayload);

            return $newDraftVersion->fresh([
                'recipe',
                'phases.items.ingredientVersion.ingredient',
                'phases.items.ingredientVersion.sapProfile',
                'phases.items.ingredientVersion.fattyAcidEntries.fattyAcid',
            ]);
        });
    }

    public function duplicate(User $user, ProductFamily $productFamily, array $payload): RecipeVersion
    {
        $copyPayload = $payload;
        $copyPayload['name'] = $this->duplicateName((string) ($payload['name'] ?? 'Soap Formula'));

        return $this->saveDraft($user, $productFamily, $copyPayload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     name: string,
     *     oil_weight: float,
     *     oil_unit: string,
     *     editing_mode: string,
     *     ifra_product_category_id: int|null,
     *     water_settings: array{mode: string, value: float},
     *     calculation_context: array<string, mixed>,
     *     phases: array<int, array<string, mixed>>
     * }
     */
    private function normalizePayload(array $payload): array
    {
        $editingMode = ($payload['editing_mode'] ?? 'percentage') === 'weight' ? 'weight' : 'percent';
        $phasePayload = $this->phasePayload($payload);
        try {
            $normalizedRecipe = $this->recipeNormalizationService->normalizeSoapRecipe(
                $phasePayload,
                (float) ($payload['oil_weight'] ?? 0),
                $editingMode,
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                $this->normalizationErrorField($exception->getMessage()) => $exception->getMessage(),
            ]);
        }

        if (abs($normalizedRecipe['totals']['oil_percentage'] - 100) > 0.01) {
            throw ValidationException::withMessages([
                'saponified_oils' => 'Saponified oils must total 100% before the formula can be saved.',
            ]);
        }

        $name = trim((string) ($payload['name'] ?? 'Untitled Soap Formula'));

        return [
            'name' => $name !== '' ? $name : 'Untitled Soap Formula',
            'oil_weight' => $normalizedRecipe['oil_weight'],
            'oil_unit' => in_array($payload['oil_unit'] ?? 'g', ['g', 'oz', 'lb'], true) ? $payload['oil_unit'] : 'g',
            'editing_mode' => $editingMode === 'weight' ? 'weight' : 'percentage',
            'ifra_product_category_id' => isset($payload['ifra_product_category_id']) && is_numeric($payload['ifra_product_category_id'])
                ? (int) $payload['ifra_product_category_id']
                : null,
            'water_settings' => [
                'mode' => in_array($payload['water_mode'] ?? 'percent_of_oils', ['percent_of_oils', 'lye_ratio', 'lye_concentration'], true)
                    ? $payload['water_mode']
                    : 'percent_of_oils',
                'value' => (float) ($payload['water_value'] ?? 38),
            ],
            'calculation_context' => [
                'editing_mode' => $editingMode === 'weight' ? 'weight' : 'percentage',
                'lye_type' => in_array($payload['lye_type'] ?? 'naoh', ['naoh', 'koh', 'dual'], true)
                    ? $payload['lye_type']
                    : 'naoh',
                'koh_purity_percentage' => (float) ($payload['koh_purity_percentage'] ?? 90),
                'dual_lye_koh_percentage' => (float) ($payload['dual_lye_koh_percentage'] ?? 40),
                'superfat' => (float) ($payload['superfat'] ?? 5),
                'oil_weight' => $normalizedRecipe['oil_weight'],
                'oil_unit' => in_array($payload['oil_unit'] ?? 'g', ['g', 'oz', 'lb'], true) ? $payload['oil_unit'] : 'g',
                'totals' => $normalizedRecipe['totals'],
            ],
            'phases' => array_map(function (array $phase): array {
                $phaseBlueprint = collect($this->phaseBlueprints())
                    ->firstWhere('key', $phase['key']);

                return [
                    'key' => $phase['key'],
                    'name' => $phase['name'],
                    'phase_type' => $phaseBlueprint['phase_type'] ?? null,
                    'is_system' => (bool) ($phaseBlueprint['is_system'] ?? false),
                    'items' => array_values(array_filter($phase['items'], function (array $item): bool {
                        return $item['ingredient_id'] !== null
                            && $item['ingredient_version_id'] !== null
                            && ($item['percentage'] > 0 || $item['weight'] > 0);
                    })),
                ];
            }, $normalizedRecipe['phases']),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    private function phasePayload(array $payload): array
    {
        $phaseItems = $payload['phase_items'] ?? [];

        return array_map(function (array $phase) use ($phaseItems): array {
            $rows = $phaseItems[$phase['key']] ?? [];

            return [
                'key' => $phase['key'],
                'name' => $phase['name'],
                'items' => array_map(function (array $row): array {
                    return [
                        'ingredient_id' => isset($row['ingredient_id']) ? (int) $row['ingredient_id'] : null,
                        'ingredient_version_id' => isset($row['ingredient_version_id']) ? (int) $row['ingredient_version_id'] : null,
                        'percentage' => (float) ($row['percentage'] ?? 0),
                        'weight' => (float) ($row['weight'] ?? 0),
                        'note' => $row['note'] ?? null,
                    ];
                }, is_array($rows) ? $rows : []),
            ];
        }, $this->phaseBlueprints());
    }

    private function createRecipe(User $user, ProductFamily $productFamily, string $name): Recipe
    {
        $recipe = new Recipe([
            'product_family_id' => $productFamily->id,
            'name' => $name,
            'slug' => $this->uniqueRecipeSlug($name),
            'owner_type' => OwnerType::User,
            'owner_id' => $user->id,
            'workspace_id' => null,
            'visibility' => Visibility::Private,
        ]);

        $recipe->save();

        return $recipe;
    }

    /**
     * @param  array<string, mixed>  $normalizedPayload
     */
    private function fillVersion(
        RecipeVersion $recipeVersion,
        Recipe $recipe,
        User $user,
        array $normalizedPayload,
        bool $isDraft,
    ): void {
        if ($recipe->name !== $normalizedPayload['name']) {
            $recipe->name = $normalizedPayload['name'];
            $recipe->save();
        }

        $recipeVersion->recipe()->associate($recipe);
        $recipeVersion->owner_type = OwnerType::User;
        $recipeVersion->owner_id = $user->id;
        $recipeVersion->workspace_id = null;
        $recipeVersion->visibility = Visibility::Private;
        $recipeVersion->is_draft = $isDraft;
        $recipeVersion->name = $normalizedPayload['name'];
        $recipeVersion->batch_size = $normalizedPayload['oil_weight'];
        $recipeVersion->batch_unit = $normalizedPayload['oil_unit'];
        $recipeVersion->ifra_product_category_id = $normalizedPayload['ifra_product_category_id'];
        $recipeVersion->water_settings = $normalizedPayload['water_settings'];
        $recipeVersion->calculation_context = $normalizedPayload['calculation_context'];
        $recipeVersion->saved_at = $isDraft ? null : ($recipeVersion->saved_at ?? now());
        $recipeVersion->archived_at = null;
    }

    /**
     * @param  array<string, mixed>  $normalizedPayload
     */
    private function syncVersionStructure(RecipeVersion $recipeVersion, User $user, array $normalizedPayload): void
    {
        RecipeItem::withoutGlobalScopes()
            ->where('recipe_version_id', $recipeVersion->id)
            ->delete();

        RecipePhase::withoutGlobalScopes()
            ->where('recipe_version_id', $recipeVersion->id)
            ->delete();

        foreach ($normalizedPayload['phases'] as $phaseIndex => $phasePayload) {
            $phase = new RecipePhase([
                'owner_type' => OwnerType::User,
                'owner_id' => $user->id,
                'workspace_id' => null,
                'visibility' => Visibility::Private,
                'name' => $phasePayload['name'],
                'slug' => $phasePayload['key'],
                'phase_type' => $phasePayload['phase_type'],
                'sort_order' => $phaseIndex + 1,
                'is_system' => $phasePayload['is_system'],
            ]);

            $phase->recipeVersion()->associate($recipeVersion);
            $phase->save();

            foreach ($phasePayload['items'] as $itemIndex => $itemPayload) {
                $recipeItem = new RecipeItem([
                    'ingredient_id' => $itemPayload['ingredient_id'],
                    'ingredient_version_id' => $itemPayload['ingredient_version_id'],
                    'owner_type' => OwnerType::User,
                    'owner_id' => $user->id,
                    'workspace_id' => null,
                    'visibility' => Visibility::Private,
                    'position' => $itemIndex + 1,
                    'percentage' => $itemPayload['percentage'],
                    'weight' => $itemPayload['weight'],
                    'note' => $itemPayload['note'],
                ]);

                $recipeItem->recipeVersion()->associate($recipeVersion);
                $recipeItem->recipePhase()->associate($phase);
                $recipeItem->save();
            }
        }
    }

    private function nextVersionNumber(Recipe $recipe): int
    {
        return ((int) RecipeVersion::withoutGlobalScopes()
            ->where('recipe_id', $recipe->id)
            ->max('version_number')) + 1;
    }

    private function uniqueRecipeSlug(string $name): string
    {
        $baseSlug = Str::slug($name);
        $slug = $baseSlug !== '' ? $baseSlug : 'soap-formula';
        $suffix = 1;

        while (Recipe::withoutGlobalScopes()->where('slug', $slug)->exists()) {
            $slug = "{$baseSlug}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    private function duplicateName(string $name): string
    {
        return Str::startsWith($name, 'Copy of ')
            ? $name
            : 'Copy of '.$name;
    }

    private function normalizationErrorField(string $message): string
    {
        $normalizedMessage = str($message)->lower();

        if ($normalizedMessage->contains('oil weight')) {
            return 'oil_weight';
        }

        if ($normalizedMessage->contains('editing mode')) {
            return 'editing_mode';
        }

        if ($normalizedMessage->contains('percentage')) {
            return 'percentage';
        }

        if ($normalizedMessage->contains('weight')) {
            return 'weight';
        }

        return 'draft';
    }

    /**
     * @return array<string, mixed>
     */
    private function mapItemToWorkbenchRow(RecipeItem $item): array
    {
        $ingredientVersion = $item->ingredientVersion;
        $ingredient = $ingredientVersion?->ingredient;
        $sapProfile = $ingredientVersion?->sapProfile;

        return [
            'id' => 'saved-'.$item->id,
            'ingredient_version_id' => $item->ingredient_version_id,
            'ingredient_id' => $item->ingredient_id,
            'name' => $ingredientVersion?->display_name,
            'inci_name' => $ingredientVersion?->inci_name,
            'category' => $ingredient?->category?->value,
            'soap_inci_naoh_name' => $ingredientVersion?->soap_inci_naoh_name,
            'soap_inci_koh_name' => $ingredientVersion?->soap_inci_koh_name,
            'koh_sap_value' => $sapProfile?->koh_sap_value === null ? null : (float) $sapProfile->koh_sap_value,
            'naoh_sap_value' => $sapProfile?->naoh_sap_value,
            'fatty_acid_profile' => $ingredientVersion?->normalizedFattyAcidProfile() ?? [],
            'percentage' => (float) $item->percentage,
            'note' => $item->note,
        ];
    }
}
