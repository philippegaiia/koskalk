<?php

namespace App\Filament\Resources\IngredientAllergenEntries\Schemas;

use App\IngredientCategory;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class IngredientAllergenEntryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Allergen Composition')
                    ->description('Attach declarable allergen percentages to aromatic ingredient versions so formulation compliance can use versioned source data.')
                    ->icon(Heroicon::Sparkles)
                    ->schema([
                        Select::make('ingredient_version_id')
                            ->relationship(
                                name: 'ingredientVersion',
                                titleAttribute: 'display_name',
                                modifyQueryUsing: fn (Builder $query): Builder => $query->whereHas(
                                    'ingredient',
                                    fn (Builder $ingredientQuery): Builder => $ingredientQuery->whereIn('category', IngredientCategory::aromaticValues())
                                )
                            )
                            ->searchable()
                            ->preload()
                            ->required(),
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
                            ->required(),
                        Textarea::make('source_notes')
                            ->rows(4)
                            ->columnSpanFull(),
                    ])
                    ->columns([
                        'md' => 2,
                    ]),
            ]);
    }
}
