<?php

use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

it('uploads selected media sequentially and keeps failed files actionable', function () {
    $script = <<<'JS'
import assert from 'node:assert/strict';
import { pathToFileURL } from 'node:url';

const moduleUrl = pathToFileURL(`${process.cwd()}/resources/js/media-library-uploader.js`).href;
const { createMediaLibraryUploader } = await import(moduleUrl);
const uploaded = [];
const started = [];
const livewire = {
    upload(property, file, finish, error, progress) {
        assert.equal(property, 'upload');
        uploaded.push(file.name);
        progress({ detail: { progress: 60 } });
        file.name === 'bad.png' ? error() : finish();
    },
    async uploadAsset() {
        started.push(uploaded.at(-1));
    },
};
const uploader = createMediaLibraryUploader({
    livewire,
    maxFiles: 5,
    remaining: null,
    messages: {
        uploadFailed: ':name could not be uploaded.',
    },
});
const input = {
    files: [
        { name: 'one.png', size: 100, lastModified: 1 },
        { name: 'bad.png', size: 200, lastModified: 2 },
        { name: 'three.png', size: 300, lastModified: 3 },
    ],
    value: 'selected',
};

uploader.selectFiles({ target: input });
assert.equal(input.value, '');
await uploader.uploadBatch();

assert.deepEqual(uploaded, ['one.png', 'bad.png', 'three.png']);
assert.deepEqual(started, ['one.png', 'three.png']);
assert.equal(uploader.files.length, 1);
assert.equal(uploader.files[0].name, 'bad.png');
assert.equal(uploader.files[0].status, 'failed');
assert.equal(uploader.files[0].error, 'bad.png could not be uploaded.');
assert.equal(uploader.currentProgress, 60);
assert.equal(uploader.uploading, false);
JS;

    $process = new Process(['node', '--input-type=module', '--eval', $script], base_path());
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
});

it('enforces the gallery batch and workspace quota limits', function () {
    $script = <<<'JS'
import assert from 'node:assert/strict';
import { pathToFileURL } from 'node:url';

const moduleUrl = pathToFileURL(`${process.cwd()}/resources/js/media-library-uploader.js`).href;
const { createMediaLibraryUploader } = await import(moduleUrl);
const files = Array.from({ length: 6 }, (_, index) => ({
    name: `image-${index + 1}.png`,
    size: index,
    lastModified: index,
}));
const uploader = createMediaLibraryUploader({
    livewire: {},
    maxFiles: 5,
    remaining: 4,
    messages: {},
});

uploader.selectFiles({ target: { files, value: 'selected' } });
assert.equal(uploader.overBatchLimit, true);
assert.equal(uploader.overQuotaLimit, true);
assert.equal(uploader.canUpload, false);

uploader.removeFile(5);
assert.equal(uploader.overBatchLimit, false);
assert.equal(uploader.overQuotaLimit, true);
assert.equal(uploader.canUpload, false);

uploader.removeFile(4);
assert.equal(uploader.overQuotaLimit, false);
assert.equal(uploader.canUpload, true);
assert.equal(uploader.batchOverflow, 0);
JS;

    $process = new Process(['node', '--input-type=module', '--eval', $script], base_path());
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
});
