<?php

namespace App\Filament\Resources\SupportedLocales\Schemas;

use App\Models\SupportedLocale;
use App\Services\SupportedLocaleCatalog;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class SupportedLocaleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Language identity')
                    ->description('Languages available for public interface translation and future activation.')
                    ->icon(Heroicon::Language)
                    ->schema([
                        Select::make('catalog_locale')
                            ->label('Language')
                            ->helperText('Names, locale code, text direction, and the suggested number locale are supplied by Laravel Lang.')
                            ->options(fn (SupportedLocaleCatalog $catalog): array => $catalog->options(
                                SupportedLocale::query()->pluck('code')->all(),
                            ))
                            ->searchable()
                            ->preload()
                            ->required()
                            ->visible(fn (?SupportedLocale $record): bool => $record === null)
                            ->columnSpanFull(),
                        TextInput::make('code')
                            ->label('Locale code')
                            ->helperText('ISO locale such as fr, de, or pt_BR.')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(16)
                            ->regex('/^[a-z]{2,3}(?:_[A-Z][A-Za-z]{1,3})?(?:_[A-Z]{2})?$/')
                            ->disabled()
                            ->validatedWhenNotDehydrated(false)
                            ->visible(fn (?SupportedLocale $record): bool => $record !== null),
                        TextInput::make('name')
                            ->label('English name')
                            ->required()
                            ->maxLength(255)
                            ->disabled()
                            ->validatedWhenNotDehydrated(false)
                            ->visible(fn (?SupportedLocale $record): bool => $record !== null),
                        TextInput::make('native_name')
                            ->label('Native name')
                            ->required()
                            ->maxLength(255)
                            ->disabled()
                            ->validatedWhenNotDehydrated(false)
                            ->visible(fn (?SupportedLocale $record): bool => $record !== null),
                        TextInput::make('number_locale')
                            ->label('Number locale')
                            ->helperText('Formatting locale such as fr_FR. Users may override number formatting separately.')
                            ->required()
                            ->maxLength(32)
                            ->disabled()
                            ->validatedWhenNotDehydrated(false)
                            ->visible(fn (?SupportedLocale $record): bool => $record !== null),
                        Select::make('text_direction')
                            ->label('Text direction')
                            ->options([
                                'ltr' => 'Left to right',
                                'rtl' => 'Right to left',
                            ])
                            ->default('ltr')
                            ->required()
                            ->disabled()
                            ->validatedWhenNotDehydrated(false)
                            ->visible(fn (?SupportedLocale $record): bool => $record !== null),
                        TextInput::make('sort_order')
                            ->label('Sort order')
                            ->numeric()
                            ->inputMode('numeric')
                            ->step(1)
                            ->default(10)
                            ->required(),
                        Toggle::make('is_active')
                            ->label('Available to users')
                            ->default(false),
                        Toggle::make('is_default')
                            ->label('Default language')
                            ->helperText('The default language is always activated automatically.')
                            ->default(false),
                    ])
                    ->columns([
                        'md' => 2,
                    ]),
            ]);
    }
}
