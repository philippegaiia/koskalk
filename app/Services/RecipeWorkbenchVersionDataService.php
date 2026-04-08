<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\RecipePhase;
use App\Models\RecipeVersion;
use Illuminate\Support\Carbon;

class RecipeWorkbenchVersionDataService
{
    public function __construct(
        private readonly RecipeWorkbenchVersionPayloadMapper $recipeWorkbenchVersionPayloadMapper,
        private readonly RecipeWorkbenchPreviewService $recipeWorkbenchPreviewService,
        private readonly RecipeWorkbenchPhaseBlueprints $recipeWorkbenchPhaseBlueprints,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function draftPayload(?Recipe $recipe): ?array
    {
        $version = $this->draftOrLatestVersion($recipe);

        return $version instanceof RecipeVersion
            ? $this->payloadForVersion($version)
            : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function currentDraftPayload(?Recipe $recipe): ?array
    {
        $version = $this->currentDraftVersion($recipe);

        return $version instanceof RecipeVersion
            ? $this->payloadForVersion($version)
            : null;
    }

    /**
     * @return array{draft: array<string, mixed>, calculation: array<string, mixed>|null, labeling: array<string, mixed>}|null
     */
    public function draftSnapshot(?Recipe $recipe): ?array
    {
        $version = $this->draftOrLatestVersion($recipe);

        return $version instanceof RecipeVersion
            ? $this->snapshotForVersion($version)
            : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function currentSavedPayload(?Recipe $recipe): ?array
    {
        $version = $this->latestSavedVersion($recipe);

        return $version instanceof RecipeVersion
            ? $this->payloadForVersion($version)
            : null;
    }

    /**
     * @return array{draft: array<string, mixed>, calculation: array<string, mixed>|null, labeling: array<string, mixed>}|null
     */
    public function currentSavedSnapshot(?Recipe $recipe): ?array
    {
        $version = $this->latestSavedVersion($recipe);

        return $version instanceof RecipeVersion
            ? $this->snapshotForVersion($version)
            : null;
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
     * @return array<int, array<string, mixed>>
     */
    public function savedFormulaHistory(Recipe $recipe): array
    {
        return $this->versionOptions($recipe);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function versionPayload(?Recipe $recipe, int $versionId): ?array
    {
        $version = $this->versionForRecipe($recipe, $versionId);

        return $version instanceof RecipeVersion
            ? $this->payloadForVersion($version)
            : null;
    }

    /**
     * @return array{draft: array<string, mixed>, calculation: array<string, mixed>|null, labeling: array<string, mixed>}|null
     */
    public function versionSnapshot(?Recipe $recipe, int $versionId): ?array
    {
        $version = $this->versionForRecipe($recipe, $versionId);

        return $version instanceof RecipeVersion
            ? $this->snapshotForVersion($version)
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function publishedVersionPayload(Recipe $recipe, int $versionId): array
    {
        $version = RecipeVersion::withoutGlobalScopes()
            ->where('recipe_id', $recipe->id)
            ->where('is_draft', false)
            ->whereKey($versionId)
            ->with($this->versionWorkbenchRelations())
            ->firstOrFail();

        return $this->payloadForVersion($version);
    }

    private function draftOrLatestVersion(?Recipe $recipe): ?RecipeVersion
    {
        if (! $recipe instanceof Recipe) {
            return null;
        }

        return $this->currentDraftVersion($recipe)
            ?? $this->latestSavedVersion($recipe);
    }

    private function currentDraftVersion(?Recipe $recipe): ?RecipeVersion
    {
        if (! $recipe instanceof Recipe) {
            return null;
        }

        return RecipeVersion::withoutGlobalScopes()
            ->where('recipe_id', $recipe->id)
            ->where('is_draft', true)
            ->with($this->versionWorkbenchRelations())
            ->first();
    }

    private function latestSavedVersion(?Recipe $recipe): ?RecipeVersion
    {
        if (! $recipe instanceof Recipe) {
            return null;
        }

        return RecipeVersion::withoutGlobalScopes()
            ->where('recipe_id', $recipe->id)
            ->where('is_draft', false)
            ->with($this->versionWorkbenchRelations())
            ->orderByDesc('version_number')
            ->first();
    }

    private function versionForRecipe(?Recipe $recipe, int $versionId): ?RecipeVersion
    {
        if (! $recipe instanceof Recipe) {
            return null;
        }

        return RecipeVersion::withoutGlobalScopes()
            ->where('recipe_id', $recipe->id)
            ->whereKey($versionId)
            ->with($this->versionWorkbenchRelations())
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadForVersion(RecipeVersion $version): array
    {
        return $this->recipeWorkbenchVersionPayloadMapper->toWorkbenchPayload(
            $version,
            $this->recipeWorkbenchPhaseBlueprints->all(),
            $this->catalogReviewState($version),
        );
    }

    /**
     * @return array{draft: array<string, mixed>, calculation: array<string, mixed>|null, labeling: array<string, mixed>}
     */
    private function snapshotForVersion(RecipeVersion $version): array
    {
        return $this->recipeWorkbenchPreviewService->snapshotFromWorkbenchDraft(
            $this->payloadForVersion($version),
        );
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
            'phases.items.ingredient.allergenEntries.allergen',
            'phases.items.ingredient.ifraCertificates.limits',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogReviewState(RecipeVersion $version): array
    {
        $reviewedAt = $version->catalog_reviewed_at;
        $latestIngredientChangeAt = $version->phases
            ->flatMap(fn (RecipePhase $phase) => $phase->items)
            ->map(fn (RecipeItem $item): ?Carbon => $this->latestIngredientChangeAt($item))
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
