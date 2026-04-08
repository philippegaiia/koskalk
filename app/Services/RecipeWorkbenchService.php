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
    public function phaseBlueprints(): array
    {
        return $this->recipeWorkbenchPhaseBlueprints->all();
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
        $normalizedPayload = $this->recipeWorkbenchPayloadNormalizer->normalize($payload);

        return $this->recipeDraftSaver->save($user, $productFamily, $normalizedPayload, $recipe);
    }

    public function saveRecipe(User $user, ProductFamily $productFamily, array $payload, ?Recipe $recipe = null): RecipeVersion
    {
        $normalizedPayload = $this->recipeWorkbenchPayloadNormalizer->normalize($payload);

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

        return $this->recipeWorkbenchPayloadNormalizer->normalize($currentDraftSavePayload) !==
            $this->recipeWorkbenchPayloadNormalizer->normalize($targetVersionSavePayload);
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
     * @return array<string, mixed>
     */
    public function deletePackagingCatalogItem(User $user, int $packagingItemId): array
    {
        return [
            'packaging_catalog' => $this->recipeVersionCostingSynchronizer->deletePackagingItem($user, $packagingItemId),
        ];
    }

    private function duplicateName(string $name): string
    {
        return Str::startsWith($name, 'Copy of ')
            ? $name
            : 'Copy of '.$name;
    }
}
