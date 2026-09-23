<?php

namespace App\Filament\Resources\HelpTopics\Tables;

use App\Models\HelpTopic;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class HelpTopicsTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('key')->searchable()->sortable()->description(fn (HelpTopic $record): ?string => $record->locales->firstWhere('locale', 'en')?->latestRevision?->title),
            TextColumn::make('domain')->badge()->formatStateUsing(fn ($state): string => str_replace('_', ' ', $state->value)),
            TextColumn::make('publication')->state(function (HelpTopic $record): string {
                $en = $record->locales->firstWhere('locale', 'en');

                return $record->archived_at ? 'Archived' : (! $en?->published_revision_id ? 'Draft' : ($en->latest_revision_id === $en->published_revision_id ? 'Published' : 'Unpublished changes'));
            })->badge(),
            TextColumn::make('translations')->state(function (HelpTopic $record): string {
                $english = $record->locales->firstWhere('locale', 'en')?->published_revision_id;
                $translated = $record->locales->where('locale', '!=', 'en')->filter(fn ($locale): bool => $locale->latest_revision_id !== null);
                $current = $english ? $translated->filter(fn ($locale): bool => $locale->publishedRevision?->source_english_revision_id === $english)->count() : 0;

                return $current.' current / '.($translated->count() - $current).' need review';
            }),
            TextColumn::make('last_edit')->state(fn (HelpTopic $record) => $record->locales->map(fn ($locale) => $locale->latestRevision?->created_at)->filter()->max())->since(),
        ])->filters([
            SelectFilter::make('domain')->options(['application' => 'Products, media and settings', 'shared_workbench' => 'Shared workbench', 'soap_workbench' => 'Soap workbench', 'cosmetic_workbench' => 'Cosmetic workbench', 'production_inventory' => 'Production inventory', 'production_purchasing' => 'Production purchasing', 'production_runs' => 'Production', 'material_library' => 'Ingredients and packaging']),
            TernaryFilter::make('archived_at')->label('Archived')->nullable()->default(false),
        ])->recordActions([EditAction::make()])->defaultSort('key')->emptyStateHeading('No help topics registered');
    }
}
