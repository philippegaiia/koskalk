<?php

namespace App\Filament\Resources\Ingredients\Tables;

use App\Actions\IngredientEnrichment\StartIngredientEnrichmentBatch;
use App\Actions\IngredientEnrichment\StartIngredientGuidanceRefresh;
use App\Actions\Ingredients\MarkIngredientsCatalogReviewed;
use App\Enums\IngredientCatalogReviewStep;
use App\Enums\IngredientCategory;
use App\Filament\Exports\IngredientExporter;
use App\Filament\Resources\IngredientEnrichmentBatches\IngredientEnrichmentBatchResource;
use App\Models\Ingredient;
use App\Models\User;
use App\Services\IngredientCatalogReviewService;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ExportBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Checkbox;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class IngredientsTable
{
    public static function configure(Table $table): Table
    {
        $reviews = app(IngredientCatalogReviewService::class);

        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                ...$reviews->translationEagerLoad(),
                'catalogReviewedBy:id,name',
            ]))
            ->columns([
                TextColumn::make('display_name')
                    ->label(__('Ingredient'))
                    ->searchable()
                    ->sortable()
                    ->description(fn (Ingredient $record): ?string => $record->inci_name)
                    ->wrap(),
                TextColumn::make('catalog_key')
                    ->label(__('Code'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('category')
                    ->badge()
                    ->sortable(),
                IconColumn::make('is_soap_saponification_trusted')
                    ->label('Soap oil')
                    ->boolean(),
                IconColumn::make('catalog_enriched')
                    ->label(__('ingredient_admin.catalog_review.columns.enriched'))
                    ->state(fn (Ingredient $record): bool => $reviews->progress($record)['enriched'])
                    ->boolean(),
                IconColumn::make('catalog_guidance')
                    ->label(__('ingredient_admin.catalog_review.columns.guidance'))
                    ->state(fn (Ingredient $record): bool => $reviews->progress($record)['has_guidance'])
                    ->boolean(),
                TextColumn::make('catalog_translated')
                    ->label(__('ingredient_admin.catalog_review.columns.translated'))
                    ->state(fn (Ingredient $record): string => self::translatedState($reviews->progress($record)))
                    ->badge()
                    ->color(fn (Ingredient $record): string => self::translatedColor($reviews->progress($record))),
                TextColumn::make('catalog_reviewed_at')
                    ->label(__('ingredient_admin.catalog_review.columns.reviewed'))
                    ->state(fn (Ingredient $record): string => match (true) {
                        $record->requires_admin_review => __('ingredient_admin.catalog_review.not_reviewed'),
                        $record->catalog_reviewed_at === null => __('ingredient_admin.catalog_review.reviewed_without_date'),
                        default => $record->catalog_reviewed_at->toFormattedDateString(),
                    })
                    ->description(fn (Ingredient $record): ?string => $record->requires_admin_review || $record->catalogReviewedBy === null
                        ? null
                        : __('ingredient_admin.catalog_review.reviewed_by', ['name' => $record->catalogReviewedBy->name]))
                    ->badge()
                    ->color(fn (Ingredient $record): string => $record->requires_admin_review ? 'gray' : 'success')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                        ->orderBy('requires_admin_review', $direction === 'asc' ? 'desc' : 'asc')
                        ->orderBy('catalog_reviewed_at', $direction)),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->multiple()
                    ->options(IngredientCategory::options()),
                TernaryFilter::make('is_soap_saponification_trusted')
                    ->label('Trusted for soap saponification'),
                SelectFilter::make('catalog_review_step')
                    ->label(__('ingredient_admin.catalog_review.filters.step'))
                    ->options(IngredientCatalogReviewStep::options())
                    ->query(fn (Builder $query, array $data): Builder => ($step = IngredientCatalogReviewStep::tryFrom((string) ($data['value'] ?? ''))) === null
                        ? $query
                        : $reviews->constrainToStep($query, $step)),
                TernaryFilter::make('catalog_enriched')
                    ->label(__('ingredient_admin.catalog_review.filters.enriched'))
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('source_data->enrichment->core'),
                        false: fn (Builder $query): Builder => $query->whereNull('source_data->enrichment->core'),
                        blank: fn (Builder $query): Builder => $query,
                    ),
                TernaryFilter::make('is_active')
                    ->label('Active'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('markCatalogReviewed')
                        ->label(__('ingredient_admin.catalog_review.actions.mark_reviewed'))
                        ->icon('heroicon-o-check-badge')
                        ->requiresConfirmation()
                        ->modalHeading(__('ingredient_admin.catalog_review.actions.mark_reviewed_heading'))
                        ->modalDescription(__('ingredient_admin.catalog_review.actions.mark_reviewed_description'))
                        ->action(function (Collection $records, MarkIngredientsCatalogReviewed $markReviewed): void {
                            $actor = auth()->user();

                            abort_unless($actor instanceof User, 403);

                            $marked = $markReviewed->handle($actor, $records);

                            Notification::make()
                                ->title(trans_choice('ingredient_admin.catalog_review.actions.marked', $marked, ['count' => $marked]))
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('runAiEnrichment')
                        ->label(__('ingredient_enrichment_admin.actions.run'))
                        ->icon('heroicon-o-sparkles')
                        ->requiresConfirmation()
                        ->modalHeading(__('ingredient_enrichment_admin.actions.run_heading'))
                        ->modalDescription(fn (): string => __('ingredient_enrichment_admin.actions.run_description', [
                            'model' => config('ingredient-enrichment.openai.model'),
                        ]))
                        ->action(function (Collection $records, StartIngredientEnrichmentBatch $startBatch): mixed {
                            $batch = $startBatch->handle(auth()->user(), $records);

                            return redirect(IngredientEnrichmentBatchResource::getUrl('view', ['record' => $batch]));
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('runGuidanceRefresh')
                        ->label(__('ingredient_enrichment_admin.actions.guidance_refresh'))
                        ->icon('heroicon-o-sparkles')
                        ->requiresConfirmation()
                        ->modalHeading(__('ingredient_enrichment_admin.actions.guidance_refresh_heading'))
                        ->modalDescription(__('ingredient_enrichment_admin.actions.guidance_refresh_description'))
                        ->schema([
                            Checkbox::make('fresh_research')
                                ->label(__('ingredient_enrichment_admin.actions.fresh_research'))
                                ->helperText(__('ingredient_enrichment_admin.actions.fresh_research_help'))
                                ->default(false),
                        ])
                        ->action(function (Collection $records, array $data, StartIngredientGuidanceRefresh $startRefresh): mixed {
                            $batch = $startRefresh->handle(
                                auth()->user(),
                                $records,
                                freshResearch: (bool) ($data['fresh_research'] ?? false),
                            );

                            return redirect(IngredientEnrichmentBatchResource::getUrl('view', ['record' => $batch]));
                        })
                        ->visible(fn (): bool => (bool) config('ingredient-enrichment.openai.guidance_generation.enabled', false))
                        ->deselectRecordsAfterCompletion(),
                    ExportBulkAction::make()
                        ->exporter(IngredientExporter::class)
                        ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                            'identifiers',
                            'aliases',
                            'substanceEntries.substance',
                        ])),
                ]),
            ])
            ->defaultSort('catalog_key')
            ->emptyStateHeading('No ingredients yet')
            ->emptyStateDescription('Seed the starter catalog or add a platform ingredient manually.')
            ->paginated([25, 50, 100, 'all']);
    }

    /** @param  array{translated: int, stale: int, expected: int, has_guidance: bool}  $progress */
    private static function translatedState(array $progress): string
    {
        if ($progress['translated'] === 0 && ! $progress['has_guidance']) {
            return '—';
        }

        if ($progress['stale'] > 0) {
            return __('ingredient_admin.catalog_review.translated_stale', ['count' => $progress['stale']]);
        }

        return __('ingredient_admin.catalog_review.translated_state', [
            'translated' => $progress['translated'],
            'expected' => $progress['expected'],
        ]);
    }

    /** @param  array{translated: int, stale: int, expected: int}  $progress */
    private static function translatedColor(array $progress): string
    {
        return match (true) {
            $progress['stale'] > 0 => 'danger',
            $progress['translated'] === 0 => 'gray',
            $progress['translated'] < $progress['expected'] => 'warning',
            default => 'success',
        };
    }
}
