<?php

namespace App\Livewire\Dashboard;

use App\IngredientCategory;
use App\SoapSap;
use App\Models\Allergen;
use App\Models\FattyAcid;
use App\Models\IfraProductCategory;
use App\Models\Ingredient;
use App\Models\IngredientFunction;
use App\Models\User;
use App\Services\CurrentAppUserResolver;
use App\Services\MediaStorage;
use App\Services\UserIngredientAuthoringService;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\BaseFileUpload;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class IngredientEditor extends Component implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;

    #[Locked]
    public ?int $actorUserId = null;

    public ?int $ingredientId = null;

    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    public ?string $statusMessage = null;

    public string $statusType = 'idle';

    public function mount(?Ingredient $ingredient, UserIngredientAuthoringService $userIngredientAuthoringService): void
    {
        $this->actorUserId = $this->currentUser()?->id;
        $this->ingredientId = $ingredient?->id;

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

        /** @var array<string, mixed> $state */
        $state = $this->form->getState();
        $currentIngredient = $this->currentIngredient();

        try {
            $ingredient = $currentIngredient instanceof Ingredient
                ? $userIngredientAuthoringService->update($currentIngredient, $state, $user)
                : $userIngredientAuthoringService->create($state, $user);
        } catch (ValidationException $exception) {
            throw $exception;
        }

        $this->ingredientId = $ingredient->id;
        $this->statusType = 'success';
        $this->statusMessage = $wasEditing
            ? 'Ingredient saved.'
            : 'Ingredient created. You can now enrich it with components or compliance data.';

        $this->form->fill($userIngredientAuthoringService->formData($ingredient));

        if (! $wasEditing) {
            return redirect()->route('ingredients.edit', $ingredient->id);
        }

        return null;
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
                                            ->placeholder('e.g. 8007-02-1')
                                            ->regex('/^[0-9]{2,7}-[0-9]{2}-[0-9]$/'),
                                        TextInput::make('ec_number')
                                            ->label('EINECS / EC number')
                                            ->maxLength(255)
                                            ->placeholder('e.g. 232-274-1')
                                            ->regex('/^[0-9]{3}-[0-9]{3}-[0-9]$/'),
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
                                            ->disk(MediaStorage::publicDisk())
                                            ->directory('ingredients/featured-images')
                                            ->visibility(MediaStorage::publicVisibility())
                                            ->deleteUploadedFileUsing(function (string $file): void {
                                                MediaStorage::deletePublicPath($file);
                                            })
                                            ->saveUploadedFileUsing(fn (BaseFileUpload $component, TemporaryUploadedFile $file): string => MediaStorage::storeFittedWebp(
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
                                            ->disk(MediaStorage::publicDisk())
                                            ->directory('ingredients/icons')
                                            ->visibility(MediaStorage::publicVisibility())
                                            ->deleteUploadedFileUsing(function (string $file): void {
                                                MediaStorage::deletePublicPath($file);
                                            })
                                            ->saveUploadedFileUsing(fn (BaseFileUpload $component, TemporaryUploadedFile $file): string => MediaStorage::storeFittedWebp(
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
                            ->visible(fn (): bool => $this->isEditing())
                            ->schema([
                                Section::make('Composition')
                                    ->description('Use this when the raw material is a blend, macerate, soap base, or any other composite ingredient.')
                                    ->schema([
                                        Repeater::make('components')
                                            ->label('Ingredient components')
                                            ->schema([
                                                Select::make('component_ingredient_id')
                                                    ->label('Component ingredient')
                                                    ->options(fn (): array => $this->componentIngredientOptions())
                                                    ->searchable()
                                                    ->preload()
                                                    ->required()
                                                    ->helperText(fn (Get $get): Htmlable|string => $this->componentIngredientHelperText($get('component_ingredient_id')))
                                                    ->createOptionForm([
                                                        TextInput::make('name')
                                                            ->label('Name')
                                                            ->required()
                                                            ->maxLength(255),
                                                        Select::make('category')
                                                            ->options(IngredientCategory::class)
                                                            ->required(),
                                                        TextInput::make('inci_name')
                                                            ->label('INCI')
                                                            ->maxLength(255),
                                                        TextInput::make('supplier_name')
                                                            ->label('Supplier')
                                                            ->maxLength(255),
                                                        TextInput::make('supplier_reference')
                                                            ->label('Supplier reference')
                                                            ->maxLength(255),
                                                    ])
                                                    ->createOptionUsing(fn (array $data): int => $this->createInlineComponent($data)),
                                                TextInput::make('percentage_in_parent')
                                                    ->label('Share in parent')
                                                    ->numeric()
                                                    ->inputMode('decimal')
                                                    ->suffix('%')
                                                    ->minValue(0)
                                                    ->maxValue(100)
                                                    ->required(),
                                                Textarea::make('source_notes')
                                                    ->rows(2)
                                                    ->columnSpanFull(),
                                            ])
                                            ->columns([
                                                'md' => 2,
                                            ])
                                            ->helperText('Each component must be a real ingredient record. Total percentages must equal 100%.')
                                            ->defaultItems(0)
                                            ->maxItems(20)
                                            ->columnSpanFull(),
                                    ]),
                            ]),
                        Tab::make('Soap Chemistry')
                            ->visible(fn (Get $get): bool => $this->isEditing() && static::isCarrierOilCategory($get('category')))
                            ->schema([
                                Section::make('SAP profile')
                                    ->description('Keep the KOH SAP value, optional iodine and INS references, and fatty-acid profile for soap calculation.')
                                    ->columns([
                                        'md' => 2,
                                    ])
                                    ->schema([
                                        TextInput::make('sap_profile.koh_sap_value')
                                            ->label('KOH SAP')
                                            ->numeric()
                                            ->inputMode('decimal')
                                            ->live(onBlur: true)
                                            ->helperText('Enter professional-style KOH SAP like 245 or decimal-style 0.245. NaOH SAP is derived automatically.'),
                                        \Filament\Infolists\Components\TextEntry::make('sap_profile.naoh_sap_value')
                                            ->label('Derived NaOH SAP')
                                            ->state(fn (Get $get): ?string => blank($get('sap_profile.koh_sap_value')) ? null : number_format(SoapSap::deriveNaohFromKoh((float) $get('sap_profile.koh_sap_value')), 6, '.', '')),
                                        TextInput::make('sap_profile.iodine_value')
                                            ->label('Iodine value')
                                            ->numeric()
                                            ->inputMode('decimal'),
                                        TextInput::make('sap_profile.ins_value')
                                            ->label('INS')
                                            ->numeric()
                                            ->inputMode('decimal'),
                                        Textarea::make('sap_profile.source_notes')
                                            ->label('Soap notes')
                                            ->rows(3)
                                            ->columnSpanFull(),
                                        Repeater::make('fatty_acid_entries')
                                            ->label('Fatty acid profile')
                                            ->schema([
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
                                                TextInput::make('percentage')
                                                    ->numeric()
                                                    ->inputMode('decimal')
                                                    ->suffix('%')
                                                    ->minValue(0)
                                                    ->maxValue(100)
                                                    ->required(),
                                                Textarea::make('source_notes')
                                                    ->rows(2)
                                                    ->columnSpanFull(),
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
                            ->visible(fn (Get $get): bool => $this->isEditing() && static::isPublicAromaticCategory($get('category')))
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
                                                TextInput::make('concentration_percent')
                                                    ->label('Concentration')
                                                    ->numeric()
                                                    ->inputMode('decimal')
                                                    ->suffix('%')
                                                    ->minValue(0)
                                                    ->maxValue(100)
                                                    ->required(),
                                                Textarea::make('source_notes')
                                                    ->rows(2)
                                                    ->columnSpanFull(),
                                            ])
                                            ->columns([
                                                'md' => 2,
                                            ])
                                            ->defaultItems(0)
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
                                        TextInput::make('ifra.peroxide_value')
                                            ->label('Peroxide value')
                                            ->numeric()
                                            ->inputMode('decimal')
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
                                                TextInput::make('max_percentage')
                                                    ->label('Max concentration')
                                                    ->numeric()
                                                    ->inputMode('decimal')
                                                    ->minValue(0)
                                                    ->maxValue(100)
                                                    ->required()
                                                    ->suffix('%'),
                                                Textarea::make('restriction_note')
                                                    ->rows(2)
                                                    ->columnSpanFull(),
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
            ->model($this->currentIngredient() ?? Ingredient::class);
    }

    public function render(): View
    {
        return view('livewire.dashboard.ingredient-editor', [
            'ingredient' => $this->currentIngredient(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createInlineComponent(array $data): int
    {
        $user = $this->currentUser();

        if (! $user instanceof User) {
            throw ValidationException::withMessages([
                'components' => 'You need to be signed in before new component ingredients can be created.',
            ]);
        }

        $ingredient = app(UserIngredientAuthoringService::class)->createInlineComponent($data, $user);

        return $ingredient->id;
    }

    /**
     * @return array<int, string>
     */
    private function componentIngredientOptions(): array
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

    private function componentIngredientHelperText(mixed $ingredientId): Htmlable|string
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
                route('ingredients.edit', $ingredient->id),
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
            ->ownedByUser($user)
            ->find($this->ingredientId);
    }

    private function currentUser(): ?User
    {
        return app(CurrentAppUserResolver::class)->resolve($this->actorUserId);
    }

    private function isEditing(): bool
    {
        return $this->ingredientId !== null;
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
