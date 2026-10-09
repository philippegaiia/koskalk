<?php

use Symfony\Component\Process\Process;

it('preserves production drafts and releases reservations on confirmed departure', function (): void {
    $process = new Process(['node', '--test', 'tests/Unit/production-editing.test.mjs', 'tests/Unit/production-editing-draft.test.mjs'], base_path());
    $process->run();
    expect($process->isSuccessful(), $process->getOutput().$process->getErrorOutput())->toBeTrue();
});
