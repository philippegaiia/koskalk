<?php

namespace App\Filament\Resources\IfraProductCategories;

use App\Filament\Resources\IfraProductCategories\Pages\CreateIfraProductCategory;
use App\Filament\Resources\IfraProductCategories\Pages\EditIfraProductCategory;
use App\Filament\Resources\IfraProductCategories\Pages\ListIfraProductCategories;
use App\Filament\Resources\IfraProductCategories\Schemas\IfraProductCategoryForm;
use App\Filament\Resources\IfraProductCategories\Tables\IfraProductCategoriesTable;
use App\Models\IfraProductCategory;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class IfraProductCategoryResource extends Resource
{
    protected static ?string $model = IfraProductCategory::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Squares2x2;

    protected static ?int $navigationSort = 55;

    public static function getNavigationGroup(): ?string
    {
        return 'Compliance';
    }

    public static function getModelLabel(): string
    {
        return 'IFRA product category';
    }

    public static function getPluralModelLabel(): string
    {
        return 'IFRA product categories';
    }

    public static function form(Schema $schema): Schema
    {
        return IfraProductCategoryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return IfraProductCategoriesTable::configure($table);
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
            'index' => ListIfraProductCategories::route('/'),
            'create' => CreateIfraProductCategory::route('/create'),
            'edit' => EditIfraProductCategory::route('/{record}/edit'),
        ];
    }
}
