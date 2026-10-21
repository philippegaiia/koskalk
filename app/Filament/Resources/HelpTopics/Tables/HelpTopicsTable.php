<?php

namespace App\Filament\Resources\HelpTopics\Tables;

use App\Actions\ContextualHelp\PublishHelpTranslationCandidates;
use App\Actions\ContextualHelp\RunHelpTopicBulkAction;
use App\Enums\HelpTranslationRequestStatus as Status;
use App\Filament\Resources\HelpTopics\Pages\ListHelpTopics;
use App\Models\HelpTopic;
use App\Models\HelpTopicLocale;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class HelpTopicsTable
{
    public static function configure(Table $table): Table
    {
        return $table->description(fn (): ?string => self::queueProgress())->columns([
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

                $review = $record->locales->where('locale', '!=', 'en')->filter(fn (HelpTopicLocale $locale): bool => $locale->latestTranslationRequest?->status === Status::Completed
                    || ($locale->latest_revision_id !== null && ($locale->latest_revision_id !== $locale->published_revision_id || $locale->latestRevision?->source_english_revision_id !== $english)))->count();

                return __('help_admin.list.translation_summary', ['current' => $current, 'review' => $review]);
            })->description(fn (HelpTopic $record): ?string => self::translationProgress($record))
                ->tooltip(fn (HelpTopic $record): ?string => $record->locales->filter(fn (HelpTopicLocale $locale): bool => filled($locale->latestTranslationRequest?->error_message))
                    ->map(fn (HelpTopicLocale $locale): string => strtoupper($locale->locale).': '.$locale->latestTranslationRequest->error_message)->implode("\n") ?: null),
            TextColumn::make('last_edit')->state(fn (HelpTopic $record) => $record->locales->map(fn ($locale) => $locale->latestRevision?->created_at)->filter()->max())->since(),
        ])->filters([
            SelectFilter::make('publication')->label(__('help_admin.list.publication'))
                ->options(['unpublished' => __('help_admin.list.unpublished'), 'published' => __('help_admin.list.published'), 'changes' => __('help_admin.list.changes')])
                ->query(function (Builder $query, array $data): Builder {
                    return match ($data['value'] ?? null) {
                        'unpublished' => $query->whereDoesntHave('locales', fn (Builder $locales) => $locales->where('locale', 'en')->whereNotNull('published_revision_id')),
                        'published' => $query->whereHas('locales', fn (Builder $locales) => $locales->where('locale', 'en')->whereNotNull('published_revision_id')->whereColumn('latest_revision_id', 'published_revision_id')),
                        'changes' => $query->whereHas('locales', fn (Builder $locales) => $locales->where('locale', 'en')->whereNotNull('published_revision_id')->whereColumn('latest_revision_id', '!=', 'published_revision_id')),
                        default => $query,
                    };
                }),
            SelectFilter::make('domain')->options(['application' => 'Products, media and settings', 'shared_workbench' => 'Shared workbench', 'soap_workbench' => 'Soap workbench', 'cosmetic_workbench' => 'Cosmetic workbench', 'production_inventory' => 'Production inventory', 'production_purchasing' => 'Production purchasing', 'production_runs' => 'Production', 'material_library' => 'Ingredients and packaging']),
            TernaryFilter::make('archived_at')->label('Archived')->nullable()->default(false),
        ])->recordActions([EditAction::make()])->toolbarActions([
            BulkAction::make('publishSelected')->label(__('help_admin.bulk.publish'))
                ->requiresConfirmation()->modalDescription(__('help_admin.bulk.publish_description'))
                ->mountUsing(fn (Collection $records, ListHelpTopics $livewire) => self::captureSelection($livewire, $records))
                ->action(fn (ListHelpTopics $livewire) => self::runBulkAction($livewire))
                ->deselectRecordsAfterCompletion(),
            BulkAction::make('translateSelected')->label(__('help_admin.bulk.translate'))
                ->modalDescription(fn (): string => __('help_admin.bulk.translate_description').' '.__('help_admin.translation.requested', ['model' => config('contextual-help.translation.model') ?: config('ingredient-enrichment.openai.localization_model'), 'effort' => config('contextual-help.translation.reasoning_effort') ?: config('ingredient-enrichment.openai.localization_reasoning_effort')]))
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
            BulkAction::make('publishTranslations')->label(__('help_admin.bulk.publish_translations'))
                ->modalDescription(fn (ListHelpTopics $livewire): string => __('help_admin.bulk.publish_translations_description').' '.self::candidateModels($livewire))
                ->schema([
                    Select::make('locales')->label(__('help_admin.bulk.languages'))->multiple()->required()->minItems(1)
                        ->maxItems(fn (ListHelpTopics $livewire): int => intdiv(HelpTopicBulkOperations::MaxTranslationRequests, max(1, count($livewire->helpBulkSnapshot))))
                        ->options(fn (): array => SupportedLocale::query()->where('is_active', true)->where('code', '!=', 'en')->ordered()->pluck('name', 'code')->all()),
                ])
                ->mountUsing(function (Collection $records, ListHelpTopics $livewire, Schema $schema): void {
                    self::captureSelection($livewire, $records);
                    $schema->fill(['locales' => SupportedLocale::query()->where('is_active', true)->where('code', '!=', 'en')->ordered()->pluck('code')->all()]);
                })
                ->action(fn (array $data, ListHelpTopics $livewire) => self::publishCandidates($livewire, $data['locales']))
                ->deselectRecordsAfterCompletion(),
        ])->paginated([25, 50, 100])->defaultPaginationPageOption(100)
            ->maxSelectableRecords(HelpTopicBulkOperations::MaxTopics)
            ->poll(fn (): ?string => HelpTranslationRequest::query()->whereIn('status', [Status::Pending, Status::Running])->exists() ? '10s' : null)
            ->defaultSort('key')->emptyStateHeading('No help topics registered');
    }

    private static function translationProgress(HelpTopic $topic): ?string
    {
        $requests = $topic->locales->pluck('latestTranslationRequest')->filter();
        $counts = collect(['queued' => Status::Pending, 'running' => Status::Running, 'failed' => Status::Failed])
            ->map(fn (Status $status): int => $requests->where('status', $status)->count());
        $text = $counts->filter()->map(fn (int $count, string $state): string => __('help_admin.list.'.$state, ['count' => $count]))->implode(' · ');

        return $text !== '' ? $text : null;
    }

    private static function queueProgress(): ?string
    {
        $counts = HelpTranslationRequest::query()->selectRaw('status, COUNT(*) AS total, MIN(created_at) AS oldest')
            ->whereIn('status', [Status::Pending, Status::Running])->groupBy('status')->get()->keyBy(fn (HelpTranslationRequest $request): string => $request->status->value);
        $pending = $counts->get(Status::Pending->value);
        $running = (int) ($counts->get(Status::Running->value)?->total ?? 0);
        $queued = (int) ($pending?->total ?? 0);
        if ($queued === 0 && $running === 0) {
            return null;
        }
        $text = __('help_admin.list.queue_summary', ['queued' => $queued, 'running' => $running]);
        if ($queued > 0 && $running === 0 && $pending->oldest < now()->subMinutes(2)->toDateTimeString()) {
            $text .= ' '.__('help_admin.list.worker_wait', ['queue' => config('contextual-help.queue')]);
        }

        return $text;
    }

    private static function candidateModels(ListHelpTopics $livewire): string
    {
        $ids = collect($livewire->helpBulkSnapshot)->flatMap(fn (array $topic): array => array_values($topic['candidate_requests']))->unique()->values();
        $requests = HelpTranslationRequest::query()->whereKey($ids)->get(['model', 'reasoning_effort', 'response_model']);
        $models = $requests->map(fn (HelpTranslationRequest $request): string => $request->model.' ('.$request->reasoning_effort.')')->unique()->implode(', ');

        if ($models === '') {
            return __('help_admin.translation.no_candidates');
        }
        $reported = $requests->map(fn (HelpTranslationRequest $request): string => $request->response_model ?: __('help_admin.translation.not_reported'))->unique()->implode(', ');

        return __('help_admin.translation.saved_models', ['models' => $models]).' '.__('help_admin.translation.saved_reported', ['models' => $reported]);
    }

    private static function captureSelection(ListHelpTopics $livewire, Collection $records): void
    {
        $livewire->helpBulkSnapshot = app(HelpTopicBulkOperations::class)->snapshot($records);
    }

    /** @param list<string> $locales */
    private static function publishCandidates(ListHelpTopics $livewire, array $locales): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        $selection = $livewire->helpBulkSnapshot;
        $livewire->helpBulkSnapshot = [];
        $result = app(PublishHelpTranslationCandidates::class)->handle($actor, $selection, $locales);
        $notification = Notification::make()->title(__('help_admin.bulk.completed'))->body(__('help_admin.bulk.translation_result', $result));
        if ($result['published'] > 0) {
            $notification->success();
        } else {
            $notification->warning();
        }
        $notification->send();
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
