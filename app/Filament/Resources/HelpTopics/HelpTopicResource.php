<?php

namespace App\Filament\Resources\HelpTopics;

use App\Filament\Resources\HelpTopics\Pages\EditHelpTopic;
use App\Filament\Resources\HelpTopics\Pages\ListHelpTopics;
use App\Filament\Resources\HelpTopics\Schemas\HelpTopicForm;
use App\Filament\Resources\HelpTopics\Tables\HelpTopicsTable;
use App\Models\HelpTopic;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class HelpTopicResource extends Resource
{
    protected static ?string $model = HelpTopic::class;

    protected static ?string $recordTitleAttribute = 'key';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::QuestionMarkCircle;

    protected static ?int $navigationSort = 82;

    public static function getNavigationGroup(): ?string
    {
        return 'Content';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Contextual Help';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['locales.latestRevision', 'locales.publishedRevision']);
    }

    public static function form(Schema $schema): Schema
    {
        return HelpTopicForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return HelpTopicsTable::configure($table);
    }

    public static function getPages(): array
    {
        return ['index' => ListHelpTopics::route('/'), 'edit' => EditHelpTopic::route('/{record}/edit')];
    }
}
