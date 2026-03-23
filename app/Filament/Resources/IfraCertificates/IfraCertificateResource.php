<?php

namespace App\Filament\Resources\IfraCertificates;

use App\Filament\Resources\IfraCertificates\Pages\CreateIfraCertificate;
use App\Filament\Resources\IfraCertificates\Pages\EditIfraCertificate;
use App\Filament\Resources\IfraCertificates\Pages\ListIfraCertificates;
use App\Filament\Resources\IfraCertificates\Schemas\IfraCertificateForm;
use App\Filament\Resources\IfraCertificates\Tables\IfraCertificatesTable;
use App\Models\IfraCertificate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class IfraCertificateResource extends Resource
{
    protected static ?string $model = IfraCertificate::class;

    protected static ?string $recordTitleAttribute = 'certificate_name';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::DocumentCheck;

    protected static ?int $navigationSort = 60;

    public static function getNavigationGroup(): ?string
    {
        return 'Compliance';
    }

    public static function getModelLabel(): string
    {
        return 'IFRA certificate';
    }

    public static function getPluralModelLabel(): string
    {
        return 'IFRA certificates';
    }

    public static function form(Schema $schema): Schema
    {
        return IfraCertificateForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return IfraCertificatesTable::configure($table);
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
            'index' => ListIfraCertificates::route('/'),
            'create' => CreateIfraCertificate::route('/create'),
            'edit' => EditIfraCertificate::route('/{record}/edit'),
        ];
    }
}
