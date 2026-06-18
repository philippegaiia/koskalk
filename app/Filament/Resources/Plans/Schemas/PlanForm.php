<?php

namespace App\Filament\Resources\Plans\Schemas;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class PlanForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Plan')
                    ->description('Product access configuration. Limits are enforced by the public app and can be adjusted without changing code.')
                    ->icon(Heroicon::OutlinedRectangleStack)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('slug')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        TextInput::make('display_order')
                            ->numeric()
                            ->inputMode('numeric')
                            ->step(1)
                            ->default(0),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                        Toggle::make('is_default')
                            ->label('Default for new/free users')
                            ->default(false),
                        Textarea::make('description')
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->columns([
                        'md' => 2,
                    ]),
                Section::make('Billing')
                    ->description('Map a paid plan to the Paddle product and price IDs. Leave empty for a free/internal plan.')
                    ->icon(Heroicon::CreditCard)
                    ->schema([
                        TextInput::make('paddle_product_id')
                            ->label('Paddle product ID')
                            ->placeholder('pro_...')
                            ->maxLength(255),
                        TextInput::make('paddle_price_id')
                            ->label('Paddle price ID')
                            ->placeholder('pri_...')
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        Select::make('billing_interval')
                            ->options([
                                'month' => 'Monthly',
                                'year' => 'Yearly',
                            ])
                            ->native(false),
                        TextInput::make('price_label')
                            ->placeholder('EUR 9 / month')
                            ->maxLength(255),
                    ])
                    ->columns([
                        'md' => 2,
                    ]),
                Section::make('Limits')
                    ->description('Leave a value empty for no hard limit. Initial free plan target: 15 saved recipes and 20 private ingredients.')
                    ->icon(Heroicon::AdjustmentsHorizontal)
                    ->schema([
                        Repeater::make('limits')
                            ->relationship()
                            ->schema([
                                Select::make('key')
                                    ->options([
                                        'saved_recipes' => 'Saved recipes',
                                        'private_ingredients' => 'Private ingredients',
                                    ])
                                    ->native(false)
                                    ->required()
                                    ->distinct()
                                    ->disableOptionsWhenSelectedInSiblingRepeaterItems(),
                                TextInput::make('value')
                                    ->label('Limit')
                                    ->numeric()
                                    ->inputMode('numeric')
                                    ->minValue(0)
                                    ->step(1)
                                    ->helperText('Empty means unlimited.'),
                            ])
                            ->columns([
                                'md' => 2,
                            ])
                            ->defaultItems(2)
                            ->addActionLabel('Add limit')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
