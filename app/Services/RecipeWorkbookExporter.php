<?php

namespace App\Services;

use Illuminate\Support\Collection;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\Common\Entity\Sheet;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

class RecipeWorkbookExporter
{
    /**
     * @param  array<string, mixed>  $exportData
     */
    public function export(array $exportData): string
    {
        $path = tempnam(sys_get_temp_dir(), 'koskalk-recipe-export-');

        abort_if($path === false, 500, 'Unable to create spreadsheet export.');

        $writer = new Writer;

        try {
            try {
                $writer->openToFile($path);

                $this->writeSummarySheet($writer, $exportData);
                $this->writeFormulaSheet($writer, $exportData);
                $this->writePackagingSheet($writer, $exportData);
                $this->writeOutputsSheet($writer, $exportData);
                $this->writeDeclarationSheet($writer, $exportData);
                $this->writeCostingSheet($writer, $exportData);
            } finally {
                $writer->close();
            }

            $contents = file_get_contents($path);

            return $contents === false ? '' : $contents;
        } finally {
            $writer->close();

            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $exportData
     */
    private function writeSummarySheet(Writer $writer, array $exportData): void
    {
        $sheet = $this->prepareSheet($writer->getCurrentSheet(), 'Summary', [24, 34, 16, 16]);
        $recipe = $exportData['recipe'];
        $batchContext = $exportData['batchContext'];

        $this->addTitle($writer, 'Recipe summary');
        $this->addRows($writer, [
            ['Recipe', $recipe['name'] ?? ''],
            ['Saved recipe', $recipe['saved_name'] ?? ''],
            ['Product family', $recipe['product_family'] ?? ''],
            ['Product type', $recipe['product_type'] ?? ''],
            ['Saved at', $recipe['saved_at'] ?? ''],
            [$recipe['batch_basis_label'] ?? 'Batch basis', $recipe['batch_basis'] ?? '', $recipe['batch_unit'] ?? 'g'],
            ['Batch number', $batchContext['batch_number'] ?? ''],
            ['Manufacture date', $batchContext['manufacture_date'] ?? ''],
            ['Units produced', $batchContext['units_produced'] ?? ''],
        ], $this->labelValueColumnStyles());

        $this->addBlank($writer);
        $this->addHeader($writer, ['Metric', 'Value']);
        $this->addRows($writer, $this->pairs($exportData['summaryRows'] ?? []), $this->labelValueColumnStyles());
        $sheet->setAutoFilter(new AutoFilter(0, 13, 1, 13 + count($exportData['summaryRows'] ?? [])));
    }

    /**
     * @param  array<string, mixed>  $exportData
     */
    private function writeFormulaSheet(Writer $writer, array $exportData): void
    {
        $sheet = $this->prepareSheet($writer->addNewSheetAndMakeItCurrent(), 'Formula', [24, 30, 34, 14, 14, 36]);
        $this->addTitle($writer, 'Formula');
        $this->addHeader($writer, ['Phase', 'Ingredient', 'INCI name', 'Percentage', 'Weight', 'Note']);
        $rows = collect($exportData['formulaRows'] ?? [])
            ->map(fn (array $row): array => [
                $row['phase'] ?? '',
                $row['ingredient'] ?? '',
                $row['inci_name'] ?? '',
                $row['percentage'] ?? '',
                $row['weight'] ?? '',
                $row['note'] ?? '',
            ])
            ->all();

        $this->addRows($writer, $rows, [
            0 => $this->wrapStyle(),
            1 => $this->wrapStyle(),
            2 => $this->wrapStyle(),
            3 => $this->numberStyle('0.00'),
            4 => $this->numberStyle('0.00'),
            5 => $this->wrapStyle(),
        ]);

        $blankRow = count($rows) + 4;
        $firstDataRow = 4;
        $lastDataRow = max($firstDataRow, $blankRow - 1);

        $this->addBlank($writer);
        $this->addStyledRow($writer, [
            'Total',
            '',
            '',
            "=SUM(D{$firstDataRow}:D{$lastDataRow})",
            "=SUM(E{$firstDataRow}:E{$lastDataRow})",
            '',
        ], $this->totalStyle(), [
            3 => $this->totalNumberStyle('0.00'),
            4 => $this->totalNumberStyle('0.00'),
        ]);

        $sheet->setAutoFilter(new AutoFilter(0, 3, 5, $lastDataRow));
    }

    /**
     * @param  array<string, mixed>  $exportData
     */
    private function writePackagingSheet(Writer $writer, array $exportData): void
    {
        $sheet = $this->prepareSheet($writer->addNewSheetAndMakeItCurrent(), 'Packaging', [34, 20, 50]);
        $this->addTitle($writer, 'Packaging plan');
        $this->addHeader($writer, ['Packaging item', 'Components per unit', 'Notes']);
        $rows = collect($exportData['packagingRows'] ?? [])
            ->map(fn (array $row): array => [
                $row['name'] ?? '',
                $row['components_per_unit'] ?? '',
                $row['notes'] ?? '',
            ])
            ->whenEmpty(fn (Collection $rows): Collection => collect([['No packaging plan saved', '', '']]))
            ->all();

        $this->addRows($writer, $rows, [
            0 => $this->wrapStyle(),
            1 => $this->numberStyle('0.000'),
            2 => $this->wrapStyle(),
        ]);

        $sheet->setAutoFilter(new AutoFilter(0, 3, 2, count($rows) + 3));
    }

    /**
     * @param  array<string, mixed>  $exportData
     */
    private function writeOutputsSheet(Writer $writer, array $exportData): void
    {
        $this->prepareSheet($writer->addNewSheetAndMakeItCurrent(), 'Outputs', [28, 44]);
        $this->addTitle($writer, 'Outputs');
        $this->addHeader($writer, ['Label', 'Value']);
        $this->addRows($writer, $this->pairs($exportData['contextRows'] ?? []), $this->labelValueColumnStyles());

        if (($exportData['lyeRows'] ?? []) !== []) {
            $this->addBlank($writer);
            $this->addHeader($writer, ['Lye / water output', 'Value']);
            $this->addRows($writer, $this->pairs($exportData['lyeRows'] ?? []), $this->labelValueColumnStyles());
        }
    }

    /**
     * @param  array<string, mixed>  $exportData
     */
    private function writeDeclarationSheet(Writer $writer, array $exportData): void
    {
        $this->prepareSheet($writer->addNewSheetAndMakeItCurrent(), 'INCI Declaration', [24, 80]);
        $this->addTitle($writer, 'INCI / Declaration');
        $this->addHeader($writer, ['Field', 'Value']);
        $rows = $this->pairs($exportData['declarationRows'] ?? []);
        $this->addRows($writer, $rows !== [] ? $rows : [['Declaration', 'No declaration data saved.']], $this->labelValueColumnStyles());
    }

    /**
     * @param  array<string, mixed>  $exportData
     */
    private function writeCostingSheet(Writer $writer, array $exportData): void
    {
        $this->prepareSheet($writer->addNewSheetAndMakeItCurrent(), 'Costing', [24, 30, 14, 16, 16]);
        $this->addTitle($writer, 'Costing');

        if (! ($exportData['hasCostingData'] ?? false)) {
            $this->addRows($writer, [['No costing data saved for this official recipe.']]);

            return;
        }

        $this->addHeader($writer, ['Metric', 'Value']);
        $this->addRows($writer, $this->pairs($exportData['costingSummary'] ?? []), $this->labelValueColumnStyles());
        $this->addBlank($writer);
        $this->addHeader($writer, ['Phase', 'Ingredient', 'Weight', 'Price per kg', 'Line cost']);

        $ingredientRowsStart = 10;
        $ingredientRows = collect($exportData['costingIngredientRows'] ?? [])
            ->values()
            ->map(fn (array $row, int $index): array => [
                $row['phase'] ?? '',
                $row['name'] ?? '',
                $row['weight'] ?? '',
                $row['price_per_kg'] ?? '',
                '=C'.($ingredientRowsStart + $index).'*D'.($ingredientRowsStart + $index).'/1000',
            ])
            ->all();

        $this->addRows($writer, $ingredientRows, [
            0 => $this->wrapStyle(),
            1 => $this->wrapStyle(),
            2 => $this->numberStyle('0.00'),
            3 => $this->moneyStyle(),
            4 => $this->moneyStyle(),
        ]);

        $this->addBlank($writer);
        $this->addHeader($writer, ['Packaging item', 'Unit cost', 'Quantity', 'Cost per finished unit', 'Line cost']);

        $packagingRowsStart = $ingredientRowsStart + count($ingredientRows) + 2;
        $packagingRows = collect($exportData['costingPackagingRows'] ?? [])
            ->values()
            ->map(fn (array $row, int $index): array => [
                $row['name'] ?? '',
                $row['unit_cost'] ?? '',
                $row['quantity'] ?? '',
                '=B'.($packagingRowsStart + $index).'*C'.($packagingRowsStart + $index),
                $row['line_cost'] ?? '',
            ])
            ->all();

        $this->addRows($writer, $packagingRows, [
            0 => $this->wrapStyle(),
            1 => $this->moneyStyle(),
            2 => $this->numberStyle('0.000'),
            3 => $this->moneyStyle(),
            4 => $this->moneyStyle(),
        ]);
    }

    private function addTitle(Writer $writer, string $title): void
    {
        $writer->addRow(Row::fromValues([$title], $this->titleStyle()));
        $this->addBlank($writer);
    }

    /**
     * @param  array<int, scalar|null>  $values
     */
    private function addHeader(Writer $writer, array $values): void
    {
        $writer->addRow(Row::fromValues($values, $this->headerStyle()));
    }

    private function addBlank(Writer $writer): void
    {
        $writer->addRow(Row::fromValues([]));
    }

    /**
     * @param  array<int, array<int, scalar|null>>  $rows
     */
    private function addRows(Writer $writer, array $rows, array $columnStyles = []): void
    {
        foreach ($rows as $row) {
            $this->addStyledRow($writer, $row, null, $columnStyles);
        }
    }

    /**
     * @param  array<int, scalar|null>  $values
     * @param  array<int, Style>  $columnStyles
     */
    private function addStyledRow(Writer $writer, array $values, ?Style $rowStyle = null, array $columnStyles = []): void
    {
        $cells = collect($values)
            ->map(fn (mixed $value, int $index): Cell => Cell::fromValue($value, $columnStyles[$index] ?? null))
            ->all();

        $writer->addRow(new Row($cells, $rowStyle));
    }

    /**
     * @param  array<int, array<string, scalar|null>>  $rows
     * @return array<int, array<int, scalar|null>>
     */
    private function pairs(array $rows): array
    {
        return collect($rows)
            ->map(fn (array $row): array => [
                $row['label'] ?? '',
                trim((string) ($row['value'] ?? '').' '.(string) ($row['unit'] ?? '')),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, float>  $widths
     */
    private function prepareSheet(Sheet $sheet, string $name, array $widths): Sheet
    {
        $sheet->setName($name);

        foreach ($widths as $index => $width) {
            $sheet->setColumnWidth($width, $index + 1);
        }

        $sheet->setSheetView((new SheetView)->setFreezeRow(4));

        return $sheet;
    }

    /**
     * @return array<int, Style>
     */
    private function labelValueColumnStyles(): array
    {
        return [
            0 => $this->labelStyle(),
            1 => $this->wrapStyle(),
        ];
    }

    private function titleStyle(): Style
    {
        return (new Style)
            ->setFontBold()
            ->setFontSize(16)
            ->setFontColor(Color::WHITE)
            ->setBackgroundColor('FF2F5D50');
    }

    private function headerStyle(): Style
    {
        return (new Style)
            ->setFontBold()
            ->setFontColor('FF24352F')
            ->setBackgroundColor('FFE9F1ED')
            ->setCellAlignment(CellAlignment::LEFT);
    }

    private function totalStyle(): Style
    {
        return (new Style)
            ->setFontBold()
            ->setBackgroundColor('FFF4F7F5');
    }

    private function labelStyle(): Style
    {
        return (new Style)
            ->setFontBold()
            ->setFontColor('FF52645D')
            ->setShouldWrapText();
    }

    private function wrapStyle(): Style
    {
        return (new Style)->setShouldWrapText();
    }

    private function numberStyle(string $format): Style
    {
        return (new Style)
            ->setFormat($format)
            ->setCellAlignment(CellAlignment::RIGHT);
    }

    private function totalNumberStyle(string $format): Style
    {
        return $this->numberStyle($format)
            ->setFontBold()
            ->setBackgroundColor('FFF4F7F5');
    }

    private function moneyStyle(): Style
    {
        return $this->numberStyle('#,##0.00');
    }
}
