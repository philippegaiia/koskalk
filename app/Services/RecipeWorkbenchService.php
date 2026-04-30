<?php

namespace App\Services;

use App\Models\ProductFamily;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use Illuminate\Support\Str;
use InvalidArgumentException;

class RecipeWorkbenchService
{
    public function __construct(
        private readonly RecipeWorkbenchDraftPayloadMapper $recipeWorkbenchDraftPayloadMapper,
        private readonly RecipeDraftSaver $recipeDraftSaver,
        private readonly RecipeVersionPublisher $recipeVersionPublisher,
        private readonly RecipeVersionCostingSynchronizer $recipeVersionCostingSynchronizer,
        private readonly RecipeWorkbenchPayloadNormalizer $recipeWorkbenchPayloadNormalizer,
        private readonly RecipeWorkbenchPreviewService $recipeWorkbenchPreviewService,
        private readonly RecipeWorkbenchPhaseBlueprints $recipeWorkbenchPhaseBlueprints,
        private readonly RecipeWorkbenchVersionDataService $recipeWorkbenchVersionDataService,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public function previewSoapCalculation(array $payload): ?array
    {
        return $this->recipeWorkbenchPreviewService->previewSoapCalculation($payload);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function phaseBlueprints(?ProductFamily $productFamily = null): array
    {
        return $this->recipeWorkbenchPhaseBlueprints->all($productFamily);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function draftPayload(?Recipe $recipe): ?array
    {
        return $this->recipeWorkbenchVersionDataService->draftPayload($recipe);
    }

    /**
     * @return array{draft: array<string, mixed>, calculation: array<string, mixed>|null}|null
     */
    public function draftSnapshot(?Recipe $recipe): ?array
    {
        return $this->recipeWorkbenchVersionDataService->draftSnapshot($recipe);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function versionOptions(Recipe $recipe): array
    {
        return $this->recipeWorkbenchVersionDataService->versionOptions($recipe);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function versionPayload(?Recipe $recipe, int $versionId): ?array
    {
        return $this->recipeWorkbenchVersionDataService->versionPayload($recipe, $versionId);
    }

    /**
     * @return array{draft: array<string, mixed>, calculation: array<string, mixed>|null}|null
     */
    public function versionSnapshot(?Recipe $recipe, int $versionId): ?array
    {
        return $this->recipeWorkbenchVersionDataService->versionSnapshot($recipe, $versionId);
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>|null
     */
    public function calculationFromWorkbenchDraft(array $draft): ?array
    {
        return $this->recipeWorkbenchPreviewService->calculationFromWorkbenchDraft($draft);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $calculation
     * @return array<string, mixed>
     */
    public function previewInci(array $payload, ?array $calculation = null): array
    {
        return $this->recipeWorkbenchPreviewService->previewInci($payload, $calculation);
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    public function inciFromWorkbenchDraft(array $draft): array
    {
        return $this->recipeWorkbenchPreviewService->inciFromWorkbenchDraft($draft);
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>|null  $calculation
     * @return array<string, mixed>
     */
    public function labelingFromWorkbenchDraft(array $draft, ?array $calculation = null): array
    {
        return $this->recipeWorkbenchPreviewService->labelingFromWorkbenchDraft($draft, $calculation);
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array{draft: array<string, mixed>, calculation: array<string, mixed>|null, labeling: array<string, mixed>}
     */
    public function snapshotFromWorkbenchDraft(array $draft): array
    {
        return $this->recipeWorkbenchPreviewService->snapshotFromWorkbenchDraft($draft);
    }

    public function saveDraft(User $user, ProductFamily $productFamily, array $payload, ?Recipe $recipe = null): RecipeVersion
    {
        $normalizedPayload = $this->recipeWorkbenchPayloadNormalizer->normalize($payload, $productFamily, false);
        $this->validatePreviewableSoapCalculation($productFamily, $normalizedPayload);

        return $this->recipeDraftSaver->save($user, $productFamily, $normalizedPayload, $recipe);
    }

    public function saveRecipe(User $user, ProductFamily $productFamily, array $payload, ?Recipe $recipe = null): RecipeVersion
    {
        $normalizedPayload = $this->recipeWorkbenchPayloadNormalizer->normalize($payload, $productFamily, true);
        $this->validatePreviewableSoapCalculation($productFamily, $normalizedPayload);

        return $this->recipeVersionPublisher->publish($user, $productFamily, $normalizedPayload, $recipe);
    }

    public function saveAsNewVersion(User $user, ProductFamily $productFamily, array $payload, ?Recipe $recipe = null): RecipeVersion
    {
        return $this->saveRecipe($user, $productFamily, $payload, $recipe);
    }

    public function duplicate(User $user, ProductFamily $productFamily, array $payload): RecipeVersion
    {
        $copyPayload = $payload;
        $copyPayload['name'] = $this->duplicateName((string) ($payload['name'] ?? 'Soap Formula'));

        return $this->saveDraft($user, $productFamily, $copyPayload);
    }

    public function duplicateRecipe(User $user, Recipe $recipe): RecipeVersion
    {
        $workbenchPayload = $this->recipeWorkbenchVersionDataService->draftPayload($recipe);
        $sourceVersion = RecipeVersion::withoutGlobalScopes()
            ->where('recipe_id', $recipe->id)
            ->where('is_draft', true)
            ->first();

        if (! is_array($workbenchPayload)) {
            throw new InvalidArgumentException('No formula data is available to duplicate.');
        }

        $duplicate = $this->duplicate(
            $user,
            $recipe->productFamily()->withoutGlobalScopes()->firstOrFail(),
            $this->recipeWorkbenchDraftPayloadMapper->toSavePayload($workbenchPayload),
        );

        $this->recipeVersionCostingSynchronizer->copyToVersion($sourceVersion, $duplicate, $user);

        return $duplicate;
    }

    public function useVersionAsDraft(User $user, Recipe $recipe, int $versionId): RecipeVersion
    {
        $draftVersion = $this->saveDraft(
            $user,
            $recipe->productFamily()->withoutGlobalScopes()->firstOrFail(),
            $this->recipeWorkbenchDraftPayloadMapper->toSavePayload(
                $this->recipeWorkbenchVersionDataService->publishedVersionPayload($recipe, $versionId),
            ),
            $recipe,
        );

        $sourceVersion = RecipeVersion::withoutGlobalScopes()
            ->where('recipe_id', $recipe->id)
            ->find($versionId);

        $this->recipeVersionCostingSynchronizer->copyToVersion($sourceVersion, $draftVersion, $user);

        return $draftVersion;
    }

    public function draftWouldBeReplacedByVersion(Recipe $recipe, int $versionId): bool
    {
        $currentDraftPayload = $this->recipeWorkbenchVersionDataService->currentDraftPayload($recipe);

        if (! is_array($currentDraftPayload)) {
            return false;
        }

        $currentDraftSavePayload = $this->recipeWorkbenchDraftPayloadMapper->toSavePayload($currentDraftPayload);
        $targetVersionSavePayload = $this->recipeWorkbenchDraftPayloadMapper->toSavePayload(
            $this->recipeWorkbenchVersionDataService->publishedVersionPayload($recipe, $versionId),
        );

        $productFamily = $recipe->productFamily()->withoutGlobalScopes()->firstOrFail();

        return $this->recipeWorkbenchPayloadNormalizer->normalize($currentDraftSavePayload, $productFamily, false) !==
            $this->recipeWorkbenchPayloadNormalizer->normalize($targetVersionSavePayload, $productFamily, false);
    }

    public function restoreSavedFormula(User $user, Recipe $recipe, int $versionId): RecipeVersion
    {
        $sourceVersion = RecipeVersion::withoutGlobalScopes()
            ->where('recipe_id', $recipe->id)
            ->find($versionId);

        $publishedVersion = $this->recipeVersionPublisher->restore(
            $user,
            $recipe,
            $this->recipeWorkbenchPayloadNormalizer->normalize(
                $this->recipeWorkbenchDraftPayloadMapper->toSavePayload(
                    $this->recipeWorkbenchVersionDataService->publishedVersionPayload($recipe, $versionId),
                ),
                $recipe->productFamily()->withoutGlobalScopes()->firstOrFail(),
                true,
            ),
        );

        $this->recipeVersionCostingSynchronizer->copyToVersion($sourceVersion, $publishedVersion, $user);

        return $publishedVersion;
    }

    /**
     * @return array<string, mixed>
     */
    public function costingPayload(?Recipe $recipe, ?User $user): array
    {
        return $this->recipeVersionCostingSynchronizer->payload($recipe, $user);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function packagingCatalogPayload(?User $user): array
    {
        if (! $user instanceof User) {
            return [];
        }

        return $this->recipeVersionCostingSynchronizer->packagingCatalogPayload($user);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function saveCosting(User $user, Recipe $recipe, array $payload): array
    {
        $draftVersion = RecipeVersion::withoutGlobalScopes()
            ->where('recipe_id', $recipe->id)
            ->where('is_draft', true)
            ->firstOrFail();

        return $this->recipeVersionCostingSynchronizer->save($draftVersion, $user, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function savePackagingCatalogItem(User $user, array $payload): array
    {
        return $this->recipeVersionCostingSynchronizer->savePackagingItem($user, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validatePreviewableSoapCalculation(ProductFamily $productFamily, array $payload): void
    {
        if ($productFamily->slug !== 'soap') {
            return;
        }

        $this->recipeWorkbenchPreviewService->previewSoapCalculation($this->previewPayloadFromNormalizedSavePayload($payload));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function previewPayloadFromNormalizedSavePayload(array $payload): array
    {
        $calculationContext = is_array($payload['calculation_context'] ?? null)
            ? $payload['calculation_context']
            : [];
        $waterSettings = is_array($payload['water_settings'] ?? null)
            ? $payload['water_settings']
            : [];
        $phaseItems = [];

        foreach (($payload['phases'] ?? []) as $phase) {
            if (! is_array($phase)) {
                continue;
            }

            $phaseKey = (string) ($phase['key'] ?? '');

            if ($phaseKey === '') {
                continue;
            }

            $phaseItems[$phaseKey] = is_array($phase['items'] ?? null) ? $phase['items'] : [];
        }

        return [
            'manufacturing_mode' => $payload['manufacturing_mode'] ?? 'saponify_in_formula',
            'exposure_mode' => $payload['exposure_mode'] ?? 'rinse_off',
            'regulatory_regime' => $payload['regulatory_regime'] ?? 'eu',
            'product_type_id' => $payload['product_type_id'] ?? null,
            'oil_weight' => $calculationContext['oil_weight'] ?? $payload['oil_weight'] ?? 0,
            'lye_type' => $calculationContext['lye_type'] ?? 'naoh',
            'koh_purity_percentage' => $calculationContext['koh_purity_percentage'] ?? 90,
            'dual_lye_koh_percentage' => $calculationContext['dual_lye_koh_percentage'] ?? 40,
            'water_mode' => $waterSettings['mode'] ?? 'percent_of_oils',
            'water_value' => $waterSettings['value'] ?? 38,
            'superfat' => $calculationContext['superfat'] ?? 5,
            'phase_items' => $phaseItems,
        ];
    }

    private function duplicateName(string $name): string
    {
        return Str::startsWith($name, 'Copy of ')
            ? $name
            : 'Copy of '.$name;
    }
}
