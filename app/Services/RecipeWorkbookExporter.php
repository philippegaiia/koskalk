<?php

namespace App\Services;

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

                $this->writeIngredientBatchSheet($writer, $exportData);

                if (($exportData['document']['family'] ?? null) === 'soap') {
                    $this->writeSoapOutputSheet($writer, $exportData['document']);
                }
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
    private function writeIngredientBatchSheet(Writer $writer, array $exportData): void
    {
        $document = $exportData['document'];
        $sheet = $this->prepareSheet(
            $writer->getCurrentSheet(),
            __('formula_documents.exports.ingredient_batch'),
            [24, 34, 20, 16, 18, 10, 40],
        );

        $this->addTitle($writer, (string) data_get($document, 'identity.name', 'Formula'));
        $this->addRows($writer, [[
            data_get($document, 'identity.product_family', ''),
            data_get($document, 'identity.product_type', ''),
            $document['basis_weight'],
            $document['unit'],
        ]]);
        $this->addBlank($writer);
        $this->addHeader($writer, ['Section', 'Ingredient', 'Percentage basis', 'Percentage', 'Scaled weight', 'Unit', 'Note']);
        $this->addRows($writer, collect($exportData['ingredientRows'])
            ->map(fn (array $row): array => [
                $row['section'],
                $row['ingredient'],
                $row['percentage_basis'],
                $row['percentage'],
                $row['weight'],
                $row['unit'],
                $row['note'],
            ])
            ->all(), [
                0 => $this->wrapStyle(),
                1 => $this->wrapStyle(),
                2 => $this->wrapStyle(),
                3 => $this->numberStyle('0.0000'),
                4 => $this->numberStyle('0.0000'),
                6 => $this->wrapStyle(),
            ]);

        $lastRow = count($exportData['ingredientRows']) + 5;
        $sheet->setAutoFilter(new AutoFilter(0, 5, 6, max(5, $lastRow)));
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function writeSoapOutputSheet(Writer $writer, array $document): void
    {
        $output = $document['soap_output'];
        $this->prepareSheet(
            $writer->addNewSheetAndMakeItCurrent(),
            __('formula_documents.exports.soap_output'),
            [34, 24, 20, 18, 44],
        );

        $this->addTitle($writer, __('formula_documents.exports.soap_output'));
        $this->addRows($writer, [
            ['Cured basis', $output['basis_weight'], $document['unit']],
            ['Residual water', $output['residual_water_percentage'], '%'],
        ]);
        $this->addBlank($writer);
        $this->addHeader($writer, ['Component', 'Role', '% cured soap', 'Weight', 'Sources']);
        $this->addRows($writer, collect($output['rows'])
            ->map(fn (array $row): array => [
                $row['name'],
                str($row['role'])->replace('_', ' ')->title()->toString(),
                $row['percentage'],
                $row['weight'],
                implode(', ', $row['sources']),
            ])
            ->all(), [
                0 => $this->wrapStyle(),
                1 => $this->wrapStyle(),
                2 => $this->numberStyle('0.0000'),
                3 => $this->numberStyle('0.0000'),
                4 => $this->wrapStyle(),
            ]);
        $this->addBlank($writer);
        $this->addHeader($writer, ['Final INCI', 'Value']);
        $this->addRows($writer, [['Final INCI', $output['inci']]], $this->labelValueColumnStyles());
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
}
