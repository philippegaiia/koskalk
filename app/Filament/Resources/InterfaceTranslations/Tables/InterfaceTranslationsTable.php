<?php

namespace App\Filament\Resources\InterfaceTranslations\Tables;

use App\Models\InterfaceTranslation;
use App\Models\SupportedLocale;
use App\Services\Translations\EnglishTranslationSource;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InterfaceTranslationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('group')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('key')
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                TextColumn::make('english_source')
                    ->label('English source')
                    ->state(fn (InterfaceTranslation $record, EnglishTranslationSource $source): ?string => $source->get($record->group, $record->key))
                    ->limit(70)
                    ->wrap(),
                TextColumn::make('translation_progress')
                    ->label('Translated')
                    ->state(function (InterfaceTranslation $record): string {
                        $locales = self::translationLocaleCodes();
                        $translated = collect($locales)
                            ->filter(fn (string $locale): bool => filled($record->text[$locale] ?? null))
                            ->count();

                        return "{$translated} / ".count($locales);
                    })
                    ->badge(),
            ])
            ->filters([
                SelectFilter::make('missing_locale')
                    ->label('Missing translation')
                    ->options(self::translationLocaleOptions())
                    ->query(function (Builder $query, array $data): Builder {
                        $locale = $data['value'] ?? null;

                        return $query->when(
                            filled($locale),
                            fn (Builder $query): Builder => $query->where(function (Builder $query) use ($locale): void {
                                $query
                                    ->whereNull("text->{$locale}")
                                    ->orWhere("text->{$locale}", '');
                            }),
                        );
                    }),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('group')
            ->emptyStateHeading('No interface keys synchronized')
            ->emptyStateDescription('Run translations:sync after adding English translation keys.');
    }

    /**
     * @return array<string, string>
     */
    private static function translationLocaleOptions(): array
    {
        return SupportedLocale::query()
            ->where('code', '!=', 'en')
            ->ordered()
            ->pluck('name', 'code')
            ->all();
    }

    /**
     * @return list<string>
     */
    private static function translationLocaleCodes(): array
    {
        return array_keys(self::translationLocaleOptions());
    }
}
