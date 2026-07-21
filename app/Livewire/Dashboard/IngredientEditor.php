<?php

namespace App\Livewire\Dashboard;

use App\IngredientCategory;
use App\Models\Allergen;
use App\Models\FattyAcid;
use App\Models\IfraProductCategory;
use App\Models\Ingredient;
use App\Models\IngredientFunction;
use App\Models\User;
use App\Services\CurrentAppUserResolver;
use App\Services\MediaStorage;
use App\Services\UserIngredientAuthoringService;
use App\SoapSap;
use App\Support\LocalizedDecimalInput;
use App\Support\NumberLocale;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\BaseFileUpload;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
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
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class IngredientEditor extends Component implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;
    use RestrictsFileUploadsToSchemaComponents;

    #[Locked]
    public ?int $ingredientId = null;

    #[Locked]
    public string $mediaPublicId;

    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    public ?string $statusMessage = null;

    public string $statusType = 'idle';

    public string $quickComponentName = '';

    public ?string $quickComponentCategory = null;

    public function mount(?Ingredient $ingredient, UserIngredientAuthoringService $userIngredientAuthoringService): void
    {
        $this->ingredientId = $ingredient?->id;
        $this->mediaPublicId = (string) ($ingredient?->public_id ?? Str::uuid());

        $this->form->fill(
            $ingredient instanceof Ingredient
                ? $userIngredientAuthoringService->formData($ingredient)
                : $userIngredientAuthoringService->blankState(),
        );
    }

    public function save(UserIngredientAuthoringService $userIngredientAuthoringService)
    {
        $user = $this->currentUser();
        $wasEditing = $this->isEditing();

        if (! $user instanceof User) {
            $this->statusType = 'error';
            $this->statusMessage = __('ingredients.editor.status.auth_required');

            return null;
        }

        abort_if($this->isReadOnly(), 403);

        /** @var array<string, mixed> $state */
        $state = $this->mergeCustomCompositionState($this->form->getState());
        $state['public_id'] = $this->mediaPublicId;
        $currentIngredient = $this->currentIngredient();

        try {
            $ingredient = $currentIngredient instanceof Ingredient
                ? $userIngredientAuthoringService->update($currentIngredient, $state, $user)
                : $userIngredientAuthoringService->create($state, $user);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $key => $messages) {
                foreach ($messages as $message) {
                    $this->addError(str_starts_with($key, 'data.') ? $key : 'data.'.$key, $message);
                }
            }

            $this->statusType = 'error';
            $this->statusMessage = __('ingredients.editor.status.invalid');

            return null;
        }

        $this->ingredientId = $ingredient->id;
        $this->statusType = 'success';
        $this->statusMessage = $wasEditing
            ? __('ingredients.editor.status.saved')
            : __('ingredients.editor.status.created');

        $this->form->fill($userIngredientAuthoringService->formData($ingredient));

        if (! $wasEditing) {
            return redirect()->route('ingredients.edit', $ingredient);
        }

        return null;
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
                                        TextInput::make('name')
                                            ->label(__('ingredients.editor.details.name'))
                                            ->required()
                                            ->maxLength(255),
                                        Select::make('ingredient_structure')
                                            ->label(__('ingredients.editor.details.type.label'))
                                            ->options([
                                                'ingredient' => __('ingredients.editor.details.type.single'),
                                                'blend' => __('ingredients.editor.details.type.blend'),
                                            ])
                                            ->required()
                                            ->live()
                                            ->helperText(__('ingredients.editor.details.type.helper')),
                                        Select::make('category')
                                            ->label(__('ingredients.editor.details.category'))
                                            ->options(IngredientCategory::class)
                                            ->required()
                                            ->live(),
                                        TextInput::make('inci_name')
                                            ->label(__('ingredients.editor.details.inci'))
                                            ->maxLength(255)
                                            ->columnSpanFull(),
                                    ]),
                                Section::make(__('ingredients.editor.supplier.section'))
                                    ->description(__('ingredients.editor.supplier.description'))
                                    ->columns([
                                        'md' => 2,
                                    ])
                                    ->schema([
                                        TextInput::make('supplier_name')
                                            ->label(__('ingredients.editor.supplier.name'))
                                            ->maxLength(255),
                                        TextInput::make('supplier_reference')
                                            ->label(__('ingredients.editor.supplier.reference'))
                                            ->maxLength(255),
                                        TextInput::make('cas_number')
                                            ->label(__('ingredients.editor.supplier.cas_number'))
                                            ->maxLength(255)
                                            ->placeholder(__('ingredients.editor.supplier.cas_placeholder')),
                                        TextInput::make('ec_number')
                                            ->label(__('ingredients.editor.supplier.ec_number'))
                                            ->maxLength(255)
                                            ->placeholder(__('ingredients.editor.supplier.ec_placeholder')),
                                        Toggle::make('is_organic')
                                            ->label(__('ingredients.editor.supplier.organic'))
                                            ->helperText(__('ingredients.editor.supplier.organic_helper'))
                                            ->columnSpanFull(),
                                        Select::make('function_ids')
                                            ->label(__('ingredients.editor.supplier.functions'))
                                            ->multiple()
                                            ->searchable()
                                            ->preload()
                                            ->options(fn (): array => IngredientFunction::query()
                                                ->where('is_active', true)
                                                ->orderBy('sort_order')
                                                ->orderBy('name')
                                                ->pluck('name', 'id')
                                                ->all())
                                            ->helperText(__('ingredients.editor.supplier.functions_helper'))
                                            ->maxItems(10)
                                            ->columnSpanFull(),
                                    ]),
                                Section::make(__('ingredients.editor.media.section'))
                                    ->description(__('ingredients.editor.media.description'))
                                    ->columns([
                                        'md' => 2,
                                    ])
                                    ->schema([
                                        FileUpload::make('featured_image_path')
                                            ->label(__('ingredients.editor.media.image'))
                                            ->image()
                                            ->maxSize(MediaStorage::ingredientImagesMaxSize())
                                            ->acceptedFileTypes([
                                                'image/jpeg',
                                                'image/webp',
                                            ])
                                            ->disk(MediaStorage::userDisk())
                                            ->directory(fn (): string => MediaStorage::ingredientDirectoryForPublicId($this->mediaPublicId, 'featured-images'))
                                            ->visibility(MediaStorage::userVisibility())
                                            ->getUploadedFileUsing(fn (BaseFileUpload $component, string $file, string|array|null $storedFileNames): ?array => $this->privateIngredientUploadMetadata(
                                                $component,
                                                $file,
                                                $storedFileNames,
                                            ))
                                            ->deleteUploadedFileUsing(function (string $file): void {
                                                MediaStorage::deleteUserPath($file);
                                            })
                                            ->saveUploadedFileUsing(fn (BaseFileUpload $component, TemporaryUploadedFile $file): string => MediaStorage::storeUserFittedWebp(
                                                $file,
                                                (string) $component->getDirectory(),
                                                MediaStorage::ingredientImageWidth(),
                                                MediaStorage::ingredientImageHeight(),
                                                MediaStorage::ingredientImagesQuality(),
                                            ))
                                            ->imageEditor()
                                            ->imageAspectRatio('1:1')
                                            ->imageEditorAspectRatioOptions(['1:1'])
                                            ->automaticallyOpenImageEditorForAspectRatio()
                                            ->helperText(__('ingredients.editor.media.image_helper'))
                                            ->columnSpan(1),
                                        FileUpload::make('icon_image_path')
                                            ->label(__('ingredients.editor.media.icon'))
                                            ->image()
                                            ->maxSize(MediaStorage::ingredientIconsMaxSize())
                                            ->acceptedFileTypes([
                                                'image/jpeg',
                                                'image/webp',
                                            ])
                                            ->disk(MediaStorage::userDisk())
                                            ->directory(fn (): string => MediaStorage::ingredientDirectoryForPublicId($this->mediaPublicId, 'icons'))
                                            ->visibility(MediaStorage::userVisibility())
                                            ->getUploadedFileUsing(fn (BaseFileUpload $component, string $file, string|array|null $storedFileNames): ?array => $this->privateIngredientUploadMetadata(
                                                $component,
                                                $file,
                                                $storedFileNames,
                                            ))
                                            ->deleteUploadedFileUsing(function (string $file): void {
                                                MediaStorage::deleteUserPath($file);
                                            })
                                            ->saveUploadedFileUsing(fn (BaseFileUpload $component, TemporaryUploadedFile $file): string => MediaStorage::storeUserFittedWebp(
                                                $file,
                                                (string) $component->getDirectory(),
                                                MediaStorage::ingredientIconsWidth(),
                                                MediaStorage::ingredientIconsHeight(),
                                                MediaStorage::ingredientIconsQuality(),
                                            ))
                                            ->imageEditor()
                                            ->imageAspectRatio('1:1')
                                            ->imageEditorAspectRatioOptions(['1:1'])
                                            ->automaticallyOpenImageEditorForAspectRatio()
                                            ->helperText(__('ingredients.editor.media.icon_helper'))
                                            ->columnSpan(1),
                                        Textarea::make('info_markdown')
                                            ->label(__('ingredients.editor.media.notes'))
                                            ->helperText(__('ingredients.editor.media.notes_helper'))
                                            ->rows(4)
                                            ->maxLength(5000)
                                            ->columnSpanFull(),
                                    ]),
                            ]),
                        Tab::make(__('ingredients.editor.tabs.composition'))
                            ->visible(fn (Get $get): bool => $get('ingredient_structure') === 'blend')
                            ->schema([
                                SchemaView::make('livewire.dashboard.partials.ingredient-composition-rows')
                                    ->columnSpanFull(),
                            ]),
                        Tab::make(__('ingredients.editor.tabs.soap_chemistry'))
                            ->visible(fn (Get $get): bool => static::isCarrierOilCategory($get('category')))
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
                            ->visible(fn (Get $get): bool => static::isPublicAromaticCategory($get('category')))
                            ->schema([
                                Section::make(__('ingredients.editor.compliance.allergens.section'))
                                    ->description(__('ingredients.editor.compliance.allergens.description'))
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
                                Section::make(__('ingredients.editor.compliance.ifra.section'))
                                    ->description(__('ingredients.editor.compliance.ifra.description'))
                                    ->columns([
                                        'md' => 2,
                                    ])
                                    ->schema([
                                        TextInput::make('ifra.reference_label')
                                            ->label(__('ingredients.editor.compliance.ifra.reference'))
                                            ->maxLength(255),
                                        TextInput::make('ifra.ifra_amendment')
                                            ->label(__('ingredients.editor.compliance.ifra.amendment'))
                                            ->maxLength(255),
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

    public function render(): View
    {
        $ingredient = $this->currentIngredient();
        $ingredient?->loadMissing('allergenEntries.allergen');

        return view('livewire.dashboard.ingredient-editor', [
            'ingredient' => $ingredient,
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

        return __('ingredients.editor.soap.koh_range', [
            'minimum' => sprintf('%.6f', $range['minimum']),
            'maximum' => sprintf('%.6f', $range['maximum']),
            'professional_minimum' => sprintf('%.1f', $range['minimum'] * SoapSap::PROFESSIONAL_KOH_SAP_DIVISOR),
            'professional_maximum' => sprintf('%.1f', $range['maximum'] * SoapSap::PROFESSIONAL_KOH_SAP_DIVISOR),
            'reference' => sprintf('%.6f', $range['original']),
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

        return __('ingredients.editor.soap.allowed_range', [
            'minimum' => sprintf('%.1f', $range['minimum']),
            'maximum' => sprintf('%.1f', $range['maximum']),
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
            ->sortBy(fn (Ingredient $ingredient): string => mb_strtolower($ingredient->display_name ?? $ingredient->source_key))
            ->mapWithKeys(function (Ingredient $ingredient): array {
                $label = $ingredient->display_name ?? $ingredient->source_key;
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

    /**
     * @param  string|array<string, string>|null  $storedFileNames
     * @return array{name: string, size: int, type: ?string, url: ?string}|null
     */
    private function privateIngredientUploadMetadata(
        BaseFileUpload $component,
        string $file,
        string|array|null $storedFileNames,
    ): ?array {
        $ingredient = $this->currentIngredient();
        $url = $ingredient instanceof Ingredient
            ? MediaStorage::ingredientUrl($ingredient, $file)
            : null;

        if ($url === null) {
            return null;
        }

        $metadata = $component->getUploadedFile($file, $storedFileNames);

        if ($metadata === null) {
            return null;
        }

        $metadata['url'] = $url;

        return $metadata;
    }

    private function isEditing(): bool
    {
        return $this->ingredientId !== null;
    }

    private function isReadOnly(): bool
    {
        $ingredient = $this->currentIngredient();
        $user = $this->currentUser();

        return $ingredient instanceof Ingredient
            && ($ingredient->owner_type === null || ! ($user instanceof User) || ! $ingredient->isEditableBy($user));
    }

    private static function isPublicAromaticCategory(mixed $state): bool
    {
        if ($state instanceof IngredientCategory) {
            $state = $state->value;
        }

        return in_array($state, [
            IngredientCategory::EssentialOil->value,
            IngredientCategory::FragranceOil->value,
            IngredientCategory::Co2Extract->value,
        ], true);
    }

    private static function isCarrierOilCategory(mixed $state): bool
    {
        if ($state instanceof IngredientCategory) {
            return $state === IngredientCategory::CarrierOil;
        }

        return $state === IngredientCategory::CarrierOil->value;
    }
}
