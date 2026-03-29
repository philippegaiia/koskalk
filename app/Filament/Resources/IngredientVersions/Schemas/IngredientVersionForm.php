<?php

namespace App\Filament\Resources\IngredientVersions\Schemas;

use App\Models\FattyAcid;
use App\Models\Ingredient;
use App\Models\IngredientVersion;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class IngredientVersionForm
{
    private static array $ingredientCache = [];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Version Identity')
                    ->description('Keep the current naming and regulatory snapshot attached to the ingredient so stewardship stays traceable without dominating the main ingredient UX.')
                    ->icon(Heroicon::Identification)
                    ->schema([
                        Select::make('ingredient_id')
                            ->relationship(name: 'ingredient', titleAttribute: 'source_key')
                            ->searchable()
                            ->preload()
                            ->required(),
                        TextInput::make('version')
                            ->required()
                            ->integer()
                            ->default(1)
                            ->minValue(1),
                        Toggle::make('is_current')
                            ->label('Current version')
                            ->default(true),
                    ])
                    ->columns([
                        'md' => 3,
                    ]),
                Section::make('Display And Regulatory Names')
                    ->description('Store the bilingual display names and all INCI variants at the version level.')
                    ->icon(Heroicon::Language)
                    ->schema([
                        TextInput::make('display_name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('display_name_en')
                            ->maxLength(255),
                        TextInput::make('display_name_fr')
                            ->maxLength(255),
                        TextInput::make('inci_name')
                            ->label('INCI')
                            ->maxLength(255)
                            ->columnSpanFull(),
                        TextInput::make('soap_inci_naoh_name')
                            ->label('INCI NaOH')
                            ->maxLength(255),
                        TextInput::make('soap_inci_koh_name')
                            ->label('INCI KOH')
                            ->maxLength(255),
                        TextInput::make('cas_number')
                            ->label('CAS number')
                            ->maxLength(255),
                        TextInput::make('ec_number')
                            ->label('EC number')
                            ->maxLength(255),
                    ])
                    ->columns([
                        'md' => 2,
                    ]),
                Section::make('Commercial Data')
                    ->description('Keep naming, sourcing, and commercial details here. Chemistry and compliance stay conditional on the material type.')
                    ->icon(Heroicon::CurrencyEuro)
                    ->schema([
                        TextInput::make('unit')
                            ->maxLength(64),
                        TextInput::make('price_eur')
                            ->label('Price EUR')
                            ->numeric()
                            ->inputMode('decimal'),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                        Toggle::make('is_manufactured')
                            ->label('Manufactured')
                            ->default(false),
                    ])
                    ->columns([
                        'md' => 4,
                    ]),
                Section::make('Soap Calculation Data')
                    ->description('For carrier oils trusted in saponified soap math, manage the normalized fatty-acid entries here. KOH SAP still lives in the SAP profile resource.')
                    ->icon(Heroicon::Beaker)
                    ->visible(fn (Get $get, ?IngredientVersion $record): bool => self::supportsSoapCalculation($get, $record))
                    ->schema([
                        TextEntry::make('fatty_acid_entry_hint')
                            ->hiddenLabel()
                            ->state('Use the normalized fatty-acid list below as the main source of truth. The older SAP-profile fatty-acid columns remain only as legacy fallback data.'),
                        TextEntry::make('fatty_acid_total')
                            ->label('Fatty acid total')
                            ->state(fn (Get $get): string => number_format(collect($get('fattyAcidEntries') ?? [])->sum(fn (array $row): float => max(0, (float) ($row['percentage'] ?? 0))), 2, '.', '').'%'),
                        Repeater::make('fattyAcidEntries')
                            ->relationship()
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
                    ->description('For essential oils, fragrance oils, CO2 extracts, and related aromatics, keep the current allergen declaration attached to this version.')
                    ->icon(Heroicon::Sparkles)
                    ->visible(fn (Get $get, ?IngredientVersion $record): bool => self::supportsAromaticCompliance($get, $record))
                    ->schema([
                        Repeater::make('allergenEntries')
                            ->relationship()
                            ->schema([
                                Select::make('allergen_id')
                                    ->relationship(name: 'allergen', titleAttribute: 'inci_name')
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
                Section::make('Internal Metadata')
                    ->description('Preserve source references for imported versions or use a stable admin key for new entries, while keeping them secondary in normal stewardship.')
                    ->icon(Heroicon::DocumentText)
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        TextInput::make('source_key')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('source_file')
                            ->required()
                            ->maxLength(255)
                            ->default('admin'),
                    ])
                    ->columns([
                        'md' => 2,
                    ]),
            ]);
    }

    private static function resolveIngredient(Get $get, ?IngredientVersion $record): ?Ingredient
    {
        $ingredientId = $get('ingredient_id');

        if (blank($ingredientId) && $record?->relationLoaded('ingredient') && $record->ingredient) {
            $ingredientId = $record->ingredient->id;
        }

        if (blank($ingredientId)) {
            return $record?->ingredient;
        }

        $key = (int) $ingredientId;

        return static::$ingredientCache[$key] ??= Ingredient::query()
            ->select('id', 'category', 'is_potentially_saponifiable')
            ->find($key);
    }

    private static function supportsSoapCalculation(Get $get, ?IngredientVersion $record): bool
    {
        return static::resolveIngredient($get, $record)?->canDriveSoapSaponification() ?? false;
    }

    private static function supportsAromaticCompliance(Get $get, ?IngredientVersion $record): bool
    {
        return static::resolveIngredient($get, $record)?->requiresAromaticCompliance() ?? false;
    }
}
