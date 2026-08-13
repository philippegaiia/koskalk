<?php

namespace App\Filament\Resources\Ingredients\Tables;

use App\Actions\IngredientEnrichment\StartIngredientEnrichmentBatch;
use App\Enums\IngredientCategory;
use App\Filament\Exports\IngredientExporter;
use App\Filament\Resources\IngredientEnrichmentBatches\IngredientEnrichmentBatchResource;
use App\Models\Ingredient;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ExportBulkAction;
use Filament\Actions\ViewAction;
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
        return $table
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
                IconColumn::make('requires_admin_review')
                    ->label('Review')
                    ->boolean(),
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
                TernaryFilter::make('requires_admin_review')
                    ->label('Requires review'),
                TernaryFilter::make('is_active')
                    ->label('Active'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
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
}
