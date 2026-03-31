<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\ProductFamily;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\RecipePhase;
use App\Models\RecipeVersion;
use App\Models\User;
use App\OwnerType;
use App\Visibility;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class RecipeWorkbenchService
{
    public function __construct(
        private readonly RecipeNormalizationService $recipeNormalizationService,
        private readonly SoapCalculationService $soapCalculationService,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public function previewSoapCalculation(array $payload): ?array
    {
        if (($payload['manufacturing_mode'] ?? 'saponify_in_formula') !== 'saponify_in_formula') {
            return null;
        }

        $oilRows = collect($payload['phase_items']['saponified_oils'] ?? [])
            ->filter(fn (mixed $row): bool => is_array($row))
            ->values();

        if ($oilRows->isEmpty()) {
            return null;
        }

        $ingredients = Ingredient::query()
            ->with(['sapProfile', 'fattyAcidEntries.fattyAcid'])
            ->whereKey($oilRows->pluck('ingredient_id')->filter()->map(fn (mixed $id): int => (int) $id)->all())
            ->get()
            ->keyBy('id');

        $oils = $oilRows
            ->map(function (array $row) use ($ingredients, $payload): ?array {
                $ingredientId = isset($row['ingredient_id']) ? (int) $row['ingredient_id'] : null;

                if ($ingredientId === null) {
                    return null;
                }

                $ingredient = $ingredients->get($ingredientId);

                if (! $ingredient instanceof Ingredient) {
                    return null;
                }

                $weight = $this->previewRowWeight($row, $payload);

                if ($weight <= 0) {
                    return null;
                }

                return [
                    'name' => $ingredient->display_name,
                    'weight' => $weight,
                    'koh_sap_value' => $ingredient->sapProfile?->koh_sap_value ?? 0,
                    'fatty_acid_profile' => $ingredient->normalizedFattyAcidProfile(),
                ];
            })
            ->filter()
            ->values()
            ->all();

        if ($oils === []) {
            return null;
        }

        try {
            return $this->soapCalculationService->calculate($oils, [
                'superfat' => (float) ($payload['superfat'] ?? 5),
                'lye_type' => $payload['lye_type'] ?? 'naoh',
                'dual_lye_koh_percentage' => (float) ($payload['dual_lye_koh_percentage'] ?? 40),
                'koh_purity_percentage' => (float) ($payload['koh_purity_percentage'] ?? 90),
                'water_mode' => $payload['water_mode'] ?? 'percent_of_oils',
                'water_value' => (float) ($payload['water_value'] ?? 38),
            ]);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

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
            ->with($this->versionWorkbenchRelations())
            ->first()
            ?? RecipeVersion::withoutGlobalScopes()
                ->where('recipe_id', $recipe->id)
                ->with($this->versionWorkbenchRelations())
                ->orderByDesc('version_number')
                ->first();

        return $version instanceof RecipeVersion ? $this->workbenchPayloadFromVersion($version) : null;
    }

    /**
     * @return array{draft: array<string, mixed>, calculation: array<string, mixed>|null}|null
     */
    public function draftSnapshot(?Recipe $recipe): ?array
    {
        if ($recipe === null) {
            return null;
        }

        $version = RecipeVersion::withoutGlobalScopes()
            ->where('recipe_id', $recipe->id)
            ->where('is_draft', true)
            ->with($this->versionWorkbenchRelations())
            ->first()
            ?? RecipeVersion::withoutGlobalScopes()
                ->where('recipe_id', $recipe->id)
                ->with($this->versionWorkbenchRelations())
                ->orderByDesc('version_number')
                ->first();

        if (! $version instanceof RecipeVersion) {
            return null;
        }

        return $this->workbenchSnapshotFromVersion($version);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function versionOptions(Recipe $recipe): array
    {
        return RecipeVersion::withoutGlobalScopes()
            ->where('recipe_id', $recipe->id)
            ->where('is_draft', false)
            ->orderByDesc('version_number')
            ->get()
            ->map(fn (RecipeVersion $version): array => [
                'id' => $version->id,
                'version_number' => $version->version_number,
                'name' => $version->name,
                'saved_at' => $version->saved_at?->toIso8601String(),
                'label' => 'v'.$version->version_number.' · '.$version->name,
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function versionPayload(?Recipe $recipe, int $versionId): ?array
    {
        if ($recipe === null) {
            return null;
        }

        $version = RecipeVersion::withoutGlobalScopes()
            ->where('recipe_id', $recipe->id)
            ->whereKey($versionId)
            ->with($this->versionWorkbenchRelations())
            ->first();

        return $version instanceof RecipeVersion ? $this->workbenchPayloadFromVersion($version) : null;
    }

    /**
     * @return array{draft: array<string, mixed>, calculation: array<string, mixed>|null}|null
     */
    public function versionSnapshot(?Recipe $recipe, int $versionId): ?array
    {
        if ($recipe === null) {
            return null;
        }

        $version = RecipeVersion::withoutGlobalScopes()
            ->where('recipe_id', $recipe->id)
            ->whereKey($versionId)
            ->with($this->versionWorkbenchRelations())
            ->first();

        if (! $version instanceof RecipeVersion) {
            return null;
        }

        return $this->workbenchSnapshotFromVersion($version);
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>|null
     */
    public function calculationFromWorkbenchDraft(array $draft): ?array
    {
        return $this->previewSoapCalculation($this->previewPayloadFromWorkbenchDraft($draft));
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
                'phases.items.ingredient',
                'phases.items.ingredient.sapProfile',
                'phases.items.ingredient.fattyAcidEntries.fattyAcid',
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
                'phases.items.ingredient',
                'phases.items.ingredient.sapProfile',
                'phases.items.ingredient.fattyAcidEntries.fattyAcid',
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
     *     manufacturing_mode: string,
     *     exposure_mode: string,
     *     regulatory_regime: string,
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
            'manufacturing_mode' => $this->normalizeManufacturingMode($payload['manufacturing_mode'] ?? 'saponify_in_formula'),
            'exposure_mode' => $this->normalizeExposureMode($payload['exposure_mode'] ?? 'rinse_off'),
            'regulatory_regime' => $this->normalizeRegulatoryRegime($payload['regulatory_regime'] ?? 'eu'),
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
                        'percentage' => (float) ($row['percentage'] ?? 0),
                        'weight' => (float) ($row['weight'] ?? 0),
                        'note' => $row['note'] ?? null,
                    ];
                }, is_array($rows) ? $rows : []),
            ];
        }, $this->phaseBlueprints());
    }

    /**
     * @return array<int, string|callable>
     */
    private function versionWorkbenchRelations(): array
    {
        return [
            'recipe',
            'phases' => fn ($query) => $query->withoutGlobalScopes()->orderBy('sort_order'),
            'phases.items' => fn ($query) => $query->withoutGlobalScopes()->orderBy('position'),
            'phases.items.ingredient',
            'phases.items.ingredient.sapProfile',
            'phases.items.ingredient.fattyAcidEntries.fattyAcid',
            'phases.items.ingredient.allergenEntries',
            'phases.items.ingredient.ifraCertificates.limits',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function workbenchPayloadFromVersion(RecipeVersion $version): array
    {
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
                    ->filter(fn (array $row): bool => $row['ingredient_id'] !== null)
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
                'is_draft' => $version->is_draft,
            ],
            'formulaName' => $version->name,
            'oilUnit' => (string) ($calculationContext['oil_unit'] ?? $version->batch_unit),
            'oilWeight' => (float) ($calculationContext['oil_weight'] ?? $version->batch_size),
            'manufacturingMode' => $this->normalizeManufacturingMode($version->manufacturing_mode),
            'exposureMode' => $this->normalizeExposureMode($version->exposure_mode),
            'regulatoryRegime' => $this->normalizeRegulatoryRegime($version->regulatory_regime),
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
            'catalogReview' => $this->catalogReviewState($version),
        ];
    }

    /**
     * @return array{draft: array<string, mixed>, calculation: array<string, mixed>|null}
     */
    private function workbenchSnapshotFromVersion(RecipeVersion $version): array
    {
        $draft = $this->workbenchPayloadFromVersion($version);

        return [
            'draft' => $draft,
            'calculation' => $this->calculationFromWorkbenchDraft($draft),
        ];
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    private function previewPayloadFromWorkbenchDraft(array $draft): array
    {
        return [
            'manufacturing_mode' => $draft['manufacturingMode'] ?? 'saponify_in_formula',
            'oil_weight' => $draft['oilWeight'] ?? 0,
            'lye_type' => $draft['lyeType'] ?? 'naoh',
            'koh_purity_percentage' => $draft['kohPurity'] ?? 90,
            'dual_lye_koh_percentage' => $draft['dualKohPercentage'] ?? 40,
            'water_mode' => $draft['waterMode'] ?? 'percent_of_oils',
            'water_value' => $draft['waterValue'] ?? 38,
            'superfat' => $draft['superfat'] ?? 5,
            'phase_items' => $draft['phaseItems'] ?? [],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $payload
     */
    private function previewRowWeight(array $row, array $payload): float
    {
        $explicitWeight = (float) ($row['weight'] ?? 0);

        if ($explicitWeight > 0) {
            return $explicitWeight;
        }

        $oilWeight = (float) ($payload['oil_weight'] ?? 0);
        $percentage = (float) ($row['percentage'] ?? 0);

        if ($oilWeight <= 0 || $percentage <= 0) {
            return 0;
        }

        return $oilWeight * ($percentage / 100);
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
        $recipeVersion->manufacturing_mode = $normalizedPayload['manufacturing_mode'];
        $recipeVersion->exposure_mode = $normalizedPayload['exposure_mode'];
        $recipeVersion->regulatory_regime = $normalizedPayload['regulatory_regime'];
        $recipeVersion->ifra_product_category_id = $normalizedPayload['ifra_product_category_id'];
        $recipeVersion->water_settings = $normalizedPayload['water_settings'];
        $recipeVersion->calculation_context = $normalizedPayload['calculation_context'];
        $recipeVersion->saved_at = $isDraft ? null : ($recipeVersion->saved_at ?? now());
        $recipeVersion->catalog_reviewed_at = now();
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
        $ingredient = $item->ingredient;
        $sapProfile = $ingredient?->sapProfile;

        return [
            'id' => 'saved-'.$item->id,
            'ingredient_id' => $item->ingredient_id,
            'name' => $ingredient?->display_name,
            'inci_name' => $ingredient?->inci_name,
            'category' => $ingredient?->category?->value,
            'soap_inci_naoh_name' => $ingredient?->soap_inci_naoh_name,
            'soap_inci_koh_name' => $ingredient?->soap_inci_koh_name,
            'koh_sap_value' => $sapProfile?->koh_sap_value === null ? null : (float) $sapProfile->koh_sap_value,
            'naoh_sap_value' => $sapProfile?->naoh_sap_value,
            'fatty_acid_profile' => $ingredient?->normalizedFattyAcidProfile() ?? [],
            'percentage' => (float) $item->percentage,
            'note' => $item->note,
        ];
    }

    private function normalizeManufacturingMode(?string $value): string
    {
        return in_array($value, ['saponify_in_formula', 'blend_only'], true)
            ? $value
            : 'saponify_in_formula';
    }

    private function normalizeExposureMode(?string $value): string
    {
        return in_array($value, ['rinse_off', 'leave_on'], true)
            ? $value
            : 'rinse_off';
    }

    private function normalizeRegulatoryRegime(?string $value): string
    {
        return in_array($value, ['eu'], true)
            ? $value
            : 'eu';
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogReviewState(RecipeVersion $version): array
    {
        $reviewedAt = $version->catalog_reviewed_at;
        $latestIngredientChangeAt = $version->phases
            ->flatMap(fn (RecipePhase $phase) => $phase->items)
            ->map(fn (RecipeItem $item): ?\Illuminate\Support\Carbon => $this->latestIngredientChangeAt($item))
            ->filter()
            ->sortDesc()
            ->first();

        $needsReview = $reviewedAt === null
            || ($latestIngredientChangeAt !== null && $latestIngredientChangeAt->gt($reviewedAt));

        return [
            'needs_review' => $needsReview,
            'reviewed_at' => $reviewedAt?->toIso8601String(),
            'latest_ingredient_change_at' => $latestIngredientChangeAt?->toIso8601String(),
            'message' => $needsReview
                ? 'One or more linked ingredients changed after this formula was last reviewed. Recheck INCI and compliance before export.'
                : 'Ingredient-linked data matches the last recorded catalog review for this formula version.',
        ];
    }

    private function latestIngredientChangeAt(RecipeItem $item): ?Carbon
    {
        $ingredient = $item->ingredient;

        if (! $ingredient instanceof Ingredient) {
            return null;
        }

        return collect([
            $ingredient->updated_at,
            $ingredient->sapProfile?->updated_at,
            $ingredient->allergenEntries->max('updated_at'),
            $ingredient->ifraCertificates->max('updated_at'),
            $ingredient->ifraCertificates
                ->flatMap(fn ($certificate) => $certificate->limits)
                ->max('updated_at'),
            $ingredient->fattyAcidEntries->max('updated_at'),
        ])
            ->filter()
            ->map(fn ($value) => $value instanceof Carbon ? $value : Carbon::parse($value))
            ->sortDesc()
            ->first();
    }
}
