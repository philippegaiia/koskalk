<?php

namespace App\Filament\Resources\Ingredients\Schemas;

use App\IngredientCategory;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\Rule;

class IngredientForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Classification')
                    ->description('Choose how the ingredient appears in formulation and whether it can enter initial soap calculations.')
                    ->icon(Heroicon::Squares2x2)
                    ->schema([
                        ToggleButtons::make('category')
                            ->label('Ingredient category')
                            ->options(IngredientCategory::class)
                            ->disableOptionWhen(fn (string $value): bool => $value === IngredientCategory::FragranceOil->value)
                            ->helperText('Fragrance oils stay user-authored, so the platform catalog does not create them here.')
                            ->required()
                            ->rules([Rule::enum(IngredientCategory::class)])
                            ->inline()
                            ->columnSpanFull(),
                        Toggle::make('is_potentially_saponifiable')
                            ->label('Potentially saponifiable')
                            ->helperText('Enable this only for carrier oils or butters that truly participate in saponification.')
                            ->default(false),
                        Toggle::make('requires_admin_review')
                            ->label('Requires admin review')
                            ->helperText('Keep this enabled when the imported category or soap eligibility still needs confirmation.')
                            ->default(true),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                    ])
                    ->columns([
                        'md' => 3,
                    ]),
                Section::make('Source Traceability')
                    ->description('Keep source metadata so imported catalog records remain traceable and admin-created records stay auditable.')
                    ->icon(Heroicon::DocumentText)
                    ->schema([
                        TextInput::make('source_key')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('source_code_prefix')
                            ->maxLength(32),
                        TextInput::make('source_file')
                            ->required()
                            ->maxLength(255)
                            ->default('admin'),
                    ])
                    ->columns([
                        'md' => 3,
                    ]),
            ]);
    }
}
