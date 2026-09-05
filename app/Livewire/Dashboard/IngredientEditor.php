<?php

namespace App\Livewire\Dashboard;

use App\Data\IngredientClassificationPromptInput;
use App\Enums\IngredientCategory;
use App\Enums\IngredientSubcategory;
use App\Enums\MediaAssetType;
use App\Enums\MediaAssetUsageRole;
use App\Enums\OwnerType;
use App\Enums\WorkspaceMemberRole;
use App\Forms\Components\IngredientIdentityFields;
use App\Forms\Components\MediaAssetPicker;
use App\Livewire\Concerns\InteractsWithAppNotifications;
use App\Livewire\Concerns\InteractsWithMediaAssetPickerUploads;
use App\Models\Allergen;
use App\Models\FattyAcid;
use App\Models\IfraAmendment;
use App\Models\IfraProductCategory;
use App\Models\Ingredient;
use App\Models\IngredientFunction;
use App\Models\Substance;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Workspace;
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
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
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
use Filament\Schemas\Components\Grid;
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
use Illuminate\Support\Facades\DB;
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
    public string $mediaPublicId;

    #[Locked]
    public ?string $returnTo = null;

    #[Locked]
    public ?string $returnSupplierPublicId = null;

    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    public ?string $workspaceMaterialCode = null;

    /** @var array{html: ?string} */
    public array $workspaceGuidance = ['html' => null];

    public bool $isEditingWorkspaceGuidance = false;

    public ?string $statusMessage = null;

    public string $statusType = 'idle';

    public string $quickComponentName = '';

    public ?string $quickComponentCategory = null;

    public ?string $generatedClassificationPrompt = null;

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
        $this->mediaPublicId = (string) ($ingredient?->public_id ?? Str::uuid());

        if ($ingredient === null && request()->query('return_to') === 'supplier_listing') {
            $this->returnTo = 'supplier_listing';
            $this->returnSupplierPublicId = $this->validReturnSupplierPublicId(request()->query('supplier'));
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
        $workspace = $this->workspaceForIngredientSettings($ingredient);
        $materialCode = $ingredient instanceof Ingredient && $workspace instanceof Workspace
            ? $workspaceIngredientCodes->codeFor($workspace, $ingredient)
            : null;
        $state['material_code'] = $materialCode;
        $this->workspaceMaterialCode = $ingredient?->owner_type === null ? $materialCode : null;
        $guidance = $ingredient instanceof Ingredient
            && $workspace instanceof Workspace
                ? $workspaceIngredientGuidances->recordFor($workspace, $ingredient)
                : null;

        if ($ingredient instanceof Ingredient && $ingredient->owner_type !== null) {
            $state['guidance_html'] = $guidance?->guidance_html;
        }

        $this->workspaceGuidanceForm->fill([
            'html' => $guidance?->guidance_html,
        ]);

        $this->form->fill($state);
    }

    public function save(
        UserIngredientAuthoringService $userIngredientAuthoringService,
        MediaAssetUsageService $mediaAssetUsages,
        WorkspaceIngredientCodeService $workspaceIngredientCodes,
        WorkspaceIngredientGuidanceContent $workspaceIngredientGuidanceContent,
        WorkspaceIngredientGuidanceService $workspaceIngredientGuidances,
    ) {
        $user = $this->currentUser();
        $wasEditing = $this->isEditing();

        if (! $user instanceof User) {
            $this->showAppNotification(
                __('ingredients.editor.status.auth_required'),
                'error',
            );

            return null;
        }

        abort_if($this->isReadOnly(), 403);

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
        $currentIngredient = $this->currentIngredient();

        try {
            $ingredient = DB::transaction(function () use ($currentIngredient, $documentMediaAssetIds, $featuredMediaAssetId, $iconMediaAssetId, $mediaAssetUsages, $state, $user, $userIngredientAuthoringService, $workspaceIngredientCodes, $workspaceIngredientGuidanceContent, $workspaceIngredientGuidances, $workspaceGuidanceHtml, $workspaceMaterialCode): Ingredient {
                $ingredient = $currentIngredient instanceof Ingredient
                    ? $userIngredientAuthoringService->update($currentIngredient, $state, $user)
                    : $userIngredientAuthoringService->create($state, $user);

                $workspace = $this->workspaceForIngredientSettings($ingredient);

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
                    expectedType: MediaAssetType::Pdf,
                );

                return $ingredient;
            });
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $key => $messages) {
                foreach ($messages as $message) {
                    $this->addError(str_starts_with($key, 'data.') ? $key : 'data.'.$key, $message);
                }
            }

            $this->showAppNotification(
                __('ingredients.editor.status.invalid'),
                'error',
            );

            return null;
        }

        $this->ingredientId = $ingredient->id;
        $statusMessage = $wasEditing
            ? __('ingredients.editor.status.saved')
            : __('ingredients.editor.status.created');
        $this->showAppNotification($statusMessage);

        $refreshedState = $userIngredientAuthoringService->formData($ingredient);
        $workspace = $this->workspaceForIngredientSettings($ingredient);
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

        if (! $wasEditing) {
            session()->flash('status', $statusMessage);

            if ($this->returnTo === 'supplier_listing') {
                return redirect()->route('production-bench.purchasing.listings.create', array_filter([
                    'material_type' => 'ingredient',
                    'ingredient' => $ingredient->public_id,
                    'supplier' => $this->returnSupplierPublicId,
                ]));
            }

            return redirect()->route('ingredients.edit', $ingredient);
        }

        return null;
    }

    public function saveWorkspaceMaterialCode(WorkspaceIngredientCodeService $workspaceIngredientCodes): void
    {
        $user = $this->currentUser();
        $ingredient = $this->currentIngredient();
        $workspace = $this->workspaceForIngredientSettings($ingredient);

        if (! $user instanceof User || ! $ingredient instanceof Ingredient || $ingredient->owner_type !== null || ! $workspace instanceof Workspace) {
            $this->addError('workspaceMaterialCode', __('ingredients.editor.validation.material_code_forbidden'));

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
    }

    public function startWorkspaceGuidanceCustomization(
        WorkspaceIngredientGuidanceService $workspaceIngredientGuidances,
    ): void {
        $context = $this->workspaceGuidanceWriteContext();

        if ($context === null || ! $this->canEditWorkspaceGuidance()) {
            $this->addError(
                'workspaceGuidance.html',
                __('ingredients.editor.validation.workspace_guidance_forbidden'),
            );

            return;
        }

        [, $workspace, $ingredient] = $context;

        $this->workspaceGuidanceForm->fill([
            'html' => $workspaceIngredientGuidances->editableHtml(
                $workspace,
                $ingredient,
                app()->getLocale(),
            ),
        ]);
        $this->isEditingWorkspaceGuidance = true;
        $this->resetErrorBag('workspaceGuidance.html');
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
    }

    public function saveWorkspaceGuidance(
        WorkspaceIngredientGuidanceService $workspaceIngredientGuidances,
    ): void {
        $context = $this->workspaceGuidanceWriteContext();

        if ($context === null || ! $this->canEditWorkspaceGuidance()) {
            $this->addError(
                'workspaceGuidance.html',
                __('ingredients.editor.validation.workspace_guidance_forbidden'),
            );

            return;
        }

        [$user, $workspace, $ingredient] = $context;

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
            $this->addError(
                'workspaceGuidance.html',
                __('ingredients.editor.validation.workspace_guidance_forbidden'),
            );

            return;
        }

        $this->workspaceGuidanceForm->fill([
            'html' => $guidance->guidance_html,
        ]);
        $this->isEditingWorkspaceGuidance = false;
        $this->resetErrorBag('workspaceGuidance.html');
        $this->showAppNotification(__('ingredients.editor.workspace_guidance.saved'));
    }

    public function usePlatformGuidance(
        WorkspaceIngredientGuidanceService $workspaceIngredientGuidances,
    ): void {
        $context = $this->workspaceGuidanceWriteContext();

        if ($context === null || ! $this->canEditWorkspaceGuidance()) {
            $this->addError(
                'workspaceGuidance.html',
                __('ingredients.editor.validation.workspace_guidance_forbidden'),
            );

            return;
        }

        [$user, $workspace, $ingredient] = $context;

        try {
            $workspaceIngredientGuidances->usePlatform($user, $workspace, $ingredient);
        } catch (ValidationException $exception) {
            $this->addWorkspaceGuidanceValidationErrors($exception);

            return;
        } catch (AuthorizationException) {
            $this->addError(
                'workspaceGuidance.html',
                __('ingredients.editor.validation.workspace_guidance_forbidden'),
            );

            return;
        }

        $guidance = $workspaceIngredientGuidances->recordFor($workspace, $ingredient);
        $this->workspaceGuidanceForm->fill(['html' => $guidance?->guidance_html]);
        $this->isEditingWorkspaceGuidance = false;
        $this->resetErrorBag('workspaceGuidance.html');
        $this->showAppNotification(__('ingredients.editor.workspace_guidance.platform_selected'));
    }

    public function useWorkspaceGuidance(
        WorkspaceIngredientGuidanceService $workspaceIngredientGuidances,
    ): void {
        $context = $this->workspaceGuidanceWriteContext();

        if ($context === null || ! $this->canEditWorkspaceGuidance()) {
            $this->addError(
                'workspaceGuidance.html',
                __('ingredients.editor.validation.workspace_guidance_forbidden'),
            );

            return;
        }

        [$user, $workspace, $ingredient] = $context;

        try {
            $guidance = $workspaceIngredientGuidances->useWorkspace($user, $workspace, $ingredient);
        } catch (ValidationException $exception) {
            $this->addWorkspaceGuidanceValidationErrors($exception);

            return;
        } catch (AuthorizationException) {
            $this->addError(
                'workspaceGuidance.html',
                __('ingredients.editor.validation.workspace_guidance_forbidden'),
            );

            return;
        }

        $this->workspaceGuidanceForm->fill(['html' => $guidance->guidance_html]);
        $this->isEditingWorkspaceGuidance = false;
        $this->resetErrorBag('workspaceGuidance.html');
        $this->showAppNotification(__('ingredients.editor.workspace_guidance.workspace_selected'));
    }

    public function canEditWorkspaceGuidance(): bool
    {
        $context = $this->workspaceGuidanceWriteContext();

        if ($context === null) {
            return false;
        }

        [$user, $workspace, $ingredient] = $context;

        return $ingredient->owner_type === null
            && $ingredient->is_active
            && in_array($workspace->roleFor($user), [
                WorkspaceMemberRole::Owner,
                WorkspaceMemberRole::Admin,
                WorkspaceMemberRole::Editor,
            ], true);
    }

    public function addComponent(int $ingredientId): void
    {
        $user = $this->currentUser();

        $componentIsAccessible = $user instanceof User
            && Ingredient::query()
                ->accessibleTo($user)
                ->where('is_active', true)
                ->whereKey($ingredientId)
                ->exists();

        if (! $componentIsAccessible) {
            $this->addError('data.components', __('ingredients.editor.validation.component_unavailable'));

            return;
        }

        if (count($this->data['components'] ?? []) >= 20) {
            $this->addError('data.components', __('ingredients.editor.validation.component_limit'));

            return;
        }

        if (collect($this->data['components'] ?? [])
            ->contains(fn (mixed $row): bool => (int) ($row['component_ingredient_id'] ?? 0) === $ingredientId)) {
            $this->addError('data.components', __('ingredients.editor.validation.component_duplicate'));

            return;
        }

        $this->data['components'][] = [
            'component_ingredient_id' => $ingredientId,
            'percentage_in_parent' => null,
        ];
    }

    public function createAndAddComponent(UserIngredientAuthoringService $userIngredientAuthoringService): void
    {
        $user = $this->currentUser();

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

        $ingredient = $userIngredientAuthoringService->createInlineComponent([
            'name' => $validated['quickComponentName'],
            'category' => $validated['quickComponentCategory'],
        ], $user);

        $this->addComponent($ingredient->id);
        $this->quickComponentName = '';
        $this->quickComponentCategory = null;
        $this->dispatch(
            'component-created',
            ingredientId: $ingredient->id,
            ingredientLabel: $ingredient->display_name,
        );
    }

    public function removeComponentRow(int $index): void
    {
        unset($this->data['components'][$index]);

        $this->data['components'] = array_values($this->data['components']);
    }

    public function updatedData(mixed $value, ?string $key): void
    {
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
                    ->tabs([
                        Tab::make(__('ingredients.editor.tabs.details'))
                            ->schema([
                                Section::make(__('ingredients.editor.details.section'))
                                    ->description(__('ingredients.editor.details.description'))
                                    ->columns([
                                        'md' => 2,
                                    ])
                                    ->schema([
                                        Hidden::make('is_soap_saponification_trusted'),
                                        TextInput::make('name')
                                            ->label(__('ingredients.editor.details.name'))
                                            ->required()
                                            ->maxLength(255),
                                        TextInput::make('inci_name')
                                            ->label(__('ingredients.editor.details.inci'))
                                            ->maxLength(255),
                                        TextInput::make('material_code')
                                            ->label(__('ingredients.editor.material_code.label'))
                                            ->helperText(__('ingredients.editor.material_code.helper'))
                                            ->placeholder(__('ingredients.editor.material_code.placeholder'))
                                            ->maxLength(64)
                                            ->visible(fn (): bool => ! $this->isReadOnly()),
                                        SchemaView::make('livewire.dashboard.partials.ingredient-classification-prompt')
                                            ->visible(fn (): bool => ! $this->isReadOnly())
                                            ->columnSpanFull(),
                                        Select::make('ingredient_structure')
                                            ->label(__('ingredients.editor.details.type.label'))
                                            ->options([
                                                'ingredient' => __('ingredients.editor.details.type.single'),
                                                'blend' => __('ingredients.editor.details.type.blend'),
                                            ])
                                            ->required()
                                            ->live()
                                            ->helperText(__('ingredients.editor.details.type.helper'))
                                            ->columnSpanFull(),
                                    ]),
                                Grid::make([
                                    'default' => 1,
                                    'xl' => 2,
                                ])
                                    ->schema([
                                        Section::make(__('ingredients.editor.classification.section'))
                                            ->description(__('ingredients.editor.classification.description'))
                                            ->extraAttributes(['data-ingredient-classification-section' => true])
                                            ->schema([
                                                Select::make('category')
                                                    ->label(__('ingredients.editor.details.category'))
                                                    ->options(IngredientCategory::workspaceAuthorableOptions())
                                                    ->required()
                                                    ->rules([Rule::enum(IngredientCategory::class)])
                                                    ->live(),
                                                Select::make('subcategory')
                                                    ->label(__('ingredients.editor.details.subcategory'))
                                                    ->options(fn (Get $get): array => IngredientSubcategory::optionsFor($get('category')))
                                                    ->searchable()
                                                    ->live()
                                                    ->helperText(__('ingredients.editor.details.subcategory_helper')),
                                                Toggle::make('requires_aromatic_compliance')
                                                    ->label(__('ingredients.editor.details.aromatic_compliance'))
                                                    ->helperText(__('ingredients.editor.details.aromatic_compliance_helper'))
                                                    ->live(),
                                                TextEntry::make('inherited_soap_chemistry')
                                                    ->label(__('ingredients.editor.soap.inherited_label'))
                                                    ->state(__('ingredients.editor.soap.inherited'))
                                                    ->belowContent(__('ingredients.editor.soap.inherited_helper'))
                                                    ->visible(fn (): bool => $this->hasInheritedSoapChemistry()),
                                                TextEntry::make('verified_function_names')
                                                    ->label(__('ingredients.editor.supplier.verified_functions'))
                                                    ->formatStateUsing(fn (mixed $state): string => collect(is_array($state) ? $state : [])->implode(', '))
                                                    ->belowContent(__('ingredients.editor.supplier.verified_functions_helper'))
                                                    ->visible(fn (Get $get): bool => collect($get('verified_function_names'))->filter()->isNotEmpty()),
                                                Select::make('function_ids')
                                                    ->label(__('ingredients.editor.supplier.additional_functions'))
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
                                        Section::make(__('ingredients.editor.identity.section'))
                                            ->description(__('ingredients.editor.identity.description'))
                                            ->extraAttributes(['data-ingredient-identity-section' => true])
                                            ->columns([
                                                'md' => 2,
                                            ])
                                            ->schema([
                                                ...IngredientIdentityFields::schema(platform: false),
                                            ]),
                                    ])
                                    ->columnSpanFull(),
                            ]),
                        Tab::make(__('ingredients.editor.tabs.composition'))
                            ->visible(fn (Get $get): bool => $get('ingredient_structure') === 'blend')
                            ->schema([
                                SchemaView::make('livewire.dashboard.partials.ingredient-composition-rows')
                                    ->columnSpanFull(),
                            ]),
                        Tab::make(__('ingredients.editor.tabs.documents'))
                            ->schema([
                                Section::make(__('ingredients.editor.media.section'))
                                    ->description(__('ingredients.editor.media.description'))
                                    ->columns([
                                        'md' => 2,
                                    ])
                                    ->schema([
                                        Textarea::make('notes')
                                            ->label(__('ingredients.editor.details.notes'))
                                            ->helperText(__('ingredients.editor.details.notes_helper'))
                                            ->rows(3)
                                            ->columnSpanFull(),
                                        MediaAssetPicker::make('featured_media_asset_id')
                                            ->label(__('ingredients.editor.media.image'))
                                            ->helperText(__('ingredients.editor.media.image_helper'))
                                            ->columnSpan(1),
                                        MediaAssetPicker::make('icon_media_asset_id')
                                            ->label(__('ingredients.editor.media.icon'))
                                            ->helperText(__('ingredients.editor.media.icon_helper'))
                                            ->columnSpan(1),
                                        MediaAssetPicker::make('document_media_asset_ids')
                                            ->label(__('ingredients.editor.media.documents'))
                                            ->helperText(__('ingredients.editor.media.documents_helper'))
                                            ->documents()
                                            ->multiple()
                                            ->maxItems(8)
                                            ->columnSpanFull(),
                                        $this->guidanceRichEditor(
                                            'guidance_html',
                                            __('ingredients.editor.media.notes'),
                                            __('ingredients.editor.media.notes_helper'),
                                        )
                                            ->visible(fn (): bool => ! $this->isCurrentPlatformIngredient())
                                            ->columnSpanFull(),
                                    ]),
                            ]),
                        Tab::make(__('ingredients.editor.tabs.soap_chemistry'))
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
                                                ->belowContent(__('ingredients.editor.soap.recommended_total')),
                                        ])
                                            ->extraAttributes([
                                                'class' => 'rounded-xl border border-[var(--color-line)] bg-[var(--color-field-muted)] px-5 py-4',
                                            ])
                                            ->columnSpanFull(),
                                        Repeater::make('fatty_acid_entries')
                                            ->label(__('ingredients.editor.soap.fatty_acid_profile'))
                                            ->schema([
                                                Hidden::make('_original_percentage'),
                                                Select::make('fatty_acid_id')
                                                    ->label(__('ingredients.editor.soap.fatty_acid'))
                                                    ->options(fn (): array => FattyAcid::query()
                                                        ->where('is_active', true)
                                                        ->orderBy('display_order')
                                                        ->pluck('name', 'id')
                                                        ->all())
                                                    ->searchable()
                                                    ->preload()
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
                            ->schema([
                                Section::make(__('ingredients.editor.compliance.allergens.section'))
                                    ->description(__('ingredients.editor.compliance.allergens.description'))
                                    ->visible(fn (Get $get): bool => (bool) $get('requires_aromatic_compliance'))
                                    ->schema([
                                        Repeater::make('allergen_entries')
                                            ->label(__('ingredients.editor.compliance.allergens.composition'))
                                            ->schema([
                                                Select::make('allergen_id')
                                                    ->label(__('ingredients.editor.compliance.allergens.allergen'))
                                                    ->options(fn (): array => Allergen::query()
                                                        ->orderBy('inci_name')
                                                        ->pluck('inci_name', 'id')
                                                        ->all())
                                                    ->searchable()
                                                    ->preload()
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
                                            ->schema([
                                                Select::make('substance_id')
                                                    ->label(__('ingredients.editor.compliance.substances.substance'))
                                                    ->options(fn (): array => Substance::query()
                                                        ->orderBy('name')
                                                        ->pluck('name', 'id')
                                                        ->all())
                                                    ->searchable()
                                                    ->preload()
                                                    ->required(),
                                                LocalizedDecimalInput::make('concentration_percent')
                                                    ->label(__('ingredients.editor.compliance.substances.concentration'))
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
                                            ->suffix('meq O2/kg'),
                                        Textarea::make('ifra.source_notes')
                                            ->label(__('ingredients.editor.compliance.ifra.notes'))
                                            ->rows(3)
                                            ->columnSpanFull(),
                                        Repeater::make('ifra.limits')
                                            ->label(__('ingredients.editor.compliance.ifra.limits'))
                                            ->schema([
                                                Select::make('ifra_product_category_id')
                                                    ->label(__('ingredients.editor.compliance.ifra.category'))
                                                    ->options(fn (): array => IfraProductCategory::query()
                                                        ->where('is_active', true)
                                                        ->orderBy('code')
                                                        ->get()
                                                        ->mapWithKeys(fn (IfraProductCategory $category): array => [
                                                            $category->id => $category->optionLabel(),
                                                        ])
                                                        ->all())
                                                    ->searchable()
                                                    ->preload()
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
                                            ->columnSpanFull(),
                                    ]),
                            ]),
                    ]),
            ])
            ->statePath('data')
            ->disabled($this->isReadOnly())
            ->model($this->currentIngredient() ?? Ingredient::class);
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
        return $this->currentIngredient()?->owner_type === null
            && $this->currentIngredient()?->exists === true;
    }

    public function render(): View
    {
        $ingredient = $this->currentIngredient();
        $ingredient?->loadMissing('allergenEntries.allergen');
        $workspace = $this->workspaceForIngredientSettings($ingredient);
        $workspaceGuidanceOverride = $ingredient instanceof Ingredient
            && $ingredient->owner_type === null
            && $workspace instanceof Workspace
                ? app(WorkspaceIngredientGuidanceService::class)->recordFor($workspace, $ingredient)
                : null;
        $effectiveWorkspaceGuidance = $ingredient instanceof Ingredient
            && $ingredient->owner_type === null
            && $workspace instanceof Workspace
                ? app(WorkspaceIngredientGuidanceService::class)->effectiveHtml(
                    $workspace,
                    $ingredient,
                    app()->getLocale(),
                )
                : null;
        $identityState = $ingredient instanceof Ingredient
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
            'hasSoapChemistry' => $this->soapChemistryAvailable(),
            'canEditWorkspaceMaterialCode' => $this->canEditWorkspaceMaterialCode(),
            'workspaceGuidanceOverride' => $workspaceGuidanceOverride,
            'effectiveWorkspaceGuidance' => $effectiveWorkspaceGuidance,
            'canEditWorkspaceGuidance' => $this->canEditWorkspaceGuidance(),
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

        return number_format(SoapSap::deriveNaohFromKoh($parsedKohSapValue), 6, '.', '');
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

        return number_format($total, 1, '.', '').'%';
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

    private function currentIngredient(): ?Ingredient
    {
        if ($this->ingredientId === null) {
            return null;
        }

        $user = $this->currentUser();

        if (! $user instanceof User) {
            return null;
        }

        $ingredient = Ingredient::query()->find($this->ingredientId);

        if (! $ingredient instanceof Ingredient) {
            return null;
        }

        if ($ingredient->owner_type === null) {
            return $ingredient->is_active ? $ingredient : null;
        }

        return $ingredient->isAccessibleBy($user) ? $ingredient : null;
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
        $ingredient = $this->currentIngredient();
        $workspace = $this->workspaceForIngredientSettings($ingredient);
        $user = $this->currentUser();

        return $ingredient instanceof Ingredient
            && $ingredient->owner_type === null
            && $ingredient->is_active
            && $workspace instanceof Workspace
            && $user instanceof User
            && in_array($workspace->roleFor($user), [
                WorkspaceMemberRole::Owner,
                WorkspaceMemberRole::Admin,
                WorkspaceMemberRole::Editor,
            ], true);
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
        $ingredient = $this->currentIngredient();
        $user = $this->currentUser();

        return $ingredient instanceof Ingredient
            && ($ingredient->owner_type === null || ! ($user instanceof User) || ! $ingredient->isEditableBy($user));
    }

    private function soapChemistryAvailable(): bool
    {
        $ingredient = $this->currentIngredient();

        if (! $ingredient instanceof Ingredient || ! $ingredient->is_soap_saponification_trusted) {
            return false;
        }

        return $ingredient->owner_type === null || $this->hasInheritedSoapChemistry();
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
