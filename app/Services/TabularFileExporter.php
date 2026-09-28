<?php

namespace App\Services;

use Illuminate\Support\Str;
use InvalidArgumentException;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\Common\Entity\Sheet;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;
use Throwable;

/**
 * Writes already-authorized table data to temporary XLSX files or CSV strings.
 */
class TabularFileExporter
{
    /**
     * @param  list<array{
     *     name: string,
     *     title?: string,
     *     context?: list<array{label: string, value: scalar|null, type?: 'string'|'number'|'boolean'}>,
     *     columns: list<array{key: string, label: string, type: 'string'|'number'|'boolean', format?: string, width?: float}>,
     *     rows: iterable<array<string, scalar|null>>,
     *     tables?: list<array{title?: string, columns: list<array{key: string, label: string, type: 'string'|'number'|'boolean', format?: string, width?: float}>, rows: iterable<array<string, scalar|null>>}>
     * }>  $sheets
     */
    public function exportXlsx(array $sheets): string
    {
        $path = $this->writeTemporaryXlsx($sheets);

        try {
            $contents = file_get_contents($path);

            if ($contents === false) {
                throw new RuntimeException('Unable to read the spreadsheet export.');
            }

            return $contents;
        } finally {
            $this->deleteTemporaryFile($path);
        }
    }

    /**
     * @param  list<array{
     *     name: string,
     *     title?: string,
     *     context?: list<array{label: string, value: scalar|null, type?: 'string'|'number'|'boolean'}>,
     *     columns: list<array{key: string, label: string, type: 'string'|'number'|'boolean', format?: string, width?: float}>,
     *     rows: iterable<array<string, scalar|null>>,
     *     tables?: list<array{title?: string, columns: list<array{key: string, label: string, type: 'string'|'number'|'boolean', format?: string, width?: float}>, rows: iterable<array<string, scalar|null>>}>
     * }>  $sheets
     */
    public function writeTemporaryXlsx(array $sheets, ?string $directory = null): string
    {
        $directory ??= sys_get_temp_dir();

        if (! is_dir($directory) || ! is_writable($directory)) {
            throw new RuntimeException('The spreadsheet temporary directory is not writable.');
        }

        $path = tempnam($directory, 'koskalk-export-');

        if ($path === false) {
            throw new RuntimeException('Unable to create spreadsheet export.');
        }

        try {
            $this->writeXlsxToFile($path, $sheets);

            return $path;
        } catch (Throwable $exception) {
            $this->deleteTemporaryFile($path);

            throw $exception;
        }
    }

    /**
     * @param  list<array{
     *     name: string,
     *     title?: string,
     *     context?: list<array{label: string, value: scalar|null, type?: 'string'|'number'|'boolean'}>,
     *     columns: list<array{key: string, label: string, type: 'string'|'number'|'boolean', format?: string, width?: float}>,
     *     rows: iterable<array<string, scalar|null>>,
     *     tables?: list<array{title?: string, columns: list<array{key: string, label: string, type: 'string'|'number'|'boolean', format?: string, width?: float}>, rows: iterable<array<string, scalar|null>>}>
     * }>  $sheets
     */
    public function writeXlsxToFile(string $path, array $sheets): void
    {
        if ($sheets === []) {
            throw new InvalidArgumentException('At least one worksheet is required.');
        }

        $writer = new Writer;
        $opened = false;

        try {
            $writer->openToFile($path);
            $opened = true;

            foreach ($sheets as $index => $definition) {
                $sheet = $index === 0
                    ? $writer->getCurrentSheet()
                    : $writer->addNewSheetAndMakeItCurrent();
                $this->writeSheet($writer, $sheet, $definition);
            }
        } catch (Throwable $exception) {
            if ($opened) {
                try {
                    $writer->close();
                } catch (Throwable) {
                    // Preserve the write error so callers can clean up the partial file.
                }
            }

            throw $exception;
        }

        if ($opened) {
            $writer->close();
        }
    }

