<?php

namespace App\Livewire\Dashboard;

use App\IngredientCategory;
use App\Models\IfraProductCategory;
use App\Models\Ingredient;
use App\Models\ProductFamily;
use App\Models\Recipe;
use App\Models\User;
use App\Services\CurrentAppUserResolver;
use App\Services\MediaStorage;
use App\Services\RecipeWorkbenchService;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class RecipeWorkbench extends Component implements HasForms
{
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
        return [
            'ok' => true,
            'calculation' => $recipeWorkbenchService->previewSoapCalculation($draft),
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

        /** @var array{description:?string, featured_image_path:?string} $state */
        $state = $this->form->getState();

        $recipe->fill([
            'description' => $state['description'] ?? null,
            'featured_image_path' => $state['featured_image_path'] ?? null,
        ]);
        $recipe->save();

        $this->recipeContentStatus = 'success';
        $this->recipeContentMessage = 'Recipe content saved.';
        $this->refreshRecipeContentForm($recipe->fresh());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Recipe content')
                    ->description('Use rich text for the process story, instructions, and in-process photos. Images stay constrained through Filament upload and editor controls.')
                    ->columns([
                        'lg' => 12,
                    ])
                    ->schema([
                        RichEditor::make('description')
                            ->label('Description and instructions')
                            ->helperText('Use this for process steps, instructions, and publication-ready recipe notes.')
                            ->toolbarButtons([
                                ['bold', 'italic', 'underline', 'strike', 'link'],
                                ['h2', 'h3', 'blockquote', 'bulletList', 'orderedList'],
                                ['attachFiles', 'undo', 'redo'],
                            ])
                            ->fileAttachmentsDisk(MediaStorage::publicDisk())
                            ->fileAttachmentsDirectory('recipes/rich-content')
                            ->fileAttachmentsVisibility('public')
                            ->fileAttachmentsAcceptedFileTypes([
                                'image/jpeg',
                                'image/webp',
                            ])
                            ->fileAttachmentsMaxSize(3072)
                            ->resizableImages()
                            ->columnSpan([
                                'lg' => 7,
                            ]),
                        FileUpload::make('featured_image_path')
                            ->label('Finished product image')
                            ->image()
                            ->disk(MediaStorage::publicDisk())
                            ->directory('recipes/featured-images')
                            ->visibility('public')
                            ->imagePreviewHeight('20rem')
                            ->panelLayout('integrated')
                            ->panelAspectRatio('4:3')
                            ->acceptedFileTypes([
                                'image/jpeg',
                                'image/webp',
                            ])
                            ->maxSize(3072)
                            ->automaticallyResizeImagesMode('cover')
                            ->automaticallyResizeImagesToHeight('1400')
                            ->automaticallyResizeImagesToWidth('1400')
                            ->automaticallyUpscaleImagesWhenResizing(false)
                            ->imageEditor()
                            ->imageAspectRatio('4:3')
                            ->imageEditorAspectRatioOptions([
                                '4:3',
                                '1:1',
                            ])
                            ->automaticallyOpenImageEditorForAspectRatio()
                            ->helperText('Allowed: JPG or WebP, up to 3 MB. Square and 4:3 crops stay large enough for recipe cards and future thumbnails.')
                            ->columnSpan([
                                'lg' => 5,
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
                'phases' => $recipeWorkbenchService->phaseBlueprints(),
                'ingredients' => $this->ingredientCatalog(),
                'ifraProductCategories' => IfraProductCategory::query()
                    ->where('is_active', true)
                    ->orderBy('code')
                    ->get()
                    ->map(fn (IfraProductCategory $category): array => [
                        'id' => $category->id,
                        'code' => $category->code,
                        'name' => $category->name,
                        'short_name' => $category->short_name,
                        'description' => $category->description,
                    ])
                    ->all(),
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
                    'image_url' => $ingredient?->featuredImageUrl(),
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
            'featured_image_url' => $recipe->featuredImageUrl(),
        ];
    }

    /**
     * @return array{description:?string, featured_image_path:?string}
     */
    private function recipeContentFormState(?Recipe $recipe = null): array
    {
        $recipe ??= $this->currentRecipe();

        return [
            'description' => $recipe?->description,
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
