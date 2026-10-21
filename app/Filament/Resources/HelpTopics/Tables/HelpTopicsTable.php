<?php

namespace App\Filament\Resources\HelpTopics\Tables;

use App\Actions\ContextualHelp\RunHelpTopicBulkAction;
use App\Enums\HelpTranslationRequestStatus as Status;
use App\Filament\Resources\HelpTopics\Pages\ListHelpTopics;
use App\Models\HelpTopic;
use App\Models\HelpTranslationRequest;
use App\Models\SupportedLocale;
use App\Models\User;
use App\Services\ContextualHelp\HelpTopicBulkOperations;
use Filament\Actions\BulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

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
        ])->recordActions([EditAction::make()])->toolbarActions([
            BulkAction::make('publishSelected')->label(__('help_admin.bulk.publish'))
                ->requiresConfirmation()->modalDescription(__('help_admin.bulk.publish_description'))
                ->mountUsing(fn (Collection $records, ListHelpTopics $livewire) => self::captureSelection($livewire, $records))
                ->action(fn (ListHelpTopics $livewire) => self::runBulkAction($livewire))
                ->deselectRecordsAfterCompletion(),
            BulkAction::make('translateSelected')->label(__('help_admin.bulk.translate'))
                ->modalDescription(__('help_admin.bulk.translate_description'))
                ->schema([
                    Select::make('locales')->label(__('help_admin.bulk.languages'))->multiple()->required()->minItems(1)->live()
                        ->maxItems(fn (ListHelpTopics $livewire): int => intdiv(HelpTopicBulkOperations::MaxTranslationRequests, max(1, count($livewire->helpBulkSnapshot))))
                        ->helperText(fn (?array $state, ListHelpTopics $livewire): string => __('help_admin.bulk.request_count', ['count' => count($livewire->helpBulkSnapshot) * count($state ?? []), 'limit' => HelpTopicBulkOperations::MaxTranslationRequests]))
                        ->options(fn (): array => SupportedLocale::query()->where('is_active', true)->where('code', '!=', 'en')->ordered()->pluck('name', 'code')->all()),
                    Checkbox::make('review_before_publishing')->label(__('help_admin.bulk.review'))->default(true)
                        ->helperText(__('help_admin.bulk.review_help')),
                ])
                ->mountUsing(function (Collection $records, ListHelpTopics $livewire, Schema $schema): void {
                    self::captureSelection($livewire, $records);
                    $schema->fill(['locales' => [], 'review_before_publishing' => true]);
                })
                ->action(fn (array $data, ListHelpTopics $livewire) => self::runBulkAction($livewire, $data['locales'], $data['review_before_publishing']))
                ->deselectRecordsAfterCompletion(),
        ])->maxSelectableRecords(HelpTopicBulkOperations::MaxTopics)
            ->poll(fn (): ?string => HelpTranslationRequest::query()->whereIn('status', [Status::Pending, Status::Running])->exists() ? '10s' : null)
            ->defaultSort('key')->emptyStateHeading('No help topics registered');
    }

    private static function captureSelection(ListHelpTopics $livewire, Collection $records): void
    {
        $livewire->helpBulkSnapshot = app(HelpTopicBulkOperations::class)->snapshot($records);
    }

    /** @param list<string> $locales */
    private static function runBulkAction(ListHelpTopics $livewire, array $locales = [], bool $reviewBeforePublishing = true): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        $selection = $livewire->helpBulkSnapshot;
        $livewire->helpBulkSnapshot = [];
        $result = app(RunHelpTopicBulkAction::class)->handle($actor, $selection, $locales, $reviewBeforePublishing);
        $notification = Notification::make()->title(__('help_admin.bulk.completed'))
            ->body(__('help_admin.bulk.result', $result));
        if ($result['published'] === 0 && $result['queued'] === 0) {
            $notification->warning();
        } else {
            $notification->success();
        }
        $notification->send();
    }
}