    /**
     * @param  array{
     *     columns: list<array{key: string, label: string, type: 'string'|'number'|'boolean'}>,
     *     rows: iterable<array<string, scalar|null>>
     * }  $table
     */
    public function exportCsv(array $table): string
    {
        $stream = fopen('php://temp', 'r+');

        if ($stream === false) {
            throw new RuntimeException('Unable to create CSV export.');
        }

        try {
            $this->writeCsvTable($stream, $table);
            rewind($stream);
            $contents = stream_get_contents($stream);

            if ($contents === false) {
                throw new RuntimeException('Unable to read the CSV export.');
            }

            return $contents;
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param  array{
     *     columns: list<array{key: string, label: string, type: 'string'|'number'|'boolean'}>,
     *     rows: iterable<array<string, scalar|null>>
     * }  $table
     */
    public function writeCsvToFile(string $path, array $table): void
    {
        $stream = fopen($path, 'wb');

        if ($stream === false) {
            throw new RuntimeException('Unable to create CSV export file.');
        }

        try {
            $this->writeCsvTable($stream, $table);
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param  array{
     *     columns: list<array{key: string, label: string, type: 'string'|'number'|'boolean'}>,
     *     rows: iterable<array<string, scalar|null>>
     * }  $table
     */
    public function writeTemporaryCsv(array $table, ?string $directory = null): string
    {
        $directory ??= sys_get_temp_dir();

        if (! is_dir($directory) || ! is_writable($directory)) {
            throw new RuntimeException('The CSV temporary directory is not writable.');
        }

        $path = tempnam($directory, 'koskalk-export-');

        if ($path === false) {
            throw new RuntimeException('Unable to create CSV export.');
        }

        try {
            $this->writeCsvToFile($path, $table);

            return $path;
        } catch (Throwable $exception) {
            $this->deleteTemporaryFile($path);

            throw $exception;
        }
    }

    public function deleteTemporaryFile(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * @param  resource  $stream
     * @param array{
     *     columns: list<array{key: string, label: string, type: 'string'|'number'|'boolean'}>,
     *     rows: iterable<array<string, scalar|null>>
     * } $table
     */
    private function writeCsvTable($stream, array $table): void
    {
        if (($table['columns'] ?? []) === []) {
            throw new InvalidArgumentException('A CSV export must define at least one column.');
        }

        $this->writeCsvRow($stream, collect($table['columns'])
            ->map(fn (array $column): string => $column['label'])
            ->all());

        foreach ($table['rows'] as $row) {
            $values = [];

            foreach ($table['columns'] as $column) {
                $values[] = $this->csvValue($row[$column['key']] ?? null, $column['type']);
            }

            $this->writeCsvRow($stream, $values);
        }
    }

    /** @param resource $stream
     * @param  list<string>  $values
     */
    private function writeCsvRow($stream, array $values): void
    {
        if (fputcsv($stream, $values, ',', '"', escape: '') === false) {
            throw new RuntimeException('Unable to write CSV export.');
        }
    }

    /**
     * @param  array{
     *     name: string,
     *     title?: string,
     *     context?: list<array{label: string, value: scalar|null, type?: 'string'|'number'|'boolean'}>,
     *     columns: list<array{key: string, label: string, type: 'string'|'number'|'boolean', format?: string, width?: float}>,
     *     rows: iterable<array<string, scalar|null>>,
     *     tables?: list<array{
     *         title?: string,
     *         columns: list<array{key: string, label: string, type: 'string'|'number'|'boolean', format?: string, width?: float}>,
     *         rows: iterable<array<string, scalar|null>>
     *     }>
     * }  $definition
     */
    private function writeSheet(Writer $writer, Sheet $sheet, array $definition): void
    {
        $tables = is_array($definition['tables'] ?? null)
            ? $definition['tables']
            : [['columns' => $definition['columns'] ?? [], 'rows' => $definition['rows'] ?? []]];

        if ($tables === []) {
            throw new InvalidArgumentException('A worksheet must define at least one column.');
        }

        $sheet->setName($this->sheetName((string) ($definition['name'] ?? 'Sheet')));

        $writer->addRow(new Row([new StringCell((string) ($definition['title'] ?? $definition['name']), $this->titleStyle())]));
        $writer->addRow(Row::fromValues([]));

        foreach ($definition['context'] ?? [] as $contextRow) {
            $writer->addRow(new Row([
                new StringCell((string) $contextRow['label'], $this->labelStyle()),
                $this->cell($contextRow['value'], $contextRow['type'] ?? 'string'),
            ]));
        }

        $writer->addRow(Row::fromValues([]));
        $currentRowNumber = 4 + count($definition['context'] ?? []);
        $firstTable = true;

        foreach ($tables as $tableIndex => $table) {
            $columns = $table['columns'] ?? [];

            if ($columns === []) {
                throw new InvalidArgumentException('A worksheet table must define at least one column.');
            }

            if (filled($table['title'] ?? null)) {
                $writer->addRow(new Row([new StringCell((string) $table['title'], $this->sectionStyle())]));
                $currentRowNumber++;
            }

            foreach ($columns as $index => $column) {
                $sheet->setColumnWidth(max(8, min(60, (float) ($column['width'] ?? 18))), $index + 1);
            }

            $headerRowNumber = $currentRowNumber;
            $writer->addRow(new Row(collect($columns)
                ->map(fn (array $column): StringCell => new StringCell((string) $column['label'], $this->headerStyle()))
                ->all()));
            $currentRowNumber++;
            $dataRowCount = 0;

            foreach ($table['rows'] as $row) {
                $cells = [];

                foreach ($columns as $column) {
                    $cells[] = $this->cell($row[$column['key']] ?? null, $column['type'], $column['format'] ?? null);
                }

                $writer->addRow(new Row($cells));
                $dataRowCount++;
                $currentRowNumber++;
            }

            if ($firstTable) {
                $sheet->setSheetView((new SheetView)->setFreezeRow($headerRowNumber + 1));
                $sheet->setAutoFilter(new AutoFilter(
                    0,
                    $headerRowNumber,
                    count($columns) - 1,
                    max($headerRowNumber, $headerRowNumber + $dataRowCount),
                ));
                $firstTable = false;
            }

            if ($tableIndex < array_key_last($tables)) {
                $writer->addRow(Row::fromValues([]));
                $currentRowNumber++;
            }
        }
    }

    private function cell(mixed $value, string $type, ?string $format = null): Cell
    {
        if ($value === null || $value === '') {
            return Cell::fromValue(null);
        }

        return match ($type) {
            'string' => new StringCell((string) $value, $this->textStyle()),
            'number' => $this->numericCell($value, $format),
            'boolean' => Cell::fromValue((bool) $value),
            default => throw new InvalidArgumentException("Unsupported spreadsheet column type [{$type}]."),
        };
    }

    private function numericCell(mixed $value, ?string $format): NumericCell
    {
        if ((! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value)))
            || (is_float($value) && ! is_finite($value))) {
            throw new InvalidArgumentException('Numeric spreadsheet cells require a finite numeric value.');
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            $integer = filter_var($value, FILTER_VALIDATE_INT);

            if ($integer === false) {
                throw new InvalidArgumentException('Integer spreadsheet cells must fit the supported integer range.');
            }

            $number = $integer;
        } else {
            $number = (float) $value;
        }

        if (! is_finite((float) $number)) {
            throw new InvalidArgumentException('Numeric spreadsheet cells require a finite numeric value.');
        }

        $style = (new Style)->setCellAlignment(CellAlignment::RIGHT);

        if (filled($format)) {
            $style->setFormat($format);
        }

        return new NumericCell($number, $style);
    }

    private function csvValue(mixed $value, string $type): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if ($type === 'string') {
            $text = (string) $value;

            return preg_match('/^[\s\x00-\x1F]*[=+\-@]/u', $text) === 1 ? "'{$text}" : $text;
        }

        if ($type === 'boolean') {
            return (bool) $value ? 'TRUE' : 'FALSE';
        }

        if ($type === 'number' && (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)))) {
            return (string) $value;
        }

        throw new InvalidArgumentException("Unsupported CSV cell value for type [{$type}].");
    }

    private function sheetName(string $name): string
    {
        $name = preg_replace('/[\\\\\/\?\*\[\]:]/u', ' ', trim($name)) ?? '';
        $name = Str::limit($name === '' ? 'Sheet' : $name, 31, '');

        return $name === '' ? 'Sheet' : $name;
    }

    private function titleStyle(): Style
    {
        return (new Style)
            ->setFontBold()
            ->setFontSize(16)
            ->setFontColor('FFFFFF')
            ->setBackgroundColor('2F5D50');
    }

    private function headerStyle(): Style
    {
        return (new Style)
            ->setFontBold()
            ->setFontColor('24352F')
            ->setBackgroundColor('E9F1ED')
            ->setCellAlignment(CellAlignment::LEFT);
    }

    private function labelStyle(): Style
    {
        return (new Style)
            ->setFontBold()
            ->setFontColor('52645D')
            ->setShouldWrapText();
    }

    private function sectionStyle(): Style
    {
        return (new Style)
            ->setFontBold()
            ->setFontColor('24352F');
    }

    private function textStyle(): Style
    {
        return (new Style)->setShouldWrapText();
    }
}
