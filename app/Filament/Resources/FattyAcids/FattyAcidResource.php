<?php

namespace App\Filament\Resources\FattyAcids;

use App\Filament\Resources\FattyAcids\Pages\CreateFattyAcid;
use App\Filament\Resources\FattyAcids\Pages\EditFattyAcid;
use App\Filament\Resources\FattyAcids\Pages\ListFattyAcids;
use App\Filament\Resources\FattyAcids\Schemas\FattyAcidForm;
use App\Filament\Resources\FattyAcids\Tables\FattyAcidsTable;
use App\Models\FattyAcid;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class FattyAcidResource extends Resource
{
    protected static ?string $model = FattyAcid::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Beaker;

    protected static ?int $navigationSort = 20;

    public static function getModelLabel(): string
    {
        return 'fatty acid';
    }

    public static function getPluralModelLabel(): string
    {
        return 'fatty acids';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Catalog';
    }

    public static function form(Schema $schema): Schema
    {
        return FattyAcidForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FattyAcidsTable::configure($table);
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
            'index' => ListFattyAcids::route('/'),
            'create' => CreateFattyAcid::route('/create'),
            'edit' => EditFattyAcid::route('/{record}/edit'),
        ];
    }
}
