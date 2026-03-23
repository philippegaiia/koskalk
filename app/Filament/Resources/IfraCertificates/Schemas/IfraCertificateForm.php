<?php

namespace App\Filament\Resources\IfraCertificates\Schemas;

use App\IngredientCategory;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class IfraCertificateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Certificate Identity')
                    ->description('Store the source IFRA document against the exact aromatic ingredient version so compliance runs stay auditable.')
                    ->icon(Heroicon::DocumentCheck)
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
                        TextInput::make('certificate_name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('document_name')
                            ->maxLength(255),
                        TextInput::make('document_path')
                            ->maxLength(255),
                        TextInput::make('issuer')
                            ->maxLength(255),
                        TextInput::make('reference_code')
                            ->maxLength(255),
                        TextInput::make('ifra_amendment')
                            ->label('IFRA amendment')
                            ->maxLength(255),
                        DatePicker::make('published_at'),
                        DatePicker::make('valid_from'),
                        Toggle::make('is_current')
                            ->label('Current certificate')
                            ->default(true),
                    ])
                    ->columns([
                        'md' => 2,
                    ]),
                Section::make('Category Limits')
                    ->description('Each certificate carries percentage limits by IFRA product category, which the formulation will evaluate against its selected product context.')
                    ->icon(Heroicon::Squares2x2)
                    ->schema([
                        Repeater::make('limits')
                            ->relationship()
                            ->schema([
                                Select::make('ifra_product_category_id')
                                    ->relationship(name: 'ifraProductCategory', titleAttribute: 'name')
                                    ->searchable()
                                    ->preload()
                                    ->required(),
                                TextInput::make('max_percentage')
                                    ->label('Max concentration')
                                    ->numeric()
                                    ->inputMode('decimal')
                                    ->suffix('%'),
                                Textarea::make('restriction_note')
                                    ->rows(3)
                                    ->columnSpanFull(),
                            ])
                            ->columns([
                                'md' => 2,
                            ])
                            ->defaultItems(0)
                            ->columnSpanFull(),
                    ]),
                Section::make('Source Notes')
                    ->schema([
                        Textarea::make('source_notes')
                            ->rows(4)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
