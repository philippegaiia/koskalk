<?php

namespace App\Filament\Resources\IngredientEnrichmentBatches\Schemas;

use App\Models\SupportedLocale;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;

class IngredientGuidanceProposalForm
{
    /** @return array<int,mixed> */
    public static function schema(bool $localizationOnly = false): array
    {
        return [
            MarkdownEditor::make('info_markdown')
                ->label(__('ingredient_enrichment_admin.review.labels.info_markdown'))
                ->required()
                ->disabled($localizationOnly)
                ->dehydrated(! $localizationOnly)
                ->columnSpanFull(),
            Repeater::make('translations')
                ->label(__('ingredient_enrichment_admin.review.labels.translations'))
                ->schema([
                    Select::make('locale')
                        ->label(__('ingredient_enrichment_admin.form.locale'))
                        ->options(fn (): array => SupportedLocale::query()
                            ->where('is_active', true)
                            ->where('code', '!=', 'en')
                            ->ordered()
                            ->pluck('name', 'code')
                            ->all())
                        ->disabled()
                        ->dehydrated()
                        ->required(),
                    MarkdownEditor::make('info_markdown')
                        ->label(__('ingredient_enrichment_admin.review.labels.info_markdown'))
                        ->required()
                        ->columnSpanFull(),
                ])
                ->reorderable(false)
                ->columnSpanFull(),
        ];
    }
}
