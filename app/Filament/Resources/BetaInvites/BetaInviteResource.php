<?php

namespace App\Filament\Resources\BetaInvites;

use App\Filament\Resources\BetaInvites\Pages\CreateBetaInvite;
use App\Filament\Resources\BetaInvites\Pages\ListBetaInvites;
use App\Filament\Resources\BetaInvites\Schemas\BetaInviteForm;
use App\Filament\Resources\BetaInvites\Tables\BetaInvitesTable;
use App\Models\BetaInvite;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class BetaInviteResource extends Resource
{
    protected static ?string $model = BetaInvite::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static ?string $recordTitleAttribute = 'email';

    protected static ?int $navigationSort = 70;

    public static function getNavigationGroup(): ?string
    {
        return 'Access';
    }

    public static function form(Schema $schema): Schema
    {
        return BetaInviteForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BetaInvitesTable::configure($table);
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
            'index' => ListBetaInvites::route('/'),
            'create' => CreateBetaInvite::route('/create'),
        ];
    }
}
