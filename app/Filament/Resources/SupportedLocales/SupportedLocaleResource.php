<?php

namespace App\Filament\Resources\SupportedLocales;

use App\Filament\Resources\SupportedLocales\Pages\CreateSupportedLocale;
use App\Filament\Resources\SupportedLocales\Pages\EditSupportedLocale;
use App\Filament\Resources\SupportedLocales\Pages\ListSupportedLocales;
use App\Filament\Resources\SupportedLocales\Schemas\SupportedLocaleForm;
use App\Filament\Resources\SupportedLocales\Tables\SupportedLocalesTable;
use App\Models\SupportedLocale;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class SupportedLocaleResource extends Resource
{
    protected static ?string $model = SupportedLocale::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::GlobeAlt;

    protected static ?int $navigationSort = 80;

    public static function getNavigationGroup(): ?string
    {
        return 'Localization';
    }

    public static function getModelLabel(): string
    {
        return 'language';
    }

    public static function getPluralModelLabel(): string
    {
        return 'languages';
    }

    public static function form(Schema $schema): Schema
    {
        return SupportedLocaleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SupportedLocalesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSupportedLocales::route('/'),
            'create' => CreateSupportedLocale::route('/create'),
            'edit' => EditSupportedLocale::route('/{record}/edit'),
        ];
    }
}
