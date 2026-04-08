<?php

namespace App\Livewire\Dashboard;

use App\Models\ProductFamily;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Services\RecipeContentUpdater;
use App\Services\RecipeVersionDeletionService;
use App\Services\RecipeWorkbenchContentFormSchema;
use App\Services\RecipeWorkbenchContextResolver;
use App\Services\RecipeWorkbenchService;
use App\Services\RecipeWorkbenchViewDataBuilder;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Component;

class RecipeWorkbench extends Component implements HasActions, HasForms
{
    use AuthorizesRequests;
    use InteractsWithActions;
    use InteractsWithForms;

    #[Locked]
    public ?int $actorUserId = null;

    public ?int $recipeId = null;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public ?string $recipeContentMessage = null;

    public string $recipeContentStatus = 'idle';

    private bool $hasResolvedSoapFamily = false;

    private ?ProductFamily $resolvedSoapFamily = null;

    private bool $hasResolvedCurrentUser = false;

    private ?User $resolvedCurrentUser = null;

    private bool $hasResolvedCurrentRecipe = false;

    private ?Recipe $resolvedCurrentRecipe = null;

    public function mount(?Recipe $recipe = null): void
    {
        $this->actorUserId = $this->currentUser()?->id;
        $this->recipeId = $recipe?->id;
        $this->flushResolvedContext();
        $this->form->fill($this->recipeContentFormState($recipe));
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    public function saveDraft(array $draft, RecipeWorkbenchService $recipeWorkbenchService, RecipeContentUpdater $recipeContentUpdater): array
    {
        $user = $this->currentUser();

        if (! $user instanceof User) {
            return [
                'ok' => false,
                'message' => 'You need to be signed in before a formula can be saved.',
            ];
        }

        $wasUnsavedRecipe = ! ($this->currentRecipe() instanceof Recipe);

        try {
            $recipeVersion = $recipeWorkbenchService->saveDraft(
                $user,
                $this->soapFamily(),
                $draft,
                $this->currentRecipe(),
            );
        } catch (ValidationException|InvalidArgumentException $exception) {
            return $this->saveErrorResponse($exception);
        }

        $this->recipeId = $recipeVersion->recipe_id;
        $this->flushResolvedContext();
        $recipe = Recipe::withoutGlobalScopes()->find($recipeVersion->recipe_id);

        if ($wasUnsavedRecipe && $recipe instanceof Recipe && $this->hasPendingRecipeContent()) {
            $recipe = $this->persistRecipeContent($recipe, $recipeContentUpdater);
        }

        $snapshot = $recipeWorkbenchService->draftSnapshot($recipe);
        $this->refreshRecipeContentForm($recipe);

        return [
            'ok' => true,
            'message' => $wasUnsavedRecipe && $this->hasPendingRecipeContent()
                ? 'Draft saved. Content and media were kept too.'
                : 'Draft saved.',
            'redirect' => route('recipes.edit', $recipeVersion->recipe_id),
            'snapshot' => $snapshot,
        ];
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    public function saveRecipe(array $draft, RecipeWorkbenchService $recipeWorkbenchService, RecipeContentUpdater $recipeContentUpdater): array
    {
        $user = $this->currentUser();

        if (! $user instanceof User) {
            return [
                'ok' => false,
                'message' => 'You need to be signed in before a formula can be saved.',
            ];
        }

        $wasUnsavedRecipe = ! ($this->currentRecipe() instanceof Recipe);

        try {
            $recipeVersion = $recipeWorkbenchService->saveRecipe(
                $user,
                $this->soapFamily(),
                $draft,
                $this->currentRecipe(),
            );
        } catch (ValidationException|InvalidArgumentException $exception) {
            return $this->saveErrorResponse($exception);
        }

        $this->recipeId = $recipeVersion->recipe_id;
        $this->flushResolvedContext();
        $recipe = Recipe::withoutGlobalScopes()->find($recipeVersion->recipe_id);

        if ($wasUnsavedRecipe && $recipe instanceof Recipe && $this->hasPendingRecipeContent()) {
            $recipe = $this->persistRecipeContent($recipe, $recipeContentUpdater);
        }

        $snapshot = $recipeWorkbenchService->draftSnapshot($recipe);
        $this->refreshRecipeContentForm($recipe);

        return [
            'ok' => true,
            'message' => $wasUnsavedRecipe && $this->hasPendingRecipeContent()
                ? 'Recipe saved. The draft stays open, and the content and media were kept too.'
                : 'Recipe saved. The draft stays open for continued editing.',
            'redirect' => route('recipes.edit', $recipeVersion->recipe_id),
            'snapshot' => $snapshot,
        ];
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    public function saveAsNewVersion(array $draft, RecipeWorkbenchService $recipeWorkbenchService, RecipeContentUpdater $recipeContentUpdater): array
    {
        return $this->saveRecipe($draft, $recipeWorkbenchService, $recipeContentUpdater);
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    public function duplicateFormula(array $draft, RecipeWorkbenchService $recipeWorkbenchService): array
    {
        $user = $this->currentUser();

        if (! $user instanceof User) {
            return [
                'ok' => false,
                'message' => 'You need to be signed in before a formula can be duplicated.',
            ];
        }

        try {
            $recipeVersion = $recipeWorkbenchService->duplicate(
                $user,
                $this->soapFamily(),
                $draft,
            );
        } catch (ValidationException|InvalidArgumentException $exception) {
            return $this->saveErrorResponse($exception);
        }

        return [
            'ok' => true,
            'message' => 'Formula duplicated into a new draft.',
            'redirect' => route('recipes.edit', $recipeVersion->recipe_id),
        ];
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    #[Renderless]
    public function previewCalculation(array $draft, RecipeWorkbenchService $recipeWorkbenchService): array
    {
        $calculation = $recipeWorkbenchService->previewSoapCalculation($draft);

        return [
            'ok' => true,
            'calculation' => $calculation,
            'labeling' => $recipeWorkbenchService->previewInci($draft, $calculation),
        ];
    }

    /**
     * @param  array<string, mixed>  $costing
     * @return array<string, mixed>
     */
    #[Renderless]
    public function saveCosting(array $costing, RecipeWorkbenchService $recipeWorkbenchService): array
    {
        $user = $this->currentUser();
        $recipe = $this->currentRecipe();

        if (! $user instanceof User || ! $recipe instanceof Recipe) {
            return [
                'ok' => false,
                'message' => 'Save the first draft before keeping costing details.',
            ];
        }

        return [
            'ok' => true,
            'message' => 'Costing saved.',
            'costing' => $recipeWorkbenchService->saveCosting($user, $recipe, $costing),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[Renderless]
    public function loadCosting(RecipeWorkbenchService $recipeWorkbenchService): array
    {
        $user = $this->currentUser();
        $recipe = $this->currentRecipe();

        if (! $user instanceof User || ! $recipe instanceof Recipe) {
            return [
                'ok' => false,
                'message' => 'Save the first draft before pricing can be loaded.',
            ];
        }

        return [
            'ok' => true,
            'costing' => $recipeWorkbenchService->costingPayload($recipe, $user),
        ];
    }

    /**
     * @param  array<string, mixed>  $packagingItem
     * @return array<string, mixed>
     */
    #[Renderless]
    public function savePackagingCatalogItem(array $packagingItem, RecipeWorkbenchService $recipeWorkbenchService): array
    {
        $user = $this->currentUser();

        if (! $user instanceof User) {
            return [
                'ok' => false,
                'message' => 'Sign in before saving packaging items.',
            ];
        }

        return [
            'ok' => true,
            'message' => 'Packaging item saved.',
            ...$recipeWorkbenchService->savePackagingCatalogItem($user, $packagingItem),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[Renderless]
    public function deletePackagingCatalogItem(int $packagingItemId, RecipeWorkbenchService $recipeWorkbenchService): array
    {
        $user = $this->currentUser();

        if (! $user instanceof User) {
            return [
                'ok' => false,
                'message' => 'Sign in before deleting packaging items.',
            ];
        }

        return [
            'ok' => true,
            'message' => 'Packaging item deleted.',
            ...$recipeWorkbenchService->deletePackagingCatalogItem($user, $packagingItemId),
        ];
    }

    #[Renderless]
    public function comparisonVersion(int $versionId, RecipeWorkbenchService $recipeWorkbenchService): array
    {
        $recipe = $this->currentRecipe();

        if (! $recipe instanceof Recipe) {
            return [
                'ok' => false,
                'message' => 'No saved recipe is available for comparison.',
            ];
        }

        $snapshot = $recipeWorkbenchService->versionSnapshot($recipe, $versionId);

        if ($snapshot === null) {
            return [
                'ok' => false,
                'message' => 'The selected version could not be loaded.',
            ];
        }

        return [
            'ok' => true,
            'snapshot' => $snapshot,
        ];
    }

    #[Renderless]
    public function loadVersion(int $versionId, RecipeWorkbenchService $recipeWorkbenchService): array
    {
        $recipe = $this->currentRecipe();

        if (! $recipe instanceof Recipe) {
            return [
                'ok' => false,
                'message' => 'No saved recipe is available to load.',
            ];
        }

        $snapshot = $recipeWorkbenchService->versionSnapshot($recipe, $versionId);

        if ($snapshot === null) {
            return [
                'ok' => false,
                'message' => 'The selected version could not be loaded.',
            ];
        }

        return [
            'ok' => true,
            'message' => 'Saved version loaded into the workbench. Save when you want to keep changes.',
            'snapshot' => $snapshot,
        ];
    }

    public function saveRecipeContent(RecipeContentUpdater $recipeContentUpdater): void
    {
        $recipe = $this->currentRecipe();

        if (! $recipe instanceof Recipe) {
            $this->recipeContentStatus = 'error';
            $this->recipeContentMessage = 'Save the first draft before adding recipe content and images.';

            return;
        }

        $this->authorize('update', $recipe);
        $pendingRichContentState = $this->pendingRecipeRichContentState();

        /** @var array{description:?string, manufacturing_instructions:?string, featured_image_path:?string} $state */
        $this->setPendingRichContentStateOnRecipeTargets($recipe, $pendingRichContentState);

        try {
            $state = $this->form->getState();
        } finally {
            $this->clearPendingRichContentStateOnRecipeTargets($recipe);
        }

        $this->recipeContentStatus = 'success';
        $this->recipeContentMessage = 'Recipe content saved.';
        $this->refreshRecipeContentForm($recipeContentUpdater->update($recipe, $state));
    }

    public function deleteVersion(int $versionId, string $confirmName = ''): void
    {
        abort_unless($this->currentUser() instanceof User, 403);
        $recipe = $this->currentRecipe();

        abort_unless($recipe instanceof Recipe, 404);

        $version = RecipeVersion::withoutGlobalScopes()
            ->where('recipe_id', $this->recipeId)
            ->findOrFail($versionId);

        $this->authorize('delete', $version);

        if (! $version->is_draft) {
            if ($confirmName !== $version->name) {
                throw ValidationException::withMessages([
                    'confirmName' => 'Confirmation name does not match.',
                ]);
            }
        }

        $deletion = app(RecipeVersionDeletionService::class)->delete($recipe, $version);

        if ($deletion['deleted_draft']) {
            session()->flash('status', 'Draft deleted.');
            $this->redirect(route('recipes.index'), navigate: true);

            return;
        }

        $recipeWorkbenchService = app(RecipeWorkbenchService::class);
        $savedSnapshot = $recipeWorkbenchService->draftSnapshot($recipe);
        $versionOptions = $recipe instanceof Recipe
            ? $recipeWorkbenchService->versionOptions($recipe)
            : [];
        $status = $deletion['last_published_deleted']
            ? 'Last published version deleted. Recipe has no published versions.'
            : 'Version deleted.';

        session()->flash('status', $status);

        $this->dispatch(
            'version-deleted',
            message: $status,
            recipe: $savedSnapshot['draft']['recipe'] ?? null,
            versionName: $savedSnapshot['draft']['formulaName'] ?? null,
            versionOptions: $versionOptions,
        );
    }

    public function form(Schema $schema): Schema
    {
        return RecipeWorkbenchContentFormSchema::configure($schema)
            ->statePath('data')
            ->model($this->currentRecipe() ?? Recipe::class);
    }

    public function render(RecipeWorkbenchService $recipeWorkbenchService): View
    {
        $recipe = $this->currentRecipe();
        $recipeWorkbenchViewDataBuilder = app(RecipeWorkbenchViewDataBuilder::class);

        return view('livewire.dashboard.recipe-workbench', [
            'workbench' => $recipeWorkbenchViewDataBuilder->build(
                $this->soapFamily(),
                $recipe,
                $this->currentUser(),
            ),
        ]);
    }

    private function soapFamily(): ProductFamily
    {
        if (! $this->hasResolvedSoapFamily) {
            $this->resolvedSoapFamily = app(RecipeWorkbenchContextResolver::class)->soapFamily();
            $this->hasResolvedSoapFamily = true;
        }

        return $this->resolvedSoapFamily;
    }

    private function currentRecipe(): ?Recipe
    {
        if (! $this->hasResolvedCurrentRecipe) {
            $this->resolvedCurrentRecipe = app(RecipeWorkbenchContextResolver::class)
                ->currentRecipe($this->recipeId, $this->currentUser());
            $this->hasResolvedCurrentRecipe = true;
        }

        return $this->resolvedCurrentRecipe;
    }

    /**
     * @return array{description:?string, manufacturing_instructions:?string, featured_image_path:?string}
     */
    private function recipeContentFormState(?Recipe $recipe = null): array
    {
        $recipe ??= $this->currentRecipe();

        return [
            'description' => $recipe?->description,
            'manufacturing_instructions' => $recipe?->manufacturing_instructions,
            'featured_image_path' => $recipe?->featured_image_path,
        ];
    }

    private function refreshRecipeContentForm(?Recipe $recipe = null): void
    {
        $recipe ??= $this->currentRecipe();

        $this->form
            ->model($recipe ?? Recipe::class)
            ->fill($this->recipeContentFormState($recipe));
    }

    private function hasPendingRecipeContent(): bool
    {
        $description = $this->pendingRichContentValue('description');
        $manufacturingInstructions = $this->pendingRichContentValue('manufacturing_instructions');
        $featuredImagePath = $this->data['featured_image_path'] ?? null;

        if (filled($description) || filled($manufacturingInstructions)) {
            return true;
        }

        if (is_string($featuredImagePath)) {
            return filled($featuredImagePath);
        }

        return is_array($featuredImagePath) && $featuredImagePath !== [];
    }

    private function persistRecipeContent(Recipe $recipe, RecipeContentUpdater $recipeContentUpdater): Recipe
    {
        $state = $this->pendingRecipeContentState();
        $pendingRichContentState = [
            'description' => $state['description'],
            'manufacturing_instructions' => $state['manufacturing_instructions'],
        ];

        $this->setPendingRichContentStateOnRecipeTargets($recipe, $pendingRichContentState);

        try {
            return $recipeContentUpdater->update($recipe, $state);
        } finally {
            $this->clearPendingRichContentStateOnRecipeTargets($recipe);
        }
    }

    /**
     * @return array{description:?string, manufacturing_instructions:?string, featured_image_path:?string}
     */
    private function pendingRecipeContentState(): array
    {
        return [
            'description' => $this->pendingRichContentValue('description'),
            'manufacturing_instructions' => $this->pendingRichContentValue('manufacturing_instructions'),
            'featured_image_path' => $this->pendingFeaturedImagePath(),
        ];
    }

    /**
     * @return array{description:?string, manufacturing_instructions:?string}
     */
    private function pendingRecipeRichContentState(): array
    {
        return [
            'description' => $this->pendingRichContentValue('description'),
            'manufacturing_instructions' => $this->pendingRichContentValue('manufacturing_instructions'),
        ];
    }

    /**
     * @param  array{description:?string, manufacturing_instructions:?string}  $state
     */
    private function setPendingRichContentStateOnRecipeTargets(Recipe $recipe, array $state): void
    {
        $recipe->setPendingRichContentState($state);

        $formRecord = $this->form->getRecord();

        if ($formRecord instanceof Recipe && $formRecord !== $recipe) {
            $formRecord->setPendingRichContentState($state);
        }
    }

    private function clearPendingRichContentStateOnRecipeTargets(Recipe $recipe): void
    {
        $recipe->clearPendingRichContentState();

        $formRecord = $this->form->getRecord();

        if ($formRecord instanceof Recipe && $formRecord !== $recipe) {
            $formRecord->clearPendingRichContentState();
        }
    }

    private function pendingRichContentValue(string $key): ?string
    {
        $value = $this->data[$key] ?? null;

        return is_string($value) || $value === null ? $value : null;
    }

    private function pendingFeaturedImagePath(): ?string
    {
        $value = $this->data['featured_image_path'] ?? null;

        if (is_string($value) || $value === null) {
            return $value;
        }

        if (! is_array($value)) {
            return null;
        }

        $firstValue = collect($value)
            ->first(fn (mixed $path): bool => is_string($path) && $path !== '');

        return is_string($firstValue) ? $firstValue : null;
    }

    private function currentUser(): ?User
    {
        if (! $this->hasResolvedCurrentUser) {
            $this->resolvedCurrentUser = app(RecipeWorkbenchContextResolver::class)->currentUser($this->actorUserId);
            $this->hasResolvedCurrentUser = true;
        }

        return $this->resolvedCurrentUser;
    }

    private function flushResolvedContext(): void
    {
        $this->hasResolvedCurrentRecipe = false;
        $this->resolvedCurrentRecipe = null;
        $this->hasResolvedCurrentUser = false;
        $this->resolvedCurrentUser = null;
        $this->hasResolvedSoapFamily = false;
        $this->resolvedSoapFamily = null;
    }

    /**
     * @return array<string, mixed>
     */
    private function saveErrorResponse(ValidationException|InvalidArgumentException $exception): array
    {
        if ($exception instanceof ValidationException) {
            $message = collect($exception->errors())
                ->flatten()
                ->first() ?? $exception->getMessage();

            return [
                'ok' => false,
                'message' => $message,
                'errors' => $exception->errors(),
            ];
        }

        return [
            'ok' => false,
            'message' => $exception->getMessage(),
            'errors' => [
                'draft' => [$exception->getMessage()],
            ],
        ];
    }
}
