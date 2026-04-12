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
            ExportColumn::make('source_file'),
            ExportColumn::make('source_key')
                ->label(__('Code')),
            ExportColumn::make('source_code_prefix'),
            ExportColumn::make('supplier_name'),
            ExportColumn::make('supplier_reference'),
            ExportColumn::make('soap_inci_naoh_name'),
            ExportColumn::make('soap_inci_koh_name'),
            ExportColumn::make('cas_number'),
            ExportColumn::make('ec_number'),
            ExportColumn::make('unit'),
            ExportColumn::make('visibility'),
            ExportColumn::make('workspace.name')
                ->label(__('Workspace')),
            ExportColumn::make('is_potentially_saponifiable'),
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
