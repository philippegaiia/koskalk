<?php

namespace App\Filament\Resources\InterfaceTranslations;

use App\Filament\Resources\InterfaceTranslations\Pages\EditInterfaceTranslation;
use App\Filament\Resources\InterfaceTranslations\Pages\ListInterfaceTranslations;
use App\Filament\Resources\InterfaceTranslations\Schemas\InterfaceTranslationForm;
use App\Filament\Resources\InterfaceTranslations\Tables\InterfaceTranslationsTable;
use App\Models\InterfaceTranslation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class InterfaceTranslationResource extends Resource
{
    protected static ?string $model = InterfaceTranslation::class;

    protected static ?string $recordTitleAttribute = 'key';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Language;

    protected static ?int $navigationSort = 81;

    public static function getNavigationGroup(): ?string
    {
        return 'Localization';
    }

    public static function getModelLabel(): string
    {
        return 'interface translation';
    }

    public static function getPluralModelLabel(): string
    {
        return 'interface translations';
    }

    public static function form(Schema $schema): Schema
    {
        return InterfaceTranslationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return InterfaceTranslationsTable::configure($table);
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
            'index' => ListInterfaceTranslations::route('/'),
            'edit' => EditInterfaceTranslation::route('/{record}/edit'),
        ];
    }
}
