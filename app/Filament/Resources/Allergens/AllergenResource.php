<?php

namespace App\Filament\Resources\Allergens;

use App\Filament\Resources\Allergens\Pages\CreateAllergen;
use App\Filament\Resources\Allergens\Pages\EditAllergen;
use App\Filament\Resources\Allergens\Pages\ListAllergens;
use App\Filament\Resources\Allergens\Schemas\AllergenForm;
use App\Filament\Resources\Allergens\Tables\AllergensTable;
use App\Models\Allergen;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class AllergenResource extends Resource
{
    protected static ?string $model = Allergen::class;

    protected static ?string $recordTitleAttribute = 'inci_name';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::ExclamationTriangle;

    protected static ?int $navigationSort = 40;

    public static function getNavigationGroup(): ?string
    {
        return 'Compliance';
    }

    public static function getModelLabel(): string
    {
        return 'allergen';
    }

    public static function getPluralModelLabel(): string
    {
        return 'allergens';
    }

    public static function form(Schema $schema): Schema
    {
        return AllergenForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AllergensTable::configure($table);
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
            'index' => ListAllergens::route('/'),
            'create' => CreateAllergen::route('/create'),
            'edit' => EditAllergen::route('/{record}/edit'),
        ];
    }
}
