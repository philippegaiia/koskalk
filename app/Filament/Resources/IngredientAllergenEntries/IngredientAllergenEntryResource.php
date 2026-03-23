<?php

namespace App\Filament\Resources\IngredientAllergenEntries;

use App\Filament\Resources\IngredientAllergenEntries\Pages\CreateIngredientAllergenEntry;
use App\Filament\Resources\IngredientAllergenEntries\Pages\EditIngredientAllergenEntry;
use App\Filament\Resources\IngredientAllergenEntries\Pages\ListIngredientAllergenEntries;
use App\Filament\Resources\IngredientAllergenEntries\Schemas\IngredientAllergenEntryForm;
use App\Filament\Resources\IngredientAllergenEntries\Tables\IngredientAllergenEntriesTable;
use App\Models\IngredientAllergenEntry;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class IngredientAllergenEntryResource extends Resource
{
    protected static ?string $model = IngredientAllergenEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Sparkles;

    protected static ?int $navigationSort = 50;

    public static function getNavigationGroup(): ?string
    {
        return 'Compliance';
    }

    public static function getModelLabel(): string
    {
        return 'ingredient allergen entry';
    }

    public static function getPluralModelLabel(): string
    {
        return 'ingredient allergen entries';
    }

    public static function form(Schema $schema): Schema
    {
        return IngredientAllergenEntryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return IngredientAllergenEntriesTable::configure($table);
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
            'index' => ListIngredientAllergenEntries::route('/'),
            'create' => CreateIngredientAllergenEntry::route('/create'),
            'edit' => EditIngredientAllergenEntry::route('/{record}/edit'),
        ];
    }
}
