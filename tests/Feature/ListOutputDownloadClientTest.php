<?php

use Symfony\Component\Process\Process;

it('runs the list output download behavior tests', function (): void {
    $process = new Process(['node', '--test', 'tests/Unit/list-output-download.test.mjs'], base_path());
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
});
