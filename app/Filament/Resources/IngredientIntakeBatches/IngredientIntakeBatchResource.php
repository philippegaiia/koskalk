<?php

namespace App\Filament\Resources\IngredientIntakeBatches;

use App\Filament\Resources\IngredientIntakeBatches\Pages\CreateIngredientIntakeBatch;
use App\Filament\Resources\IngredientIntakeBatches\Pages\ListIngredientIntakeBatches;
use App\Filament\Resources\IngredientIntakeBatches\Pages\ViewIngredientIntakeBatch;
use App\Filament\Resources\IngredientIntakeBatches\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\IngredientIntakeBatches\Schemas\IngredientIntakeBatchForm;
use App\Filament\Resources\IngredientIntakeBatches\Schemas\IngredientIntakeBatchInfolist;
use App\Filament\Resources\IngredientIntakeBatches\Tables\IngredientIntakeBatchesTable;
use App\Models\IngredientIntakeBatch;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class IngredientIntakeBatchResource extends Resource
{
    protected static ?string $model = IngredientIntakeBatch::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?int $navigationSort = 19;

    public static function getNavigationGroup(): ?string
    {
        return 'Catalog';
    }

    public static function getModelLabel(): string
    {
        return __('ingredient_intake_admin.resource.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ingredient_intake_admin.resource.plural_label');
    }

    public static function form(Schema $schema): Schema
    {
        return IngredientIntakeBatchForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return IngredientIntakeBatchInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return IngredientIntakeBatchesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListIngredientIntakeBatches::route('/'),
            'create' => CreateIngredientIntakeBatch::route('/create'),
            'view' => ViewIngredientIntakeBatch::route('/{record}'),
        ];
    }
}
