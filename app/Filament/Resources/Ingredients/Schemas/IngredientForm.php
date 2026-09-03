<?php

namespace App\Filament\Resources\Ingredients\Schemas;

use App\Actions\IngredientEnrichment\StartIngredientGuidanceRefresh;
use App\Enums\IngredientCategory;
use App\Enums\IngredientSubcategory;
use App\Enums\IngredientTranslationOrigin;
use App\Filament\Resources\IngredientEnrichmentBatches\IngredientEnrichmentBatchResource;
use App\Filament\Resources\Ingredients\Pages\CreateIngredient;
use App\Filament\Resources\Ingredients\Pages\EditIngredient;
use App\Forms\Components\IngredientIdentityFields;
use App\Models\Allergen;
use App\Models\FattyAcid;
use App\Models\IfraAmendment;
use App\Models\IfraProductCategory;
use App\Models\Ingredient;
use App\Models\IngredientFunction;
use App\Models\Substance;
use App\Models\SupportedLocale;
use App\Models\User;
use App\Services\IngredientTranslationService;
use App\Services\MediaStorage;
use App\SoapSap;
use App\Support\FilamentUploadMetadata;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\BaseFileUpload;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component as LivewireComponent;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class IngredientForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make(__('ingredients.editor.admin.tabs.label'))
                    ->id('ingredient-editor-tabs')
                    ->persistTabInQueryString('ingredient-tab')
                    ->tabs([
                        static::generalTab(),
                        static::marketDeclarationsTab(),
                        static::guidanceMediaTab(),
                        static::translationsTab(),
                        static::soapChemistryTab(),
                        static::allergensTab(),
                        static::substancesTab(),
                        static::ifraTab(),
                        static::componentsTab(),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    private static function generalTab(): Tab
    {
        return Tab::make(__('ingredients.editor.admin.tabs.general'))
            ->icon(Heroicon::Squares2x2)
            ->schema([
                Section::make(__('ingredients.editor.admin.classification.section'))
                    ->description(__('ingredients.editor.admin.classification.description'))
                    ->schema([
                        TextEntry::make('catalog_key')
                            ->label(__('ingredients.editor.admin.identity.catalog_key'))
                            ->state(fn (?Ingredient $record): string => $record?->catalog_key
                                ?? __('ingredients.editor.admin.identity.catalog_key_pending'))
                            ->helperText(__('ingredients.editor.admin.identity.catalog_key_helper'))
                            ->copyable(fn (?Ingredient $record): bool => $record instanceof Ingredient),
                        TextInput::make('current_version.display_name')
                            ->label(__('ingredients.editor.admin.identity.display_name'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('current_version.inci_name')
                            ->label(__('ingredients.editor.details.inci'))
                            ->maxLength(255),
                        Select::make('category')
                            ->label(__('ingredients.editor.admin.classification.category'))
                            ->options(IngredientCategory::options())
                            ->searchable()
                            ->helperText(__('ingredients.editor.admin.classification.category_helper'))
                            ->live()
                            ->required()
                            ->rules([Rule::enum(IngredientCategory::class)]),
                        Select::make('subcategory')
                            ->label(__('ingredients.editor.details.subcategory'))
                            ->options(fn (Get $get): array => IngredientSubcategory::optionsFor($get('category')))
                            ->searchable()
                            ->live()
                            ->required(fn (Get $get): bool => ! static::isCategory($get('category'), IngredientCategory::Other))
                            ->helperText(__('ingredients.editor.admin.classification.subcategory_helper')),
                        TextInput::make('current_version.unit')
                            ->label(__('ingredients.editor.admin.identity.unit'))
                            ->maxLength(64),
                        Toggle::make('current_version.is_manufactured')
                            ->label(__('ingredients.editor.admin.identity.manufactured'))
                            ->default(false),
                        Grid::make([
                            'default' => 1,
                            'lg' => 2,
                        ])->schema([
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
                        ])->columnSpanFull(),
                    ])
                    ->columns([
                        'default' => 1,
                        'lg' => 2,
                    ])
                    ->columnSpanFull(),
                Section::make(__('ingredients.editor.identity.section'))
                    ->description(__('ingredients.editor.identity.description'))
                    ->icon(Heroicon::FingerPrint)
                    ->schema([
                        ...IngredientIdentityFields::schema('current_version.', platform: true),
                    ])
                    ->columns(1)
                    ->columnSpanFull(),
                Section::make(__('ingredients.editor.admin.cosing_functions.section'))
                    ->description(__('ingredients.editor.admin.cosing_functions.description'))
                    ->icon(Heroicon::ListBullet)
                    ->schema([
                        Select::make('reviewed_function_ids')
                            ->label(__('ingredients.editor.admin.classification.functions'))
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->options(fn (): array => IngredientFunction::query()
                                ->where('is_active', true)
                                ->orderBy('sort_order')
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->helperText(__('ingredients.editor.admin.classification.functions_helper'))
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
                View::make('filament.resources.ingredients.classification-prompt')
                    ->visible(fn (LivewireComponent $livewire): bool => $livewire instanceof CreateIngredient || $livewire instanceof EditIngredient)
                    ->columnSpanFull(),
            ]);
    }

    private static function marketDeclarationsTab(): Tab
    {
        return Tab::make(__('ingredient_admin.market_labels.form.section'))
            ->icon(Heroicon::GlobeAlt)
            ->schema([
                Section::make(__('ingredient_admin.market_labels.form.section'))
                    ->description(__('ingredient_admin.market_labels.form.description'))
                    ->schema([
                        TextEntry::make('market_labels_canonical_inci')
                            ->label(__('ingredient_admin.market_labels.form.canonical_inci'))
                            ->state(fn (Get $get): ?string => $get('current_version.inci_name'))
                            ->placeholder(__('ingredient_admin.market_labels.form.canonical_inci_empty'))
                            ->helperText(__('ingredient_admin.market_labels.form.canonical_inci_helper'))
                            ->columnSpanFull(),
                        static::marketLabelFieldset('eu'),
                        static::marketLabelFieldset('us'),
                    ])
                    ->columns([
                        'default' => 1,
                        'xl' => 2,
                    ])
                    ->columnSpanFull(),
            ]);
    }

    private static function guidanceMediaTab(): Tab
    {
        return Tab::make(__('ingredients.editor.admin.tabs.guidance_media'))
            ->icon(Heroicon::DocumentText)
            ->schema([
                Section::make(__('ingredients.editor.admin.guidance.section'))
                    ->description(__('ingredients.editor.admin.guidance.description'))
                    ->key('guidance-media::section')
                    ->afterHeader([
                        Action::make('updateTranslations')
                            ->label(__('ingredient_admin.translations.update_translations'))
                            ->icon(Heroicon::Language)
                            ->modalHeading(__('ingredient_admin.translations.update_translations_heading'))
                            ->modalDescription(function (?Ingredient $record): string {
                                $catalogueLocales = collect(config('interface-translations.catalogue_locales', []))
                                    ->filter(fn (mixed $locale): bool => is_string($locale) && filled(trim($locale)))
                                    ->map(fn (string $locale): string => trim($locale))
                                    ->unique()
                                    ->values();
                                $translationRows = $record instanceof Ingredient
                                    ? collect(app(IngredientTranslationService::class)->formData($record))
                                    : collect();
                                $translationRows = $translationRows
                                    ->filter(fn (mixed $row): bool => is_array($row) && is_string($row['locale'] ?? null))
                                    ->filter(fn (array $row): bool => $catalogueLocales->contains($row['locale']));

                                $incompleteLocales = $translationRows
                                    ->filter(fn (array $row): bool => ($row['origin'] ?? null) !== IngredientTranslationOrigin::ReviewerEdited->value
                                        && (blank($row['display_name'] ?? null)
                                            || blank($row['info_markdown'] ?? null)
                                            || (filled($record?->saponification_name) && blank($row['saponification_name'] ?? null))))
                                    ->pluck('locale');
                                $currentLocales = $translationRows
                                    ->filter(fn (array $row): bool => ($row['is_stale'] ?? true) === false)
                                    ->reject(fn (array $row): bool => $incompleteLocales->contains($row['locale']))
                                    ->pluck('locale')
                                    ->implode(', ') ?: __('ingredient_admin.translations.none');
                                $missingLocales = $catalogueLocales
                                    ->diff($translationRows->pluck('locale'))
                                    ->implode(', ') ?: __('ingredient_admin.translations.none');
                                $outdatedLocales = $translationRows
                                    ->filter(fn (array $row): bool => ($row['is_stale'] ?? false) === true
                                        && ($row['origin'] ?? null) !== IngredientTranslationOrigin::ReviewerEdited->value)
                                    ->pluck('locale')
                                    ->implode(', ') ?: __('ingredient_admin.translations.none');
                                $preservedLocales = $translationRows
                                    ->filter(fn (array $row): bool => ($row['is_stale'] ?? false) === true
                                        && ($row['origin'] ?? null) === IngredientTranslationOrigin::ReviewerEdited->value)
                                    ->pluck('locale')
                                    ->implode(', ') ?: __('ingredient_admin.translations.none');

                                return __('ingredient_admin.translations.update_translations_description', [
                                    'current' => $currentLocales,
                                    'missing' => $missingLocales,
                                    'outdated' => $outdatedLocales,
                                    'incomplete' => $incompleteLocales->implode(', ') ?: __('ingredient_admin.translations.none'),
                                    'preserved' => $preservedLocales,
                                ]);
                            })
                            ->requiresConfirmation()
                            ->visible(fn (LivewireComponent $livewire, ?Ingredient $record): bool => $livewire instanceof EditIngredient
                                && $record?->exists === true
                                && $record->owner_type === null
                                && $record->owner_id === null
                                && filled($record->info_markdown)
                                && static::hasTranslationsToUpdate($record))
                            ->action(function (Action $action, Ingredient $record, StartIngredientGuidanceRefresh $startRefresh): void {
                                $actor = auth()->user();

                                abort_unless($actor instanceof User, 403);

                                $batch = $startRefresh->handle($actor, collect([$record]), true);

                                $action->redirect(
                                    IngredientEnrichmentBatchResource::getUrl('view', ['record' => $batch]),
                                    navigate: true,
                                );
                            }),
                    ])
                    ->schema([
                        MarkdownEditor::make('info_markdown')
                            ->label(__('ingredients.editor.admin.guidance.field'))
                            ->helperText(__('ingredients.editor.admin.guidance.helper'))
                            ->columnSpanFull(),
                        FileUpload::make('featured_image_path')
                            ->label(__('ingredients.editor.admin.guidance.image'))
                            ->image()
                            ->maxSize(MediaStorage::ingredientImagesMaxSize())
                            ->disk(MediaStorage::publicDisk())
                            ->directory('ingredients/featured-images')
                            ->visibility(MediaStorage::publicVisibility())
                            ->storeFileNamesIn('featured_image_original_name')
                            ->getUploadedFileUsing(fn (BaseFileUpload $component, string $file, string|array|null $storedFileNames): ?array => FilamentUploadMetadata::applyDisplayName(
                                $component->getUploadedFile($file, $storedFileNames),
                                $storedFileNames,
                                __('ingredients.editor.admin.guidance.current_image'),
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
                            ->helperText(__('ingredients.editor.admin.guidance.image_helper'))
                            ->columnSpan(1),
                        FileUpload::make('icon_image_path')
                            ->label(__('ingredients.editor.admin.guidance.icon'))
                            ->image()
                            ->maxSize(MediaStorage::ingredientIconsMaxSize())
                            ->disk(MediaStorage::publicDisk())
                            ->directory('ingredients/icons')
                            ->visibility(MediaStorage::publicVisibility())
                            ->storeFileNamesIn('icon_image_original_name')
                            ->getUploadedFileUsing(fn (BaseFileUpload $component, string $file, string|array|null $storedFileNames): ?array => FilamentUploadMetadata::applyDisplayName(
                                $component->getUploadedFile($file, $storedFileNames),
                                $storedFileNames,
                                __('ingredients.editor.admin.guidance.current_image'),
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
                            ->helperText(__('ingredients.editor.admin.guidance.icon_helper'))
                            ->columnSpan(1),
                    ])
                    ->columns([
                        'default' => 1,
                        'lg' => 2,
                    ])
                    ->columnSpanFull(),
            ]);
    }

    private static function hasTranslationsToUpdate(Ingredient $ingredient): bool
    {
        $translations = collect(app(IngredientTranslationService::class)->formData($ingredient))
            ->filter(fn (mixed $row): bool => is_array($row) && is_string($row['locale'] ?? null))
            ->keyBy('locale');

        return collect(config('interface-translations.catalogue_locales', []))
            ->filter(fn (mixed $locale): bool => is_string($locale) && filled(trim($locale)))
            ->contains(function (string $locale) use ($translations, $ingredient): bool {
                $translation = $translations->get(trim($locale));

                return $translation === null
                    || (($translation['is_stale'] ?? false) === true
                        && ($translation['origin'] ?? null) !== IngredientTranslationOrigin::ReviewerEdited->value)
                    || (($translation['origin'] ?? null) !== IngredientTranslationOrigin::ReviewerEdited->value
                        && (blank($translation['display_name'] ?? null)
                            || blank($translation['info_markdown'] ?? null)
                            || (filled($ingredient->saponification_name) && blank($translation['saponification_name'] ?? null))));
            });
    }

    private static function translationsTab(): Tab
    {
        return Tab::make(__('ingredients.editor.admin.tabs.translations'))
            ->icon(Heroicon::Language)
            ->visible(fn (?Ingredient $record): bool => $record === null || $record->owner_type === null)
            ->schema([
                Section::make(__('ingredients.editor.admin.translations.section'))
                    ->description(__('ingredients.editor.admin.translations.description'))
                    ->visible(fn (?Ingredient $record): bool => $record === null || $record->owner_type === null)
                    ->schema([
                        TextEntry::make('translation_source_name')
                            ->label(__('ingredients.editor.admin.translations.english_name'))
                            ->state(fn (Get $get): ?string => $get('current_version.display_name')),
                        TextEntry::make('translation_source_guidance')
                            ->label(__('ingredients.editor.admin.translations.english_guidance'))
                            ->state(fn (Get $get): ?string => $get('info_markdown'))
                            ->placeholder(__('ingredients.editor.admin.translations.no_guidance'))
                            ->columnSpanFull(),
                        Repeater::make('translations')
                            ->label(__('ingredients.editor.admin.translations.localized_content'))
                            ->schema([
                                Select::make('locale')
                                    ->label(__('ingredients.editor.admin.translations.language'))
                                    ->options(fn (): array => static::translationLocaleOptions())
                                    ->live()
                                    ->required()
                                    ->distinct()
                                    ->disableOptionsWhenSelectedInSiblingRepeaterItems(),
                                TextEntry::make('translation_status')
                                    ->label(__('ingredient_admin.translations.freshness'))
                                    ->state(fn (Get $get): string => __('ingredient_admin.translations.freshness_states.'.(($get('freshness') ?: 'outdated')))
                                        .' · '.__('ingredient_admin.translations.origins.'.(($get('origin') ?: 'legacy')))),
                                TextInput::make('display_name')
                                    ->label(__('ingredients.editor.admin.translations.display_name'))
                                    ->maxLength(255)
                                    ->helperText(__('ingredients.editor.admin.translations.display_name_helper')),
                                TextInput::make('saponification_name')
                                    ->label(__('ingredients.editor.admin.saponified_declaration.translated_material_name'))
                                    ->maxLength(255)
                                    ->helperText(__('ingredients.editor.admin.saponified_declaration.translated_material_name_helper')),
                                MarkdownEditor::make('info_markdown')
                                    ->label(__('ingredients.editor.admin.translations.guidance'))
                                    ->helperText(__('ingredients.editor.admin.translations.guidance_helper'))
                                    ->columnSpanFull(),
                            ])
                            ->itemLabel(fn (array $state): ?string => static::translationLocaleOptions()[$state['locale'] ?? ''] ?? null)
                            ->collapsed()
                            ->defaultItems(0)
                            ->reorderable(false)
                            ->addActionLabel(__('ingredients.editor.admin.translations.add'))
                            ->columnSpanFull(),
                    ])
                    ->columns(1)
                    ->columnSpanFull(),
            ]);
    }

    private static function soapChemistryTab(): Tab
    {
        return Tab::make(__('ingredients.editor.admin.specialist.soap_chemistry'))
            ->icon(Heroicon::Beaker)
            ->visible(fn (Get $get): bool => static::isCategory($get('category'), IngredientCategory::Lipids))
            ->schema([
                Section::make(__('ingredients.editor.admin.saponified_declaration.section'))
                    ->description(__('ingredients.editor.admin.saponified_declaration.description'))
                    ->visible(fn (Get $get): bool => (bool) $get('is_soap_saponification_trusted'))
                    ->schema([
                        TextInput::make('current_version.saponification_name')
                            ->label(__('ingredients.editor.admin.saponified_declaration.material_name'))
                            ->helperText(__('ingredients.editor.admin.saponified_declaration.material_name_helper'))
                            ->maxLength(255),
                        TextInput::make('current_version.soap_inci_naoh_name')
                            ->label(__('ingredients.editor.admin.saponified_declaration.naoh'))
                            ->maxLength(255),
                        TextInput::make('current_version.soap_inci_koh_name')
                            ->label(__('ingredients.editor.admin.saponified_declaration.koh'))
                            ->maxLength(255),
                    ])
                    ->columns([
                        'default' => 1,
                        'xl' => 3,
                    ])
                    ->columnSpanFull(),
                Section::make(__('ingredients.editor.admin.specialist.soap_chemistry'))
                    ->schema([
                        TextInput::make('sap_profile.koh_sap_value')
                            ->label(__('ingredients.editor.soap.koh_sap'))
                            ->numeric()
                            ->inputMode('decimal')
                            ->live(onBlur: true)
                            ->helperText(__('ingredients.editor.soap.koh_helper')),
                        TextEntry::make('sap_profile.naoh_sap_value')
                            ->label(__('ingredients.editor.soap.naoh_sap'))
                            ->state(fn (Get $get): ?string => blank($get('sap_profile.koh_sap_value')) ? null : number_format(SoapSap::deriveNaohFromKoh((float) $get('sap_profile.koh_sap_value')), 6, '.', '')),
                        TextInput::make('sap_profile.iodine_value')
                            ->label(__('ingredients.editor.soap.iodine'))
                            ->numeric()
                            ->inputMode('decimal'),
                        TextInput::make('sap_profile.ins_value')
                            ->label(__('ingredients.editor.soap.ins'))
                            ->numeric()
                            ->inputMode('decimal'),
                        Textarea::make('sap_profile.source_notes')
                            ->label(__('ingredients.editor.soap.notes'))
                            ->rows(3)
                            ->columnSpanFull(),
                        Repeater::make('fatty_acid_entries')
                            ->label(__('ingredients.editor.soap.fatty_acid_profile'))
                            ->schema([
                                Select::make('fatty_acid_id')
                                    ->label(__('ingredients.editor.soap.fatty_acid'))
                                    ->options(fn (): array => FattyAcid::query()
                                        ->where('is_active', true)
                                        ->orderBy('display_order')
                                        ->pluck('name', 'id')
                                        ->all())
                                    ->searchable()
                                    ->preload()
                                    ->live()
                                    ->required(),
                                TextInput::make('percentage')
                                    ->label(__('ingredients.editor.soap.percentage'))
                                    ->numeric()
                                    ->inputMode('decimal')
                                    ->suffix('%')
                                    ->minValue(0)
                                    ->maxValue(100)
                                    ->live(onBlur: true)
                                    ->required(),
                            ])
                            ->columns([
                                'default' => 1,
                                'lg' => 2,
                            ])
                            ->itemLabel(fn (array $state): ?string => static::fattyAcidItemLabel($state))
                            ->collapsed()
                            ->defaultItems(0)
                            ->reorderable(false)
                            ->columnSpanFull(),
                    ])
                    ->columns([
                        'default' => 1,
                        'lg' => 2,
                    ])
                    ->columnSpanFull(),
            ]);
    }

    private static function allergensTab(): Tab
    {
        return Tab::make(__('ingredients.editor.compliance.allergens.section'))
            ->icon(Heroicon::Sparkles)
            ->schema([
                Section::make(__('ingredients.editor.compliance.allergens.section'))
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
                                    ->live()
                                    ->required(),
                                TextInput::make('concentration_percent')
                                    ->label(__('ingredients.editor.compliance.allergens.concentration'))
                                    ->numeric()
                                    ->inputMode('decimal')
                                    ->suffix('%')
                                    ->minValue(0)
                                    ->maxValue(100)
                                    ->live(onBlur: true)
                                    ->required(),
                            ])
                            ->columns([
                                'default' => 1,
                                'lg' => 2,
                            ])
                            ->itemLabel(fn (array $state): ?string => static::allergenItemLabel($state))
                            ->collapsed()
                            ->defaultItems(0)
                            ->columnSpanFull(),
                        Textarea::make('allergen_source_notes')
                            ->label(__('ingredients.editor.compliance.allergens.source'))
                            ->helperText(__('ingredients.editor.compliance.allergens.source_helper'))
                            ->rows(2)
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    private static function substancesTab(): Tab
    {
        return Tab::make(__('ingredients.editor.admin.tabs.substances'))
            ->icon(Heroicon::ShieldCheck)
            ->schema([
                Section::make(__('ingredients.editor.compliance.substances.section'))
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
                                    ->live()
                                    ->required(),
                                TextInput::make('concentration_percent')
                                    ->label(__('ingredients.editor.compliance.substances.concentration'))
                                    ->numeric()
                                    ->inputMode('decimal')
                                    ->suffix('%')
                                    ->minValue(0)
                                    ->maxValue(100)
                                    ->live(onBlur: true),
                            ])
                            ->columns([
                                'default' => 1,
                                'lg' => 2,
                            ])
                            ->itemLabel(fn (array $state): ?string => static::substanceItemLabel($state))
                            ->collapsed()
                            ->defaultItems(0)
                            ->reorderable(false)
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    private static function ifraTab(): Tab
    {
        return Tab::make(__('ingredients.editor.admin.tabs.ifra'))
            ->icon(Heroicon::DocumentCheck)
            ->schema([
                Section::make(__('ingredients.editor.compliance.ifra.section'))
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
                        TextInput::make('ifra.peroxide_value')
                            ->label(__('ingredients.editor.compliance.ifra.peroxide'))
                            ->numeric()
                            ->inputMode('decimal')
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
                                    ->live()
                                    ->required(),
                                TextInput::make('max_percentage')
                                    ->label(__('ingredients.editor.compliance.ifra.maximum'))
                                    ->numeric()
                                    ->inputMode('decimal')
                                    ->minValue(0)
                                    ->maxValue(100)
                                    ->suffix('%')
                                    ->live(onBlur: true)
                                    ->required(),
                                Textarea::make('restriction_note')
                                    ->label(__('ingredients.editor.compliance.ifra.restriction_note'))
                                    ->rows(2)
                                    ->columnSpanFull(),
                            ])
                            ->columns([
                                'default' => 1,
                                'lg' => 2,
                            ])
                            ->itemLabel(fn (array $state): ?string => static::ifraLimitItemLabel($state))
                            ->collapsed()
                            ->defaultItems(0)
                            ->reorderable(false)
                            ->columnSpanFull(),
                    ])
                    ->columns([
                        'default' => 1,
                        'lg' => 2,
                    ])
                    ->columnSpanFull(),
            ]);
    }

    private static function componentsTab(): Tab
    {
        return Tab::make(__('ingredients.editor.admin.tabs.components'))
            ->icon(Heroicon::QueueList)
            ->schema([
                Section::make(__('ingredients.editor.admin.components.section'))
                    ->description(__('ingredients.editor.admin.components.description'))
                    ->schema([
                        TextEntry::make('components_total')
                            ->label(__('ingredients.editor.admin.components.total_label'))
                            ->state(fn (Get $get): string => static::componentTotalLabel($get('components')))
                            ->columnSpanFull(),
                        Repeater::make('components')
                            ->label(__('ingredients.editor.admin.components.entries'))
                            ->schema([
                                Select::make('component_ingredient_id')
                                    ->label(__('ingredients.editor.admin.components.ingredient'))
                                    ->options(fn (?Ingredient $record): array => static::componentIngredientOptions($record))
                                    ->searchable()
                                    ->preload()
                                    ->live()
                                    ->helperText(fn (Get $get): string => static::componentIngredientHelperText($get('component_ingredient_id')))
                                    ->required(),
                                TextInput::make('percentage_in_parent')
                                    ->label(__('ingredients.editor.admin.components.percentage'))
                                    ->numeric()
                                    ->inputMode('decimal')
                                    ->suffix('%')
                                    ->minValue(0)
                                    ->maxValue(100)
                                    ->live(onBlur: true)
                                    ->required(),
                            ])
                            ->columns([
                                'default' => 1,
                                'lg' => 2,
                            ])
                            ->itemLabel(fn (array $state): ?string => static::componentItemLabel($state))
                            ->collapsed()
                            ->helperText(__('ingredients.editor.admin.components.total_helper'))
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
                                        $fail(__('ingredients.editor.admin.components.validation_total'));
                                    }
                                };
                            })
                            ->defaultItems(0)
                            ->columnSpanFull(),
                        Textarea::make('composition_source_notes')
                            ->label(__('ingredients.editor.admin.components.source'))
                            ->helperText(__('ingredients.editor.admin.components.source_helper'))
                            ->rows(2)
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    private static function marketLabelFieldset(string $market): Fieldset
    {
        $declarationPath = "market_labels.{$market}.declaration_name";
        $overridePath = "market_labels.{$market}.use_override";

        return Fieldset::make(__("ingredient_admin.market_labels.markets.{$market}"))
            ->label(__("ingredient_admin.market_labels.markets.{$market}"))
            ->schema([
                Hidden::make("market_labels.{$market}.market_code")
                    ->default($market),
                Hidden::make("market_labels.{$market}.reviewed_at"),
                Hidden::make("market_labels.{$market}.source_tier"),
                Hidden::make("market_labels.{$market}.confidence"),
                Hidden::make("market_labels.{$market}.source_version"),
                Hidden::make("market_labels.{$market}.source_updated_at"),
                Hidden::make("market_labels.{$market}.retrieved_at"),
                ...($market === 'eu' ? [
                    Toggle::make($overridePath)
                        ->label(__('ingredient_admin.market_labels.form.use_eu_override'))
                        ->helperText(__('ingredient_admin.market_labels.form.use_eu_override_helper'))
                        ->live()
                        ->dehydrated(false)
                        ->afterStateHydrated(function (Toggle $component, Get $get): void {
                            $component->state(static::declarationsDiffer(
                                $get('market_labels.eu.declaration_name'),
                                $get('current_version.inci_name'),
                            ));
                        })
                        ->columnSpanFull(),
                ] : []),
                TextInput::make($declarationPath)
                    ->label(__($market === 'eu'
                        ? 'ingredient_admin.market_labels.form.eu_override'
                        : 'ingredient_admin.market_labels.form.us_declaration'))
                    ->helperText(__("ingredient_admin.market_labels.form.{$market}_description"))
                    ->maxLength(255)
                    ->rules($market === 'us' ? ['not_regex:/^CI\s*[0-9]{5}$/i'] : [])
                    ->visible(fn (Get $get): bool => $market === 'us' || (bool) $get($overridePath))
                    ->dehydratedWhenHidden(false)
                    ->columnSpanFull(),
                TextInput::make("market_labels.{$market}.source_name")
                    ->label(__('ingredient_admin.market_labels.action.source'))
                    ->required(fn (Get $get): bool => filled($get($declarationPath)) && ($market === 'us' || (bool) $get($overridePath)))
                    ->maxLength(255)
                    ->visible(fn (Get $get): bool => $market === 'us' || (bool) $get($overridePath))
                    ->dehydratedWhenHidden(false),
                TextInput::make("market_labels.{$market}.source_url")
                    ->label(__('ingredient_admin.market_labels.action.source_url'))
                    ->required(fn (Get $get): bool => filled($get($declarationPath)) && ($market === 'us' || (bool) $get($overridePath)))
                    ->url()
                    ->maxLength(2000)
                    ->visible(fn (Get $get): bool => $market === 'us' || (bool) $get($overridePath))
                    ->dehydratedWhenHidden(false),
                Hidden::make("market_labels.{$market}.effective_from"),
                Hidden::make("market_labels.{$market}.effective_until"),
            ])
            ->columns([
                'default' => 1,
                'lg' => 2,
            ]);
    }

    private static function declarationsDiffer(mixed $declaration, mixed $canonical): bool
    {
        $normalizedDeclaration = Str::lower(Str::squish((string) $declaration));
        $normalizedCanonical = Str::lower(Str::squish((string) $canonical));

        return $normalizedDeclaration !== '' && $normalizedDeclaration !== $normalizedCanonical;
    }

    /** @param array<string, mixed> $state */
    private static function fattyAcidItemLabel(array $state): ?string
    {
        $name = FattyAcid::query()->find($state['fatty_acid_id'] ?? null)?->name;

        return static::percentageItemLabel($name, $state['percentage'] ?? null);
    }

    /** @param array<string, mixed> $state */
    private static function allergenItemLabel(array $state): ?string
    {
        $name = Allergen::query()->find($state['allergen_id'] ?? null)?->inci_name;

        return static::percentageItemLabel($name, $state['concentration_percent'] ?? null);
    }

    /** @param array<string, mixed> $state */
    private static function substanceItemLabel(array $state): ?string
    {
        $name = Substance::query()->find($state['substance_id'] ?? null)?->name;

        return static::percentageItemLabel($name, $state['concentration_percent'] ?? null);
    }

    /** @param array<string, mixed> $state */
    private static function ifraLimitItemLabel(array $state): ?string
    {
        $category = IfraProductCategory::query()->find($state['ifra_product_category_id'] ?? null);

        return static::percentageItemLabel($category?->optionLabel(), $state['max_percentage'] ?? null);
    }

    /** @param array<string, mixed> $state */
    private static function componentItemLabel(array $state): ?string
    {
        $ingredient = Ingredient::query()->find($state['component_ingredient_id'] ?? null);
        $name = $ingredient?->display_name ?? $ingredient?->catalog_key;

        return static::percentageItemLabel($name, $state['percentage_in_parent'] ?? null);
    }

    private static function percentageItemLabel(?string $name, mixed $percentage): ?string
    {
        if (blank($name)) {
            return null;
        }

        if (blank($percentage)) {
            return $name;
        }

        return __('ingredients.editor.admin.item_label.percentage', [
            'name' => $name,
            'percentage' => $percentage,
        ]);
    }

    private static function componentTotalLabel(mixed $state): string
    {
        $total = collect(is_array($state) ? $state : [])
            ->filter(fn (mixed $row): bool => is_array($row))
            ->sum(fn (array $row): float => (float) ($row['percentage_in_parent'] ?? 0));

        $translationKey = abs($total - 100.0) <= 0.01
            ? 'ingredients.editor.admin.components.total_complete'
            : 'ingredients.editor.admin.components.total_incomplete';

        return __($translationKey, ['total' => number_format($total, 2, '.', '')]);
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
            return __('ingredients.editor.admin.components.ingredient_helper');
        }

        $options = static::componentIngredientOptions(null);

        if (! isset($options[(int) $ingredientId])) {
            return __('ingredients.editor.admin.components.missing_inci');
        }

        $label = $options[(int) $ingredientId];

        if (! str_contains($label, '(')) {
            return __('ingredients.editor.admin.components.missing_inci');
        }

        preg_match('/\(([^)]+)\)/', $label, $matches);

        return __('ingredients.editor.admin.components.resolved_inci', [
            'inci' => $matches[1] ?? __('ingredients.editor.admin.components.unknown'),
        ]);
    }

    private static function isCategory(mixed $state, IngredientCategory $category): bool
    {
        if ($state instanceof IngredientCategory) {
            return $state === $category;
        }

        return IngredientCategory::tryFrom((string) $state) === $category;
    }
}
