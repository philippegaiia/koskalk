<?php

namespace App\Filament\Exports;

use App\Models\FattyAcid;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Support\Number;

class FattyAcidExporter extends Exporter
{
    protected static ?string $model = FattyAcid::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id')
                ->label('ID'),
            ExportColumn::make('key')
                ->label(__('Key')),
            ExportColumn::make('name')
                ->label(__('Name')),
            ExportColumn::make('short_name')
                ->label(__('Short name')),
            ExportColumn::make('chain_length')
                ->label(__('Chain length')),
            ExportColumn::make('double_bonds')
                ->label(__('Double bonds')),
            ExportColumn::make('saturation_class')
                ->label(__('Saturation class')),
            ExportColumn::make('iodine_factor')
                ->label(__('Iodine factor')),
            ExportColumn::make('default_group_key')
                ->label(__('Default group key')),
            ExportColumn::make('display_order')
                ->label(__('Display order')),
            ExportColumn::make('is_core'),
            ExportColumn::make('is_active'),
            ExportColumn::make('default_hidden_below_percent')
                ->label(__('Hide below %')),
            ExportColumn::make('created_at'),
            ExportColumn::make('updated_at'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your fatty acid export has completed and '.Number::format($export->successful_rows).' '.str('row')->plural($export->successful_rows).' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.Number::format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to export.';
        }

        return $body;
    }
}
