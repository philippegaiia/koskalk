<?php

use Symfony\Component\Process\Process;

function runLiquidSoapBenchScript(string $assertions): Process
{
    $script = <<<'JS'
import assert from 'node:assert/strict';
import { register } from 'node:module';
import { pathToFileURL } from 'node:url';

register(
    'data:text/javascript,' + encodeURIComponent(`
        export async function resolve(specifier, context, nextResolve) {
            if (specifier.startsWith('.') && !specifier.endsWith('.js')) {
                try {
                    return await nextResolve(specifier, context);
                } catch {
                    return nextResolve(specifier + '.js', context);
                }
            }

            return nextResolve(specifier, context);
        }
    `),
    pathToFileURL(`${process.cwd()}/`).href,
);

global.window = { location: { hash: '' }, localStorage: { getItem: () => null, setItem: () => {} } };
global.document = { addEventListener() {}, removeEventListener() {} };
Object.defineProperty(global, 'navigator', {
    value: { languages: ['en-US'], language: 'en-US', maxTouchPoints: 0 },
    configurable: true,
});

const { createRecipeWorkbench } = await import('./resources/js/recipe-workbench/component.js');
const { serializeDraft } = await import('./resources/js/recipe-workbench/payload.js');
const { draftStateFromDraft } = await import('./resources/js/recipe-workbench/snapshot.js');
const liquidFamily = { slug: 'liquid-soap', soap_process: 'liquid_paste' };
const barFamily = { slug: 'soap', soap_process: 'bar' };
const bench = (productFamily) => createRecipeWorkbench({ productFamily, numberLocaleOptions: { en_US: '1,234.56' } });
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script."\n".$assertions),
        base_path(),
    );
    $process->run();

    return $process;
}

it('starts a new liquid soap formula with KOH, a 28% alkali solution and the process defaults', function () {
    $process = runLiquidSoapBenchScript(<<<'JS'
const workbench = bench(liquidFamily);

assert.equal(workbench.isLiquidSoapFormula, true);
assert.equal(workbench.formulaName, 'New liquid soap');
assert.equal(workbench.lyeType, 'koh');
assert.equal(workbench.kohPurity, 90);
assert.equal(workbench.waterMode, 'lye_concentration');
assert.equal(workbench.waterValue, 28);
assert.equal(workbench.superfat, 0);
assert.deepEqual({ ...workbench.liquidProcess }, {
    firstDilutionPercentage: 35,
    finalDilutionPercentage: 25,
    neutralizerSolutionPercentage: 20,
    measuredPasteWeight: '',
});
JS);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
});

it('sends the saved liquid settings without the weighed paste and leaves bar soap payloads alone', function () {
    $process = runLiquidSoapBenchScript(<<<'JS'
const liquid = bench(liquidFamily);
liquid.liquidProcess.finalDilutionPercentage = 22.5;
liquid.liquidProcess.measuredPasteWeight = '1680';

assert.deepEqual(serializeDraft(liquid).liquid_process, {
    first_dilution_percentage: 35,
    final_dilution_percentage: 22.5,
    neutralizer_solution_percentage: 20,
});

const bar = bench(barFamily);
assert.equal(bar.isLiquidSoapFormula, false);
assert.equal(bar.liquidProcess, null);
assert.equal(Object.hasOwn(serializeDraft(bar), 'liquid_process'), false);
JS);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
});

it('restores saved liquid settings and keeps the paste weight typed for this batch', function () {
    $process = runLiquidSoapBenchScript(<<<'JS'
const workbench = bench(liquidFamily);
workbench.liquidProcess.measuredPasteWeight = '1680';

const next = draftStateFromDraft({
    lyeType: 'koh',
    liquidProcess: { firstDilutionPercentage: 40, finalDilutionPercentage: 30, neutralizerSolutionPercentage: 25 },
}, workbench);

assert.deepEqual(next.liquidProcess, {
    firstDilutionPercentage: 40,
    finalDilutionPercentage: 30,
    neutralizerSolutionPercentage: 25,
    measuredPasteWeight: '1680',
});
assert.equal(draftStateFromDraft({ lyeType: 'naoh' }, bench(barFamily)).liquidProcess, null);
JS);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
});
