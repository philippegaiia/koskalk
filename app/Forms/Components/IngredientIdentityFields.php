<?php

namespace App\Forms\Components;

use App\Enums\IngredientAliasKind;
use App\Enums\IngredientIdentifierScheme;
use App\Models\SupportedLocale;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;

final class IngredientIdentityFields
{
    /**
     * @return array<int, TextInput|Repeater>
     */
    public static function schema(string $primaryPrefix = '', bool $platform = true): array
    {
        $prefix = trim($primaryPrefix);

        if ($prefix !== '' && ! str_ends_with($prefix, '.')) {
            $prefix .= '.';
        }

        return [
            TextInput::make($prefix.'cas_number')
                ->label(__('ingredients.editor.identity.cas_number'))
                ->placeholder(__('ingredients.editor.identity.cas_placeholder'))
                ->maxLength(64),
            TextInput::make($prefix.'ec_number')
                ->label(__('ingredients.editor.identity.ec_number'))
                ->placeholder(__('ingredients.editor.identity.ec_placeholder'))
                ->maxLength(64),
            Repeater::make('additional_identifiers')
                ->label(__('ingredients.editor.identity.additional_identifiers'))
                ->helperText(__('ingredients.editor.identity.additional_identifiers_helper'))
                ->schema([
                    Select::make('scheme')
                        ->label(__('ingredients.editor.identity.scheme'))
                        ->options(self::identifierSchemeOptions())
                        ->required()
                        ->searchable(),
                    TextInput::make('value')
                        ->label(__('ingredients.editor.identity.value'))
                        ->maxLength(64)
                        ->required(),
                    Toggle::make('is_primary')
                        ->label(__('ingredients.editor.identity.primary'))
                        ->helperText(__('ingredients.editor.identity.primary_helper')),
                ])
                ->columns([
                    'md' => 3,
                ])
                ->defaultItems(0)
                ->reorderable(false)
                ->maxItems(10)
                ->addActionLabel(__('ingredients.editor.identity.add_identifier'))
                ->columnSpanFull(),
            Repeater::make('aliases')
                ->label(__('ingredients.editor.identity.aliases'))
                ->helperText($platform
                    ? __('ingredients.editor.identity.aliases_platform_helper')
                    : __('ingredients.editor.identity.aliases_workspace_helper'))
                ->schema([
                    Select::make('locale')
                        ->label(__('ingredients.editor.identity.language'))
                        ->options(self::localeOptions())
                        ->required()
                        ->searchable(),
                    Select::make('kind')
                        ->label(__('ingredients.editor.identity.alias_kind'))
                        ->options(self::aliasKindOptions())
                        ->required(),
                    TextInput::make('name')
                        ->label(__('ingredients.editor.identity.alias_name'))
                        ->maxLength(150)
                        ->required(),
                ])
                ->columns([
                    'md' => 3,
                ])
                ->defaultItems(0)
                ->reorderable(false)
                ->maxItems($platform ? 25 : 5)
                ->addActionLabel(__('ingredients.editor.identity.add_alias'))
                ->columnSpanFull(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function identifierSchemeOptions(): array
    {
        return collect(IngredientIdentifierScheme::cases())
            ->mapWithKeys(fn (IngredientIdentifierScheme $scheme): array => [
                $scheme->value => $scheme->label(),
            ])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private static function aliasKindOptions(): array
    {
        return collect(IngredientAliasKind::cases())
            ->mapWithKeys(fn (IngredientAliasKind $kind): array => [
                $kind->value => $kind->label(),
            ])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private static function localeOptions(): array
    {
        return [
            'und' => __('ingredients.editor.identity.language_neutral'),
            ...SupportedLocale::query()
                ->where('is_active', true)
                ->ordered()
                ->get(['code', 'name', 'native_name'])
                ->mapWithKeys(fn (SupportedLocale $locale): array => [
                    $locale->code => $locale->name === $locale->native_name
                        ? $locale->name
                        : sprintf('%s (%s)', $locale->name, $locale->native_name),
                ])
                ->all(),
        ];
    }
}
