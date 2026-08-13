<?php

namespace App\Filament\Resources\IngredientEnrichmentBatches;

use App\Filament\Resources\IngredientEnrichmentBatches\Pages\ListIngredientEnrichmentBatches;
use App\Filament\Resources\IngredientEnrichmentBatches\Pages\ViewIngredientEnrichmentBatch;
use App\Filament\Resources\IngredientEnrichmentBatches\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\IngredientEnrichmentBatches\Schemas\IngredientEnrichmentBatchInfolist;
use App\Filament\Resources\IngredientEnrichmentBatches\Tables\IngredientEnrichmentBatchesTable;
use App\Models\IngredientEnrichmentBatch;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class IngredientEnrichmentBatchResource extends Resource
{
    protected static ?string $model = IngredientEnrichmentBatch::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Sparkles;

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): ?string
    {
        return 'Catalog';
    }

    public static function getModelLabel(): string
    {
        return __('ingredient_enrichment_admin.resource.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ingredient_enrichment_admin.resource.plural_label');
    }

    public static function infolist(Schema $schema): Schema
    {
        return IngredientEnrichmentBatchInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return IngredientEnrichmentBatchesTable::configure($table);
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
            'index' => ListIngredientEnrichmentBatches::route('/'),
            'view' => ViewIngredientEnrichmentBatch::route('/{record}'),
        ];
    }
}
