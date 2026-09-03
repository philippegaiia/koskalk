<?php

namespace App\Filament\Resources\IngredientEnrichmentBatches\Schemas;

use App\Models\SupportedLocale;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;

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
                    TextInput::make('display_name')
                        ->label(__('ingredient_enrichment_admin.review.labels.display_name'))
                        ->required(),
                    TextInput::make('saponification_name')
                        ->label(__('ingredient_enrichment_admin.review.labels.saponification_name')),
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
