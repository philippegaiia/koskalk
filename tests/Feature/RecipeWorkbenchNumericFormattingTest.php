<?php

use Symfony\Component\Process\Process;

it('chooses oil weight precision from the displayed unit and quantity', function (): void {
    $script = <<<'JS'
import assert from 'node:assert/strict';
import { massDisplayDecimals } from './resources/js/recipe-workbench/mass.js';

assert.equal(massDisplayDecimals(2400, 'g'), 1);
assert.equal(massDisplayDecimals(240, 'g'), 2);
assert.equal(massDisplayDecimals(0.125, 'g'), 3);
assert.equal(massDisplayDecimals(1, 'kg'), 3);
assert.equal(massDisplayDecimals(0.5, 'kg'), 4);
assert.equal(massDisplayDecimals(12, 'oz'), 2);
assert.equal(massDisplayDecimals(4, 'oz'), 3);
assert.equal(massDisplayDecimals(2, 'lb'), 3);
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );
    $process->mustRun();

    expect($process->getOutput())->toBe('');
});

it('formats percentages on first paint and aligns formula values on their decimal separator', function (): void {
    $reactionCore = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/reaction-core.blade.php'));
    $postReaction = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/post-reaction.blade.php'));
    $cosmeticFormula = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/cosmetic-formula.blade.php'));
    $formulaSection = file_get_contents(resource_path('js/recipe-workbench/sections/formula-section.js'));
    $styles = file_get_contents(resource_path('css/app.css'));

    expect($reactionCore)
        ->toContain('x-effect="syncFormattedInput($el, row.percentage, 2)"')
        ->toContain(':style="decimalAlignmentStyle(row.percentage)"')
        ->toContain('oilWeightDecimals(rowWeight(row))')
        ->toContain('sk-decimal-aligned')
        ->and($postReaction)
        ->toContain('x-effect="syncFormattedInput($el, row.percentage, 2)"')
        ->toContain(':style="decimalAlignmentStyle(row.percentage)"')
        ->toContain('format(rowWeight(row), 3)')
        ->toContain('sk-decimal-aligned')
        ->and($cosmeticFormula)
        ->toContain('x-effect="syncFormattedInput($el, row.percentage, 2)"')
        ->toContain(':style="decimalAlignmentStyle(row.percentage)"')
        ->toContain('sk-decimal-aligned')
        ->and($formulaSection)
        ->toContain('decimalAlignmentStyle(value)')
        ->toContain('syncFormattedInput(element, value, decimals)')
        ->toContain('oilWeightDecimals(value)')
        ->and($styles)
        ->toContain('.sk-decimal-aligned')
        ->toContain('calc(50% - var(--sk-decimal-offset))');
});
