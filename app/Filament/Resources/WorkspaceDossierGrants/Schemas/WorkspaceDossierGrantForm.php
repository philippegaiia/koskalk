<?php

namespace App\Filament\Resources\WorkspaceDossierGrants\Schemas;

use App\Models\WorkspaceDossierGrant;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class WorkspaceDossierGrantForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Grant the DIP module')
                    ->description('Owners and Admins of this workspace will see the Product Information File module.')
                    ->icon(Heroicon::Key)
                    ->schema([
                        Select::make('workspace_id')
                            ->label('Workspace')
                            ->relationship(
                                name: 'workspace',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn (Builder $query): Builder => $query->withoutGlobalScopes(),
                            )
                            ->unique(table: WorkspaceDossierGrant::class, column: 'workspace_id')
                            ->searchable()
                            ->preload()
                            ->required(),
                    ]),
            ]);
    }
}
