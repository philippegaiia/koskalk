<?php

namespace App\Filament\Resources\IngredientSubstanceEntries;

use App\Filament\Resources\IngredientSubstanceEntries\Pages\CreateIngredientSubstanceEntry;
use App\Filament\Resources\IngredientSubstanceEntries\Pages\EditIngredientSubstanceEntry;
use App\Filament\Resources\IngredientSubstanceEntries\Pages\ListIngredientSubstanceEntries;
use App\Filament\Resources\IngredientSubstanceEntries\Schemas\IngredientSubstanceEntryForm;
use App\Filament\Resources\IngredientSubstanceEntries\Tables\IngredientSubstanceEntriesTable;
use App\Models\IngredientSubstanceEntry;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class IngredientSubstanceEntryResource extends Resource
{
    protected static ?string $model = IngredientSubstanceEntry::class;

    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Beaker;

    protected static ?string $recordTitleAttribute = 'source_notes';

    public static function getNavigationGroup(): ?string
    {
        return 'Compliance';
    }

    public static function getModelLabel(): string
    {
        return 'ingredient substance entry';
    }

    public static function getPluralModelLabel(): string
    {
        return 'ingredient substance entries';
    }

    public static function form(Schema $schema): Schema
    {
        return IngredientSubstanceEntryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return IngredientSubstanceEntriesTable::configure($table);
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
            'index' => ListIngredientSubstanceEntries::route('/'),
            'create' => CreateIngredientSubstanceEntry::route('/create'),
            'edit' => EditIngredientSubstanceEntry::route('/{record}/edit'),
        ];
    }
}
