<?php

namespace App\Filament\Resources\Ingredients\Schemas;

use App\Enums\IngredientCategory;
use App\Enums\IngredientSubcategory;
use App\Filament\Resources\Ingredients\Pages\CreateIngredient;
use App\Filament\Resources\Ingredients\Pages\EditIngredient;
use App\Forms\Components\IngredientIdentityFields;
use App\Models\Allergen;
use App\Models\FattyAcid;
use App\Models\Ingredient;
use App\Models\IngredientFunction;
use App\Models\Substance;
use App\Models\SupportedLocale;
use App\Services\MediaStorage;
use App\SoapSap;
use App\Support\FilamentUploadMetadata;
use Closure;
use Filament\Forms\Components\BaseFileUpload;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\Rule;
use Livewire\Component as LivewireComponent;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class IngredientForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('ingredients.editor.admin.classification.section'))
                    ->description(__('ingredients.editor.admin.classification.description'))
                    ->icon(Heroicon::Squares2x2)
                    ->schema([
                        Select::make('category')
                            ->label(__('ingredients.editor.admin.classification.category'))
                            ->options(IngredientCategory::options())
                            ->searchable()
                            ->helperText(__('ingredients.editor.admin.classification.category_helper'))
                            ->live()
                            ->required()
                            ->rules([Rule::enum(IngredientCategory::class)])
                            ->columnSpanFull(),
                        Select::make('subcategory')
                            ->label(__('ingredients.editor.details.subcategory'))
                            ->options(fn (Get $get): array => IngredientSubcategory::optionsFor($get('category')))
                            ->searchable()
                            ->live()
                            ->required(fn (Get $get): bool => ! static::isCategory($get('category'), IngredientCategory::Other))
                            ->helperText(__('ingredients.editor.admin.classification.subcategory_helper'))
                            ->columnSpanFull(),
                        Toggle::make('is_soap_saponification_trusted')
                            ->label(__('ingredients.editor.details.soap_trusted'))
                            ->helperText(__('ingredients.editor.admin.classification.soap_trusted_helper'))
                            ->default(false),
                        Toggle::make('requires_aromatic_compliance')
                            ->label(__('ingredients.editor.details.aromatic_compliance'))
                            ->helperText(__('ingredients.editor.admin.classification.aromatic_compliance_helper'))
                            ->default(false),
                        Toggle::make('requires_admin_review')
                            ->label(__('ingredients.editor.admin.classification.needs_review'))
                            ->helperText(__('ingredients.editor.admin.classification.needs_review_helper'))
                            ->default(true),
                        Toggle::make('is_active')
                            ->label(__('ingredients.editor.admin.classification.active'))
                            ->default(true),
                        TextEntry::make('verified_function_names')
                            ->label(__('ingredients.editor.supplier.verified_functions'))
                            ->state(fn (?Ingredient $record): string => $record?->functions()
                                ->wherePivotIn('source', ['cosing', 'inherited'])
                                ->orderBy('ingredient_functions.sort_order')
                                ->pluck('ingredient_functions.name')
                                ->implode(', ') ?: __('ingredients.editor.supplier.none_verified'))
                            ->helperText(__('ingredients.editor.supplier.verified_functions_helper'))
                            ->columnSpanFull(),
                        Select::make('function_ids')
                            ->label(__('ingredients.editor.supplier.additional_functions'))
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->options(fn (): array => IngredientFunction::query()
                                ->where('is_active', true)
                                ->orderBy('sort_order')
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->helperText(__('ingredients.editor.supplier.additional_functions_helper'))
                            ->columnSpanFull(),
                    ])
                    ->columns([
                        'md' => 3,
                    ]),
                Section::make('Material Identity')
                    ->description('Edit the current material data directly here. This replaces the old day-to-day need to jump into a separate ingredient version resource.')
                    ->icon(Heroicon::Identification)
                    ->schema([
                        TextInput::make('current_version.display_name')
                            ->label('Display name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('current_version.inci_name')
                            ->label('INCI')
                            ->maxLength(255)
                            ->columnSpanFull(),
                        TextInput::make('current_version.soap_inci_naoh_name')
                            ->label('Soap INCI NaOH')
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => (bool) $get('is_soap_saponification_trusted')),
                        TextInput::make('current_version.soap_inci_koh_name')
                            ->label('Soap INCI KOH')
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => (bool) $get('is_soap_saponification_trusted')),
                        TextInput::make('current_version.saponification_name')
                            ->label('Saponification name')
                            ->helperText('English source name used in summaries such as “Saponified oils (coconut, olive)”.')
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => (bool) $get('is_soap_saponification_trusted')),
                        ...IngredientIdentityFields::schema('current_version.', platform: true),
                        TextInput::make('current_version.unit')
                            ->maxLength(64),
                        Toggle::make('current_version.is_manufactured')
                            ->label('Manufactured in-house')
                            ->default(false),
                    ])
                    ->columns([
                        'md' => 2,
                    ]),
                View::make('filament.resources.ingredients.classification-prompt')
                    ->visible(fn (LivewireComponent $livewire): bool => $livewire instanceof CreateIngredient || $livewire instanceof EditIngredient)
                    ->columnSpanFull(),
                Section::make('Guidance & Media')
                    ->description('Use a concise markdown field for advice-ready notes, plus a main ingredient image and an optional compact icon for selectors.')
                    ->icon(Heroicon::DocumentText)
                    ->schema([
                        MarkdownEditor::make('info_markdown')
                            ->label('Ingredient guidance')
                            ->helperText('Good for sourcing nuances, sensory notes, formulation advice, or future assistant guidance.')
                            ->columnSpanFull(),
                        FileUpload::make('featured_image_path')
                            ->label('Ingredient image')
                            ->image()
                            ->maxSize(MediaStorage::ingredientImagesMaxSize())
                            ->disk(MediaStorage::publicDisk())
                            ->directory('ingredients/featured-images')
                            ->visibility(MediaStorage::publicVisibility())
                            ->storeFileNamesIn('featured_image_original_name')
                            ->getUploadedFileUsing(fn (BaseFileUpload $component, string $file, string|array|null $storedFileNames): ?array => FilamentUploadMetadata::applyDisplayName(
                                $component->getUploadedFile($file, $storedFileNames),
                                $storedFileNames,
                                'Current image',
                            ))
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
                            ->helperText('Square image for the ingredient sheet and larger cards.')
                            ->columnSpan(1),
                        FileUpload::make('icon_image_path')
                            ->label('Ingredient icon')
                            ->image()
                            ->maxSize(MediaStorage::ingredientIconsMaxSize())
                            ->disk(MediaStorage::publicDisk())
                            ->directory('ingredients/icons')
                            ->visibility(MediaStorage::publicVisibility())
                            ->storeFileNamesIn('icon_image_original_name')
                            ->getUploadedFileUsing(fn (BaseFileUpload $component, string $file, string|array|null $storedFileNames): ?array => FilamentUploadMetadata::applyDisplayName(
                                $component->getUploadedFile($file, $storedFileNames),
                                $storedFileNames,
                                'Current image',
                            ))
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
                            ->helperText('Optional 96x96 icon for compact selectors and ingredient chips.')
                            ->columnSpan(1),
                    ])
                    ->columns([
                        'md' => 2,
                    ]),
                Section::make('Translations')
                    ->description('Translate the public ingredient name and guidance.')
                    ->icon(Heroicon::Language)
                    ->visible(fn (?Ingredient $record): bool => $record === null || $record->owner_type === null)
                    ->schema([
                        TextEntry::make('translation_source_name')
                            ->label('English name')
                            ->state(fn (Get $get): ?string => $get('current_version.display_name')),
                        TextEntry::make('translation_source_guidance')
                            ->label('English guidance')
                            ->state(fn (Get $get): ?string => $get('info_markdown'))
                            ->placeholder('No English guidance entered.')
                            ->columnSpanFull(),
                        Repeater::make('translations')
                            ->label('Localized content')
                            ->schema([
                                Select::make('locale')
                                    ->label('Language')
                                    ->options(fn (): array => static::translationLocaleOptions())
                                    ->required()
                                    ->distinct()
                                    ->disableOptionsWhenSelectedInSiblingRepeaterItems(),
                                TextInput::make('display_name')
                                    ->label('Translated display name')
                                    ->maxLength(255)
                                    ->helperText('Leave empty to show the English name.'),
                                TextInput::make('saponification_name')
                                    ->label('Translated saponification name')
                                    ->maxLength(255)
                                    ->helperText('For example, “coco” in a saponified-oils summary.'),
                                MarkdownEditor::make('info_markdown')
                                    ->label('Translated guidance')
                                    ->helperText('Leave empty to show the English guidance.')
                                    ->columnSpanFull(),
                            ])
                            ->columns([
                                'md' => 2,
                            ])
                            ->defaultItems(0)
                            ->reorderable(false)
                            ->addActionLabel('Add language')
                            ->columnSpanFull(),
                    ])
                    ->columns([
                        'md' => 2,
                    ]),
                Section::make('Soap Chemistry')
                    ->description('For carrier oils and butters, keep the current SAP, optional iodine and INS references, and fatty-acid profile directly on the ingredient workflow.')
                    ->icon(Heroicon::Beaker)
                    ->visible(fn (Get $get): bool => static::isCategory($get('category'), IngredientCategory::Lipids))
                    ->schema([
                        TextInput::make('sap_profile.koh_sap_value')
                            ->label('KOH SAP')
                            ->numeric()
                            ->inputMode('decimal')
                            ->live(onBlur: true)
                            ->helperText('You can enter professional-style KOH SAP like 245 or decimal-style 0.245. NaOH SAP is derived automatically.'),
                        TextEntry::make('sap_profile.naoh_sap_value')
                            ->label('Derived NaOH SAP')
                            ->state(fn (Get $get): ?string => blank($get('sap_profile.koh_sap_value')) ? null : number_format(SoapSap::deriveNaohFromKoh((float) $get('sap_profile.koh_sap_value')), 6, '.', '')),
                        TextInput::make('sap_profile.iodine_value')
                            ->label('Iodine')
                            ->numeric()
                            ->inputMode('decimal'),
                        TextInput::make('sap_profile.ins_value')
                            ->label('INS')
                            ->numeric()
                            ->inputMode('decimal'),
                        Textarea::make('sap_profile.source_notes')
                            ->label('Soap notes')
                            ->helperText('One source for the SAP values, iodine, INS, and the fatty-acid profile, e.g. lab analysis or supplier COA.')
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
                            ])
                            ->columns([
                                'md' => 2,
                            ])
                            ->defaultItems(0)
                            ->reorderable(false)
                            ->columnSpanFull(),
                    ])
                    ->columns([
                        'md' => 2,
                    ]),
                Section::make('Aromatic Compliance')
                    ->description('For aromatic materials, keep the current allergen declaration directly on the ingredient so stewardship stays in one place.')
                    ->icon(Heroicon::Sparkles)
                    ->visible(fn (Get $get): bool => static::isAromaticCategory($get('category')))
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
                Section::make(__('ingredients.editor.compliance.substances.section'))
                    ->description(__('ingredients.editor.compliance.substances.description'))
                    ->icon(Heroicon::ShieldCheck)
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
                                TextInput::make('concentration_percent')
                                    ->label(__('ingredients.editor.compliance.substances.concentration'))
                                    ->numeric()
                                    ->inputMode('decimal')
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
                    ])
                    ->columns([
                        'md' => 2,
                    ]),
                Section::make('Composite Components')
                    ->description('Use this only when the raw material is itself a blend, macerate, or soap base. Every sub-component must already exist in the catalog so INCI expansion stays consistent.')
                    ->icon(Heroicon::QueueList)
                    ->schema([
                        Repeater::make('components')
                            ->label('Ingredient components')
                            ->schema([
                                Select::make('component_ingredient_id')
                                    ->label('Catalog ingredient')
                                    ->options(fn (?Ingredient $record): array => static::componentIngredientOptions($record))
                                    ->searchable()
                                    ->preload()
                                    ->helperText(fn (Get $get): string => static::componentIngredientHelperText($get('component_ingredient_id')))
                                    ->required(),
                                TextInput::make('percentage_in_parent')
                                    ->label('Share in parent')
                                    ->numeric()
                                    ->inputMode('decimal')
                                    ->suffix('%')
                                    ->minValue(0)
                                    ->maxValue(100)
                                    ->required(),
                            ])
                            ->columns([
                                'md' => 2,
                            ])
                            ->helperText('For accurate INCI generation, component percentages should total 100%.')
                            ->rule(static function (): Closure {
                                return static function (string $attribute, mixed $value, Closure $fail): void {
                                    if (! is_array($value)) {
                                        return;
                                    }

                                    $rows = collect($value)
                                        ->filter(fn (mixed $row): bool => is_array($row))
                                        ->filter(fn (array $row): bool => filled($row['component_ingredient_id'] ?? null));

                                    if ($rows->isEmpty()) {
                                        return;
                                    }

                                    $total = $rows->sum(fn (array $row): float => (float) ($row['percentage_in_parent'] ?? 0));

                                    if (abs($total - 100.0) > 0.01) {
                                        $fail('Composite ingredient percentages must total 100%.');
                                    }
                                };
                            })
                            ->defaultItems(0)
                            ->columnSpanFull(),
                        Textarea::make('composition_source_notes')
                            ->label('Composition source')
                            ->helperText('One source for the whole blend composition, e.g. supplier spec or lab report.')
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * @return array<int, string>
     */
    private static function componentIngredientOptions(?Ingredient $record): array
    {
        return Ingredient::query()
            ->whereNull('owner_type')
            ->where('is_active', true)
            ->when($record?->exists, fn ($query) => $query->whereKeyNot($record?->getKey()))
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

    /**
     * @return array<string, string>
     */
    private static function translationLocaleOptions(): array
    {
        return SupportedLocale::query()
            ->where('code', '!=', 'en')
            ->ordered()
            ->get(['code', 'name', 'native_name'])
            ->mapWithKeys(fn (SupportedLocale $locale): array => [
                $locale->code => $locale->name === $locale->native_name
                    ? $locale->name
                    : sprintf('%s (%s)', $locale->name, $locale->native_name),
            ])
            ->all();
    }

    private static function componentIngredientHelperText(mixed $ingredientId): string
    {
        if (! filled($ingredientId)) {
            return 'Every component must already exist in the catalog. Create the ingredient first, then reference it here.';
        }

        $options = static::componentIngredientOptions(null);

        if (! isset($options[(int) $ingredientId])) {
            return 'This linked component does not yet have an INCI name on its current material record.';
        }

        $label = $options[(int) $ingredientId];

        if (! str_contains($label, '(')) {
            return 'This linked component does not yet have an INCI name on its current material record.';
        }

        preg_match('/\(([^)]+)\)/', $label, $matches);

        return sprintf('Resolved INCI: %s', $matches[1] ?? 'unknown');
    }

    private static function isCategory(mixed $state, IngredientCategory $category): bool
    {
        if ($state instanceof IngredientCategory) {
            return $state === $category;
        }

        return IngredientCategory::tryFrom((string) $state) === $category;
    }

    private static function isAromaticCategory(mixed $state): bool
    {
        if ($state instanceof IngredientCategory) {
            $state = $state->value;
        }

        return IngredientCategory::tryFrom((string) $state) === IngredientCategory::AromaticMaterials;
    }
}
