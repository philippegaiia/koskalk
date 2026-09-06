<?php

namespace App\Livewire\Dashboard;

use App\Data\IngredientClassificationPromptInput;
use App\Enums\IngredientCategory;
use App\Enums\IngredientFunctionSource;
use App\Enums\IngredientIdentifierScheme;
use App\Enums\IngredientSubcategory;
use App\Enums\MediaAssetType;
use App\Enums\MediaAssetUsageRole;
use App\Enums\OwnerType;
use App\Forms\Components\IngredientIdentityFields;
use App\Forms\Components\MediaAssetPicker;
use App\Livewire\Concerns\InteractsWithAppNotifications;
use App\Livewire\Concerns\InteractsWithMediaAssetPickerUploads;
use App\Models\Allergen;
use App\Models\FattyAcid;
use App\Models\IfraAmendment;
use App\Models\IfraCertificate;
use App\Models\IfraProductCategory;
use App\Models\Ingredient;
use App\Models\IngredientFunction;
use App\Models\MediaAsset;
use App\Models\Substance;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceIngredientGuidance;
use App\Services\CurrentAppUserResolver;
use App\Services\IngredientClassificationPromptBuilder;
use App\Services\IngredientIdentitySynchronizer;
use App\Services\MediaAssetUsageService;
use App\Services\UserIngredientAuthoringService;
use App\Services\WorkspaceIngredientCodeService;
use App\Services\WorkspaceIngredientGuidanceContent;
use App\Services\WorkspaceIngredientGuidanceService;
use App\SoapSap;
use App\Support\LocalizedDecimalInput;
use App\Support\NumberLocale;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Filament\Schemas\Schema;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class IngredientEditor extends Component implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithAppNotifications;
    use InteractsWithForms;
    use InteractsWithMediaAssetPickerUploads;
    use RestrictsFileUploadsToSchemaComponents;

    #[Locked]
    public ?int $ingredientId = null;

    #[Locked]
    public ?int $destinationWorkspaceId = null;

    #[Locked]
    public string $mediaPublicId;

    #[Locked]
    public ?string $returnTo = null;

    #[Locked]
    public ?string $returnSupplierPublicId = null;

    #[Locked]
    public ?string $legacyIngredientTab = null;

    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    public bool $confirmCompositionRemoval = false;

    /**
     * @var array<string, mixed>
     */
    public array $referenceData = [];

    public ?string $workspaceMaterialCode = null;

    /** @var array{html: ?string} */
    public array $workspaceGuidance = ['html' => null];

    public bool $isEditingWorkspaceGuidance = false;

    public ?string $statusMessage = null;

    public string $statusType = 'idle';

    public string $quickComponentName = '';

    public ?string $quickComponentCategory = null;

    public ?string $generatedClassificationPrompt = null;

    private bool $hasResolvedFreshAuthenticatedUser = false;

    private ?User $resolvedFreshAuthenticatedUser = null;

    private bool $hasResolvedCurrentIngredient = false;

    private ?int $resolvedCurrentIngredientId = null;

    private ?Ingredient $resolvedCurrentIngredient = null;

    private ?string $canEditIngredientDataCacheKey = null;

    private bool $resolvedCanEditIngredientData = false;

    /** @var array<int, string>|null */
    private ?array $fattyAcidOptionsCache = null;

    /** @var array<int> */
    private array $inactiveFattyAcidIdsCache = [];

    /** @var array<int, string>|null */
    private ?array $allergenOptionsCache = null;

    /** @var array<int, string>|null */
    private ?array $substanceOptionsCache = null;

    /** @var array<int, string>|null */
    private ?array $ifraProductCategoryOptionsCache = null;

    /** @var array<int> */
    private array $inactiveIfraProductCategoryIdsCache = [];

    public function generateClassificationPrompt(IngredientClassificationPromptBuilder $builder): void
    {
        $name = trim((string) ($this->data['name'] ?? ''));
        $inciName = trim((string) ($this->data['inci_name'] ?? ''));

        if ($name === '' && $inciName === '') {
            $this->showAppNotification(
                __('ingredients.editor.classification_prompt.identity_required'),
                'error',
            );

            return;
        }

        $this->generatedClassificationPrompt = $builder->build(
            new IngredientClassificationPromptInput(
                name: $this->data['name'] ?? null,
                inciName: $this->data['inci_name'] ?? null,
                casNumber: $this->data['cas_number'] ?? null,
                ecNumber: $this->data['ec_number'] ?? null,
                supplierNotes: $this->data['notes'] ?? null,
                responseLocale: app()->getLocale(),
                additionalIdentifiers: $this->data['additional_identifiers'] ?? [],
            ),
        );
    }

    public function mount(
        ?Ingredient $ingredient,
        UserIngredientAuthoringService $userIngredientAuthoringService,
        MediaAssetUsageService $mediaAssetUsages,
        WorkspaceIngredientCodeService $workspaceIngredientCodes,
        WorkspaceIngredientGuidanceService $workspaceIngredientGuidances,
    ): void {
        if ($ingredient?->exists !== true) {
            $ingredient = null;
        }

        $this->ingredientId = $ingredient?->id;
        $mountUser = $this->freshAuthenticatedUser();
        $ingredient = $this->currentIngredient();
        $settingsWorkspace = $this->workspaceForIngredientSettings($ingredient);
        $this->destinationWorkspaceId = $settingsWorkspace?->id;
        $this->mediaPublicId = (string) ($ingredient?->public_id ?? Str::uuid());
        $this->legacyIngredientTab = match (request()->query('ingredient-tab')) {
            'documents::tab', 'documents::data::tab' => 'documents',
            'compliance::tab', 'compliance::data::tab' => 'compliance',
            default => null,
        };

        if ($ingredient === null && request()->query('return_to') === 'supplier_listing') {
            $this->returnTo = 'supplier_listing';
            $this->returnSupplierPublicId = $this->validReturnSupplierPublicId(request()->query('supplier'));
        }

        if ($ingredient instanceof Ingredient && $this->isReferenceViewFor($ingredient)) {
            $this->initializeReferenceState($ingredient, $mountUser);

            return;
        }

        $state = $ingredient instanceof Ingredient
            ? $userIngredientAuthoringService->formData($ingredient)
            : $userIngredientAuthoringService->blankState();
        $state['featured_media_asset_id'] = $ingredient instanceof Ingredient
            ? ($mediaAssetUsages->idsFor($ingredient, MediaAssetUsageRole::IngredientMain)[0] ?? null)
            : null;
        $state['icon_media_asset_id'] = $ingredient instanceof Ingredient
            ? ($mediaAssetUsages->idsFor($ingredient, MediaAssetUsageRole::IngredientIconOverride)[0] ?? null)
            : null;
        $state['document_media_asset_ids'] = $ingredient instanceof Ingredient
            ? $mediaAssetUsages->idsFor($ingredient, MediaAssetUsageRole::IngredientDocument)
            : [];
        $workspace = $settingsWorkspace;
        $materialCode = $ingredient instanceof Ingredient && $workspace instanceof Workspace
            ? $workspaceIngredientCodes->codeFor($workspace, $ingredient)
            : null;
        $state['material_code'] = $materialCode;
        $this->workspaceMaterialCode = $ingredient instanceof Ingredient
            && $this->isPlatformIngredient($ingredient)
                ? $materialCode
                : null;
        $guidance = $ingredient instanceof Ingredient
            && $workspace instanceof Workspace
                ? $workspaceIngredientGuidances->recordFor($workspace, $ingredient)
                : null;

        if ($ingredient instanceof Ingredient && ! $this->isPlatformIngredient($ingredient)) {
            $state['guidance_html'] = $guidance?->guidance_html;
        }

        $this->workspaceGuidanceForm->fill([
            'html' => $guidance?->guidance_html,
        ]);

        $this->form->fill($state);
    }

    public function hydrate(): void
    {
        $ingredient = $this->currentIngredient();

        if (! $ingredient instanceof Ingredient) {
            if ($this->ingredientId !== null) {
                $this->data = [];
                $this->referenceData = [];
                $this->workspaceMaterialCode = null;
                $this->workspaceGuidance = ['html' => null];
                $this->workspaceGuidanceForm->fill(['html' => null]);
                $this->isEditingWorkspaceGuidance = false;
            }

            return;
        }

        if (! $this->isReferenceViewFor($ingredient)) {
            return;
        }

        $this->data = [];
        $this->referenceData = [];

        if (! $this->isPlatformIngredient($ingredient)) {
            $this->workspaceMaterialCode = null;
            $this->workspaceGuidance = ['html' => null];
            $this->workspaceGuidanceForm->fill(['html' => null]);

            return;
        }

        if (! ($this->destinationWorkspaceForDisplay($ingredient) instanceof Workspace)) {
            $this->workspaceMaterialCode = null;
            $this->workspaceGuidance = ['html' => null];
            $this->workspaceGuidanceForm->fill(['html' => null]);
            $this->isEditingWorkspaceGuidance = false;
        }
    }

    public function save(
        UserIngredientAuthoringService $userIngredientAuthoringService,
        MediaAssetUsageService $mediaAssetUsages,
        WorkspaceIngredientCodeService $workspaceIngredientCodes,
        WorkspaceIngredientGuidanceContent $workspaceIngredientGuidanceContent,
        WorkspaceIngredientGuidanceService $workspaceIngredientGuidances,
    ) {
        $user = $this->freshAuthenticatedUser();
        $wasEditing = $this->ingredientId !== null;

        if (! $user instanceof User) {
            $this->showAppNotification(
                __('ingredients.editor.status.auth_required'),
                'error',
            );

            return null;
        }

        try {
            $currentIngredient = $wasEditing
                ? $this->authorizeIngredientWrite($user)
                : null;
            $destinationWorkspace = $currentIngredient?->workspace_id !== null
                ? $this->authorizeOwningWorkspace($user, $currentIngredient)
                : (! $wasEditing ? $this->authorizeDestinationWorkspace($user) : null);

            if (! $wasEditing) {
                Gate::forUser($user)->authorize(
                    'createInWorkspace',
                    [Ingredient::class, $destinationWorkspace],
                );
            }
        } catch (AuthorizationException) {
            $this->addStaleWorkspaceError('data');

            return null;
        }

        if ($this->isCompositionRemovalPending() && ! $this->confirmCompositionRemoval) {
            $this->addError(
                'confirmCompositionRemoval',
                __('ingredients.editor.validation.composition_removal_confirmation'),
            );

            return null;
        }

        /** @var array<string, mixed> $state */
        $state = $this->mergeCustomCompositionState($this->form->getState());
        $featuredMediaAssetId = $state['featured_media_asset_id'] ?? null;
        $iconMediaAssetId = $state['icon_media_asset_id'] ?? null;
        $documentMediaAssetIds = $state['document_media_asset_ids'] ?? [];
        $workspaceMaterialCode = $state['material_code'] ?? null;
        $workspaceGuidanceHtml = $state['guidance_html'] ?? null;
        unset($state['featured_media_asset_id'], $state['icon_media_asset_id'], $state['document_media_asset_ids']);
        unset($state['material_code']);
        unset($state['guidance_html']);
        $state['public_id'] = $this->mediaPublicId;
        $confirmCompositionRemoval = $this->confirmCompositionRemoval;

        try {
            $ingredient = DB::transaction(function () use ($confirmCompositionRemoval, $currentIngredient, $destinationWorkspace, $documentMediaAssetIds, $featuredMediaAssetId, $iconMediaAssetId, $mediaAssetUsages, $state, $user, $userIngredientAuthoringService, $workspaceIngredientCodes, $workspaceIngredientGuidanceContent, $workspaceIngredientGuidances, $workspaceGuidanceHtml, $workspaceMaterialCode): Ingredient {
                $ingredient = $currentIngredient instanceof Ingredient
                    ? $userIngredientAuthoringService->update($currentIngredient, $state, $user, $destinationWorkspace, $confirmCompositionRemoval)
                    : $userIngredientAuthoringService->createInWorkspace($state, $user, $destinationWorkspace);

                $workspace = $destinationWorkspace instanceof Workspace
                    ? $destinationWorkspace
                    : ($ingredient->workspace_id === null
                        ? null
                        : Workspace::withoutGlobalScopes()->find((int) $ingredient->workspace_id));

                if ($workspace instanceof Workspace) {
                    if ($ingredient->owner_type === OwnerType::Workspace) {
                        if (filled($workspaceIngredientGuidanceContent->text($workspaceGuidanceHtml))) {
                            $workspaceIngredientGuidances->save(
                                $user,
                                $workspace,
                                $ingredient,
                                $workspaceGuidanceHtml,
                            );
                        } else {
                            $workspaceIngredientGuidances->clearWorkspaceOwned(
                                $user,
                                $workspace,
                                $ingredient,
                            );
                        }
                    }

                    $workspaceIngredientCodes->synchronize($user, $workspace, $ingredient, $workspaceMaterialCode);
                }

                $mediaAssetUsages->syncSingle(
                    $user,
                    $ingredient,
                    MediaAssetUsageRole::IngredientMain,
                    $featuredMediaAssetId,
                );
                $mediaAssetUsages->syncSingle(
                    $user,
                    $ingredient,
                    MediaAssetUsageRole::IngredientIconOverride,
                    $iconMediaAssetId,
                );
                $mediaAssetUsages->syncMany(
                    $user,
                    $ingredient,
                    MediaAssetUsageRole::IngredientDocument,
                    $documentMediaAssetIds,
                    maximum: 8,
                    expectedType: [MediaAssetType::Pdf, MediaAssetType::Image],
                );

                return $ingredient;
            });
        } catch (AuthorizationException) {
            $this->addStaleWorkspaceError('data');

            return null;
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $key => $messages) {
                foreach ($messages as $message) {
                    $field = $key === 'confirmCompositionRemoval' || str_starts_with($key, 'data.')
                        ? $key
                        : 'data.'.$key;
                    $this->addError($field, $message);
                }
            }

            $this->showAppNotification(
                __('ingredients.editor.status.invalid'),
                'error',
            );

            return null;
        }

        $this->refreshAuthenticatedUserContext($user);
        $this->ingredientId = $ingredient->id;
        $this->resolvedCurrentIngredient = $ingredient;
        $this->resolvedCurrentIngredientId = $ingredient->id;
        $this->hasResolvedCurrentIngredient = true;
        $this->canEditIngredientDataCacheKey = null;
        $statusMessage = $wasEditing
            ? __('ingredients.editor.status.saved')
            : __('ingredients.editor.status.created');

        $refreshedState = $userIngredientAuthoringService->formData($ingredient);
        $workspace = $destinationWorkspace instanceof Workspace
            ? $destinationWorkspace
            : ($ingredient->workspace_id === null
                ? null
                : Workspace::withoutGlobalScopes()->find((int) $ingredient->workspace_id));
        $refreshedState['material_code'] = $workspace instanceof Workspace
            ? $workspaceIngredientCodes->codeFor($workspace, $ingredient)
            : null;
        $refreshedState['featured_media_asset_id'] = $featuredMediaAssetId;
        $refreshedState['icon_media_asset_id'] = $iconMediaAssetId;
        $refreshedState['document_media_asset_ids'] = $documentMediaAssetIds;
        $refreshedState['guidance_html'] = $workspace instanceof Workspace
            && $ingredient->owner_type === OwnerType::Workspace
                ? $workspaceIngredientGuidances->recordFor($workspace, $ingredient)?->guidance_html
                : null;
        $this->form->fill($refreshedState);
        $this->confirmCompositionRemoval = false;

        /** @var array<string, mixed> $canonicalState */
        $canonicalState = $this->data;

        if (! $wasEditing) {
            session()->flash('status', $statusMessage);

            $redirect = $this->returnTo === 'supplier_listing'
                ? route('production-bench.purchasing.listings.create', array_filter([
                    'material_type' => 'ingredient',
                    'ingredient' => $ingredient->public_id,
                    'supplier' => $this->returnSupplierPublicId,
                ]))
                : route('ingredients.edit', $ingredient);

            $this->dispatch(
                'ingredient-editor:created',
                scope: 'ingredient',
                baseline: $canonicalState,
                redirect: $redirect,
                message: $statusMessage,
            );

            return null;
        }

        $this->showAppNotification($statusMessage);
        $this->dispatch(
            'ingredient-editor:saved',
            scope: 'ingredient',
            baseline: $canonicalState,
        );

        return null;
    }

    public function saveWorkspaceMaterialCode(WorkspaceIngredientCodeService $workspaceIngredientCodes): void
    {
        try {
            [$user, $workspace, $ingredient] = $this->authorizePlatformWorkspaceContext();
        } catch (AuthorizationException) {
            $this->addStaleWorkspaceError('workspaceMaterialCode');

            return;
        }

        try {
            $workspaceIngredientCodes->synchronize($user, $workspace, $ingredient, $this->workspaceMaterialCode);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->addError('workspaceMaterialCode', $message);
                }
            }

            return;
        } catch (AuthorizationException) {
            $this->addError('workspaceMaterialCode', __('ingredients.editor.validation.material_code_forbidden'));

            return;
        }

        $this->workspaceMaterialCode = $workspaceIngredientCodes->codeFor($workspace, $ingredient);
        $this->showAppNotification(__('ingredients.editor.material_code.saved'));
        $this->dispatch(
            'ingredient-editor:saved',
            scope: 'material-code',
            baseline: $this->workspaceMaterialCode,
        );
    }

    public function startWorkspaceGuidanceCustomization(
        WorkspaceIngredientGuidanceService $workspaceIngredientGuidances,
    ): void {
        try {
            [, $workspace, $ingredient] = $this->authorizePlatformWorkspaceContext();
        } catch (AuthorizationException) {
            $this->addStaleWorkspaceError('workspaceGuidance.html');

            return;
        }

        $this->workspaceGuidanceForm->fill([
            'html' => $workspaceIngredientGuidances->editableHtml(
                $workspace,
                $ingredient,
                app()->getLocale(),
            ),
        ]);
        $this->isEditingWorkspaceGuidance = true;
        $this->resetErrorBag('workspaceGuidance.html');
        $this->dispatch(
            'ingredient-editor:baseline',
            scope: 'guidance',
            baseline: ['html' => $this->workspaceGuidanceForm->getState()['html'] ?? null],
        );
    }

    public function cancelWorkspaceGuidanceCustomization(
        WorkspaceIngredientGuidanceService $workspaceIngredientGuidances,
    ): void {
        $context = $this->workspaceGuidanceWriteContext();
        $guidance = $context === null
            ? null
            : $workspaceIngredientGuidances->recordFor($context[1], $context[2]);

        $this->workspaceGuidanceForm->fill([
            'html' => $guidance?->guidance_html,
        ]);
        $this->isEditingWorkspaceGuidance = false;
        $this->resetErrorBag('workspaceGuidance.html');
        $this->dispatch(
            'ingredient-editor:cancelled',
            scope: 'guidance',
            baseline: ['html' => $this->workspaceGuidanceForm->getState()['html'] ?? null],
        );
    }

    public function saveWorkspaceGuidance(
        WorkspaceIngredientGuidanceService $workspaceIngredientGuidances,
    ): void {
        try {
            [$user, $workspace, $ingredient] = $this->authorizePlatformWorkspaceContext();
        } catch (AuthorizationException) {
            $this->addStaleWorkspaceError('workspaceGuidance.html');

            return;
        }

        try {
            $guidance = $workspaceIngredientGuidances->save(
                $user,
                $workspace,
                $ingredient,
                $this->workspaceGuidanceForm->getState()['html'] ?? null,
            );
        } catch (ValidationException $exception) {
            $this->addWorkspaceGuidanceValidationErrors($exception);

            return;
        } catch (AuthorizationException) {
            $this->addStaleWorkspaceError('workspaceGuidance.html');

            return;
        }

        $this->workspaceGuidanceForm->fill([
            'html' => $guidance->guidance_html,
        ]);
        $this->isEditingWorkspaceGuidance = false;
        $this->resetErrorBag('workspaceGuidance.html');
        $this->showAppNotification(__('ingredients.editor.workspace_guidance.saved'));
        $this->dispatch(
            'ingredient-editor:saved',
            scope: 'guidance',
            baseline: ['html' => $this->workspaceGuidanceForm->getState()['html'] ?? null],
        );
    }

    public function usePlatformGuidance(
        WorkspaceIngredientGuidanceService $workspaceIngredientGuidances,
    ): void {
        try {
            [$user, $workspace, $ingredient] = $this->authorizePlatformWorkspaceContext();
        } catch (AuthorizationException) {
            $this->addStaleWorkspaceError('workspaceGuidance.html');

            return;
        }

        try {
            $workspaceIngredientGuidances->usePlatform($user, $workspace, $ingredient);
        } catch (ValidationException $exception) {
            $this->addWorkspaceGuidanceValidationErrors($exception);

            return;
        } catch (AuthorizationException) {
            $this->addStaleWorkspaceError('workspaceGuidance.html');

            return;
        }

        $guidance = $workspaceIngredientGuidances->recordFor($workspace, $ingredient);
        $this->workspaceGuidanceForm->fill(['html' => $guidance?->guidance_html]);
        $this->isEditingWorkspaceGuidance = false;
        $this->resetErrorBag('workspaceGuidance.html');
        $this->showAppNotification(__('ingredients.editor.workspace_guidance.platform_selected'));
        $this->dispatch(
            'ingredient-editor:saved',
            scope: 'guidance',
            baseline: ['html' => $this->workspaceGuidanceForm->getState()['html'] ?? null],
        );
    }

    public function useWorkspaceGuidance(
        WorkspaceIngredientGuidanceService $workspaceIngredientGuidances,
    ): void {
        try {
            [$user, $workspace, $ingredient] = $this->authorizePlatformWorkspaceContext();
        } catch (AuthorizationException) {
            $this->addStaleWorkspaceError('workspaceGuidance.html');

            return;
        }

        try {
            $guidance = $workspaceIngredientGuidances->useWorkspace($user, $workspace, $ingredient);
        } catch (ValidationException $exception) {
            $this->addWorkspaceGuidanceValidationErrors($exception);

            return;
        } catch (AuthorizationException) {
            $this->addStaleWorkspaceError('workspaceGuidance.html');

            return;
        }

        $this->workspaceGuidanceForm->fill(['html' => $guidance->guidance_html]);
        $this->isEditingWorkspaceGuidance = false;
        $this->resetErrorBag('workspaceGuidance.html');
        $this->showAppNotification(__('ingredients.editor.workspace_guidance.workspace_selected'));
        $this->dispatch(
            'ingredient-editor:saved',
            scope: 'guidance',
            baseline: ['html' => $this->workspaceGuidanceForm->getState()['html'] ?? null],
        );
    }

    public function canEditWorkspaceGuidance(): bool
    {
        try {
            $this->authorizePlatformWorkspaceContext();
        } catch (AuthorizationException) {
            return false;
        }

        return true;
    }

    public function addComponent(int $ingredientId): bool
    {
        $user = $this->freshAuthenticatedUser();

        $componentIsAccessible = $user instanceof User
            && Ingredient::query()
                ->accessibleTo($user)
                ->where('is_active', true)
                ->whereKey($ingredientId)
                ->exists();

        if (! $componentIsAccessible) {
            $this->addError('data.components', __('ingredients.editor.validation.component_unavailable'));

            return false;
        }

        if (count($this->data['components'] ?? []) >= 20) {
            $this->addError('data.components', __('ingredients.editor.validation.component_limit'));

            return false;
        }

        if (collect($this->data['components'] ?? [])
            ->contains(fn (mixed $row): bool => (int) ($row['component_ingredient_id'] ?? 0) === $ingredientId)) {
            $this->addError('data.components', __('ingredients.editor.validation.component_duplicate'));

            return false;
        }

        $componentIndex = count($this->data['components'] ?? []);
        $this->data['components'][] = [
            'component_ingredient_id' => $ingredientId,
            'percentage_in_parent' => null,
        ];
        $this->dispatch(
            'ingredient-composition-added',
            targetId: 'composition-share-'.$componentIndex,
        );

        return true;
    }

    public function createAndAddComponent(UserIngredientAuthoringService $userIngredientAuthoringService): void
    {
        $user = $this->freshAuthenticatedUser();

        if (! $user instanceof User) {
            $this->addError('quickComponentName', __('ingredients.editor.validation.quick_auth_required'));

            return;
        }

        if (count($this->data['components'] ?? []) >= 20) {
            $this->addError('data.components', __('ingredients.editor.validation.component_limit'));

            return;
        }

        $validated = $this->validate([
            'quickComponentName' => ['required', 'string', 'max:255'],
            'quickComponentCategory' => ['required', Rule::enum(IngredientCategory::class)],
        ]);

        try {
            $parentIngredient = $this->ingredientId === null
                ? null
                : $this->authorizeIngredientWrite($user);
            $destinationWorkspace = $parentIngredient?->workspace_id !== null
                ? $this->authorizeOwningWorkspace($user, $parentIngredient)
                : $this->authorizeDestinationWorkspace($user);

            Gate::forUser($user)->authorize(
                'createInWorkspace',
                [Ingredient::class, $destinationWorkspace],
            );

            $ingredient = $userIngredientAuthoringService->createInlineComponentInWorkspace([
                'name' => $validated['quickComponentName'],
                'category' => $validated['quickComponentCategory'],
            ], $user, $destinationWorkspace);
        } catch (AuthorizationException) {
            $this->addStaleWorkspaceError('quickComponentName');

            return;
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $key => $messages) {
                $field = match ($key) {
                    'name' => 'quickComponentName',
                    default => $key,
                };

                foreach ($messages as $message) {
                    $this->addError($field, $message);
                }
            }

            return;
        }

        if ($this->destinationWorkspaceId === null && $ingredient->workspace_id !== null) {
            $this->destinationWorkspaceId = (int) $ingredient->workspace_id;
            $this->refreshAuthenticatedUserContext($user);
        }

        if (! $this->addComponent($ingredient->id)) {
            return;
        }

        $this->quickComponentName = '';
        $this->quickComponentCategory = null;
        $this->dispatch(
            'component-created',
            ingredientId: $ingredient->id,
            ingredientLabel: $ingredient->display_name,
        );
    }

    public function quickComponentWorkspaceLabel(): string
    {
        return $this->destinationWorkspaceForDisplay($this->currentIngredient())?->name
            ?? __('ingredients.editor.composition.workspace_fallback');
    }

    public function removeComponentRow(int $index): void
    {
        if (! array_key_exists($index, $this->data['components'] ?? [])) {
            return;
        }

        unset($this->data['components'][$index]);

        $this->data['components'] = array_values($this->data['components']);
        $this->reindexComponentErrorsAfterRemoval($index);
        $remainingRows = count($this->data['components']);
        $targetId = $remainingRows === 0
            ? 'composition-ingredient-search'
            : 'composition-share-'.min($index, $remainingRows - 1);

        $this->dispatch('ingredient-composition-removed', targetId: $targetId);
    }

    private function reindexComponentErrorsAfterRemoval(int $removedIndex): void
    {
        /** @var array<string, array<int, string>> $errors */
        $errors = $this->getErrorBag()->getMessages();
        /** @var array<string, array<int, string>> $reindexedErrors */
        $reindexedErrors = [];

        foreach ($errors as $field => $messages) {
            if (preg_match('/^data\.components\.(\d+)(\..+)$/', $field, $matches) !== 1) {
                $reindexedErrors[$field] = $messages;

                continue;
            }

            $componentIndex = (int) $matches[1];
            if ($componentIndex === $removedIndex) {
                continue;
            }

            $reindexedField = $componentIndex > $removedIndex
                ? 'data.components.'.($componentIndex - 1).$matches[2]
                : $field;
            $reindexedErrors[$reindexedField] = [
                ...($reindexedErrors[$reindexedField] ?? []),
                ...$messages,
            ];
        }

        $this->setErrorBag($reindexedErrors);
    }

    public function updatedData(mixed $value, ?string $key): void
    {
        if ($key === 'ingredient_structure') {
            $this->confirmCompositionRemoval = false;
            $this->resetErrorBag('confirmCompositionRemoval');

            return;
        }

        if (! is_string($key) || ! preg_match('/^components\.\d+\.percentage_in_parent$/', $key)) {
            return;
        }

        $field = 'data.'.$key;
        $this->resetErrorBag($field);

        if (blank($value)) {
            return;
        }

        $percentage = NumberLocale::parseDecimalInput($value);

        if ($percentage === null || $percentage < 0 || $percentage > 100) {
            $this->addError($field, __('ingredients.editor.validation.component_share'));
        }
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('ingredient-editor')
                    ->contained(false)
                    ->persistTabInQueryString('ingredient-tab')
                    ->activeTab(fn (): int => $this->legacyIngredientTabActivePosition())
                    ->tabs([
                        Tab::make(__('ingredients.editor.tabs.details'))
                            ->id('overview')
                            ->key('overview', isInheritable: false)
                            ->schema([
                                Section::make(__('ingredients.editor.overview.basics_section'))
                                    ->description(__('ingredients.editor.details.description'))
                                    ->extraAttributes(['data-ingredient-basics-section' => true])
                                    ->columns([
                                        'md' => 2,
                                    ])
                                    ->schema([
                                        Hidden::make('is_soap_saponification_trusted'),
                                        TextInput::make('name')
                                            ->label(__('ingredients.editor.overview.name'))
                                            ->required()
                                            ->maxLength(255),
                                        Select::make('category')
                                            ->label(__('ingredients.editor.details.category'))
                                            ->options(IngredientCategory::workspaceAuthorableOptions())
                                            ->required()
                                            ->rules([Rule::enum(IngredientCategory::class)])
                                            ->live(),
                                        Select::make('ingredient_structure')
                                            ->label(__('ingredients.editor.details.type.label'))
                                            ->options([
                                                'ingredient' => __('ingredients.editor.details.type.single'),
                                                'blend' => __('ingredients.editor.details.type.blend'),
                                            ])
                                            ->required()
                                            ->live()
                                            ->helperText(__('ingredients.editor.overview.type_helper'))
                                            ->columnSpanFull(),
                                        SchemaView::make('livewire.dashboard.partials.ingredient-composition-removal-confirmation')
                                            ->visible(fn (): bool => $this->isCompositionRemovalPending())
                                            ->columnSpanFull(),
                                        TextInput::make('inci_name')
                                            ->label(__('ingredients.editor.overview.inci'))
                                            ->helperText(__('ingredients.editor.overview.inci_helper'))
                                            ->maxLength(255),
                                        TextInput::make('material_code')
                                            ->label(__('ingredients.editor.overview.material_code'))
                                            ->helperText(__('ingredients.editor.material_code.helper'))
                                            ->placeholder(__('ingredients.editor.material_code.placeholder'))
                                            ->maxLength(64)
                                            ->visible(fn (): bool => $this->canEditIngredientData()),
                                    ]),
                                Section::make(__('ingredients.editor.classification.section'))
                                    ->description(__('ingredients.editor.classification.description'))
                                    ->extraAttributes(['data-ingredient-classification-section' => true])
                                    ->schema([
                                        Select::make('subcategory')
                                            ->label(__('ingredients.editor.details.subcategory'))
                                            ->options(fn (Get $get): array => IngredientSubcategory::optionsFor($get('category')))
                                            ->searchable()
                                            ->live()
                                            ->helperText(__('ingredients.editor.details.subcategory_helper')),
                                        Toggle::make('requires_aromatic_compliance')
                                            ->label(__('ingredients.editor.overview.aromatic_compliance'))
                                            ->helperText(__('ingredients.editor.overview.aromatic_compliance_helper'))
                                            ->live(),
                                        TextEntry::make('inherited_soap_chemistry')
                                            ->label(__('ingredients.editor.soap.inherited_label'))
                                            ->state(__('ingredients.editor.overview.inherited_soap'))
                                            ->belowContent(__('ingredients.editor.soap.inherited_helper'))
                                            ->visible(fn (): bool => $this->hasInheritedSoapChemistry()),
                                        TextEntry::make('verified_function_names')
                                            ->label(__('ingredients.editor.overview.verified_functions'))
                                            ->formatStateUsing(fn (mixed $state): string => collect(is_array($state) ? $state : [])->implode(', '))
                                            ->belowContent(__('ingredients.editor.supplier.verified_functions_helper'))
                                            ->visible(fn (): bool => collect($this->data['verified_function_names'] ?? [])->filter()->isNotEmpty()),
                                        Select::make('function_ids')
                                            ->label(__('ingredients.editor.overview.workspace_functions'))
                                            ->multiple()
                                            ->searchable()
                                            ->preload()
                                            ->options(fn (): array => IngredientFunction::query()
                                                ->where('is_active', true)
                                                ->orderBy('sort_order')
                                                ->orderBy('name')
                                                ->get()
                                                ->mapWithKeys(fn (IngredientFunction $function): array => [
                                                    $function->id => $function->localizedName(),
                                                ])
                                                ->all())
                                            ->helperText(__('ingredients.editor.supplier.functions_helper'))
                                            ->maxItems(10),
                                    ]),
                                Section::make(__('ingredients.editor.overview.identifiers_section'))
                                    ->description(__('ingredients.editor.identity.description'))
                                    ->extraAttributes(['data-ingredient-identity-section' => true])
                                    ->columns([
                                        'md' => 2,
                                    ])
                                    ->schema([
                                        ...IngredientIdentityFields::schema(platform: false),
                                    ]),
                                SchemaView::make('livewire.dashboard.partials.ingredient-classification-prompt')
                                    ->visible(fn (): bool => $this->canEditIngredientData())
                                    ->columnSpanFull(),
                            ]),
                        Tab::make(__('ingredients.editor.tabs.composition'))
                            ->id('composition')
                            ->key('composition', isInheritable: false)
                            ->visible(fn (Get $get): bool => $get('ingredient_structure') === 'blend')
                            ->schema([
                                SchemaView::make('livewire.dashboard.partials.ingredient-composition-rows')
                                    ->columnSpanFull(),
                            ]),
                        Tab::make(__('ingredients.editor.tabs.documents'))
                            ->id('guidance-files')
                            ->key('guidance-files', isInheritable: false)
                            ->schema([
                                Section::make(__('ingredients.editor.guidance_files.guidance_section'))
                                    ->description(__('ingredients.editor.guidance_files.guidance_description'))
                                    ->extraAttributes(['data-ingredient-guidance-section' => true])
                                    ->visible(fn (): bool => ! $this->isCurrentPlatformIngredient())
                                    ->schema([
                                        $this->guidanceRichEditor(
                                            'guidance_html',
                                            __('ingredients.editor.guidance_files.guidance'),
                                            __('ingredients.editor.guidance_files.guidance_helper', [
                                                'max' => WorkspaceIngredientGuidanceService::MAX_LENGTH,
                                            ]),
                                        )
                                            ->columnSpanFull(),
                                    ]),
                                Section::make(__('ingredients.editor.guidance_files.source_notes_section'))
                                    ->description(__('ingredients.editor.guidance_files.source_notes_description'))
                                    ->extraAttributes(['data-ingredient-source-notes-section' => true])
                                    ->schema([
                                        Textarea::make('notes')
                                            ->label(__('ingredients.editor.guidance_files.source_notes'))
                                            ->helperText(__('ingredients.editor.guidance_files.source_notes_helper'))
                                            ->rows(3)
                                            ->columnSpanFull(),
                                    ]),
                                Section::make(__('ingredients.editor.guidance_files.media_section'))
                                    ->description(__('ingredients.editor.guidance_files.media_description'))
                                    ->extraAttributes(['data-ingredient-media-section' => true])
                                    ->columns([
                                        'md' => 2,
                                    ])
                                    ->schema([
                                        MediaAssetPicker::make('featured_media_asset_id')
                                            ->label(__('ingredients.editor.guidance_files.image'))
                                            ->helperText(__('ingredients.editor.guidance_files.image_helper'))
                                            ->columnSpan(1),
                                        MediaAssetPicker::make('icon_media_asset_id')
                                            ->label(__('ingredients.editor.guidance_files.icon'))
                                            ->helperText(__('ingredients.editor.guidance_files.icon_helper'))
                                            ->columnSpan(1),
                                        MediaAssetPicker::make('document_media_asset_ids')
                                            ->label(__('ingredients.editor.guidance_files.documents'))
                                            ->helperText(__('ingredients.editor.guidance_files.documents_helper'))
                                            ->documents()
                                            ->multiple()
                                            ->maxItems(8)
                                            ->columnSpanFull(),
                                    ]),
                            ]),
                        Tab::make(__('ingredients.editor.tabs.soap_chemistry'))
                            ->id('soap-chemistry')
                            ->key('soap-chemistry', isInheritable: false)
                            ->visible(fn (): bool => $this->soapChemistryAvailable())
                            ->schema([
                                Section::make(__('ingredients.editor.soap.section'))
                                    ->description(__('ingredients.editor.soap.description'))
                                    ->columns([
                                        'md' => 2,
                                    ])
                                    ->schema([
                                        LocalizedDecimalInput::make('sap_profile.koh_sap_value')
                                            ->label(__('ingredients.editor.soap.koh_sap'))
                                            ->live(onBlur: true)
                                            ->afterStateUpdated(fn (LocalizedDecimalInput $component, mixed $state): mixed => $component->state(
                                                $this->canonicalKohSapDisplay($state),
                                            ))
                                            ->helperText(fn (): string => $this->kohSapHelperText()),
                                        Group::make([
                                            TextEntry::make('sap_profile.naoh_sap_value')
                                                ->label(__('ingredients.editor.soap.naoh_sap'))
                                                ->state(fn (Get $get): string => $this->derivedNaohSapDisplay($get('sap_profile.koh_sap_value')))
                                                ->size('lg')
                                                ->weight('semibold')
                                                ->extraAttributes(['class' => 'numeric'])
                                                ->belowContent(__('ingredients.editor.soap.naoh_helper')),
                                        ])
                                            ->extraAttributes([
                                                'class' => 'rounded-xl border border-[var(--color-line)] bg-[var(--color-field-muted)] px-5 py-4',
                                            ]),
                                        LocalizedDecimalInput::make('sap_profile.iodine_value')
                                            ->label(__('ingredients.editor.soap.iodine')),
                                        LocalizedDecimalInput::make('sap_profile.ins_value')
                                            ->label(__('ingredients.editor.soap.ins')),
                                        Textarea::make('sap_profile.source_notes')
                                            ->label(__('ingredients.editor.soap.notes'))
                                            ->rows(3)
                                            ->columnSpanFull(),
                                        Group::make([
                                            TextEntry::make('fatty_acid_total')
                                                ->label(__('ingredients.editor.soap.fatty_acid_total'))
                                                ->state(fn (Get $get): string => $this->fattyAcidTotalDisplay($get('fatty_acid_entries')))
                                                ->size('lg')
                                                ->weight('semibold')
                                                ->extraAttributes(['class' => 'numeric'])
                                                ->belowContent(fn (Get $get): string => is_array($entries = $get('fatty_acid_entries')) && count($entries) > 0
                                                    ? __('ingredients.editor.soap.recommended_total')
                                                    : __('ingredients.editor.soap.fatty_acid_empty')),
                                        ])
                                            ->extraAttributes([
                                                'class' => 'rounded-xl border border-[var(--color-line)] bg-[var(--color-field-muted)] px-5 py-4',
                                            ])
                                            ->columnSpanFull(),
                                        Repeater::make('fatty_acid_entries')
                                            ->label(__('ingredients.editor.soap.fatty_acid_profile'))
                                            ->itemLabel(fn (array $state): string => $this->fattyAcidOptions()[(int) ($state['fatty_acid_id'] ?? 0)]
                                                ?? __('ingredients.editor.soap.new_fatty_acid'))
                                            ->addActionLabel(__('ingredients.editor.soap.add_fatty_acid'))
                                            ->deleteAction(fn (Action $action): Action => $action->label(__('ingredients.editor.soap.remove_fatty_acid')))
                                            ->schema([
                                                Hidden::make('_original_percentage'),
                                                Select::make('fatty_acid_id')
                                                    ->label(__('ingredients.editor.soap.fatty_acid'))
                                                    ->options(fn (): array => $this->fattyAcidOptions())
                                                    ->disableOptionWhen(fn (Get $get, mixed $value): bool => $this->isInactiveFattyAcidOption(
                                                        $value,
                                                        $get('fatty_acid_id'),
                                                    ))
                                                    ->searchable()
                                                    ->preload()
                                                    ->live()
                                                    ->required(),
                                                LocalizedDecimalInput::make('percentage')
                                                    ->label(__('ingredients.editor.soap.percentage'))
                                                    ->suffix('%')
                                                    ->minValue(0)
                                                    ->maxValue(100)
                                                    ->live(onBlur: true)
                                                    ->helperText(fn (Get $get): ?string => $this->fattyAcidHelperText($get('fatty_acid_id')))
                                                    ->required(),
                                            ])
                                            ->columns([
                                                'md' => 2,
                                            ])
                                            ->defaultItems(0)
                                            ->reorderable(false)
                                            ->columnSpanFull(),
                                    ]),
                            ]),
                        Tab::make(__('ingredients.editor.tabs.compliance'))
                            ->id('regulatory-data')
                            ->key('regulatory-data', isInheritable: false)
                            ->schema([
                                Section::make(__('ingredients.editor.compliance.allergens.section'))
                                    ->description(__('ingredients.editor.compliance.allergens.description'))
                                    ->visible(fn (Get $get): bool => (bool) $get('requires_aromatic_compliance'))
                                    ->schema([
                                        Repeater::make('allergen_entries')
                                            ->label(__('ingredients.editor.compliance.allergens.composition'))
                                            ->itemLabel(fn (array $state): string => $this->allergenOptions()[(int) ($state['allergen_id'] ?? 0)]
                                                ?? __('ingredients.editor.compliance.allergens.new_allergen'))
                                            ->addActionLabel(__('ingredients.editor.compliance.allergens.add'))
                                            ->deleteAction(fn (Action $action): Action => $action->label(__('ingredients.editor.compliance.allergens.remove')))
                                            ->schema([
                                                Select::make('allergen_id')
                                                    ->label(__('ingredients.editor.compliance.allergens.allergen'))
                                                    ->options(fn (): array => $this->allergenOptions())
                                                    ->searchable()
                                                    ->preload()
                                                    ->live()
                                                    ->required(),
                                                LocalizedDecimalInput::make('concentration_percent')
                                                    ->label(__('ingredients.editor.compliance.allergens.concentration'))
                                                    ->suffix('%')
                                                    ->minValue(0)
                                                    ->maxValue(100)
                                                    ->required(),
                                            ])
                                            ->columns([
                                                'md' => 2,
                                            ])
                                            ->defaultItems(0)
                                            ->reorderable(false)
                                            ->columnSpanFull(),
                                        Textarea::make('allergen_source_notes')
                                            ->label(__('ingredients.editor.compliance.allergens.source'))
                                            ->helperText(__('ingredients.editor.compliance.allergens.source_helper'))
                                            ->rows(2)
                                            ->columnSpanFull(),
                                    ]),
                                Section::make(__('ingredients.editor.compliance.substances.section'))
                                    ->description(__('ingredients.editor.compliance.substances.description'))
                                    ->schema([
                                        Repeater::make('substance_entries')
                                            ->label(__('ingredients.editor.compliance.substances.entries'))
                                            ->itemLabel(fn (array $state): string => $this->substanceOptions()[(int) ($state['substance_id'] ?? 0)]
                                                ?? __('ingredients.editor.compliance.substances.new_substance'))
                                            ->addActionLabel(__('ingredients.editor.compliance.substances.add'))
                                            ->deleteAction(fn (Action $action): Action => $action->label(__('ingredients.editor.compliance.substances.remove')))
                                            ->schema([
                                                Select::make('substance_id')
                                                    ->label(__('ingredients.editor.compliance.substances.substance'))
                                                    ->options(fn (): array => $this->substanceOptions())
                                                    ->searchable()
                                                    ->preload()
                                                    ->live()
                                                    ->required(),
                                                LocalizedDecimalInput::make('concentration_percent')
                                                    ->label(__('ingredients.editor.compliance.substances.concentration'))
                                                    ->helperText(__('ingredients.editor.compliance.substances.concentration_helper'))
                                                    ->suffix('%')
                                                    ->minValue(0)
                                                    ->maxValue(100),
                                            ])
                                            ->columns([
                                                'md' => 2,
                                            ])
                                            ->defaultItems(0)
                                            ->reorderable(false)
                                            ->columnSpanFull(),
                                    ]),
                                Section::make(__('ingredients.editor.compliance.ifra.section'))
                                    ->description(__('ingredients.editor.compliance.ifra.description'))
                                    ->visible(fn (Get $get): bool => (bool) $get('requires_aromatic_compliance'))
                                    ->columns([
                                        'md' => 2,
                                    ])
                                    ->schema([
                                        TextInput::make('ifra.reference_label')
                                            ->label(__('ingredients.editor.compliance.ifra.reference'))
                                            ->maxLength(255),
                                        Select::make('ifra.ifra_amendment_id')
                                            ->label(__('ingredients.editor.compliance.ifra.amendment'))
                                            ->options(fn (): array => IfraAmendment::query()
                                                ->orderByDesc('notification_date')
                                                ->orderByDesc('id')
                                                ->pluck('code', 'id')
                                                ->all())
                                            ->searchable()
                                            ->preload()
                                            ->nullable(),
                                        TextInput::make('ifra.source_amendment_label')
                                            ->label(__('ingredients.editor.compliance.ifra.source_amendment'))
                                            ->helperText(__('ingredients.editor.compliance.ifra.source_amendment_help'))
                                            ->disabled()
                                            ->dehydrated(false)
                                            ->visible(fn (Get $get): bool => filled($get('ifra.source_amendment_label'))),
                                        LocalizedDecimalInput::make('ifra.peroxide_value')
                                            ->label(__('ingredients.editor.compliance.ifra.peroxide'))
                                            ->minValue(0)
                                            ->suffix('meq O₂/kg'),
                                        Textarea::make('ifra.source_notes')
                                            ->label(__('ingredients.editor.compliance.ifra.notes'))
                                            ->rows(3)
                                            ->columnSpanFull(),
                                        Repeater::make('ifra.limits')
                                            ->label(__('ingredients.editor.compliance.ifra.limits'))
                                            ->itemLabel(fn (array $state): string => $this->ifraProductCategoryOptions()[(int) ($state['ifra_product_category_id'] ?? 0)]
                                                ?? __('ingredients.editor.compliance.ifra.new_category_limit'))
                                            ->addActionLabel(__('ingredients.editor.compliance.ifra.add_category_limit'))
                                            ->deleteAction(fn (Action $action): Action => $action->label(__('ingredients.editor.compliance.ifra.remove_category_limit')))
                                            ->schema([
                                                Select::make('ifra_product_category_id')
                                                    ->label(__('ingredients.editor.compliance.ifra.category'))
                                                    ->options(fn (): array => $this->ifraProductCategoryOptions())
                                                    ->disableOptionWhen(fn (Get $get, mixed $value): bool => $this->isInactiveIfraProductCategoryOption(
                                                        $value,
                                                        $get('ifra_product_category_id'),
                                                    ))
                                                    ->searchable()
                                                    ->preload()
                                                    ->live()
                                                    ->required(),
                                                LocalizedDecimalInput::make('max_percentage')
                                                    ->label(__('ingredients.editor.compliance.ifra.maximum'))
                                                    ->minValue(0)
                                                    ->maxValue(100)
                                                    ->required()
                                                    ->suffix('%'),
                                            ])
                                            ->columns([
                                                'md' => 2,
                                            ])
                                            ->defaultItems(0)
                                            ->reorderable(false)
                                            ->columnSpanFull(),
                                    ]),
                            ]),
                    ]),
            ])
            ->statePath('data')
            ->disabled(! $this->canEditIngredientData())
            ->model($this->currentIngredient() ?? Ingredient::class);
    }

    public function compositionRemovalConfirmationForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Checkbox::make('confirmCompositionRemoval')
                    ->label(__('ingredients.editor.details.composition_removal_confirmation'))
                    ->live(),
            ]);
    }

    public function workspaceGuidanceForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->guidanceRichEditor('html'),
            ])
            ->statePath('workspaceGuidance');
    }

    private function guidanceRichEditor(
        string $name,
        ?string $label = null,
        ?string $helper = null,
    ): RichEditor {
        return RichEditor::make($name)
            ->label($label ?? __('ingredients.editor.workspace_guidance.heading'))
            ->helperText($helper ?? __('ingredients.editor.workspace_guidance.helper', [
                'max' => WorkspaceIngredientGuidanceService::MAX_LENGTH,
            ]))
            ->extraInputAttributes([
                'class' => 'min-h-[18rem] [&_.fi-fo-rich-editor-content]:min-h-[16rem]',
            ])
            ->toolbarButtons($this->workspaceGuidanceToolbar())
            ->linkProtocols(['http', 'https']);
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function workspaceGuidanceToolbar(): array
    {
        return [
            ['bold', 'italic', 'link'],
            ['paragraph', 'h2', 'h3'],
            ['bulletList', 'orderedList'],
            ['undo', 'redo'],
        ];
    }

    private function isCurrentPlatformIngredient(): bool
    {
        $ingredient = $this->currentIngredient();

        return $ingredient instanceof Ingredient
            && $ingredient->exists
            && $this->isPlatformIngredient($ingredient);
    }

    private function isPlatformIngredient(Ingredient $ingredient): bool
    {
        return $ingredient->owner_type === null
            && $ingredient->owner_id === null
            && $ingredient->workspace_id === null;
    }

    private function initializeReferenceState(Ingredient $ingredient, ?User $user): void
    {
        $this->data = [];
        $this->referenceData = [];
        $this->workspaceMaterialCode = null;
        $this->workspaceGuidance = ['html' => null];
        $this->isEditingWorkspaceGuidance = false;

        if (! $this->isPlatformIngredient($ingredient)) {
            return;
        }

        $workspace = $this->destinationWorkspaceForDisplay($ingredient, $user);

        if (! $workspace instanceof Workspace) {
            return;
        }

        $this->workspaceMaterialCode = app(WorkspaceIngredientCodeService::class)
            ->codeFor($workspace, $ingredient);
        $guidance = app(WorkspaceIngredientGuidanceService::class)
            ->recordFor($workspace, $ingredient);
        $this->workspaceGuidance = ['html' => $guidance?->guidance_html];
        $this->workspaceGuidanceForm->fill($this->workspaceGuidance);
    }

    /**
     * Build the only ingredient data that is assigned to public reference state.
     *
     * @return array<string, mixed>
     */
    private function referenceDataFor(
        Ingredient $ingredient,
        User $user,
        ?Workspace $workspace,
        ?WorkspaceIngredientGuidance $guidanceRecord,
    ): array {
        $ingredient->loadMissing([
            'translations',
            'identifiers',
            'aliases',
            'components.componentIngredient.translations',
            'functions',
            'sapProfile',
            'fattyAcidEntries.fattyAcid',
            'allergenEntries.allergen',
            'substanceEntries.substance',
            'ifraCertificates.ifraAmendment',
            'ifraCertificates.limits.ifraProductCategory',
            'mediaAssetUsages.mediaAsset.workspace',
        ]);

        $identityState = app(IngredientIdentitySynchronizer::class)->formState($ingredient);
        $category = $ingredient->category;
        $subcategory = $ingredient->subcategory;
        $structure = $ingredient->components->isNotEmpty() ? 'blend' : 'ingredient';
        $canSeePrivateData = $this->canSeePrivateReferenceData($ingredient, $user, $workspace);
        $canSeeTechnicalData = $canSeePrivateData || $ingredient->isPublicCatalog();
        $aliases = $canSeePrivateData ? ($identityState['aliases'] ?? []) : [];
        $additionalIdentifiers = collect($identityState['additional_identifiers'] ?? [])
            ->map(function (array $identifier): array {
                $scheme = (string) ($identifier['scheme'] ?? '');
                $schemeEnum = IngredientIdentifierScheme::tryFrom($scheme);

                return [
                    'scheme' => $scheme,
                    'label' => $schemeEnum?->label() ?? $scheme,
                    'value' => (string) ($identifier['value'] ?? ''),
                    'is_primary' => (bool) ($identifier['is_primary'] ?? false),
                ];
            })
            ->values()
            ->all();

        $functions = $ingredient->functions
            ->map(function (IngredientFunction $function): array {
                $source = $function->pivot?->source;
                $sourceValue = $source instanceof IngredientFunctionSource
                    ? $source->value
                    : (string) $source;

                return [
                    'name' => $function->localizedName(),
                    'description' => filled($function->localizedDescription())
                        ? $function->localizedDescription()
                        : null,
                    'source' => $sourceValue !== '' ? $sourceValue : null,
                ];
            })
            ->values()
            ->all();

        $components = $structure === 'blend'
            ? $ingredient->components
                ->map(function ($entry) use ($user, $canSeePrivateData): ?array {
                    $component = $entry->componentIngredient;

                    if (! $component instanceof Ingredient || ! $component->isAccessibleBy($user)) {
                        return null;
                    }

                    return [
                        'name' => $component->localizedDisplayName() ?: $component->display_name,
                        'inci_name' => $component->inci_name,
                        'percentage' => $entry->percentage_in_parent === null
                            ? null
                            : (float) $entry->percentage_in_parent,
                        'source_notes' => $canSeePrivateData ? $entry->source_notes : null,
                    ];
                })
                ->filter()
                ->values()
                ->all()
            : [];

        $allergens = $ingredient->allergenEntries
            ->map(function ($entry) use ($canSeePrivateData): array {
                return [
                    'name' => $entry->allergen?->inci_name
                        ?: $entry->allergen?->common_name_en,
                    'concentration' => $entry->concentration_percent === null
                        ? null
                        : (float) $entry->concentration_percent,
                    'source_notes' => $canSeePrivateData ? $entry->source_notes : null,
                ];
            })
            ->filter(fn (array $entry): bool => filled($entry['name']))
            ->values()
            ->all();

        $substances = $ingredient->substanceEntries
            ->map(function ($entry) use ($canSeePrivateData): array {
                return [
                    'name' => $entry->substance?->name ?: $entry->substance?->inci_name,
                    'inci_name' => $entry->substance?->inci_name,
                    'concentration' => $entry->concentration_percent === null
                        ? null
                        : (float) $entry->concentration_percent,
                    'source_notes' => $canSeePrivateData ? $entry->source_notes : null,
                ];
            })
            ->filter(fn (array $entry): bool => filled($entry['name']))
            ->values()
            ->all();

        $soap = $this->referenceSoapData($ingredient, $canSeePrivateData, $canSeeTechnicalData);
        $ifra = $this->referenceIfraData($ingredient, $canSeePrivateData, $canSeeTechnicalData);
        $guidance = $this->referenceGuidanceData($ingredient, $workspace, $canSeePrivateData, $guidanceRecord);
        $materialCode = ! $this->isPlatformIngredient($ingredient)
            && $canSeePrivateData
            && $workspace instanceof Workspace
                ? app(WorkspaceIngredientCodeService::class)->codeFor($workspace, $ingredient)
                : null;
        $documents = $ingredient->mediaAssetsForRole(MediaAssetUsageRole::IngredientDocument)
            ->filter(fn (MediaAsset $asset): bool => Gate::forUser($user)->allows('view', $asset))
            ->map(fn (MediaAsset $asset): array => [
                'public_id' => (string) $asset->public_id,
                'name' => $asset->displayName(),
                'original_filename' => $asset->original_filename,
                'type' => $asset->type instanceof MediaAssetType ? $asset->type->value : (string) $asset->type,
                'download_url' => route('media.download', $asset),
            ])
            ->values()
            ->all();
        $identity = [
            'name' => $ingredient->localizedDisplayName() ?: $ingredient->display_name,
            'inci_name' => $ingredient->inci_name,
            'cas_number' => $identityState['cas_number'] ?? null,
            'ec_number' => $identityState['ec_number'] ?? null,
            'additional_identifiers' => $additionalIdentifiers,
            'aliases' => $aliases,
        ];

        return [
            'ingredient_structure' => $structure,
            'identity' => $identity,
            'name' => $identity['name'],
            'material_code' => $materialCode,
            'inci_name' => $identity['inci_name'],
            'cas_number' => $identity['cas_number'],
            'ec_number' => $identity['ec_number'],
            'additional_identifiers' => $additionalIdentifiers,
            'aliases' => $aliases,
            'classification' => [
                'category' => $category instanceof IngredientCategory
                    ? [
                        'value' => $category->value,
                        'label' => (string) $category->getLabel(),
                        'description' => (string) $category->getDescription(),
                    ]
                    : null,
                'subcategory' => $subcategory instanceof IngredientSubcategory
                    ? [
                        'value' => $subcategory->value,
                        'label' => (string) $subcategory->getLabel(),
                        'description' => (string) $subcategory->getDescription(),
                    ]
                    : null,
                'requires_aromatic_compliance' => $ingredient->requires_aromatic_compliance,
            ],
            'functions' => $functions,
            'components' => $components,
            'composition_source_notes' => $canSeePrivateData
                ? $ingredient->composition_source_notes
                : null,
            'guidance' => $guidance,
            'documents' => $documents,
            'soap' => $soap,
            'fatty_acids' => $soap['fatty_acids'] ?? [],
            'allergens' => $allergens,
            'allergen_source_notes' => $canSeePrivateData
                ? $ingredient->allergen_source_notes
                : null,
            'substances' => $substances,
            'ifra' => $ifra,
            'notes' => $canSeePrivateData ? $ingredient->notes : null,
        ];
    }

    private function canSeePrivateReferenceData(
        Ingredient $ingredient,
        User $user,
        ?Workspace $workspace,
    ): bool {
        return $this->isPlatformIngredient($ingredient)
            || $ingredient->isOwnedBy($user)
            || $workspace instanceof Workspace;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function referenceGuidanceData(
        Ingredient $ingredient,
        ?Workspace $workspace,
        bool $canSeePrivateData,
        ?WorkspaceIngredientGuidance $override,
    ): ?array {
        $guidances = app(WorkspaceIngredientGuidanceService::class);
        $html = null;
        $source = null;

        if ($this->isPlatformIngredient($ingredient)) {
            $html = $override?->is_active
                ? $override->guidance_html
                : $guidances->platformHtml($ingredient->localizedInfoMarkdown(app()->getLocale()));
            $source = $override?->is_active ? 'workspace' : 'platform';
        } elseif ($canSeePrivateData && $workspace instanceof Workspace) {
            $html = $override?->is_active ? $override->guidance_html : null;
            $source = $html === null ? null : 'workspace';
        }

        if (! is_string($html) || trim($html) === '') {
            return null;
        }

        return [
            'html' => $html,
            'source' => $source,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function referenceSoapData(
        Ingredient $ingredient,
        bool $canSeePrivateData,
        bool $canSeeTechnicalData,
    ): ?array {
        if (! $ingredient->is_soap_saponification_trusted || ! $canSeeTechnicalData) {
            return null;
        }

        $profile = $ingredient->sapProfile;
        $koh = $profile?->koh_sap_value;

        if (! $this->isPlatformIngredient($ingredient)
            && ! is_numeric(data_get($ingredient->source_data, 'user_authoring.trusted_koh_sap_value'))) {
            return null;
        }

        if ($koh === null) {
            $koh = data_get($ingredient->source_data, 'user_authoring.trusted_koh_sap_value');
        }

        if (! is_numeric($koh)) {
            return null;
        }

        $fattyAcids = $ingredient->fattyAcidEntries
            ->map(fn ($entry): array => [
                'name' => $entry->fattyAcid?->name,
                'percentage' => $entry->percentage === null ? null : (float) $entry->percentage,
                'source_notes' => $canSeePrivateData ? $entry->source_notes : null,
            ])
            ->filter(fn (array $entry): bool => filled($entry['name']))
            ->values()
            ->all();

        return [
            'koh_sap_value' => (float) $koh,
            'naoh_sap_value' => round(SoapSap::deriveNaohFromKoh((float) $koh), 6),
            'iodine_value' => $profile?->iodine_value === null ? null : (float) $profile->iodine_value,
            'ins_value' => $profile?->ins_value === null ? null : (float) $profile->ins_value,
            'source_notes' => $canSeePrivateData ? $profile?->source_notes : null,
            'fatty_acids' => $fattyAcids,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function referenceIfraData(
        Ingredient $ingredient,
        bool $canSeePrivateData,
        bool $canSeeTechnicalData,
    ): ?array {
        if (! $ingredient->requires_aromatic_compliance || ! $canSeeTechnicalData) {
            return null;
        }

        $certificate = $ingredient->ifraCertificates
            ->filter(fn ($candidate): bool => $candidate->is_current)
            ->sortByDesc('id')
            ->first();

        if ($certificate === null) {
            return null;
        }

        $limits = $certificate->limits
            ->sortBy('ifra_product_category_id')
            ->map(fn ($limit): array => [
                'category' => $limit->ifraProductCategory?->localizedName()
                    ?: $limit->ifraProductCategory?->name,
                'code' => $limit->ifraProductCategory?->code,
                'max_percentage' => $limit->max_percentage === null
                    ? null
                    : (float) $limit->max_percentage,
                'restriction_note' => $canSeePrivateData ? $limit->restriction_note : null,
            ])
            ->values()
            ->all();

        return [
            'reference_label' => $certificate->certificate_name,
            'certificate_name' => $certificate->certificate_name,
            'amendment' => $certificate->ifraAmendment?->code,
            'source_amendment_label' => $certificate->source_amendment_label,
            'peroxide_value' => $certificate->peroxide_value === null
                ? null
                : (float) $certificate->peroxide_value,
            'source_notes' => $canSeePrivateData ? $certificate->source_notes : null,
            'limits' => $limits,
        ];
    }

    private function isReferenceViewFor(?Ingredient $ingredient, ?bool $canEditIngredientData = null): bool
    {
        if (! $ingredient instanceof Ingredient) {
            return false;
        }

        return $this->isPlatformIngredient($ingredient)
            || ! ($canEditIngredientData ?? $this->canEditIngredientData());
    }

    public function render(): View
    {
        $ingredient = $this->currentIngredient();
        $canEditIngredientData = $this->canEditIngredientData();
        $isReferenceView = $this->isReferenceViewFor($ingredient, $canEditIngredientData);
        $workspace = $ingredient instanceof Ingredient
            ? $this->destinationWorkspaceForDisplay($ingredient)
            : $this->workspaceForIngredientSettings($ingredient);
        $guidanceRecord = $isReferenceView && $ingredient instanceof Ingredient
            && $workspace instanceof Workspace
                ? app(WorkspaceIngredientGuidanceService::class)->recordFor($workspace, $ingredient)
                : null;

        if ($isReferenceView && $ingredient instanceof Ingredient) {
            $user = $this->freshAuthenticatedUser();
            $this->data = [];
            $this->referenceData = $user instanceof User
                ? $this->referenceDataFor($ingredient, $user, $workspace, $guidanceRecord)
                : [];

            if (! $this->isPlatformIngredient($ingredient)) {
                $this->workspaceMaterialCode = null;
                $this->workspaceGuidance = ['html' => null];
            }
        } else {
            $this->referenceData = [];
        }

        $ingredient?->loadMissing('allergenEntries.allergen');
        $canEditWorkspaceMaterialCode = $this->canEditWorkspaceMaterialCode();
        if ($isReferenceView && $ingredient instanceof Ingredient && $this->isPlatformIngredient($ingredient)) {
            if ($workspace instanceof Workspace) {
                if (! $canEditWorkspaceMaterialCode) {
                    $this->workspaceMaterialCode = app(WorkspaceIngredientCodeService::class)
                        ->codeFor($workspace, $ingredient);
                }
            } else {
                $this->workspaceMaterialCode = null;
                $this->workspaceGuidance = ['html' => null];
                $this->workspaceGuidanceForm->fill(['html' => null]);
                $this->isEditingWorkspaceGuidance = false;
            }
        }
        $workspaceGuidanceOverride = $ingredient instanceof Ingredient
            && $this->isPlatformIngredient($ingredient)
            && $workspace instanceof Workspace
                ? $guidanceRecord
                : null;
        $effectiveWorkspaceGuidance = $ingredient instanceof Ingredient
            && $this->isPlatformIngredient($ingredient)
            && $workspace instanceof Workspace
                ? ($this->referenceData['guidance']['html'] ?? null)
                : null;
        $identityState = $ingredient instanceof Ingredient && ! $isReferenceView
            ? app(IngredientIdentitySynchronizer::class)->formState($ingredient)
            : [
                'cas_number' => null,
                'ec_number' => null,
                'additional_identifiers' => [],
                'aliases' => [],
            ];

        return view('livewire.dashboard.ingredient-editor', [
            'ingredient' => $ingredient,
            'identityState' => $identityState,
            'canEditIngredientData' => $canEditIngredientData,
            'isReferenceView' => $isReferenceView,
            'referenceData' => $this->referenceData,
            'numberLocale' => $this->currentUser()?->number_locale,
            'hasSoapChemistry' => $this->soapChemistryAvailable(),
            'canEditWorkspaceMaterialCode' => $canEditWorkspaceMaterialCode,
            'workspaceGuidanceOverride' => $workspaceGuidanceOverride,
            'effectiveWorkspaceGuidance' => $effectiveWorkspaceGuidance,
            'canEditWorkspaceGuidance' => $canEditWorkspaceMaterialCode,
            'workspaceName' => $workspace?->name,
        ]);
    }

    private function kohSapHelperText(): string
    {
        $ingredient = $this->currentIngredient();
        $range = $ingredient instanceof Ingredient
            ? app(UserIngredientAuthoringService::class)->trustedKohSapRange($ingredient)
            : null;

        if ($range === null) {
            return __('ingredients.editor.soap.koh_helper');
        }

        $numberLocale = $this->currentUser()?->number_locale;

        return __('ingredients.editor.soap.koh_range', [
            'minimum' => NumberLocale::formatDecimal($range['minimum'], 6, $numberLocale),
            'maximum' => NumberLocale::formatDecimal($range['maximum'], 6, $numberLocale),
            'professional_minimum' => NumberLocale::formatDecimal($range['minimum'] * SoapSap::PROFESSIONAL_KOH_SAP_DIVISOR, 1, $numberLocale),
            'professional_maximum' => NumberLocale::formatDecimal($range['maximum'] * SoapSap::PROFESSIONAL_KOH_SAP_DIVISOR, 1, $numberLocale),
            'reference' => NumberLocale::formatDecimal($range['original'], 6, $numberLocale),
        ]);
    }

    private function fattyAcidHelperText(mixed $fattyAcidId): ?string
    {
        $ingredient = $this->currentIngredient();
        $range = $ingredient instanceof Ingredient
            ? app(UserIngredientAuthoringService::class)->trustedFattyAcidRange($ingredient, $fattyAcidId)
            : null;

        if ($range === null) {
            return null;
        }

        $numberLocale = $this->currentUser()?->number_locale;

        return __('ingredients.editor.soap.allowed_range', [
            'minimum' => NumberLocale::formatDecimal($range['minimum'], 1, $numberLocale),
            'maximum' => NumberLocale::formatDecimal($range['maximum'], 1, $numberLocale),
        ]);
    }

    private function derivedNaohSapDisplay(mixed $kohSapValue): string
    {
        $parsedKohSapValue = NumberLocale::parseDecimalInput($kohSapValue);

        if ($parsedKohSapValue === null) {
            return __('ingredients.editor.common.not_available');
        }

        return NumberLocale::formatDecimal(
            SoapSap::deriveNaohFromKoh($parsedKohSapValue),
            3,
            $this->currentUser()?->number_locale,
        );
    }

    private function canonicalKohSapDisplay(mixed $kohSapValue): ?string
    {
        if (blank($kohSapValue)) {
            return null;
        }

        $parsedKohSapValue = NumberLocale::parseDecimalInput($kohSapValue);

        if ($parsedKohSapValue === null) {
            return trim((string) $kohSapValue);
        }

        $formatted = number_format(SoapSap::normalizeKohSapInput($parsedKohSapValue), 6, '.', '');
        [$whole, $decimals] = explode('.', $formatted);

        $canonicalValue = $whole.'.'.str_pad(rtrim($decimals, '0'), 3, '0');

        return str_contains(NumberLocale::formatDecimal(0, 1, $this->currentUser()?->number_locale), ',')
            ? str_replace('.', ',', $canonicalValue)
            : $canonicalValue;
    }

    private function fattyAcidTotalDisplay(mixed $entries): string
    {
        $total = collect(is_array($entries) ? $entries : [])
            ->sum(fn (mixed $entry): float => $this->effectiveFattyAcidPercentage($entry));

        return NumberLocale::formatDecimal($total, 1, $this->currentUser()?->number_locale).'%';
    }

    private function effectiveFattyAcidPercentage(mixed $entry): float
    {
        if (! is_array($entry)) {
            return 0.0;
        }

        $displayed = NumberLocale::parseDecimalInput($entry['percentage'] ?? null) ?? 0.0;
        $original = NumberLocale::parseDecimalInput($entry['_original_percentage'] ?? null);

        return $original !== null && round($displayed, 1) === round($original, 1)
            ? $original
            : $displayed;
    }

    /**
     * @return array<int, string>
     */
    private function fattyAcidOptions(): array
    {
        if ($this->fattyAcidOptionsCache !== null) {
            return $this->fattyAcidOptionsCache;
        }

        $currentIngredient = $this->currentIngredient();
        $fattyAcids = FattyAcid::query()
            ->where(function (Builder $query) use ($currentIngredient): void {
                $query->where('is_active', true);

                if ($currentIngredient instanceof Ingredient) {
                    $query->orWhereIn(
                        'id',
                        $currentIngredient->fattyAcidEntries()->select('fatty_acid_id'),
                    );
                }
            })
            ->orderBy('display_order')
            ->get(['id', 'name', 'is_active']);

        $this->inactiveFattyAcidIdsCache = $fattyAcids
            ->filter(fn (FattyAcid $fattyAcid): bool => ! $fattyAcid->is_active)
            ->map(fn (FattyAcid $fattyAcid): int => (int) $fattyAcid->id)
            ->values()
            ->all();

        return $this->fattyAcidOptionsCache = $fattyAcids
            ->mapWithKeys(fn (FattyAcid $fattyAcid): array => [
                (int) $fattyAcid->id => (string) $fattyAcid->name,
            ])
            ->all();
    }

    private function isInactiveFattyAcidOption(mixed $value, mixed $currentValue): bool
    {
        $fattyAcidId = (int) $value;

        if (! in_array($fattyAcidId, $this->inactiveFattyAcidIds(), true)) {
            return false;
        }

        return $fattyAcidId !== (int) $currentValue;
    }

    /**
     * @return array<int>
     */
    private function inactiveFattyAcidIds(): array
    {
        $this->fattyAcidOptions();

        return $this->inactiveFattyAcidIdsCache;
    }

    /**
     * @return array<int, string>
     */
    private function allergenOptions(): array
    {
        return $this->allergenOptionsCache ??= Allergen::query()
            ->orderBy('inci_name')
            ->pluck('inci_name', 'id')
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function substanceOptions(): array
    {
        return $this->substanceOptionsCache ??= Substance::query()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function ifraProductCategoryOptions(): array
    {
        if ($this->ifraProductCategoryOptionsCache !== null) {
            return $this->ifraProductCategoryOptionsCache;
        }

        $currentIngredient = $this->currentIngredient();
        $categories = IfraProductCategory::query()
            ->where(function (Builder $query) use ($currentIngredient): void {
                $query->where('is_active', true);

                if ($currentIngredient instanceof Ingredient) {
                    $query->orWhereHas('certificateLimits', function (Builder $query) use ($currentIngredient): void {
                        $query->whereIn(
                            'ifra_certificate_id',
                            IfraCertificate::query()
                                ->select('id')
                                ->where('ingredient_id', $currentIngredient->id)
                                ->where('is_current', true)
                                ->latest('id')
                                ->limit(1),
                        );
                    });
                }
            })
            ->orderBy('code')
            ->get();

        $this->inactiveIfraProductCategoryIdsCache = $categories
            ->filter(fn (IfraProductCategory $category): bool => ! $category->is_active)
            ->map(fn (IfraProductCategory $category): int => (int) $category->id)
            ->values()
            ->all();

        return $this->ifraProductCategoryOptionsCache = $categories
            ->mapWithKeys(fn (IfraProductCategory $category): array => [
                (int) $category->id => $category->optionLabel(),
            ])
            ->all();
    }

    private function isInactiveIfraProductCategoryOption(mixed $value, mixed $currentValue): bool
    {
        $categoryId = (int) $value;

        if (! in_array($categoryId, $this->inactiveIfraProductCategoryIds(), true)) {
            return false;
        }

        return $categoryId !== (int) $currentValue;
    }

    /**
     * @return array<int>
     */
    private function inactiveIfraProductCategoryIds(): array
    {
        $this->ifraProductCategoryOptions();

        return $this->inactiveIfraProductCategoryIdsCache;
    }

    /**
     * @return array<int, string>
     */
    public function componentIngredientOptions(): array
    {
        $currentIngredient = $this->currentIngredient();

        return Ingredient::query()
            ->accessibleTo($this->currentUser())
            ->where('is_active', true)
            ->when($currentIngredient?->exists, fn ($query) => $query->whereKeyNot($currentIngredient?->getKey()))
            ->get()
            ->sortBy(fn (Ingredient $ingredient): string => mb_strtolower($ingredient->display_name ?? $ingredient->catalog_key))
            ->mapWithKeys(function (Ingredient $ingredient): array {
                $label = $ingredient->display_name ?? $ingredient->catalog_key;
                $inciName = $ingredient->inci_name;

                if (filled($inciName)) {
                    $label .= sprintf(' (%s)', $inciName);
                }

                return [$ingredient->id => $label];
            })
            ->all();
    }

    public function componentPercentageTotal(): float
    {
        return collect($this->data['components'] ?? [])
            ->sum(fn (mixed $row): float => is_array($row)
                ? NumberLocale::parseDecimalInput($row['percentage_in_parent'] ?? null) ?? 0.0
                : 0.0);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function mergeCustomCompositionState(array $state): array
    {
        $state['components'] = $this->data['components'] ?? [];
        $state['composition_source_notes'] = $this->data['composition_source_notes'] ?? null;

        return $state;
    }

    public function isCompositionRemovalPending(): bool
    {
        if (($this->data['ingredient_structure'] ?? null) !== 'ingredient') {
            return false;
        }

        $hasDraftComponents = collect($this->data['components'] ?? [])
            ->contains(fn (mixed $row): bool => is_array($row) && filled($row['component_ingredient_id'] ?? null));

        if ($hasDraftComponents) {
            return true;
        }

        $ingredient = $this->currentIngredient();

        if (! $ingredient instanceof Ingredient) {
            return false;
        }

        $ingredient->loadMissing('components');

        return $ingredient->components->isNotEmpty();
    }

    public function componentIngredientHelperText(mixed $ingredientId): Htmlable|string
    {
        if (! filled($ingredientId)) {
            return __('ingredients.editor.composition.picker_helper');
        }

        $ingredient = Ingredient::query()
            ->find((int) $ingredientId);

        if (! $ingredient instanceof Ingredient) {
            return __('ingredients.editor.composition.missing_component');
        }

        $parts = [];

        if (filled($ingredient->inci_name)) {
            $parts[] = __('ingredients.editor.composition.resolved_inci', [
                'inci' => e($ingredient->inci_name),
            ]);
        } else {
            $parts[] = __('ingredients.editor.composition.missing_inci');
        }

        if ($this->currentUser() instanceof User && $ingredient->isEditableBy($this->currentUser())) {
            $parts[] = sprintf(
                '<a href="%s" class="font-medium text-[var(--color-accent-strong)] underline">%s</a>',
                route('ingredients.edit', $ingredient),
                __('ingredients.editor.composition.open_ingredient'),
            );
        }

        return new HtmlString(implode(' ', $parts));
    }

    public function canEditIngredientData(): bool
    {
        $cacheKey = implode('|', [
            auth()->id() ?? 'guest',
            $this->ingredientId ?? 'new',
            $this->destinationWorkspaceId ?? 'personal',
        ]);

        if ($this->canEditIngredientDataCacheKey === $cacheKey) {
            return $this->resolvedCanEditIngredientData;
        }

        $this->canEditIngredientDataCacheKey = $cacheKey;
        $this->resolvedCanEditIngredientData = $this->resolveCanEditIngredientData();

        return $this->resolvedCanEditIngredientData;
    }

    private function resolveCanEditIngredientData(): bool
    {
        $user = $this->freshAuthenticatedUser();

        if (! $user instanceof User) {
            return false;
        }

        if ($this->ingredientId === null) {
            try {
                $destinationWorkspace = $this->authorizeDestinationWorkspace($user);
            } catch (AuthorizationException) {
                return false;
            }

            return Gate::forUser($user)->allows(
                'createInWorkspace',
                [Ingredient::class, $destinationWorkspace],
            );
        }

        $ingredient = $this->currentIngredient();

        if (! $ingredient instanceof Ingredient) {
            return false;
        }

        if ($this->isPlatformIngredient($ingredient) && ! $ingredient->is_active) {
            return false;
        }

        if (! $ingredient->isAccessibleBy($user)) {
            return false;
        }

        return Gate::forUser($user)->allows('editWorkspaceIngredient', $ingredient);
    }

    private function freshAuthenticatedUser(): ?User
    {
        if ($this->hasResolvedFreshAuthenticatedUser) {
            return $this->resolvedFreshAuthenticatedUser;
        }

        $userId = auth()->id();

        $this->resolvedFreshAuthenticatedUser = $userId === null
            ? null
            : User::query()->find($userId);
        $this->hasResolvedFreshAuthenticatedUser = true;

        return $this->resolvedFreshAuthenticatedUser;
    }

    private function refreshAuthenticatedUserContext(User $freshUser): void
    {
        $authenticatedUser = auth()->user();

        if (! $authenticatedUser instanceof User || $authenticatedUser->id !== $freshUser->id) {
            return;
        }

        $authenticatedUser->forceFill([
            'active_workspace_id' => $freshUser->active_workspace_id,
        ]);
        $authenticatedUser->forgetAccessibleWorkspaceIds();
    }

    private function authorizeIngredientWrite(User $user): Ingredient
    {
        $ingredient = $this->ingredientId === null
            ? null
            : Ingredient::query()->find($this->ingredientId);

        if (! $ingredient instanceof Ingredient
            || ($this->isPlatformIngredient($ingredient) && ! $ingredient->is_active)
            || ! $ingredient->isAccessibleBy($user)) {
            throw new AuthorizationException;
        }

        Gate::forUser($user)->authorize('editWorkspaceIngredient', $ingredient);

        return $ingredient;
    }

    /**
     * @return array{0: User, 1: Workspace, 2: Ingredient}
     */
    private function authorizePlatformWorkspaceContext(): array
    {
        $user = $this->freshAuthenticatedUser();
        $ingredient = $this->currentIngredient();

        if (! $user instanceof User
            || ! $ingredient instanceof Ingredient
            || ! $this->isPlatformIngredient($ingredient)
            || ! $ingredient->is_active) {
            throw new AuthorizationException;
        }

        $workspace = $this->authorizeDestinationWorkspace($user);

        if (! $workspace instanceof Workspace) {
            throw new AuthorizationException;
        }

        Gate::forUser($user)->authorize(
            'createInWorkspace',
            [Ingredient::class, $workspace],
        );

        return [$user, $workspace, $ingredient];
    }

    private function authorizeDestinationWorkspace(User $user): ?Workspace
    {
        $activeWorkspace = $user->company();

        if ($this->destinationWorkspaceId === null) {
            if ($activeWorkspace instanceof Workspace || $user->active_workspace_id !== null) {
                throw new AuthorizationException;
            }

            return null;
        }

        $destinationWorkspace = Workspace::withoutGlobalScopes()->find($this->destinationWorkspaceId);

        if (! $destinationWorkspace instanceof Workspace
            || ! $activeWorkspace instanceof Workspace
            || (int) $activeWorkspace->id !== (int) $destinationWorkspace->id
            || ($user->active_workspace_id !== null
                && (int) $user->active_workspace_id !== (int) $destinationWorkspace->id)) {
            throw new AuthorizationException;
        }

        return $destinationWorkspace;
    }

    private function authorizeOwningWorkspace(User $user, Ingredient $ingredient): Workspace
    {
        $workspaceId = $ingredient->workspace_id;

        if ($workspaceId === null || $this->destinationWorkspaceId !== (int) $workspaceId) {
            throw new AuthorizationException;
        }

        $workspace = Workspace::withoutGlobalScopes()->find((int) $workspaceId);

        if (! $workspace instanceof Workspace) {
            throw new AuthorizationException;
        }

        return $workspace;
    }

    private function addStaleWorkspaceError(string $field): void
    {
        $message = __('ingredients.editor.validation.stale_workspace');
        $this->addError($field, $message);
        $this->showAppNotification($message, 'error');
    }

    private function currentIngredient(): ?Ingredient
    {
        if ($this->hasResolvedCurrentIngredient
            && $this->resolvedCurrentIngredientId === $this->ingredientId) {
            return $this->resolvedCurrentIngredient;
        }

        $this->resolvedCurrentIngredientId = $this->ingredientId;
        $this->hasResolvedCurrentIngredient = true;

        if ($this->ingredientId === null) {
            return $this->resolvedCurrentIngredient = null;
        }

        $user = $this->freshAuthenticatedUser();

        if (! $user instanceof User) {
            return $this->resolvedCurrentIngredient = null;
        }

        $ingredient = Ingredient::query()->find($this->ingredientId);

        if (! $ingredient instanceof Ingredient) {
            return $this->resolvedCurrentIngredient = null;
        }

        if ($this->isPlatformIngredient($ingredient)) {
            return $this->resolvedCurrentIngredient = $ingredient->is_active ? $ingredient : null;
        }

        return $this->resolvedCurrentIngredient = $ingredient->isAccessibleBy($user) ? $ingredient : null;
    }

    private function currentUser(): ?User
    {
        return app(CurrentAppUserResolver::class)->resolve();
    }

    private function workspaceForIngredientSettings(?Ingredient $ingredient = null): ?Workspace
    {
        $user = $this->currentUser();

        if (! $user instanceof User) {
            return null;
        }

        if ($ingredient?->workspace_id !== null) {
            $workspace = Workspace::withoutGlobalScopes()->find((int) $ingredient->workspace_id);

            if ($workspace instanceof Workspace && $workspace->hasMember($user)) {
                return $workspace;
            }
        }

        return $user->company();
    }

    private function destinationWorkspaceForDisplay(
        ?Ingredient $ingredient = null,
        ?User $user = null,
    ): ?Workspace {
        $user ??= $this->freshAuthenticatedUser();

        if (! $user instanceof User || $this->destinationWorkspaceId === null) {
            return null;
        }

        $activeWorkspace = $user->company();
        $destinationWorkspace = $activeWorkspace?->id === $this->destinationWorkspaceId
            ? $activeWorkspace
            : Workspace::withoutGlobalScopes()->find($this->destinationWorkspaceId);

        if (! $destinationWorkspace instanceof Workspace || ! $destinationWorkspace->hasMember($user)) {
            return null;
        }

        if ($ingredient instanceof Ingredient && ! $this->isPlatformIngredient($ingredient)) {
            return $ingredient->workspace_id !== null
                && (int) $ingredient->workspace_id === (int) $destinationWorkspace->id
                ? $destinationWorkspace
                : null;
        }

        return $activeWorkspace instanceof Workspace
            && (int) $activeWorkspace->id === (int) $destinationWorkspace->id
                ? $destinationWorkspace
                : null;
    }

    /**
     * @return array{User, Workspace, Ingredient}|null
     */
    private function workspaceGuidanceWriteContext(): ?array
    {
        $user = $this->currentUser();
        $ingredient = $this->currentIngredient();
        $workspace = $this->workspaceForIngredientSettings($ingredient);

        if (! $user instanceof User
            || ! $ingredient instanceof Ingredient
            || ! $workspace instanceof Workspace) {
            return null;
        }

        return [$user, $workspace, $ingredient];
    }

    private function addWorkspaceGuidanceValidationErrors(ValidationException $exception): void
    {
        foreach ($exception->errors() as $messages) {
            foreach ($messages as $message) {
                $this->addError('workspaceGuidance.html', $message);
            }
        }
    }

    public function canEditWorkspaceMaterialCode(): bool
    {
        try {
            $this->authorizePlatformWorkspaceContext();
        } catch (AuthorizationException) {
            return false;
        }

        return true;
    }

    private function isEditing(): bool
    {
        return $this->ingredientId !== null;
    }

    private function validReturnSupplierPublicId(mixed $supplierPublicId): ?string
    {
        $workspaceId = $this->currentUser()?->company()?->id;

        if (! is_string($supplierPublicId) || $workspaceId === null) {
            return null;
        }

        return Supplier::query()
            ->where('workspace_id', $workspaceId)
            ->where('public_id', $supplierPublicId)
            ->value('public_id');
    }

    private function isReadOnly(): bool
    {
        return $this->ingredientId !== null && ! $this->canEditIngredientData();
    }

    private function soapChemistryAvailable(): bool
    {
        $ingredient = $this->currentIngredient();

        if (! $ingredient instanceof Ingredient || ! $ingredient->is_soap_saponification_trusted) {
            return false;
        }

        return $this->isPlatformIngredient($ingredient) || $this->hasInheritedSoapChemistry();
    }

    private function legacyIngredientTabActivePosition(): int
    {
        $targetTabIndex = match ($this->legacyIngredientTab) {
            'documents' => 3,
            'compliance' => 5,
            default => null,
        };

        if ($targetTabIndex === null) {
            return 1;
        }

        $tabVisibility = [
            1 => true,
            2 => ($this->data['ingredient_structure'] ?? 'ingredient') === 'blend',
            3 => true,
            4 => $this->soapChemistryAvailable(),
            5 => true,
        ];
        $visiblePosition = 0;

        foreach ($tabVisibility as $tabIndex => $isVisible) {
            if (! $isVisible) {
                continue;
            }

            $visiblePosition++;

            if ($tabIndex === $targetTabIndex) {
                return $visiblePosition;
            }
        }

        return 1;
    }

    private function hasInheritedSoapChemistry(): bool
    {
        $ingredient = $this->currentIngredient();

        return $ingredient instanceof Ingredient
            && $ingredient->owner_type !== null
            && $ingredient->is_soap_saponification_trusted
            && is_numeric(data_get($ingredient->source_data, 'user_authoring.trusted_koh_sap_value'));
    }

    private static function isCategory(mixed $state, IngredientCategory $expected): bool
    {
        if ($state instanceof IngredientCategory) {
            return $state === $expected;
        }

        return IngredientCategory::tryFrom((string) $state) === $expected;
    }
}
