<?php

namespace App\Livewire\Dashboard;

use App\Livewire\Concerns\InteractsWithMediaAssetPickerUploads;
use App\MediaAssetUsageRole;
use App\Models\ProductFamily;
use App\Models\ProductType;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Services\MediaAssetUsageService;
use App\Services\RecipeContentPersistenceService;
use App\Services\RecipeContentUpdater;
use App\Services\RecipeSopSnapshotService;
use App\Services\RecipeVersionDeletionService;
use App\Services\RecipeWorkbenchContentFormSchema;
use App\Services\RecipeWorkbenchContextResolver;
use App\Services\RecipeWorkbenchService;
use App\Services\RecipeWorkbenchViewDataBuilder;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Component;
use Throwable;

class RecipeWorkbench extends Component implements HasActions, HasForms
{
    use AuthorizesRequests;
    use InteractsWithActions;
    use InteractsWithForms;
    use InteractsWithMediaAssetPickerUploads;
    use RestrictsFileUploadsToSchemaComponents;

    #[Locked]
    public ?int $recipeId = null;

    public string $productFamilySlug = 'soap';

    public ?string $productTypeSlug = null;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public ?string $recipeContentMessage = null;

    public string $recipeContentStatus = 'idle';

    private bool $hasResolvedProductFamily = false;

    private ?ProductFamily $resolvedProductFamily = null;

    private bool $hasResolvedProductType = false;

    private ?ProductType $resolvedProductType = null;

    private bool $hasResolvedCurrentRecipe = false;

    private ?Recipe $resolvedCurrentRecipe = null;

    /**
     * @var array<string, array{ok: bool, message?: string, calculation: array<string, mixed>|null, labeling: array<string, mixed>|null, restrictions: array<string, mixed>|null}>
     */
    private array $previewResponses = [];

