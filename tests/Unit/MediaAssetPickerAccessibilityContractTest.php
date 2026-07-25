<?php

use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

it('moves focus to the visible Library tab when an upload starts processing', function () {
    $script = <<<'JS'
import assert from 'node:assert/strict';
import { pathToFileURL } from 'node:url';

const moduleUrl = pathToFileURL(`${process.cwd()}/resources/js/media-asset-picker.js`).href;
const { createMediaAssetPicker } = await import(moduleUrl);
const picker = createMediaAssetPicker({
    embedded: true,
    assetsUrl: '/media',
    livewire: {},
    statePath: 'mountedActionSchema0.media_asset_id',
    state: null,
    multiple: false,
    maximumItems: 1,
    preserveAspectRatio: true,
    messages: {},
});
let libraryTabFocuses = 0;
let nextTicks = 0;
let polls = 0;

picker.$refs = {
    libraryTab: {
        focus() {
            libraryTabFocuses += 1;
        },
    },
};
picker.$nextTick = (callback) => {
    nextTicks += 1;
    callback();
};
picker.pollUpload = () => {
    polls += 1;
};
picker.activeTab = 'upload';
picker.trackUpload({
    statePath: 'mountedActionSchema0.media_asset_id',
    assetId: 42,
    statusUrl: '/media/42/status',
});

assert.equal(picker.activeTab, 'library');
assert.equal(picker.pendingUpload.id, 42);
assert.equal(nextTicks, 1);
assert.equal(libraryTabFocuses, 1);
assert.equal(polls, 1);
JS;

    $process = new Process(
        ['node', '--input-type=module', '--eval', $script],
        base_path(),
    );
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
});

it('renders upload processing feedback as a polite atomic status region', function () {
    $pickerView = file_get_contents(
        resource_path('views/forms/components/media-asset-picker.blade.php'),
    );

    expect($pickerView)
        ->toContain('data-media-picker-pending-status')
        ->toContain('role="status"')
        ->toContain('aria-live="polite"')
        ->toContain('aria-atomic="true"');
});
