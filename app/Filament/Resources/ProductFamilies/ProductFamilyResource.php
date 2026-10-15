<?php

namespace App\Filament\Resources\ProductFamilies;

use App\Filament\Resources\ProductFamilies\Pages\EditProductFamily;
use App\Filament\Resources\ProductFamilies\Pages\ListProductFamilies;
use App\Filament\Resources\ProductFamilies\Schemas\ProductFamilyForm;
use App\Filament\Resources\ProductFamilies\Tables\ProductFamiliesTable;
use App\Models\ProductFamily;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Product families select the calculation engine, so they are fixed records:
 * administrators adjust their calculation defaults but never create or delete them.
 */
class ProductFamilyResource extends Resource
{
    protected static ?string $model = ProductFamily::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Beaker;

    protected static ?int $navigationSort = 14;

    public static function getModelLabel(): string
    {
        return 'product family';
    }

    public static function getPluralModelLabel(): string
    {
        return 'product families';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Catalog';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return ProductFamilyForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductFamiliesTable::configure($table);
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
            'index' => ListProductFamilies::route('/'),
            'edit' => EditProductFamily::route('/{record}/edit'),
        ];
    }
}
