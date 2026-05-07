<?php

namespace App\Filament\Resources\RegulatoryRegimeAllergens;

use App\Filament\Resources\RegulatoryRegimeAllergens\Pages\CreateRegulatoryRegimeAllergen;
use App\Filament\Resources\RegulatoryRegimeAllergens\Pages\EditRegulatoryRegimeAllergen;
use App\Filament\Resources\RegulatoryRegimeAllergens\Pages\ListRegulatoryRegimeAllergens;
use App\Filament\Resources\RegulatoryRegimeAllergens\Schemas\RegulatoryRegimeAllergenForm;
use App\Filament\Resources\RegulatoryRegimeAllergens\Tables\RegulatoryRegimeAllergensTable;
use App\Models\RegulatoryRegimeAllergen;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class RegulatoryRegimeAllergenResource extends Resource
{
    protected static ?string $model = RegulatoryRegimeAllergen::class;

    protected static ?string $recordTitleAttribute = 'declaration_label';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Sparkles;

    protected static ?int $navigationSort = 43;

    public static function getNavigationGroup(): ?string
    {
        return 'Compliance';
    }

    public static function getModelLabel(): string
    {
        return 'regime allergen rule';
    }

    public static function getPluralModelLabel(): string
    {
        return 'regime allergen rules';
    }

    public static function form(Schema $schema): Schema
    {
        return RegulatoryRegimeAllergenForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RegulatoryRegimeAllergensTable::configure($table);
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
            'index' => ListRegulatoryRegimeAllergens::route('/'),
            'create' => CreateRegulatoryRegimeAllergen::route('/create'),
            'edit' => EditRegulatoryRegimeAllergen::route('/{record}/edit'),
        ];
    }
}