    public function mount(?Recipe $recipe = null, string $productFamilySlug = 'soap', ?string $productTypeSlug = null): void
    {
        $this->recipeId = $recipe?->id;
        $this->productFamilySlug = $recipe?->productFamily?->slug ?? $productFamilySlug;
        $this->productTypeSlug = $recipe?->productType?->slug ?? $productTypeSlug;

        if ($recipe instanceof Recipe) {
            $recipe->loadMissing('mediaAssetUsages');
            $this->resolvedCurrentRecipe = $recipe;
            $this->hasResolvedCurrentRecipe = true;
            $this->resolvedProductFamily = $recipe->productFamily;
            $this->hasResolvedProductFamily = true;
            $this->resolvedProductType = $recipe->productType;
            $this->hasResolvedProductType = true;
        } else {
            $this->flushResolvedContext();
        }

        $this->form->fill($this->recipeContentFormState($recipe));
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    public function save(array $draft, RecipeWorkbenchService $recipeWorkbenchService, RecipeContentUpdater $recipeContentUpdater): array
    {
        $user = $this->currentUser();

        if (! $user instanceof User) {
            return [
                'ok' => false,
                'message' => 'You need to be signed in before a formula can be saved.',
            ];
        }

        $this->authorizeRecipeMutationOrCreation();

        if ($this->currentRecipe()?->isLocked()) {
            return [
                'ok' => false,
                'message' => 'Unlock this formula before editing it.',
            ];
        }

        $wasUnsavedRecipe = ! ($this->currentRecipe() instanceof Recipe);
        $preparePayloadForRecipe = $wasUnsavedRecipe
            ? fn (Recipe $recipe, array $payload): array => $this->prepareNewRecipePayload(
                $recipe,
                $payload,
                $recipeContentUpdater,
            )
            : null;

        try {
            $recipeVersion = $recipeWorkbenchService->save(
                $user,
                $this->productFamily(),
                $this->draftWithWorkbenchContext($draft, $recipeContentUpdater),
                $this->currentRecipe(),
                preparePayloadForRecipe: $preparePayloadForRecipe,
            );
        } catch (ValidationException|InvalidArgumentException $exception) {
            return $this->saveErrorResponse($exception);
        }

        $this->recipeId = $recipeVersion->recipe_id;
        $this->flushResolvedContext();
        $recipe = Recipe::withoutGlobalScopes()->find($recipeVersion->recipe_id);

        $snapshot = $recipeWorkbenchService->currentVersionSnapshot($recipe);
        $this->refreshRecipeContentForm($recipe);

        return [
            'ok' => true,
            'message' => $wasUnsavedRecipe && $this->hasPendingRecipeContent()
                ? 'Formula saved. Content and media were kept too.'
                : 'Formula saved.',
            'redirect' => route('recipes.edit', Recipe::withoutGlobalScopes()->findOrFail($recipeVersion->recipe_id)),
            'snapshot' => $snapshot,
        ];
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    public function publish(array $draft, RecipeWorkbenchService $recipeWorkbenchService, RecipeContentUpdater $recipeContentUpdater): array
    {
        $user = $this->currentUser();

        if (! $user instanceof User) {
            return [
                'ok' => false,
                'message' => 'You need to be signed in before a formula can be saved.',
            ];
        }

        $this->authorizeRecipeMutationOrCreation();

        if ($this->currentRecipe()?->isLocked()) {
            return [
                'ok' => false,
                'message' => 'Unlock this formula before editing it.',
            ];
        }

        $wasUnsavedRecipe = ! ($this->currentRecipe() instanceof Recipe);
        $preparePayloadForRecipe = $wasUnsavedRecipe
            ? fn (Recipe $recipe, array $payload): array => $this->prepareNewRecipePayload(
                $recipe,
                $payload,
                $recipeContentUpdater,
            )
            : null;

        try {
            $recipeVersion = $recipeWorkbenchService->publish(
                $user,
                $this->productFamily(),
                $this->draftWithWorkbenchContext($draft, $recipeContentUpdater),
                $this->currentRecipe(),
                $preparePayloadForRecipe,
            );
        } catch (ValidationException|InvalidArgumentException $exception) {
            return $this->saveErrorResponse($exception);
        }

        $this->recipeId = $recipeVersion->recipe_id;
        $this->flushResolvedContext();
        $recipe = Recipe::withoutGlobalScopes()->find($recipeVersion->recipe_id);

        $snapshot = $recipeWorkbenchService->currentVersionSnapshot($recipe);
        $this->refreshRecipeContentForm($recipe);

        return [
            'ok' => true,
            'message' => $wasUnsavedRecipe && $this->hasPendingRecipeContent()
                ? 'Formula saved. Content and media were kept too.'
                : 'Formula saved.',
            'redirect' => route('recipes.edit', Recipe::withoutGlobalScopes()->findOrFail($recipeVersion->recipe_id)),
            'snapshot' => $snapshot,
        ];
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    public function saveAsNewVersion(array $draft, RecipeWorkbenchService $recipeWorkbenchService, RecipeContentUpdater $recipeContentUpdater): array
    {
        return $this->publish($draft, $recipeWorkbenchService, $recipeContentUpdater);
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    public function duplicateFormula(
        array $draft,
        RecipeWorkbenchService $recipeWorkbenchService,
        ?RecipeContentUpdater $recipeContentUpdater = null,
    ): array {
        $user = $this->currentUser();

        if (! $user instanceof User) {
            return [
                'ok' => false,
                'message' => 'You need to be signed in before a formula can be duplicated.',
            ];
        }

        $recipe = $this->currentRecipe();
        $recipeContentUpdater ??= app(RecipeContentUpdater::class);
        $hasPendingAttachments = $this->hasPendingManufacturingAttachments();
        $preparePayloadForRecipe = (! $recipe instanceof Recipe || $hasPendingAttachments)
            ? fn (Recipe $destinationRecipe, array $payload): array => $this->prepareNewRecipePayload(
                $destinationRecipe,
                $payload,
                $recipeContentUpdater,
                $recipe,
            )
            : null;

        if ($recipe instanceof Recipe) {
            $this->authorize('view', $recipe);
        }

        $this->authorize('create', Recipe::class);

        try {
            $recipeVersion = $recipeWorkbenchService->duplicate(
                $user,
                $this->productFamily(),
                $preparePayloadForRecipe !== null
                    ? $this->draftWithPendingWorkbenchContext($draft)
                    : $this->draftWithWorkbenchContext($draft, $recipeContentUpdater),
                $recipe,
                $preparePayloadForRecipe,
            );
        } catch (ValidationException|InvalidArgumentException $exception) {
            return $this->saveErrorResponse($exception);
        }

        return [
            'ok' => true,
            'message' => 'Formula duplicated.',
            'redirect' => route('recipes.edit', Recipe::withoutGlobalScopes()->findOrFail($recipeVersion->recipe_id)),
        ];
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    #[Renderless]
    public function previewCalculation(array $draft, RecipeWorkbenchService $recipeWorkbenchService): array
    {
        return $this->previewResponse($draft, $recipeWorkbenchService);
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    #[Renderless]
    public function previewLabeling(array $draft, RecipeWorkbenchService $recipeWorkbenchService): array
    {
        $response = $this->previewResponse($draft, $recipeWorkbenchService);

        if (! $response['ok']) {
            return [
                'ok' => false,
                'message' => $response['message'],
                'labeling' => null,
                'restrictions' => null,
            ];
        }

        return [
            'ok' => true,
            'labeling' => $response['labeling'],
            'restrictions' => $response['restrictions'],
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
                'message' => __('workbench.costing.messages.save_product'),
            ];
        }

        $this->authorize('update', $recipe);

        return [
            'ok' => true,
            'message' => __('workbench.costing.messages.saved'),
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
                'message' => __('workbench.costing.messages.load_product'),
            ];
        }

        $this->authorize('view', $recipe);

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
                'message' => __('workbench.packaging.messages.sign_in'),
            ];
        }

        return [
            'ok' => true,
            'message' => __('workbench.packaging.messages.saved'),
            ...$recipeWorkbenchService->savePackagingCatalogItem($user, $packagingItem),
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

        $this->authorize('view', $recipe);

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

        $this->authorize('view', $recipe);

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

    /**
     * @return array{ok: bool, message: string, saved_at?: string}
     */
    public function saveRecipeContent(RecipeContentPersistenceService $recipeContentPersistenceService): array
    {
        $recipe = $this->currentRecipe();

        if (! $recipe instanceof Recipe) {
            $this->recipeContentStatus = 'error';
            $this->recipeContentMessage = __('workbench.instructions.draft_text_help');

            return [
                'ok' => false,
                'message' => $this->recipeContentMessage,
            ];
        }

        $this->authorize('update', $recipe);
        try {
            $pendingRichContentState = $this->pendingRecipeRichContentState();

            $this->setPendingRichContentStateOnRecipeTargets($recipe, $pendingRichContentState);

            try {
                $state = $this->form->getState();
                $featuredMediaAssetId = $state['featured_media_asset_id'] ?? null;
                unset($state['featured_media_asset_id']);
            } finally {
                $this->clearPendingRichContentStateOnRecipeTargets($recipe);
            }

            $user = $this->currentUser();

            abort_unless($user instanceof User, 403);

            $updatedRecipe = $recipeContentPersistenceService->update(
                $user,
                $recipe,
                $state,
                $featuredMediaAssetId,
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            $this->recipeContentStatus = 'error';
            $this->recipeContentMessage = __('workbench.instructions.save_failed');

            return [
                'ok' => false,
                'message' => $this->recipeContentMessage,
            ];
        }

        $this->recipeContentStatus = 'success';
        $this->recipeContentMessage = __('workbench.instructions.all_saved');
        $this->refreshRecipeContentForm($updatedRecipe);

        return [
            'ok' => true,
            'message' => $this->recipeContentMessage,
            'saved_at' => now()->toISOString(),
        ];
    }

    public function deleteVersion(int $versionId, string $confirmName = ''): void
    {
        abort_unless($this->currentUser() instanceof User, 403);
        $recipe = $this->currentRecipe();

        abort_unless($recipe instanceof Recipe, 404);

        $version = RecipeVersion::withoutGlobalScopes()
            ->where('recipe_id', $this->recipeId)
            ->findOrFail($versionId);

        $this->authorize('delete', $recipe);
        $this->authorize('delete', $version);

        if (! $version->is_current) {
            if ($confirmName !== $version->name) {
                throw ValidationException::withMessages([
                    'confirmName' => 'Confirmation name does not match.',
                ]);
            }
        }

        $deletion = app(RecipeVersionDeletionService::class)->delete($recipe, $version);

        if ($deletion['deleted_current']) {
            session()->flash('status', 'Draft deleted.');
            $this->redirect(route('recipes.index'), navigate: true);

            return;
        }

        $recipeWorkbenchService = app(RecipeWorkbenchService::class);
        $savedSnapshot = $recipeWorkbenchService->currentVersionSnapshot($recipe);
        $versionOptions = $recipe instanceof Recipe
            ? $recipeWorkbenchService->publishedVersionHistory($recipe)
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
                $this->productFamily(),
                $recipe,
                $this->currentUser(),
                $this->productType(),
            ),
        ]);
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array{ok: bool, message?: string, calculation: array<string, mixed>|null, labeling: array<string, mixed>|null, restrictions: array<string, mixed>|null}
     */
    private function previewResponse(array $draft, RecipeWorkbenchService $recipeWorkbenchService): array
    {
        $draftHash = hash('sha256', serialize($draft));

        if (array_key_exists($draftHash, $this->previewResponses)) {
            return $this->previewResponses[$draftHash];
        }

        try {
            $calculation = $recipeWorkbenchService->previewSoapCalculation($draft);

            return $this->previewResponses[$draftHash] = [
                'ok' => true,
                'calculation' => $calculation,
                'labeling' => $recipeWorkbenchService->previewInci($draft, $calculation),
                'restrictions' => $recipeWorkbenchService->previewRestrictions($draft, $calculation),
            ];
        } catch (InvalidArgumentException $exception) {
            return $this->previewResponses[$draftHash] = [
                'ok' => false,
                'message' => $exception->getMessage(),
                'calculation' => null,
                'labeling' => null,
                'restrictions' => null,
            ];
        }
    }

    private function productFamily(): ProductFamily
    {
        if (! $this->hasResolvedProductFamily) {
            $recipe = $this->currentRecipe();

            $this->resolvedProductFamily = $recipe?->productFamily
                ?? app(RecipeWorkbenchContextResolver::class)->productFamily($this->productFamilySlug);
            $this->hasResolvedProductFamily = true;
        }

        return $this->resolvedProductFamily;
    }

    private function productType(): ?ProductType
    {
        if (! $this->hasResolvedProductType) {
            $recipe = $this->currentRecipe();

            $this->resolvedProductType = $recipe?->productType
                ?? app(RecipeWorkbenchContextResolver::class)->productType($this->productFamily(), $this->productTypeSlug);
            $this->hasResolvedProductType = true;
        }

        return $this->resolvedProductType;
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    private function draftWithWorkbenchContext(array $draft, RecipeContentUpdater $recipeContentUpdater): array
    {
        $draft['manufacturing_instructions'] = $this->dehydratePendingManufacturingInstructions($recipeContentUpdater);
        $productType = $this->productType();

        if ($productType instanceof ProductType) {
            $draft['product_type_id'] = $productType->id;
        }

        return $draft;
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    private function draftWithPendingWorkbenchContext(array $draft): array
    {
        $this->validateOnly('data.manufacturing_instructions');
        $draft['manufacturing_instructions'] = $this->pendingRichContentValue('manufacturing_instructions');
        $productType = $this->productType();

        if ($productType instanceof ProductType) {
            $draft['product_type_id'] = $productType->id;
        }

        return $draft;
    }

    private function hasPendingManufacturingAttachments(): bool
    {
        $attachments = data_get(
            $this->componentFileAttachments,
            'data.manufacturing_instructions',
            [],
        );

        return is_array($attachments) && $attachments !== [];
    }

    private function dehydratePendingManufacturingInstructions(RecipeContentUpdater $recipeContentUpdater): ?string
    {
        $recipe = $this->currentRecipe();

        if (! $recipe instanceof Recipe) {
            $this->validateOnly('data.manufacturing_instructions');

            return $this->pendingRichContentValue('manufacturing_instructions');
        }

        $richEditor = $this->form->getComponent('manufacturing_instructions');

        if (! $richEditor instanceof RichEditor) {
            return null;
        }

        $this->validateOnly('data.manufacturing_instructions');
        $pendingRichContentState = $this->pendingRecipeRichContentState();
        $this->setPendingRichContentStateOnRecipeTargets($recipe, $pendingRichContentState);

        try {
            $richEditor->saveFileAttachments();
            $manufacturingInstructions = $this->pendingRichContentValue('manufacturing_instructions');
            $recipeContentUpdater->validate($recipe, [
                'description' => $recipe->description,
                'manufacturing_instructions' => $manufacturingInstructions,
                'featured_image_path' => $recipe->featured_image_path,
                'featured_image_original_name' => $recipe->featured_image_original_name,
            ]);

            return $manufacturingInstructions;
        } finally {
            $this->clearPendingRichContentStateOnRecipeTargets($recipe);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function prepareNewRecipePayload(
        Recipe $recipe,
        array $payload,
        RecipeContentUpdater $recipeContentUpdater,
        ?Recipe $sopSourceRecipe = null,
    ): array {
        $previousRecord = $this->form->getRecord();
        $this->form->model($recipe);
        $pendingRichContentState = $this->pendingRecipeRichContentState();
        $this->setPendingRichContentStateOnRecipeTargets($recipe, $pendingRichContentState);

        try {
            $this->form->getState(afterValidate: function (array $state) use (
                &$payload,
                $recipe,
                $recipeContentUpdater,
                $sopSourceRecipe,
            ): void {
                $user = $this->currentUser();

                abort_unless($user instanceof User, 403);

                $featuredMediaAssetId = $state['featured_media_asset_id'] ?? null;

                if ($sopSourceRecipe instanceof Recipe && ! $sopSourceRecipe->is($recipe)) {
                    $state['manufacturing_instructions'] = app(RecipeSopSnapshotService::class)
                        ->duplicateInstructions(
                            $sopSourceRecipe,
                            $recipe,
                            $state['manufacturing_instructions'] ?? null,
                            preserveDestinationPaths: true,
                            rejectInvalidPaths: true,
                        );
                }

                $recipeContentUpdater->update($recipe, $state);
                $mediaAssetUsages = app(MediaAssetUsageService::class);
                $mediaAssetUsages->syncSingle(
                    $user,
                    $recipe,
                    MediaAssetUsageRole::RecipeFeatured,
                    $featuredMediaAssetId,
                );
                $payload['manufacturing_instructions'] = $state['manufacturing_instructions'] ?? null;
            });

            return $payload;
        } finally {
            $this->clearPendingRichContentStateOnRecipeTargets($recipe);
            $this->form->model($previousRecord instanceof Recipe ? $previousRecord : Recipe::class);
        }
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

    private function authorizeRecipeMutationOrCreation(): void
    {
        $recipe = $this->currentRecipe();

        if ($this->recipeId !== null && ! $recipe instanceof Recipe) {
            abort(404);
        }

        if ($recipe instanceof Recipe) {
            $this->authorize('update', $recipe);

            return;
        }

        $this->authorize('create', Recipe::class);
    }

    private function recipeContentFormState(?Recipe $recipe = null): array
    {
        $recipe ??= $this->currentRecipe();

        return [
            'description' => $recipe?->description,
            'manufacturing_instructions' => $recipe?->manufacturing_instructions,
            'featured_media_asset_id' => $recipe instanceof Recipe
                ? ($this->mediaAssetUsageIds($recipe, MediaAssetUsageRole::RecipeFeatured)[0] ?? null)
                : null,
        ];
    }

    /**
     * @return array<int, int>
     */
    private function mediaAssetUsageIds(Recipe $recipe, MediaAssetUsageRole $role): array
    {
        if ($recipe->relationLoaded('mediaAssetUsages')) {
            return $recipe->mediaAssetUsages
                ->where('role', $role)
                ->sortBy('id')
                ->pluck('media_asset_id')
                ->all();
        }

        return app(MediaAssetUsageService::class)->idsFor($recipe, $role);
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
        $featuredImagePath = $this->data['featured_media_asset_id'] ?? null;

        return filled($description)
            || filled($manufacturingInstructions)
            || filled($featuredImagePath);
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

        if (is_string($value) || $value === null) {
            return $value;
        }

        if (! is_array($value)) {
            return null;
        }

        $richEditor = $this->form->getComponent($key);

        if (! $richEditor instanceof RichEditor) {
            return null;
        }

        $editor = $richEditor->getTipTapEditor()->setContent($value);

        if ($richEditor->getFileAttachmentsVisibility() === 'private') {
            $editor->descendants(function (object &$node): void {
                if ($node->type === 'image' && filled($node->attrs->id ?? null)) {
                    $node->attrs->src = null;
                }
            });
        }

        return $editor->getHTML();
    }

    private function pendingFeaturedImageOriginalName(): ?string
    {
        return $this->pendingRichContentValue('featured_image_original_name');
    }

    private function currentUser(): ?User
    {
        return app(RecipeWorkbenchContextResolver::class)->currentUser();
    }

    private function flushResolvedContext(): void
    {
        $this->hasResolvedCurrentRecipe = false;
        $this->resolvedCurrentRecipe = null;
        $this->hasResolvedProductFamily = false;
        $this->resolvedProductFamily = null;
        $this->hasResolvedProductType = false;
        $this->resolvedProductType = null;
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
