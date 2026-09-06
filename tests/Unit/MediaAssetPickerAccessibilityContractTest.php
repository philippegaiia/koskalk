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

it('uses the application accent color for upload progress', function () {
    $pickerView = file_get_contents(
        resource_path('views/forms/components/media-asset-picker.blade.php'),
    );

    expect($pickerView)
        ->toContain('data-media-picker-upload-progress-bar')
        ->toContain('bg-[var(--color-accent)]')
        ->not->toContain('<progress');
});

it('exposes removable selections, upload persistence details, and readable filenames', function () {
    $pickerView = file_get_contents(
        resource_path('views/forms/components/media-asset-picker.blade.php'),
    );

    expect($pickerView)
        ->toContain("__('media_library.picker.clear')")
        ->toContain("__('media_library.picker.remove_selection_help')")
        ->toContain("__('media_library.picker.upload_description')")
        ->toContain('media_library.picker.upload_requirements')
        ->toContain('getFileAttachmentsAcceptedFileTypes()')
        ->toContain('getFileAttachmentsMaxSize()')
        ->toContain('data-ingredient-editor-ignore-dirty')
        ->toContain('break-words')
        ->toContain('[overflow-wrap:anywhere]')
        ->not->toContain('class="block truncate px-2 pt-2 text-sm font-medium"')
        ->not->toContain('class="block truncate px-2 pb-2 text-xs text-[var(--color-ink-soft)]"');
});

it('describes the remove action only while a picker selection exists', function () {
    $pickerView = file_get_contents(
        resource_path('views/forms/components/media-asset-picker.blade.php'),
    );

    expect($pickerView)
        ->toContain('id="{{ $pickerId }}-remove-selection-help"')
        ->toContain('x-bind:aria-describedby="(multiple ? (Array.isArray(state) && state.length) : state) ?')
        ->toContain('x-show="multiple ? (Array.isArray(state) && state.length) : state"');

    expect(__('media_library.picker.remove_selection_help'))
        ->toBe('Removing selected files from this form does not delete them from your Media Library.');
});

it('offers common image and pdf formats for document uploads', function () {
    $pickerView = file_get_contents(
        resource_path('views/forms/components/media-asset-picker.blade.php'),
    );

    expect($pickerView)
        ->toContain('$acceptsDocuments ? \'.pdf,.jpg,.jpeg,.png,.webp,.heic,.heif,application/pdf,image/jpeg,image/png,image/webp,image/heic,image/heif\'')
        ->toContain("'application/pdf'");
});

it('tracks and clears the single modal upload filename', function () {
    $script = <<<'JS'
import assert from 'node:assert/strict';
import { pathToFileURL } from 'node:url';

const moduleUrl = pathToFileURL(`${process.cwd()}/resources/js/media-asset-picker.js`).href;
const { createMediaAssetPicker } = await import(moduleUrl);
const picker = createMediaAssetPicker({
    embedded: false,
    assetsUrl: '/media',
    livewire: {},
    statePath: 'data.featured_media_asset_id',
    state: null,
    multiple: false,
    maximumItems: 1,
    preserveAspectRatio: false,
    messages: {},
});

picker.$refs = { uploadInput: { value: 'selected' } };
picker.selectUploadFile({ target: { files: [{ name: 'soap-front.png' }] } });
assert.equal(picker.uploadFilename, 'soap-front.png');
picker.clearUploadFile();
assert.equal(picker.uploadFilename, '');
assert.equal(picker.$refs.uploadInput.value, '');
JS;

    $process = new Process(['node', '--input-type=module', '--eval', $script], base_path());
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
});

it('does not resurrect a cancelled upload when status polling is already in flight', function () {
    $script = <<<'JS'
import assert from 'node:assert/strict';
import { pathToFileURL } from 'node:url';

const moduleUrl = pathToFileURL(`${process.cwd()}/resources/js/media-asset-picker.js`).href;
const { createMediaAssetPicker } = await import(moduleUrl);
global.window = {
    clearTimeout() {},
    setTimeout() {},
};
global.document = {
    querySelector() {
        return null;
    },
};

let resolveStatus;
global.fetch = (url, options) => {
    if (options.method === 'GET') {
        return new Promise((resolve) => {
            resolveStatus = resolve;
        });
    }

    return Promise.resolve({
        ok: true,
        async json() {
            return { removed: true };
        },
    });
};

const picker = createMediaAssetPicker({
    embedded: true,
    assetsUrl: '/media',
    livewire: {},
    statePath: 'document_media_asset_ids',
    state: null,
    multiple: false,
    maximumItems: 1,
    preserveAspectRatio: true,
    messages: { refreshFailed: 'Refresh failed' },
});
let refreshed = false;
picker.loadAssets = async (reset) => {
    refreshed = reset;
};
picker.pendingUpload = {
    id: 42,
    generation: 1,
    status: 'processing',
    progress: 20,
    statusUrl: '/media/42/status',
    removeUrl: '/media/42',
    retryUrl: null,
    failureReason: null,
};

const polling = picker.pollUpload(1);
await Promise.resolve();
const removing = picker.removeUpload();
await removing;
resolveStatus({
    ok: true,
    async json() {
        return { status: 'failed', progress: 0, failure_reason: 'stale' };
    },
});
await polling;

assert.equal(picker.pendingUpload, null);
assert.equal(refreshed, true);
JS;

    $process = new Process(['node', '--input-type=module', '--eval', $script], base_path());
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
});

it('resumes polling when a processing upload removal fails', function () {
    $script = <<<'JS'
import assert from 'node:assert/strict';
import { pathToFileURL } from 'node:url';

const moduleUrl = pathToFileURL(`${process.cwd()}/resources/js/media-asset-picker.js`).href;
const { createMediaAssetPicker } = await import(moduleUrl);
global.window = { clearTimeout() {} };
global.document = { querySelector() { return null; } };
global.fetch = async () => ({
    ok: false,
    status: 500,
    async json() {
        return { message: 'try again' };
    },
});

const picker = createMediaAssetPicker({
    embedded: true,
    assetsUrl: '/media',
    livewire: {},
    statePath: 'document_media_asset_ids',
    state: null,
    multiple: false,
    maximumItems: 1,
    preserveAspectRatio: true,
    messages: { refreshFailed: 'Refresh failed' },
});
const pendingUpload = {
    id: 42,
    generation: 1,
    status: 'processing',
    progress: 20,
    statusUrl: '/media/42/status',
    removeUrl: '/media/42',
    retryUrl: null,
    failureReason: null,
};
let polledGeneration = null;
picker.pendingUploadGeneration = 1;
picker.pendingUpload = pendingUpload;
picker.pollUpload = (generation) => {
    polledGeneration = generation;
};

await picker.removeUpload();

assert.equal(picker.pendingUpload.generation, 2);
assert.equal(picker.pendingUpload.failureReason, 'try again');
assert.equal(polledGeneration, 2);
JS;

    $process = new Process(['node', '--input-type=module', '--eval', $script], base_path());
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
});
