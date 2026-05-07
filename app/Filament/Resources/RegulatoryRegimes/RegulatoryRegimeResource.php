<?php

namespace App\Filament\Resources\RegulatoryRegimes;

use App\Filament\Resources\RegulatoryRegimes\Pages\CreateRegulatoryRegime;
use App\Filament\Resources\RegulatoryRegimes\Pages\EditRegulatoryRegime;
use App\Filament\Resources\RegulatoryRegimes\Pages\ListRegulatoryRegimes;
use App\Filament\Resources\RegulatoryRegimes\Schemas\RegulatoryRegimeForm;
use App\Filament\Resources\RegulatoryRegimes\Tables\RegulatoryRegimesTable;
use App\Models\RegulatoryRegime;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class RegulatoryRegimeResource extends Resource
{
    protected static ?string $model = RegulatoryRegime::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::DocumentCheck;

    protected static ?int $navigationSort = 42;

    public static function getNavigationGroup(): ?string
    {
        return 'Compliance';
    }

    public static function getModelLabel(): string
    {
        return 'regulatory regime';
    }

    public static function getPluralModelLabel(): string
    {
        return 'regulatory regimes';
    }

    public static function form(Schema $schema): Schema
    {
        return RegulatoryRegimeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RegulatoryRegimesTable::configure($table);
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
            'index' => ListRegulatoryRegimes::route('/'),
            'create' => CreateRegulatoryRegime::route('/create'),
            'edit' => EditRegulatoryRegime::route('/{record}/edit'),
        ];
    }
}
