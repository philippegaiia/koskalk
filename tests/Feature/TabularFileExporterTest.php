<?php

use App\Services\TabularFileExporter;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Reader\XLSX\Reader;

it('writes typed XLSX cells and keeps formula-like user text as text', function (): void {
    $exporter = app(TabularFileExporter::class);
    $path = $exporter->writeTemporaryXlsx([
        [
            'name' => 'Typed data',
            'title' => '=1+1',
            'context' => [
                ['label' => 'Currency', 'value' => 'EUR', 'type' => 'string'],
                ['label' => 'Basis', 'value' => 500, 'type' => 'number'],
            ],
            'columns' => [
                ['key' => 'name', 'label' => 'Ingredient', 'type' => 'string'],
                ['key' => 'quantity', 'label' => 'Quantity', 'type' => 'number', 'format' => '0.000000'],
                ['key' => 'unit', 'label' => 'Unit', 'type' => 'string'],
            ],
            'rows' => [[
                'name' => '=HYPERLINK("https://example.test")',
                'quantity' => '0.000001',
                'unit' => 'g',
            ]],
        ],
    ]);

    try {
        $reader = new Reader;
        $reader->open($path);
        $rows = collect($reader->getSheetIterator()->current()->getRowIterator())->values();
        $reader->close();

        $archive = new ZipArchive;
        expect($archive->open($path))->toBeTrue();
        $xml = new DOMDocument;
        $xml->loadXML($archive->getFromName('xl/worksheets/sheet1.xml'));
        $styles = new DOMDocument;
        $styles->loadXML($archive->getFromName('xl/styles.xml'));
        $archive->close();
        $formulaLikeCell = (new DOMXPath($xml))->query('//*[local-name()="c" and @r="A1"]')->item(0);
        $formulaLikeDataCell = (new DOMXPath($xml))->query('//*[local-name()="c" and @r="A7"]')->item(0);

        $colorValues = collect((new DOMXPath($styles))->query('//*[local-name()="color"]/@rgb'))
            ->map(fn (DOMAttr $attribute): string => $attribute->value);

        expect($colorValues)->not->toBeEmpty();

        foreach ($colorValues as $colorValue) {
            expect(preg_match('/^[0-9A-F]{8}$/', $colorValue))->toBe(1);
        }

        expect($formulaLikeCell?->getAttribute('t'))->toBe('inlineStr')
            ->and($formulaLikeCell?->getElementsByTagName('f')->length)->toBe(0)
            ->and($formulaLikeDataCell?->getAttribute('t'))->toBe('inlineStr')
            ->and($rows[4]->getCells()[1])->toBeInstanceOf(NumericCell::class)
            ->and((float) $rows[4]->toArray()[1])->toBe(0.000001)
            ->and($rows[4]->toArray()[2])->toBe('g');
    } finally {
        $exporter->deleteTemporaryFile($path);
    }
});

it('writes every row in a long iterable without truncation', function (): void {
    $directory = sys_get_temp_dir().'/koskalk-tabular-'.bin2hex(random_bytes(6));
    mkdir($directory);
    $rows = (static function (): Generator {
        for ($index = 1; $index <= 1_205; $index++) {
            yield ['name' => "Ingredient {$index}", 'quantity' => $index];
        }
    })();
    $exporter = app(TabularFileExporter::class);
    $path = $exporter->writeTemporaryXlsx([
        [
            'name' => 'Rows',
            'columns' => [
                ['key' => 'name', 'label' => 'Name', 'type' => 'string'],
                ['key' => 'quantity', 'label' => 'Quantity', 'type' => 'number'],
            ],
            'rows' => $rows,
        ],
    ], $directory);

    try {
        $reader = new Reader;
        $reader->open($path);
        $sheetRows = collect($reader->getSheetIterator()->current()->getRowIterator())->all();
        $reader->close();

        expect($sheetRows)->toHaveCount(1 + 1 + 1_205)
            ->and($sheetRows[array_key_last($sheetRows)]->toArray())->toContain('Ingredient 1205');
    } finally {
        $exporter->deleteTemporaryFile($path);
        rmdir($directory);
    }
});

