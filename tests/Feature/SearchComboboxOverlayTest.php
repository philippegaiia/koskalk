<?php

use Illuminate\Support\Facades\Blade;
use Symfony\Component\Process\Process;

it('renders a detached options panel only for floating comboboxes', function () {
    $html = Blade::render('<x-search-combobox id="packaging-test" label="Packaging" :floating-options="true" />');
    $document = new DOMDocument;
    @$document->loadHTML($html);
    $xpath = new DOMXPath($document);

    expect($xpath->query('//template[@x-teleport="body"]//div[@role="listbox"]')->length)->toBe(1);

    $html = Blade::render('<x-search-combobox id="regular-test" label="Regular" />');
    @$document->loadHTML($html);

    expect((new DOMXPath($document))->query('//template[@x-teleport="body"]')->length)->toBe(0);
});

it('positions floating options inside the viewport and preserves selection', function () {
    $script = <<<'JS'
import assert from 'node:assert/strict';
import { createSearchCombobox } from './resources/js/search-combobox.js';

const cases = [
    { viewport: [1000, 900], rect: { top: 380, bottom: 424, left: 100, width: 300 }, expected: { top: '430px', left: '100px', width: '300px', maxHeight: '288px' } },
    { viewport: [1000, 900], rect: { top: 740, bottom: 784, left: 100, width: 300 }, expected: { top: '446px', left: '100px', width: '300px', maxHeight: '288px' } },
    { viewport: [320, 900], rect: { top: 80, bottom: 124, left: 280, width: 300 }, expected: { top: '130px', left: '16px', width: '288px', maxHeight: '288px' } },
];
for (const { viewport, rect, expected } of cases) {
    globalThis.window = { innerWidth: viewport[0], innerHeight: viewport[1] };
    const combobox = createSearchCombobox({ id: 'packaging', floatingOptions: true, options: [{ id: 7, label: 'Last box' }] });
    let disabled = false;
    const events = [];
    combobox.$refs = {
        control: { getBoundingClientRect: () => rect },
        options: { scrollHeight: 1000 },
        input: { matches: () => disabled },
    };
    combobox.$dispatch = (name, detail) => events.push({ name, detail });
    combobox.open = true;
    combobox.repositionOptions();
    assert.deepEqual(combobox.panelStyle, expected);
    combobox.selectOption(combobox.options[0]);
    assert.equal(combobox.open, false);
    assert.equal(events[0].detail.id, 7);
    disabled = true;
    combobox.selectOption(combobox.options[0]);
    assert.equal(events.length, 1, 'A detached panel must respect its original disabled fieldset');
}
JS;

    $process = new Process(['node', '--input-type=module', '--eval', $script], base_path());
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
});
