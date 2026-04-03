<?php

namespace App\Livewire\Dashboard;

use App\IngredientCategory;
use App\Models\IfraProductCategory;
use App\Models\Ingredient;
use App\Models\ProductFamily;
use App\Models\ProductFamilyIfraCategory;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Services\CurrentAppUserResolver;
use App\Services\MediaStorage;
use App\Services\RecipeWorkbenchService;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\BaseFileUpload;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

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

    public function mount(?Recipe $recipe = null): void
    {
        $this->actorUserId = $this->currentUser()?->id;
        $this->recipeId = $recipe?->id;
        $this->form->fill($this->recipeContentFormState($recipe));
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    public function saveDraft(array $draft, RecipeWorkbenchService $recipeWorkbenchService): array
    {
        $user = $this->currentUser();

        if (! $user instanceof User) {
            return [
                'ok' => false,
                'message' => 'You need to be signed in before a formula can be saved.',
            ];
        }

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
        $recipe = Recipe::withoutGlobalScopes()->find($recipeVersion->recipe_id);
        $snapshot = $recipeWorkbenchService->draftSnapshot($recipe);
        $this->refreshRecipeContentForm($recipe);

        return [
            'ok' => true,
            'message' => 'Draft saved.',
            'redirect' => route('recipes.edit', $recipeVersion->recipe_id),
            'snapshot' => $snapshot,
        ];
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    public function saveAsNewVersion(array $draft, RecipeWorkbenchService $recipeWorkbenchService): array
    {
        $user = $this->currentUser();

        if (! $user instanceof User) {
            return [
                'ok' => false,
                'message' => 'You need to be signed in before a formula can be versioned.',
            ];
        }

        try {
            $recipeVersion = $recipeWorkbenchService->saveAsNewVersion(
                $user,
                $this->soapFamily(),
                $draft,
                $this->currentRecipe(),
            );
        } catch (ValidationException|InvalidArgumentException $exception) {
            return $this->saveErrorResponse($exception);
        }

        $this->recipeId = $recipeVersion->recipe_id;
        $recipe = Recipe::withoutGlobalScopes()->find($recipeVersion->recipe_id);
        $snapshot = $recipeWorkbenchService->draftSnapshot($recipe);
        $this->refreshRecipeContentForm($recipe);

        return [
            'ok' => true,
            'message' => 'Version saved. A new draft is open for continued editing.',
            'redirect' => route('recipes.edit', $recipeVersion->recipe_id),
            'snapshot' => $snapshot,
        ];
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
    public function previewCalculation(array $draft, RecipeWorkbenchService $recipeWorkbenchService): array
    {
        $calculation = $recipeWorkbenchService->previewSoapCalculation($draft);

        return [
            'ok' => true,
            'calculation' => $calculation,
            'labeling' => $recipeWorkbenchService->previewInci($draft, $calculation),
        ];
    }

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

    public function saveRecipeContent(): void
    {
        $recipe = $this->currentRecipe();

        if (! $recipe instanceof Recipe) {
            $this->recipeContentStatus = 'error';
            $this->recipeContentMessage = 'Save the first draft before adding recipe content and images.';

            return;
        }

        $this->authorize('update', $recipe);
        $previousFeaturedImagePath = $recipe->featured_image_path;
        $previousRichContentAttachmentPaths = $recipe->richContentAttachmentPaths();
        $pendingRichContentState = $this->pendingRecipeRichContentState();

        /** @var array{description:?string, manufacturing_instructions:?string, featured_image_path:?string} $state */
        $this->setPendingRichContentStateOnRecipeTargets($recipe, $pendingRichContentState);

        try {
            $state = $this->form->getState();
        } finally {
            $this->clearPendingRichContentStateOnRecipeTargets($recipe);
        }

        $recipe->fill([
            'description' => $state['description'] ?? null,
            'manufacturing_instructions' => $state['manufacturing_instructions'] ?? null,
            'featured_image_path' => $state['featured_image_path'] ?? null,
        ]);
        $recipe->save();

        if ($previousFeaturedImagePath !== $recipe->featured_image_path) {
            MediaStorage::deletePublicPath($previousFeaturedImagePath);
        }

        $previousRichContentAttachmentPaths
            ->diff($recipe->richContentAttachmentPaths())
            ->each(function (string $path): void {
                MediaStorage::deletePublicPath($path);
            });

        $this->recipeContentStatus = 'success';
        $this->recipeContentMessage = 'Recipe content saved.';
        $this->refreshRecipeContentForm($recipe->fresh());
    }

    public function deleteVersion(int $versionId, string $confirmName = ''): void
    {
        abort_unless($this->currentUser() instanceof User, 403);

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

        $isDraft = $version->is_draft;

        $version->delete();

        if ($isDraft) {
            session()->flash('status', 'Draft deleted.');
            $this->redirect(route('recipes.index'), navigate: true);

            return;
        }

        $recipe = $this->currentRecipe();
        $recipeWorkbenchService = app(RecipeWorkbenchService::class);
        $savedSnapshot = $recipeWorkbenchService->draftSnapshot($recipe);
        $versionOptions = $recipe instanceof Recipe
            ? $recipeWorkbenchService->versionOptions($recipe)
            : [];
        $status = empty($versionOptions)
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
        return $schema
            ->components([
                Section::make('Recipe content')
                    ->description('Keep presentation copy and manufacturing steps separate, with the product image stored alongside them.')
                    ->columns([
                        'lg' => 12,
                    ])
                    ->schema([
                        RichEditor::make('description')
                            ->label('Presentation')
                            ->helperText('Use this for product story, benefits, positioning, and publication-ready notes.')
                            ->toolbarButtons([
                                ['bold', 'italic', 'underline', 'strike', 'link'],
                                ['h2', 'h3', 'blockquote', 'bulletList', 'orderedList'],
                                ['attachFiles', 'undo', 'redo'],
                            ])
                            ->fileAttachmentsDisk(MediaStorage::publicDisk())
                            ->fileAttachmentsDirectory('recipes/rich-content')
                            ->fileAttachmentsVisibility(MediaStorage::publicVisibility())
                            ->fileAttachmentsAcceptedFileTypes([
                                'image/jpeg',
                                'image/webp',
                            ])
                            ->fileAttachmentsMaxSize(MediaStorage::recipeRichContentImagesMaxSize())
                            ->resizableImages()
                            ->columnSpan([
                                'lg' => 6,
                            ]),
                        RichEditor::make('manufacturing_instructions')
                            ->label('Manufacturing instructions')
                            ->helperText('Use this for process steps, timing, cautions, and print-ready production instructions.')
                            ->toolbarButtons([
                                ['bold', 'italic', 'underline', 'strike', 'link'],
                                ['h2', 'h3', 'blockquote', 'bulletList', 'orderedList'],
                                ['attachFiles', 'undo', 'redo'],
                            ])
                            ->fileAttachmentsDisk(MediaStorage::publicDisk())
                            ->fileAttachmentsDirectory('recipes/rich-content')
                            ->fileAttachmentsVisibility(MediaStorage::publicVisibility())
                            ->fileAttachmentsAcceptedFileTypes([
                                'image/jpeg',
                                'image/webp',
                            ])
                            ->fileAttachmentsMaxSize(MediaStorage::recipeRichContentImagesMaxSize())
                            ->resizableImages()
                            ->columnSpan([
                                'lg' => 6,
                            ]),
                        FileUpload::make('featured_image_path')
                            ->label('Finished product image')
                            ->image()
                            ->disk(MediaStorage::publicDisk())
                            ->directory('recipes/featured-images')
                            ->visibility(MediaStorage::publicVisibility())
                            ->deleteUploadedFileUsing(function (string $file): void {
                                MediaStorage::deletePublicPath($file);
                            })
                            ->imagePreviewHeight('20rem')
                            ->panelLayout('integrated')
                            ->panelAspectRatio('4:3')
                            ->acceptedFileTypes([
                                'image/jpeg',
                                'image/webp',
                            ])
                            ->maxSize(MediaStorage::recipeFeaturedImagesMaxSize())
                            ->saveUploadedFileUsing(fn (BaseFileUpload $component, TemporaryUploadedFile $file): string => MediaStorage::storeResizedWebp(
                                $file,
                                (string) $component->getDirectory(),
                                (int) config('media.recipe_featured_images.max_width', 1200),
                                (int) config('media.recipe_featured_images.max_height', 900),
                                MediaStorage::recipeFeaturedImagesQuality(),
                            ))
                            ->imageEditor()
                            ->imageAspectRatio('4:3')
                            ->imageEditorAspectRatioOptions(['4:3'])
                            ->imageEditorViewportWidth('1200')
                            ->imageEditorViewportHeight('900')
                            ->automaticallyOpenImageEditorForAspectRatio()
                            ->helperText('Allowed: JPG or WebP, up to 1 MB. Recipe images are cropped to 4:3 and stored up to 1200x900.')
                            ->columnSpan([
                                'lg' => 12,
                            ]),
                    ]),
            ])
            ->statePath('data')
            ->model($this->currentRecipe() ?? Recipe::class);
    }

    public function render(RecipeWorkbenchService $recipeWorkbenchService): View
    {
        $soapFamily = $this->soapFamily();
        $savedSnapshot = $recipeWorkbenchService->draftSnapshot($this->currentRecipe());

        return view('livewire.dashboard.recipe-workbench', [
            'workbench' => [
                'productFamily' => [
                    'id' => $soapFamily->id,
                    'name' => $soapFamily->name,
                    'slug' => $soapFamily->slug,
                    'calculation_basis' => $soapFamily->calculation_basis,
                ],
                'recipe' => $this->currentRecipeData(),
                'savedSnapshot' => $savedSnapshot,
                'versionOptions' => $this->currentRecipe() instanceof Recipe
                    ? $recipeWorkbenchService->versionOptions($this->currentRecipe())
                    : [],
                'versionViewRouteTemplate' => $this->currentRecipe() instanceof Recipe
                    ? route('recipes.version', [
                        'recipe' => $this->currentRecipe()->id,
                        'version' => '__VERSION__',
                    ])
                    : null,
                'phases' => $recipeWorkbenchService->phaseBlueprints(),
                'ingredients' => $this->ingredientCatalog(),
                'ifraProductCategories' => $this->ifraProductCategories($soapFamily),
                'defaultIfraProductCategoryId' => $this->defaultIfraProductCategoryId($soapFamily),
            ],
        ]);
    }

    private function soapFamily(): ProductFamily
    {
        return ProductFamily::query()
            ->where('slug', 'soap')
            ->firstOrFail();
    }

    /**
     * @return array<int, array{id:int, code:string, name:string, short_name:?string, description:?string}>
     */
    private function ifraProductCategories(ProductFamily $productFamily): array
    {
        $mappedCategories = ProductFamilyIfraCategory::query()
            ->with('ifraProductCategory')
            ->where('product_family_id', $productFamily->id)
            ->orderBy('sort_order')
            ->orderByDesc('is_default')
            ->get()
            ->map(fn (ProductFamilyIfraCategory $mapping): ?IfraProductCategory => $mapping->ifraProductCategory)
            ->filter(fn (?IfraProductCategory $category): bool => $category instanceof IfraProductCategory && $category->is_active)
            ->values();

        $categories = $mappedCategories->isNotEmpty()
            ? $mappedCategories
            : IfraProductCategory::query()
                ->where('is_active', true)
                ->get();

        return $categories
            ->sortBy(fn (IfraProductCategory $category): array => $this->ifraProductCategorySortKey($category->code))
            ->values()
            ->map(fn (IfraProductCategory $category): array => [
                'id' => $category->id,
                'code' => $category->code,
                'name' => $category->name,
                'short_name' => $category->short_name,
                'description' => $category->description,
            ])
            ->all();
    }

    private function defaultIfraProductCategoryId(ProductFamily $productFamily): ?int
    {
        $mappedDefault = ProductFamilyIfraCategory::query()
            ->with('ifraProductCategory:id,is_active')
            ->where('product_family_id', $productFamily->id)
            ->where('is_default', true)
            ->orderBy('sort_order')
            ->first();

        if ($mappedDefault?->ifraProductCategory?->is_active) {
            return $mappedDefault->ifra_product_category_id;
        }

        return IfraProductCategory::query()
            ->where('is_active', true)
            ->where('code', '9')
            ->value('id');
    }

    /**
     * @return array{int,string}
     */
    private function ifraProductCategorySortKey(string $code): array
    {
        preg_match('/^(\d+)([A-Za-z]*)$/', $code, $matches);

        return [
            isset($matches[1]) ? (int) $matches[1] : PHP_INT_MAX,
            strtoupper($matches[2] ?? ''),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function ingredientCatalog(): array
    {
        $user = $this->currentUser();

        return Ingredient::query()
            ->with(['sapProfile', 'fattyAcidEntries.fattyAcid'])
            ->where('is_active', true)
            ->accessibleTo($user)
            ->whereIn('category', [
                IngredientCategory::CarrierOil->value,
                IngredientCategory::EssentialOil->value,
                IngredientCategory::FragranceOil->value,
                IngredientCategory::BotanicalExtract->value,
                IngredientCategory::Co2Extract->value,
                IngredientCategory::Clay->value,
                IngredientCategory::Glycol->value,
                IngredientCategory::Colorant->value,
                IngredientCategory::Preservative->value,
                IngredientCategory::Additive->value,
            ])
            ->get()
            ->filter(fn (Ingredient $ingredient): bool => $ingredient->availableWorkbenchPhases() !== [])
            ->map(function (Ingredient $ingredient): array {
                $category = $ingredient->category;
                $sapProfile = $ingredient->sapProfile;
                $availablePhases = $ingredient?->availableWorkbenchPhases() ?? [];

                return [
                    'id' => $ingredient->id,
                    'ingredient_id' => $ingredient->id,
                    'name' => $ingredient->display_name,
                    'inci_name' => $ingredient->inci_name,
                    'image_url' => $ingredient?->pickerImageUrl(),
                    'category' => $category?->value,
                    'category_label' => $category?->getLabel(),
                    'soap_inci_naoh_name' => $ingredient->soap_inci_naoh_name,
                    'soap_inci_koh_name' => $ingredient->soap_inci_koh_name,
                    'needs_compliance' => $ingredient?->requiresAromaticCompliance() ?? false,
                    'koh_sap_value' => $sapProfile?->koh_sap_value === null ? null : (float) $sapProfile->koh_sap_value,
                    'naoh_sap_value' => $sapProfile?->naoh_sap_value,
                    'fatty_acid_profile' => $ingredient->normalizedFattyAcidProfile(),
                    'available_phases' => $availablePhases,
                    'default_phase' => $ingredient?->preferredWorkbenchPhase(),
                    'can_add_to_saponified_oils' => in_array('saponified_oils', $availablePhases, true),
                    'can_add_to_additives' => in_array('additives', $availablePhases, true),
                    'can_add_to_fragrance' => in_array('fragrance', $availablePhases, true),
                ];
            })
            ->sortBy('name')
            ->values()
            ->all();
    }

    private function currentRecipe(): ?Recipe
    {
        if ($this->recipeId === null) {
            return null;
        }

        $user = $this->currentUser();

        if (! $user instanceof User) {
            return null;
        }

        $recipe = Recipe::withoutGlobalScopes()
            ->whereKey($this->recipeId)
            ->first();

        if (! $recipe instanceof Recipe || ! $recipe->isAccessibleBy($user)) {
            return null;
        }

        return $recipe;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function currentRecipeData(): ?array
    {
        $recipe = $this->currentRecipe();

        if (! $recipe instanceof Recipe) {
            return null;
        }

        return [
            'id' => $recipe->id,
            'name' => $recipe->name,
            'description' => $recipe->description,
            'manufacturing_instructions' => $recipe->manufacturing_instructions,
            'featured_image_url' => $recipe->featuredImageUrl(),
        ];
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

    private function currentUser(): ?User
    {
        return app(CurrentAppUserResolver::class)->resolve($this->actorUserId);
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
