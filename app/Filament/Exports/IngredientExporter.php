<?php

namespace App\Filament\Exports;

use App\Models\Ingredient;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Support\Number;

class IngredientExporter extends Exporter
{
    protected static ?string $model = Ingredient::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id')
                ->label('ID'),
            ExportColumn::make('display_name')
                ->label(__('Ingredient')),
            ExportColumn::make('inci_name'),
            ExportColumn::make('category'),
            ExportColumn::make('catalog_key')
                ->label(__('Code')),
            ExportColumn::make('notes'),
            ExportColumn::make('soap_inci_naoh_name'),
            ExportColumn::make('soap_inci_koh_name'),
            ExportColumn::make('identifiers')
                ->state(fn (Ingredient $record): string => $record->identifiers
                    ->map(fn ($identifier): string => sprintf('%s: %s', $identifier->scheme->label(), $identifier->value))
                    ->implode('; ')),
            ExportColumn::make('aliases')
                ->state(fn (Ingredient $record): string => $record->aliases
                    ->map(fn ($alias): string => sprintf('%s: %s', $alias->locale, $alias->name))
                    ->implode('; ')),
            ExportColumn::make('substance_entries')
                ->state(fn (Ingredient $record): string => $record->substanceEntries
                    ->loadMissing('substance')
                    ->map(fn ($entry): string => sprintf(
                        '%s%s',
                        $entry->substance?->name ?? 'Unknown substance',
                        $entry->concentration_percent === null ? '' : sprintf(' (%s%%)', $entry->concentration_percent),
                    ))
                    ->implode('; ')),
            ExportColumn::make('unit'),
            ExportColumn::make('visibility'),
            ExportColumn::make('workspace.name')
                ->label(__('Workspace')),
            ExportColumn::make('is_soap_saponification_trusted'),
            ExportColumn::make('is_manufactured'),
            ExportColumn::make('is_active'),
            ExportColumn::make('requires_admin_review'),
            ExportColumn::make('info_markdown'),
            ExportColumn::make('featured_image_path'),
            ExportColumn::make('created_at'),
            ExportColumn::make('updated_at'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your ingredient export has completed and '.Number::format($export->successful_rows).' '.str('row')->plural($export->successful_rows).' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.Number::format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to export.';
        }

        return $body;
    }
}
