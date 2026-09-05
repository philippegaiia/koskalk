<?php

namespace App\Filament\Resources\IngredientEnrichmentBatches\Schemas;

use App\Enums\IngredientAliasKind;
use App\Enums\IngredientCategory;
use App\Enums\IngredientEvidenceConfidence;
use App\Enums\IngredientIdentifierScheme;
use App\Enums\IngredientSourceTier;
use App\Enums\IngredientSubcategory;
use App\Models\IngredientFunction;
use App\Models\SupportedLocale;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;

class IngredientEnrichmentProposalForm
{
    /** @return array<int, mixed> */
    public static function schema(): array
    {
        return [
            Section::make(__('ingredient_enrichment_admin.form.identity'))->schema([
                TextInput::make('display_name')->label(__('ingredient_enrichment_admin.review.labels.display_name'))->required(),
                TextInput::make('inci_name')->label(__('ingredient_enrichment_admin.review.labels.inci_name'))->required(),
                Select::make('category')->label(__('ingredient_enrichment_admin.review.labels.category'))
                    ->options(IngredientCategory::options())->required()->live(),
                Select::make('subcategory')->label(__('ingredient_enrichment_admin.review.labels.subcategory'))
                    ->options(fn (Get $get): array => IngredientSubcategory::optionsFor($get('category')))
                    ->required(fn (Get $get): bool => $get('category') !== IngredientCategory::Other->value),
                TextInput::make('saponification_name')->label(__('ingredient_enrichment_admin.review.labels.saponification_name')),
                TextInput::make('soap_inci_naoh_name')->label(__('ingredient_enrichment_admin.review.labels.soap_inci_naoh_name')),
                TextInput::make('soap_inci_koh_name')->label(__('ingredient_enrichment_admin.review.labels.soap_inci_koh_name')),
                Toggle::make('soapmaking_relevant')->label(__('ingredient_enrichment_admin.review.labels.soapmaking_relevant')),
                MarkdownEditor::make('info_markdown')->label(__('ingredient_enrichment_admin.review.labels.info_markdown'))
                    ->columnSpanFull(),
            ])->columns(2),
            Section::make(__('ingredient_enrichment_admin.review.labels.aliases'))->schema([
                Repeater::make('aliases')->hiddenLabel()->schema([
                    TextInput::make('locale')->label(__('ingredient_enrichment_admin.form.locale'))->required(),
                    TextInput::make('name')->label(__('ingredient_enrichment_admin.form.alias_name'))->required(),
                    Select::make('kind')->label(__('ingredient_enrichment_admin.form.alias_kind'))
                        ->options(collect(IngredientAliasKind::cases())->mapWithKeys(
                            fn (IngredientAliasKind $kind): array => [$kind->value => $kind->label()],
                        )->all())->required(),
                    ...self::sourceFields(),
                ])->columns(3)->columnSpanFull(),
            ]),
            Section::make(__('ingredient_enrichment_admin.review.labels.identifiers'))->schema([
                Repeater::make('identifiers')->hiddenLabel()->schema([
                    Select::make('scheme')->label(__('ingredient_enrichment_admin.form.scheme'))->options(collect(IngredientIdentifierScheme::cases())
                        ->mapWithKeys(fn (IngredientIdentifierScheme $scheme): array => [$scheme->value => $scheme->label()])->all())->required(),
                    TextInput::make('value')->label(__('ingredient_enrichment_admin.form.value'))->required(),
                    Toggle::make('is_primary')->label(__('ingredient_enrichment_admin.form.primary')),
                    ...self::sourceFields(),
                ])->columns(3)->columnSpanFull(),
            ]),
            Section::make(__('ingredient_enrichment_admin.review.labels.cosing_functions'))->schema([
                Repeater::make('cosing_functions')->hiddenLabel()->schema([
                    Select::make('key')->label(__('ingredient_enrichment_admin.form.function'))->options(fn (): array => IngredientFunction::query()->where('is_active', true)
                        ->orderBy('sort_order')->pluck('name', 'key')->all())->searchable()->required(),
                    ...self::sourceFields(),
                ])->columns(3)->columnSpanFull(),
            ]),
            Section::make(__('ingredient_enrichment_admin.review.labels.translations'))->schema([
                Repeater::make('translations')->hiddenLabel()->schema([
                    Select::make('locale')->label(__('ingredient_enrichment_admin.form.locale'))->options(fn (): array => SupportedLocale::query()->where('is_active', true)
                        ->ordered()->pluck('name', 'code')->all())->required(),
                    TextInput::make('display_name')->label(__('ingredient_enrichment_admin.review.labels.display_name'))->required(),
                    TextInput::make('saponification_name')->label(__('ingredient_enrichment_admin.review.labels.saponification_name')),
                    MarkdownEditor::make('info_markdown')->label(__('ingredient_enrichment_admin.review.labels.info_markdown'))
                        ->dehydrated(fn (?string $state): bool => filled($state))->columnSpanFull(),
                ])->columns(3)->columnSpanFull(),
            ]),
            Section::make(__('ingredient_enrichment_admin.review.labels.market_labels'))->schema([
                Repeater::make('market_labels')->hiddenLabel()->schema([
                    Select::make('market_code')->label(__('ingredient_enrichment_admin.form.market'))->options(fn (): array => collect(config('ingredient-enrichment.market_codes', []))
                        ->mapWithKeys(fn (string $market): array => [$market => strtoupper($market)])->all())->required(),
                    TextInput::make('declaration_name')->label(__('ingredient_enrichment_admin.form.declaration_name'))->required(),
                    DatePicker::make('reviewed_at')->label(__('ingredient_enrichment_admin.form.reviewed_at')),
                    DatePicker::make('effective_from')->label(__('ingredient_enrichment_admin.form.effective_from')),
                    DatePicker::make('effective_until')->label(__('ingredient_enrichment_admin.form.effective_until')),
                    ...self::sourceFields(),
                ])->columns(3)->columnSpanFull(),
            ]),
        ];
    }

    /** @return array<int, mixed> */
    private static function sourceFields(): array
    {
        return [
            TextInput::make('source_name')->label(__('ingredient_enrichment_admin.form.source_name'))->required(),
            TextInput::make('source_url')->label(__('ingredient_enrichment_admin.form.source_url'))->url()->required()->columnSpan(2),
            Select::make('source_tier')->label(__('ingredient_enrichment_admin.form.source_tier'))->options(collect(IngredientSourceTier::cases())
                ->mapWithKeys(fn (IngredientSourceTier $tier): array => [$tier->value => $tier->label()])->all())->required(),
            Select::make('confidence')->label(__('ingredient_enrichment_admin.form.confidence'))->options(collect(IngredientEvidenceConfidence::cases())
                ->mapWithKeys(fn (IngredientEvidenceConfidence $confidence): array => [$confidence->value => $confidence->label()])->all())->required(),
            TextInput::make('source_version')->label(__('ingredient_enrichment_admin.form.source_version')),
            DatePicker::make('source_updated_at')->label(__('ingredient_enrichment_admin.form.source_updated_at')),
            TextInput::make('retrieved_at')->label(__('ingredient_enrichment_admin.form.retrieved_at'))->required(),
        ];
    }
}
