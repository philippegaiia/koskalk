<?php

namespace App\Filament\Resources\UserIngredients;

use App\Filament\Resources\UserIngredients\Pages\ListUserIngredients;
use App\Filament\Resources\UserIngredients\Tables\UserIngredientsTable;
use App\Models\Ingredient;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UserIngredientResource extends Resource
{
    protected static ?string $model = Ingredient::class;

    protected static ?string $recordTitleAttribute = 'display_name';

    protected static bool $isGloballySearchable = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlass;

    protected static ?int $navigationSort = 11;

    public static function getModelLabel(): string
    {
        return 'user ingredient';
    }

    public static function getPluralModelLabel(): string
    {
        return 'user ingredients';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Catalog';
    }

    public static function table(Table $table): Table
    {
        return UserIngredientsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereNotNull('owner_type');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUserIngredients::route('/'),
        ];
    }
}
