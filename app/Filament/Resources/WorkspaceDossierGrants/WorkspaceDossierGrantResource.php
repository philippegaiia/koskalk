<?php

namespace App\Filament\Resources\WorkspaceDossierGrants;

use App\Filament\Resources\WorkspaceDossierGrants\Pages\CreateWorkspaceDossierGrant;
use App\Filament\Resources\WorkspaceDossierGrants\Pages\ListWorkspaceDossierGrants;
use App\Filament\Resources\WorkspaceDossierGrants\Schemas\WorkspaceDossierGrantForm;
use App\Filament\Resources\WorkspaceDossierGrants\Tables\WorkspaceDossierGrantsTable;
use App\Models\WorkspaceDossierGrant;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WorkspaceDossierGrantResource extends Resource
{
    protected static ?string $model = WorkspaceDossierGrant::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Key;

    protected static ?int $navigationSort = 60;

    public static function getNavigationGroup(): ?string
    {
        return 'Product dossiers';
    }

    public static function getModelLabel(): string
    {
        return 'DIP module grant';
    }

    public static function getPluralModelLabel(): string
    {
        return 'DIP module grants';
    }

    public static function form(Schema $schema): Schema
    {
        return WorkspaceDossierGrantForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WorkspaceDossierGrantsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkspaceDossierGrants::route('/'),
            'create' => CreateWorkspaceDossierGrant::route('/create'),
        ];
    }
}