it('cleans temporary XLSX files after success and writer failure', function (): void {
    $directory = sys_get_temp_dir().'/koskalk-tabular-'.bin2hex(random_bytes(6));
    mkdir($directory);
    $exporter = app(TabularFileExporter::class);
    $sheet = [
        'name' => 'Rows',
        'columns' => [['key' => 'name', 'label' => 'Name', 'type' => 'string']],
        'rows' => [['name' => 'Valid']],
    ];
    $path = $exporter->writeTemporaryXlsx([$sheet], $directory);
    expect(is_file($path))->toBeTrue();
    $exporter->deleteTemporaryFile($path);
    expect(glob($directory.'/*'))->toBe([]);

    $failingRows = (static function (): Generator {
        yield ['name' => 'First row'];
        throw new RuntimeException('row stream failed');
    })();

    expect(fn () => $exporter->writeTemporaryXlsx([[...$sheet, 'rows' => $failingRows]], $directory))
        ->toThrow(RuntimeException::class, 'row stream failed')
        ->and(glob($directory.'/*'))->toBe([]);

    rmdir($directory);
});

it('escapes formula-like strings in CSV while preserving separate numeric and unit columns', function (): void {
    $csv = app(TabularFileExporter::class)->exportCsv([
        'context' => [['label' => 'Currency', 'value' => 'EUR', 'type' => 'string']],
        'columns' => [
            ['key' => 'name', 'label' => 'Ingredient', 'type' => 'string'],
            ['key' => 'quantity', 'label' => 'Quantity', 'type' => 'number'],
            ['key' => 'unit', 'label' => 'Unit', 'type' => 'string'],
            ['key' => 'currency', 'label' => 'Currency', 'type' => 'string'],
        ],
        'rows' => [['name' => ' =1+1', 'quantity' => '0.5', 'unit' => 'kg', 'currency' => 'EUR']],
    ]);
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $csv);
    rewind($stream);
    $header = fgetcsv($stream, escape: '');
    $row = fgetcsv($stream, escape: '');
    fclose($stream);

    expect($header)->toBe(['Ingredient', 'Quantity', 'Unit', 'Currency'])
        ->and($row)->toBe(["' =1+1", '0.5', 'kg', 'EUR']);
});

it('writes CSV iterables directly to a temporary file with one header row', function (): void {
    $directory = sys_get_temp_dir().'/koskalk-tabular-'.bin2hex(random_bytes(6));
    mkdir($directory);
    $exporter = app(TabularFileExporter::class);
    $path = $exporter->writeTemporaryCsv([
        'columns' => [
            ['key' => 'name', 'label' => 'Name', 'type' => 'string'],
            ['key' => 'quantity', 'label' => 'Quantity', 'type' => 'number'],
        ],
        'rows' => (static function (): Generator {
            yield ['name' => 'Oil', 'quantity' => '500'];
            yield ['name' => 'Water', 'quantity' => '250'];
        })(),
    ], $directory);
    $stream = fopen($path, 'r');
    $header = fgetcsv($stream, escape: '');
    $firstRow = fgetcsv($stream, escape: '');
    $secondRow = fgetcsv($stream, escape: '');
    fclose($stream);

    expect($header)->toBe(['Name', 'Quantity'])
        ->and($firstRow)->toBe(['Oil', '500'])
        ->and($secondRow)->toBe(['Water', '250']);

    $exporter->deleteTemporaryFile($path);
    expect(glob($directory.'/*'))->toBe([]);
    rmdir($directory);
});

it('rejects numeric strings that overflow the spreadsheet cell range and removes partial files', function (): void {
    $directory = sys_get_temp_dir().'/koskalk-tabular-'.bin2hex(random_bytes(6));
    mkdir($directory);
    $exporter = app(TabularFileExporter::class);
    $base = [
        'name' => 'Numbers',
        'columns' => [['key' => 'quantity', 'label' => 'Quantity', 'type' => 'number']],
    ];

    foreach (['1e9999', '9223372036854775808'] as $value) {
        expect(fn () => $exporter->writeTemporaryXlsx([[...$base, 'rows' => [['quantity' => $value]]]], $directory))
            ->toThrow(InvalidArgumentException::class);
        expect(glob($directory.'/*'))->toBe([]);
    }

    rmdir($directory);
});
