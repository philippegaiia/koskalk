<?php

namespace App\Filament\Resources\ToxicologyProfiles;

use App\Filament\Resources\ToxicologyProfiles\Pages\CreateToxicologyProfile;
use App\Filament\Resources\ToxicologyProfiles\Pages\ListToxicologyProfiles;
use App\Filament\Resources\ToxicologyProfiles\Pages\ViewToxicologyProfile;
use App\Filament\Resources\ToxicologyProfiles\Schemas\ToxicologyProfileForm;
use App\Filament\Resources\ToxicologyProfiles\Tables\ToxicologyProfilesTable;
use App\Models\ToxicologyProfile;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ToxicologyProfileResource extends Resource
{
    protected static ?string $model = ToxicologyProfile::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Beaker;

    protected static ?int $navigationSort = 61;

    public static function getNavigationGroup(): ?string
    {
        return 'Product dossiers';
    }

    public static function getModelLabel(): string
    {
        return 'toxicological profile';
    }

    public static function getPluralModelLabel(): string
    {
        return 'toxicological profiles';
    }

    public static function form(Schema $schema): Schema
    {
        return ToxicologyProfileForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ToxicologyProfilesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListToxicologyProfiles::route('/'),
            'create' => CreateToxicologyProfile::route('/create'),
            'view' => ViewToxicologyProfile::route('/{record}'),
        ];
    }
}
