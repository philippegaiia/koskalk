<?php

namespace App\Filament\Resources\IngredientSapProfiles;

use App\Filament\Resources\IngredientSapProfiles\Pages\CreateIngredientSapProfile;
use App\Filament\Resources\IngredientSapProfiles\Pages\EditIngredientSapProfile;
use App\Filament\Resources\IngredientSapProfiles\Pages\ListIngredientSapProfiles;
use App\Filament\Resources\IngredientSapProfiles\Schemas\IngredientSapProfileForm;
use App\Filament\Resources\IngredientSapProfiles\Tables\IngredientSapProfilesTable;
use App\Models\IngredientSapProfile;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class IngredientSapProfileResource extends Resource
{
    protected static ?string $model = IngredientSapProfile::class;

    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Beaker;

    protected static ?int $navigationSort = 30;

    public static function getModelLabel(): string
    {
        return 'SAP profile';
    }

    public static function getPluralModelLabel(): string
    {
        return 'SAP profiles';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Catalog';
    }

    public static function form(Schema $schema): Schema
    {
        return IngredientSapProfileForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return IngredientSapProfilesTable::configure($table);
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
            'index' => ListIngredientSapProfiles::route('/'),
            'create' => CreateIngredientSapProfile::route('/create'),
            'edit' => EditIngredientSapProfile::route('/{record}/edit'),
        ];
    }
}
