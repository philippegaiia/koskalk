<?php

namespace App\Filament\Resources\UserIngredients;

use App\Enums\OwnerType;
use App\Filament\Resources\UserIngredients\Pages\ListUserIngredients;
use App\Filament\Resources\UserIngredients\Tables\UserIngredientsTable;
use App\Models\Ingredient;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;

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
            ->select('ingredients.*', 'ingredient_workspace.name as workspace_name', 'ingredient_account.email as owner_email')
            ->leftJoin('workspaces as ingredient_workspace', fn (JoinClause $join): JoinClause => $join
                ->on('ingredient_workspace.id', '=', 'ingredients.workspace_id')
                ->orOn(fn (JoinClause $legacy): JoinClause => $legacy
                    ->whereNull('ingredients.workspace_id')
                    ->where('ingredients.owner_type', OwnerType::Workspace->value)
                    ->on('ingredient_workspace.id', '=', 'ingredients.owner_id')))
            ->leftJoin('users as ingredient_account', fn (JoinClause $join): JoinClause => $join
                ->on('ingredient_account.id', '=', 'ingredient_workspace.owner_user_id')
                ->orOn(fn (JoinClause $legacy): JoinClause => $legacy
                    ->whereNull('ingredients.workspace_id')
                    ->where('ingredients.owner_type', OwnerType::User->value)
                    ->on('ingredient_account.id', '=', 'ingredients.owner_id')))
            ->whereNotNull('ingredients.owner_type');
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
