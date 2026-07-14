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
            $this->statusMessage = 'You need to be signed in before personal ingredients can be saved.';

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
            $this->statusMessage = 'The ingredient was not saved. Review the highlighted chemistry values.';

            return null;
        }

        $this->ingredientId = $ingredient->id;
        $this->statusType = 'success';
        $this->statusMessage = $wasEditing
            ? 'Ingredient saved.'
            : 'Ingredient created. You can now enrich it with components or compliance data.';

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
            $this->addError('data.components', 'This ingredient is no longer available to add.');

            return;
        }

        if (count($this->data['components'] ?? []) >= 20) {
            $this->addError('data.components', 'A blend can contain at most 20 components.');

            return;
        }

        if (collect($this->data['components'] ?? [])
            ->contains(fn (mixed $row): bool => (int) ($row['component_ingredient_id'] ?? 0) === $ingredientId)) {
            $this->addError('data.components', 'That ingredient is already part of this blend.');

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
            $this->addError('quickComponentName', 'Sign in before creating an ingredient.');

            return;
        }

        if (count($this->data['components'] ?? []) >= 20) {
            $this->addError('data.components', 'A blend can contain at most 20 components.');

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
            $this->addError($field, 'Each component share must be between 0% and 100%.');
        }
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('Ingredient editor')
                    ->contained(false)
                    ->persistTabInQueryString('ingredient-tab')
                    ->tabs([
                        Tab::make('Identity')
                            ->schema([
                                Section::make('Identity')
                                    ->description($this->isEditing()
                                        ? 'Keep the current material identity, supplier reference, identifiers, media, and notes here.'
                                        : 'Create the ingredient with the essential data first. Composition and compliance can be added after the first save.')
                                    ->columns([
                                        'md' => 2,
                                    ])
                                    ->schema([
                                        TextInput::make('name')
                                            ->label('Name')
                                            ->required()
                                            ->maxLength(255),
                                        Select::make('ingredient_structure')
                                            ->label('Catalog item type')
                                            ->options([
                                                'ingredient' => 'Ingredient',
                                                'blend' => 'Blend / composite',
                                            ])
                                            ->required()
                                            ->live()
                                            ->helperText('Choose Blend / composite only when this catalog item is made from other ingredients.'),
                                        Select::make('category')
                                            ->options(IngredientCategory::class)
                                            ->required()
                                            ->live(),
                                        TextInput::make('inci_name')
                                            ->label('INCI')
                                            ->maxLength(255)
                                            ->columnSpanFull(),
                                        TextInput::make('supplier_name')
                                            ->label('Supplier')
                                            ->maxLength(255),
                                        TextInput::make('supplier_reference')
                                            ->label('Supplier reference')
                                            ->maxLength(255),
                                        TextInput::make('cas_number')
                                            ->label('CAS number')
                                            ->maxLength(255)
                                            ->placeholder('e.g. 8007-02-1'),
                                        TextInput::make('ec_number')
                                            ->label('EINECS / EC number')
                                            ->maxLength(255)
                                            ->placeholder('e.g. 232-274-1'),
                                        Toggle::make('is_organic')
                                            ->label('Organic')
                                            ->helperText('Use this when the supplied ingredient is certified or sold as organic.')
                                            ->columnSpanFull(),
                                        FileUpload::make('featured_image_path')
                                            ->label('Ingredient image')
                                            ->image()
                                            ->maxSize(MediaStorage::ingredientImagesMaxSize())
                                            ->acceptedFileTypes([
                                                'image/jpeg',
                                                'image/webp',
                                            ])
                                            ->disk(MediaStorage::userDisk())
                                            ->directory(fn (): string => MediaStorage::ingredientDirectoryForPublicId($this->mediaPublicId, 'featured-images'))
                                            ->visibility(MediaStorage::userVisibility())
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
                                            ->helperText('Optional square image for the ingredient sheet and larger cards.')
                                            ->columnSpan(1),
                                        FileUpload::make('icon_image_path')
                                            ->label('Ingredient icon')
                                            ->image()
                                            ->maxSize(MediaStorage::ingredientIconsMaxSize())
                                            ->acceptedFileTypes([
                                                'image/jpeg',
                                                'image/webp',
                                            ])
                                            ->disk(MediaStorage::userDisk())
                                            ->directory(fn (): string => MediaStorage::ingredientDirectoryForPublicId($this->mediaPublicId, 'icons'))
                                            ->visibility(MediaStorage::userVisibility())
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
                                            ->helperText('Optional 96x96 icon for compact selectors. If empty, the main image is used.')
                                            ->columnSpan(1),
                                        Textarea::make('info_markdown')
                                            ->label('Notes and formulation info')
                                            ->rows(4)
                                            ->maxLength(5000)
                                            ->columnSpanFull(),
                                        Select::make('function_ids')
                                            ->label('EU / COSING functions')
                                            ->multiple()
                                            ->searchable()
                                            ->preload()
                                            ->options(fn (): array => IngredientFunction::query()
                                                ->where('is_active', true)
                                                ->orderBy('sort_order')
                                                ->orderBy('name')
                                                ->pluck('name', 'id')
                                                ->all())
                                            ->helperText('Optional official functions for this ingredient. One ingredient can carry several COSING functions.')
                                            ->maxItems(10)
                                            ->columnSpanFull(),
                                    ]),
                            ]),
                        Tab::make('Composition')
                            ->visible(fn (Get $get): bool => $get('ingredient_structure') === 'blend')
                            ->schema([
                                SchemaView::make('livewire.dashboard.partials.ingredient-composition-rows')
                                    ->columnSpanFull(),
                            ]),
                        Tab::make('Soap Chemistry')
                            ->visible(fn (Get $get): bool => static::isCarrierOilCategory($get('category')))
                            ->schema([
                                Section::make('SAP profile')
                                    ->description('Keep the KOH SAP value, optional iodine and INS references, and fatty-acid profile for soap calculation.')
                                    ->columns([
                                        'md' => 2,
                                    ])
                                    ->schema([
                                        LocalizedDecimalInput::make('sap_profile.koh_sap_value')
                                            ->label('KOH SAP')
                                            ->live(onBlur: true)
                                            ->afterStateUpdated(fn (LocalizedDecimalInput $component, mixed $state): mixed => $component->state(
                                                $this->canonicalKohSapDisplay($state),
                                            ))
                                            ->helperText(fn (): string => $this->kohSapHelperText()),
                                        Group::make([
                                            TextEntry::make('sap_profile.naoh_sap_value')
                                                ->label('NaOH SAP')
                                                ->state(fn (Get $get): string => $this->derivedNaohSapDisplay($get('sap_profile.koh_sap_value')))
                                                ->size('lg')
                                                ->weight('semibold')
                                                ->extraAttributes(['class' => 'numeric'])
                                                ->belowContent('Calculated automatically from the KOH SAP value.'),
                                        ])
                                            ->extraAttributes([
                                                'class' => 'rounded-xl border border-[var(--color-line)] bg-[var(--color-field-muted)] px-5 py-4',
                                            ]),
                                        LocalizedDecimalInput::make('sap_profile.iodine_value')
                                            ->label('Iodine value'),
                                        LocalizedDecimalInput::make('sap_profile.ins_value')
                                            ->label('INS'),
                                        Textarea::make('sap_profile.source_notes')
                                            ->label('Soap notes')
                                            ->rows(3)
                                            ->columnSpanFull(),
                                        Group::make([
                                            TextEntry::make('fatty_acid_total')
                                                ->label('Fatty acid total')
                                                ->state(fn (Get $get): string => $this->fattyAcidTotalDisplay($get('fatty_acid_entries')))
                                                ->size('lg')
                                                ->weight('semibold')
                                                ->extraAttributes(['class' => 'numeric'])
                                                ->belowContent('Target: 80% to 100%'),
                                        ])
                                            ->extraAttributes([
                                                'class' => 'rounded-xl border border-[var(--color-line)] bg-[var(--color-field-muted)] px-5 py-4',
                                            ])
                                            ->columnSpanFull(),
                                        Repeater::make('fatty_acid_entries')
                                            ->label('Fatty acid profile')
                                            ->schema([
                                                Hidden::make('_original_percentage'),
                                                Select::make('fatty_acid_id')
                                                    ->label('Fatty acid')
                                                    ->options(fn (): array => FattyAcid::query()
                                                        ->where('is_active', true)
                                                        ->orderBy('display_order')
                                                        ->pluck('name', 'id')
                                                        ->all())
                                                    ->searchable()
                                                    ->preload()
                                                    ->required(),
                                                LocalizedDecimalInput::make('percentage')
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
                        Tab::make('Compliance')
                            ->visible(fn (Get $get): bool => static::isPublicAromaticCategory($get('category')))
                            ->schema([
                                Section::make('Allergens')
                                    ->description('Optional allergen declaration for aromatic ingredients.')
                                    ->schema([
                                        Repeater::make('allergen_entries')
                                            ->label('Allergen composition')
                                            ->schema([
                                                Select::make('allergen_id')
                                                    ->label('Allergen')
                                                    ->options(fn (): array => Allergen::query()
                                                        ->orderBy('inci_name')
                                                        ->pluck('inci_name', 'id')
                                                        ->all())
                                                    ->searchable()
                                                    ->preload()
                                                    ->required(),
                                                LocalizedDecimalInput::make('concentration_percent')
                                                    ->label('Concentration')
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
                                            ->label('Allergen declaration source')
                                            ->helperText('One source for the whole allergen declaration, e.g. IFRA or SDS allergen statement.')
                                            ->rows(2)
                                            ->columnSpanFull(),
                                    ]),
                                Section::make('IFRA guidance')
                                    ->description('Keep the current IFRA reference and the category limits together here.')
                                    ->columns([
                                        'md' => 2,
                                    ])
                                    ->schema([
                                        TextInput::make('ifra.reference_label')
                                            ->label('Reference label')
                                            ->maxLength(255),
                                        TextInput::make('ifra.ifra_amendment')
                                            ->label('IFRA amendment')
                                            ->maxLength(255),
                                        LocalizedDecimalInput::make('ifra.peroxide_value')
                                            ->label('Peroxide value')
                                            ->minValue(0)
                                            ->suffix('meq O2/kg'),
                                        Textarea::make('ifra.source_notes')
                                            ->label('Notes')
                                            ->rows(3)
                                            ->columnSpanFull(),
                                        Repeater::make('ifra.limits')
                                            ->label('IFRA category limits')
                                            ->schema([
                                                Select::make('ifra_product_category_id')
                                                    ->label('IFRA category')
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
                                                    ->label('Max concentration')
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
            return 'Enter professional-style KOH SAP like 245 or decimal-style 0.245. NaOH SAP is derived automatically.';
        }

        return sprintf(
            'Allowed KOH SAP range: %.6f–%.6f (%.1f–%.1f in professional notation). Platform reference: %.6f.',
            $range['minimum'],
            $range['maximum'],
            $range['minimum'] * SoapSap::PROFESSIONAL_KOH_SAP_DIVISOR,
            $range['maximum'] * SoapSap::PROFESSIONAL_KOH_SAP_DIVISOR,
            $range['original'],
        );
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

        return sprintf('Allowed: %.1f%%–%.1f%%.', $range['minimum'], $range['maximum']);
    }

    private function derivedNaohSapDisplay(mixed $kohSapValue): string
    {
        $parsedKohSapValue = NumberLocale::parseDecimalInput($kohSapValue);

        if ($parsedKohSapValue === null) {
            return 'Not available';
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
            return 'Select an existing ingredient, or create the missing one from this picker.';
        }

        $ingredient = Ingredient::query()
            ->find((int) $ingredientId);

        if (! $ingredient instanceof Ingredient) {
            return 'This linked component could not be found anymore.';
        }

        $parts = [];

        if (filled($ingredient->inci_name)) {
            $parts[] = sprintf('Resolved INCI: %s.', e($ingredient->inci_name));
        } else {
            $parts[] = 'This linked component does not yet have an INCI name.';
        }

        if ($this->currentUser() instanceof User && $ingredient->isOwnedBy($this->currentUser())) {
            $parts[] = sprintf(
                '<a href="%s" class="font-medium text-[var(--color-accent-strong)] underline">Open ingredient</a>',
                route('ingredients.edit', $ingredient),
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

        return Ingredient::query()
            ->where(function ($query) use ($user): void {
                $query->ownedByUser($user)
                    ->orWhere(function ($platformQuery): void {
                        $platformQuery
                            ->whereNull('owner_type')
                            ->where('is_active', true);
                    });
            })
            ->find($this->ingredientId);
    }

    private function currentUser(): ?User
    {
        return app(CurrentAppUserResolver::class)->resolve();
    }

    private function isEditing(): bool
    {
        return $this->ingredientId !== null;
    }

    private function isReadOnly(): bool
    {
        $ingredient = $this->currentIngredient();

        return $ingredient instanceof Ingredient && $ingredient->owner_type === null;
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
