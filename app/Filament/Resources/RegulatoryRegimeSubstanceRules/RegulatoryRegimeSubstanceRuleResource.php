<?php

namespace App\Filament\Resources\RegulatoryRegimeSubstanceRules;

use App\Filament\Resources\RegulatoryRegimeSubstanceRules\Pages\CreateRegulatoryRegimeSubstanceRule;
use App\Filament\Resources\RegulatoryRegimeSubstanceRules\Pages\EditRegulatoryRegimeSubstanceRule;
use App\Filament\Resources\RegulatoryRegimeSubstanceRules\Pages\ListRegulatoryRegimeSubstanceRules;
use App\Filament\Resources\RegulatoryRegimeSubstanceRules\Schemas\RegulatoryRegimeSubstanceRuleForm;
use App\Filament\Resources\RegulatoryRegimeSubstanceRules\Tables\RegulatoryRegimeSubstanceRulesTable;
use App\Models\RegulatoryRegimeSubstanceRule;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class RegulatoryRegimeSubstanceRuleResource extends Resource
{
    protected static ?string $model = RegulatoryRegimeSubstanceRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::ShieldCheck;

    protected static ?int $navigationSort = 45;

    protected static ?string $recordTitleAttribute = 'rule_type';

    public static function getNavigationGroup(): ?string
    {
        return 'Compliance';
    }

    public static function getModelLabel(): string
    {
        return 'regime substance rule';
    }

    public static function getPluralModelLabel(): string
    {
        return 'regime substance rules';
    }

    public static function form(Schema $schema): Schema
    {
        return RegulatoryRegimeSubstanceRuleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RegulatoryRegimeSubstanceRulesTable::configure($table);
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
            'index' => ListRegulatoryRegimeSubstanceRules::route('/'),
            'create' => CreateRegulatoryRegimeSubstanceRule::route('/create'),
            'edit' => EditRegulatoryRegimeSubstanceRule::route('/{record}/edit'),
        ];
    }
}
