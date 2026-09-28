<?php

use Symfony\Component\Process\Process;

it('runs the print document section behavior tests', function (): void {
    $process = new Process(['node', '--test', 'tests/Unit/print-document.test.mjs'], base_path());
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
});
