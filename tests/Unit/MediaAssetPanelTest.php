<?php

use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

it('keeps drafts dirty until confirmed saved and protects close while editing or saving', function () {
    $script = <<<'JS'
import assert from 'node:assert/strict';
import { createMediaAssetPanel } from './resources/js/media-asset-panel.js';
let closes = 0;
let focus = 0;
let result = false;
const wire = {
    displayNames: { 1: 'Before' }, selectedLabelIds: [2, 3], newLabelName: '',
    async saveAssetSettings() { return result; },
    async closeAssetPanel() { closes++; },
};
const panel = createMediaAssetPanel({livewire: wire, assetId: 1, focalX: 50, focalY: 50});
panel.$nextTick = callback => callback();
panel.$refs = {keepEditing: {focus() {focus++;}}};
globalThis.document = {getElementById() {return {focus() {focus++;}};}};
panel.init();
assert.equal(panel.dirty, false);
wire.selectedLabelIds = [3, '2'];
assert.equal(panel.dirty, false);
wire.displayNames[1] = 'After';
assert.equal(panel.dirty, true);
panel.closePanel();
assert.equal(panel.confirmingClose, true);
assert.equal(closes, 0);
await panel.saveSettings();
assert.equal(panel.saveError, true);
assert.equal(panel.dirty, true);
result = true;
await panel.saveSettings();
assert.equal(panel.dirty, false);
assert.equal(panel.justSaved, true);
assert.equal(panel.confirmingClose, false);
panel.focalX = 40;
assert.equal(panel.dirty, true);
wire.saveAssetSettings = async () => {throw new Error('offline');};
await panel.saveSettings();
assert.equal(panel.dirty, true);
assert.equal(panel.saveError, true);
panel.saving = true;
await panel.discardAndClose();
assert.equal(closes, 0);
panel.saving = false;
await panel.discardAndClose();
assert.equal(closes, 1);
assert.equal(focus, 2);
JS;

    $process = new Process(['node', '--input-type=module', '--eval', $script], base_path());
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
});
