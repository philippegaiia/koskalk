<?php

use Symfony\Component\Process\Process;

it('colors each fatty acid detail bar with its grouped profile color', function () {
    $presentationSection = file_get_contents(resource_path('js/recipe-workbench/sections/presentation-section.js'));
    $fattyAcidProfile = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/fatty-acid-profile.blade.php'));

    expect($presentationSection)
        ->toContain('this.fattyAcidGroupColorFor(key)')
        ->and($presentationSection)->toContain("caprylic: 'vs'")
        ->and($presentationSection)->toContain("lauric: 'vs'")
        ->and($presentationSection)->toContain("palmitic: 'hs'")
        ->and($presentationSection)->toContain("oleic: 'mu'")
        ->and($presentationSection)->toContain("linoleic: 'pu'")
        ->and($presentationSection)->toContain("ricinoleic: 'sp'")
        ->and($fattyAcidProfile)->toContain('fattyAcidRowBarStyle(row.value, row.color)');
});

it('keeps the individual fatty acid detail threshold inclusive without hiding grouped trace data', function (): void {
    $script = <<<'JS'
import assert from 'node:assert/strict';
import fs from 'node:fs';

const source = fs
  .readFileSync('resources/js/recipe-workbench/sections/presentation-section.js', 'utf8')
  .replace('export function createPresentationSection', 'function createPresentationSection');

eval(`${source}\nglobalThis.createPresentationSection = createPresentationSection;`);

const workbench = {
  backendCalculation: {
    properties: {
      fatty_acid_profile: {
        caprylic: 0.49,
        capric: 0.5,
        lauric: 2,
      },
      fatty_acid_groups: { vs: 2.99 },
    },
  },
  fattyAcidLabels: () => ({
    caprylic: 'Caprylic',
    capric: 'Capric',
    lauric: 'Lauric',
  }),
  fattyAcidGroupColorFor: (key) => key,
  number: (value) => Number(value ?? 0),
};

Object.defineProperties(workbench, Object.getOwnPropertyDescriptors(globalThis.createPresentationSection()));

assert.deepEqual(workbench.fattyAcidProfileRows.map(({ key, value }) => ({ key, value })), [
  { key: 'capric', value: 0.5 },
  { key: 'lauric', value: 2 },
]);
assert.equal(workbench.hasFattyAcidProfileData, true);
assert.deepEqual(workbench.fattyAcidGroupSegments().map(({ key, value }) => ({ key, value })), [
  { key: 'vs', value: 2.99 },
]);

workbench.backendCalculation.properties.fatty_acid_profile = { caprylic: 0.49 };
workbench.backendCalculation.properties.fatty_acid_groups = { vs: 0.49 };

assert.deepEqual(workbench.fattyAcidProfileRows, []);
assert.equal(workbench.hasFattyAcidProfileData, true);
assert.deepEqual(workbench.fattyAcidGroupSegments().map(({ key, value }) => ({ key, value })), [
  { key: 'vs', value: 0.49 },
]);
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
});
