<?php

namespace App\Filament\Resources\InterfaceTranslations\Schemas;

use App\Models\InterfaceTranslation;
use App\Models\SupportedLocale;
use App\Rules\PreservesTranslationPlaceholders;
use App\Services\Translations\EnglishTranslationSource;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class InterfaceTranslationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Interface key')
                    ->description('Keys are synchronized from English language files and cannot be changed here.')
                    ->icon(Heroicon::CodeBracket)
                    ->schema([
                        TextInput::make('group')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('key')
                            ->disabled()
                            ->dehydrated(false),
                        Textarea::make('english_source')
                            ->label('English source')
                            ->rows(3)
                            ->disabled()
                            ->dehydrated(false)
                            ->afterStateHydrated(function (Textarea $component, ?InterfaceTranslation $record, EnglishTranslationSource $source): void {
                                $component->state($record === null ? null : $source->get($record->group, $record->key));
                            })
                            ->columnSpanFull(),
                    ])
                    ->columns([
                        'md' => 2,
                    ]),
                Section::make('Translations')
                    ->description('Empty values use the English fallback. Named placeholders must remain unchanged.')
                    ->icon(Heroicon::Language)
                    ->schema([
                        Tabs::make('Translation languages')
                            ->tabs(self::translationTabs())
                            ->scrollable(false)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * @return array<int, Tab>
     */
    private static function translationTabs(): array
    {
        return SupportedLocale::query()
            ->where('code', '!=', 'en')
            ->ordered()
            ->get()
            ->map(fn (SupportedLocale $locale): Tab => Tab::make($locale->name)
                ->schema([
                    Textarea::make("text.{$locale->code}")
                        ->label("{$locale->name} ({$locale->native_name})")
                        ->rows(5)
                        ->nullable()
                        ->rules(fn (?InterfaceTranslation $record, EnglishTranslationSource $source): array => [
                            new PreservesTranslationPlaceholders(
                                $record === null ? '' : ($source->get($record->group, $record->key) ?? ''),
                            ),
                        ])
                        ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? $state : null),
                ]))
            ->all();
    }
}
