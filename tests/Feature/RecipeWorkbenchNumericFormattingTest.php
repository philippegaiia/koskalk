<?php

use Symfony\Component\Process\Process;

it('chooses standard and addition weight precision from the displayed unit and quantity', function (): void {
    $script = <<<'JS'
import assert from 'node:assert/strict';
import { massDisplayDecimals } from './resources/js/recipe-workbench/mass.js';

assert.equal(massDisplayDecimals(2400, 'g'), 1);
assert.equal(massDisplayDecimals(240, 'g'), 2);
assert.equal(massDisplayDecimals(0.125, 'g'), 2);
assert.equal(massDisplayDecimals(1, 'kg'), 3);
assert.equal(massDisplayDecimals(0.5, 'kg'), 3);
assert.equal(massDisplayDecimals(12, 'oz'), 2);
assert.equal(massDisplayDecimals(4, 'oz'), 2);
assert.equal(massDisplayDecimals(0.5, 'oz'), 3);
assert.equal(massDisplayDecimals(2, 'lb'), 3);
assert.equal(massDisplayDecimals(0.5, 'lb'), 3);

assert.equal(massDisplayDecimals(20, 'g', 'addition'), 3);
assert.equal(massDisplayDecimals(0.02, 'kg', 'addition'), 4);
assert.equal(massDisplayDecimals(0.5, 'oz', 'addition'), 3);
assert.equal(massDisplayDecimals(0.05, 'lb', 'addition'), 4);

assert.equal(massDisplayDecimals(100, 'g', 'calculated'), 2);
assert.equal(massDisplayDecimals(100.01, 'g', 'calculated'), 0);
assert.equal(massDisplayDecimals(12.778, 'kg', 'calculated'), 3);
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );
    $process->mustRun();

    expect($process->getOutput())->toBe('');
});

it('uses unit-aware precision for soap lye, liquids, additions, and batch totals', function (): void {
    $formulaSection = file_get_contents(resource_path('js/recipe-workbench/sections/formula-section.js'));
    $presentationSection = file_get_contents(resource_path('js/recipe-workbench/sections/presentation-section.js'));
    $reactionCore = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/reaction-core.blade.php'));
    $postReaction = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/post-reaction.blade.php'));
    $formulaSettings = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/formula-settings.blade.php'));

    expect($formulaSection)
        ->toContain("massDecimals(value, profile = 'standard')")
        ->toContain('additionWeightDecimals(value)')
        ->toContain("return this.massDecimals(value, 'addition')")
        ->toContain('this.calculatedMassDecimals(lyeWeight)')
        ->toContain('this.calculatedMassDecimals(waterWeight)')
        ->and($reactionCore)
        ->toContain('formatLyeSummaryCardValue(card)')
        ->and($postReaction)
        ->toContain('additionWeightDecimals(rowWeight(row))')
        ->not->toContain('format(rowWeight(row), 3)')
        ->and($formulaSettings)
        ->toContain('calculatedMassDecimals(lyeLiquidWeight(row))')
        ->toContain('calculatedMassDecimals(lyeLiquidWaterWeight())')
        ->and($presentationSection)
        ->toContain('this.calculatedMassDecimals(producedGlycerineWeight)')
        ->toContain('this.calculatedMassDecimals(wetWeight)')
        ->toContain('this.calculatedMassDecimals(curedWeight)');
});

it('formats percentages on first paint and aligns formula values on their decimal separator', function (): void {
    $reactionCore = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/reaction-core.blade.php'));
    $postReaction = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/post-reaction.blade.php'));
    $cosmeticFormula = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/cosmetic-formula.blade.php'));
    $costing = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/costing-tab.blade.php'));
    $output = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/output-tab.blade.php'));
    $ingredientLists = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/ingredient-list-preview.blade.php'));
    $restrictions = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/restrictions-preview.blade.php'));
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
        ->toContain('additionWeightDecimals(rowWeight(row))')
        ->toContain('sk-decimal-aligned')
        ->and($cosmeticFormula)
        ->toContain('x-effect="syncFormattedInput($el, row.percentage, 2)"')
        ->toContain(':style="decimalAlignmentStyle(row.percentage)"')
        ->toContain('sk-decimal-aligned')
        ->and($costing)
        ->toContain(':style="decimalAlignmentStyle(row.percentage)"')
        ->toContain(':style="decimalAlignmentStyle(lineCostForRow(row))"')
        ->toContain('sk-decimal-aligned')
        ->and($output)
        ->toContain(':style="decimalAlignmentStyle(row.percentage)"')
        ->toContain(':style="decimalAlignmentStyle(row.weight)"')
        ->toContain('sk-decimal-aligned')
        ->and($ingredientLists)
        ->toContain(':style="decimalAlignmentStyle(row.percent_of_cured_basis)"')
        ->toContain('sk-decimal-aligned')
        ->and($restrictions)
        ->toContain(':style="decimalAlignmentStyle(row.percent_of_formula)"')
        ->toContain('sk-decimal-aligned')
        ->and($formulaSection)
        ->toContain('decimalAlignmentStyle(value)')
        ->toContain('syncFormattedInput(element, value, decimals)')
        ->toContain('oilWeightDecimals(value)')
        ->and($styles)
        ->toContain('.sk-decimal-aligned')
        ->toContain('calc(50% - var(--sk-decimal-offset))');
});
