<?php

namespace App\Filament\Resources\Ingredients\Schemas;

use App\IngredientCategory;
use App\Models\Allergen;
use App\Models\FattyAcid;
use App\Models\Ingredient;
use App\Models\IngredientFunction;
use App\Services\MediaStorage;
use App\SoapSap;
use Closure;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\Rule;

class IngredientForm
{
    private static ?array $cachedComponentOptions = null;

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Classification')
                    ->description('Define the ingredient family first. Soap-specific trust only controls whether a carrier oil can drive saponification math.')
                    ->icon(Heroicon::Squares2x2)
                    ->schema([
                        ToggleButtons::make('category')
                            ->label('Ingredient category')
                            ->options(IngredientCategory::class)
                            ->disableOptionWhen(fn (string $value): bool => $value === IngredientCategory::FragranceOil->value)
                            ->helperText('Platform data usually avoids supplier-specific fragrance oils, even though user-created fragrance oils remain a core workflow.')
                            ->live()
                            ->required()
                            ->rules([Rule::enum(IngredientCategory::class)])
                            ->inline()
                            ->columnSpanFull(),
                        Toggle::make('is_potentially_saponifiable')
                            ->label('Trusted for soap saponification')
                            ->helperText('This only unlocks the ingredient as an oil to saponify. It does not block additive use or non-soap cosmetic use.')
                            ->default(false),
                        Toggle::make('requires_admin_review')
                            ->label('Needs review')
                            ->helperText('Keep this enabled when the imported category, soap trust, or compliance status still needs confirmation.')
                            ->default(true),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
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
                            ->helperText('Optional official ingredient functions. One ingredient can carry multiple COSING functions.')
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
                        TextInput::make('current_version.display_name_en')
                            ->label('Display name EN')
                            ->maxLength(255),
                        TextInput::make('current_version.inci_name')
                            ->label('INCI')
                            ->maxLength(255)
                            ->columnSpanFull(),
                        TextInput::make('current_version.soap_inci_naoh_name')
                            ->label('Soap INCI NaOH')
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => static::isCategory($get('category'), IngredientCategory::CarrierOil)),
                        TextInput::make('current_version.soap_inci_koh_name')
                            ->label('Soap INCI KOH')
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => static::isCategory($get('category'), IngredientCategory::CarrierOil)),
                        TextInput::make('current_version.cas_number')
                            ->label('CAS number')
                            ->maxLength(255),
                        TextInput::make('current_version.ec_number')
                            ->label('EC number')
                            ->maxLength(255),
                        TextInput::make('current_version.unit')
                            ->maxLength(64),
                        TextInput::make('current_version.price_eur')
                            ->label('Price EUR')
                            ->numeric()
                            ->inputMode('decimal'),
                        Toggle::make('current_version.is_manufactured')
                            ->label('Manufactured')
                            ->default(false),
                    ])
                    ->columns([
                        'md' => 2,
                    ]),
                Section::make('Guidance & Media')
                    ->description('Use a concise markdown field for advice-ready ingredient notes, and a single featured image for selectors, cards, and future guidance surfaces.')
                    ->icon(Heroicon::DocumentText)
                    ->schema([
                        MarkdownEditor::make('info_markdown')
                            ->label('Ingredient guidance')
                            ->helperText('Good for sourcing nuances, sensory notes, formulation advice, or future assistant guidance.')
                            ->columnSpanFull(),
                        FileUpload::make('featured_image_path')
                            ->label('Ingredient image')
                            ->image()
                            ->maxSize(2048)
                            ->disk(MediaStorage::publicDisk())
                            ->directory('ingredients/featured-images')
                            ->visibility('public')
                            ->imageEditor()
                            ->imageAspectRatio('1:1')
                            ->imageEditorAspectRatioOptions(['1:1'])
                            ->automaticallyOpenImageEditorForAspectRatio()
                            ->helperText('One square image is enough for now. We can derive smaller thumbnails later if needed.')
                            ->columnSpanFull(),
                    ])
                    ->columns([
                        'md' => 2,
                    ]),
                Section::make('Soap Chemistry')
                    ->description('For carrier oils and butters, keep the current SAP, optional iodine and INS references, and fatty-acid profile directly on the ingredient workflow.')
                    ->icon(Heroicon::Beaker)
                    ->visible(fn (Get $get): bool => static::isCategory($get('category'), IngredientCategory::CarrierOil))
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
                                Textarea::make('source_notes')
                                    ->rows(3)
                                    ->columnSpanFull(),
                            ])
                            ->columns([
                                'md' => 2,
                            ])
                            ->defaultItems(0)
                            ->columnSpanFull(),
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
                                Textarea::make('source_notes')
                                    ->rows(2)
                                    ->columnSpanFull(),
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
                    ]),
            ]);
    }

    /**
     * @return array<int, string>
     */
    private static function componentIngredientOptions(?Ingredient $record): array
    {
        if (static::$cachedComponentOptions !== null) {
            return static::$cachedComponentOptions;
        }

        return static::$cachedComponentOptions = Ingredient::query()
            ->where('is_active', true)
            ->when($record?->exists, fn ($query) => $query->whereKeyNot($record?->getKey()))
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

        return $state === $category->value;
    }

    private static function isAromaticCategory(mixed $state): bool
    {
        if ($state instanceof IngredientCategory) {
            $state = $state->value;
        }

        return in_array($state, IngredientCategory::aromaticValues(), true);
    }
}
