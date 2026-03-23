<?php

namespace App\Filament\Resources\IngredientVersions;

use App\Filament\Resources\IngredientVersions\Pages\CreateIngredientVersion;
use App\Filament\Resources\IngredientVersions\Pages\EditIngredientVersion;
use App\Filament\Resources\IngredientVersions\Pages\ListIngredientVersions;
use App\Filament\Resources\IngredientVersions\Schemas\IngredientVersionForm;
use App\Filament\Resources\IngredientVersions\Tables\IngredientVersionsTable;
use App\Models\IngredientVersion;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class IngredientVersionResource extends Resource
{
    protected static ?string $model = IngredientVersion::class;

    protected static ?string $recordTitleAttribute = 'display_name';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::ClipboardDocumentList;

    protected static ?int $navigationSort = 20;

    public static function getModelLabel(): string
    {
        return 'ingredient version';
    }

    public static function getPluralModelLabel(): string
    {
        return 'ingredient versions';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Catalog';
    }

    public static function form(Schema $schema): Schema
    {
        return IngredientVersionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return IngredientVersionsTable::configure($table);
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
            'index' => ListIngredientVersions::route('/'),
            'create' => CreateIngredientVersion::route('/create'),
            'edit' => EditIngredientVersion::route('/{record}/edit'),
        ];
    }
}
