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

it('keeps total mass inputs numeric while preserving localized editing text', function (): void {
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

const { createFormulaSection } = await import('./resources/js/recipe-workbench/sections/formula-section.js');
const { formatDecimalInput } = await import('./resources/js/recipe-workbench/number-format.js');

global.document = { activeElement: null };

const state = Object.create(createFormulaSection());
Object.assign(state, {
    numberLocale: 'fr_FR',
    oilUnit: 'kg',
    oilWeight: 1,
});

const input = { value: '1,5' };
global.document.activeElement = input;
state.updateOilWeight({ target: input });

assert.equal(state.oilWeight, 1.5);
assert.equal(typeof state.oilWeight, 'number');
assert.equal(input.value, '1,5');

global.document.activeElement = input;
state.normalizeOilWeightBlur({ target: input });
assert.equal(state.oilWeight, 1.5);
assert.equal(input.value, '1,5');

state.oilWeight = 1.75;
state.syncOilWeightInput(input);
assert.equal(input.value, '1,5');

global.document.activeElement = null;
state.oilUnit = 'oz';
state.oilWeight = 35.27396194958041;
state.syncOilWeightInput(input);
assert.equal(input.value, '35,27');

state.oilUnit = 'lb';
state.oilWeight = 2.2046226218487757;
state.syncOilWeightInput(input);
assert.equal(input.value, '2,205');

state.oilUnit = 'g';
state.oilWeight = 1000;
state.syncOilWeightInput(input);
assert.equal(input.value, '1000');

assert.equal(formatDecimalInput('35,27396194958041', 'fr_FR', 2), '35,27');
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and($process->getOutput())->toBe('');
});

it('preserves 1000 grams through pounds, untouched blur, and back to grams', function (): void {
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

global.window = {
    location: { hash: '' },
    localStorage: { getItem: () => null, setItem: () => {} },
};
global.document = { activeElement: null };
Object.defineProperty(global, 'navigator', {
    value: { languages: ['en-US'], language: 'en-US', maxTouchPoints: 0 },
    configurable: true,
});

const { createRecipeWorkbench } = await import('./resources/js/recipe-workbench/component.js');
const state = createRecipeWorkbench({
    productFamily: { slug: 'soap' },
    numberLocale: 'en_US',
    numberLocaleOptions: { en_US: '1,234.56' },
    preferredMassUnit: 'g',
});
state.scheduleCalculationPreview = () => {};
state.oilUnit = 'g';
state.oilWeight = 1000;

state.changeOilUnit('lb');
const preciseOilWeightInPounds = state.oilWeight;

const input = { value: '' };
state.syncOilWeightInput(input);
assert.equal(input.value, '2.205');

global.document.activeElement = input;
state.normalizeOilWeightBlur({ target: input });
assert.equal(state.oilWeight, preciseOilWeightInPounds);
assert.equal(input.value, '2.205');

global.document.activeElement = null;
state.changeOilUnit('g');
state.syncOilWeightInput(input);

assert.equal(state.oilUnit, 'g');
assert.ok(Math.abs(state.oilWeight - 1000) < 1e-9);
assert.equal(input.value, '1000');
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and($process->getOutput())->toBe('');
});

it('applies a localized oil weight edit on blur', function (): void {
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

global.document = { activeElement: null };

const { createFormulaSection } = await import('./resources/js/recipe-workbench/sections/formula-section.js');
const state = Object.create(createFormulaSection());
Object.assign(state, {
    numberLocale: 'fr_FR',
    oilUnit: 'kg',
    oilWeight: 1,
});

const input = { value: '1,75' };
global.document.activeElement = input;
state.updateOilWeight({ target: input });
state.normalizeOilWeightBlur({ target: input });

assert.equal(state.oilWeight, 1.75);
assert.equal(typeof state.oilWeight, 'number');
assert.equal(input.value, '1,75');
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and($process->getOutput())->toBe('');
});

it('clears the oil weight when the input is emptied', function (): void {
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

const { createFormulaSection } = await import('./resources/js/recipe-workbench/sections/formula-section.js');

global.document = { activeElement: null };

const state = Object.create(createFormulaSection());
Object.assign(state, {
    numberLocale: 'en_US',
    oilUnit: 'g',
    oilWeight: 1000,
});

const input = { value: '' };
global.document.activeElement = input;
state.updateOilWeight({ target: input });
state.normalizeOilWeightBlur({ target: input });

assert.equal(state.oilWeight, 0);
assert.equal(input.value, '');
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and($process->getOutput())->toBe('');
});

it('keeps the total weight field reactive after editing it while focused', function (): void {
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

global.window = {
    location: { hash: '' },
    localStorage: { getItem: () => null, setItem: () => {} },
};
global.document = { activeElement: null };
Object.defineProperty(global, 'navigator', {
    value: { languages: ['en-US'], language: 'en-US', maxTouchPoints: 0 },
    configurable: true,
});

const { createRecipeWorkbench } = await import('./resources/js/recipe-workbench/component.js');
const component = createRecipeWorkbench({
    productFamily: { slug: 'soap' },
    numberLocale: 'en_US',
    numberLocaleOptions: { en_US: '1,234.56' },
    preferredMassUnit: 'kg',
});
component.scheduleCalculationPreview = () => {};

const input = { value: '' };
let dependencies = new Set();
let pendingEffect = false;
let tracking = false;
let reactiveState;

const runEffect = () => {
    dependencies = new Set();
    tracking = true;
    reactiveState.syncOilWeightInput(input);
    tracking = false;
    pendingEffect = false;
};

const flushEffect = () => {
    if (pendingEffect) {
        runEffect();
    }
};

reactiveState = new Proxy(component, {
    get(target, property, receiver) {
        if (tracking) {
            dependencies.add(property);
        }

        return Reflect.get(target, property, receiver);
    },
    set(target, property, value, receiver) {
        const didSet = Reflect.set(target, property, value, receiver);

        if (dependencies.has(property)) {
            pendingEffect = true;
        }

        return didSet;
    },
});

runEffect();
assert.equal(input.value, '1');

global.document.activeElement = input;
input.value = '2';
reactiveState.updateOilWeight({ target: input });
flushEffect();
assert.equal(input.value, '2');

global.document.activeElement = null;
reactiveState.changeOilUnit('lb');
flushEffect();

assert.equal(reactiveState.oilWeight, 4.409245243697551);
assert.equal(input.value, '4.409');
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and($process->getOutput())->toBe('');
});

it('uses dedicated mass input synchronization for both shared formula benches', function (): void {
    $formulaSettings = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/formula-settings.blade.php'));

    expect(substr_count($formulaSettings, 'x-effect="syncOilWeightInput($el)"'))
        ->toBe(2)
        ->and(substr_count($formulaSettings, '@input="updateOilWeight($event)"'))
        ->toBe(2)
        ->and(substr_count($formulaSettings, '@blur="normalizeOilWeightBlur($event)"'))
        ->toBe(2)
        ->and($formulaSettings)
        ->not->toContain('x-model="oilWeight"');
});

it('uses the shared input treatment for soap setting values without changing cosmetic styling', function (): void {
    $formulaSettings = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/formula-settings.blade.php'));
    $appStylesSource = file_get_contents(resource_path('css/app.css'));

    preg_match('/<input aria-labelledby="setting-base-weight"[^>]+>/', $formulaSettings, $soapOilWeightInput);
    preg_match('/<input aria-labelledby="setting-water-mode"[^>]+>/', $formulaSettings, $soapWaterValueInput);
    preg_match('/<input aria-labelledby="setting-batch-weight"[^>]+>/', $formulaSettings, $cosmeticTotalBatchInput);

    expect($soapOilWeightInput[0] ?? '')
        ->toContain('sk-input numeric mt-3')
        ->not->toContain('focus:outline')
        ->and($soapWaterValueInput[0] ?? '')
        ->toContain('sk-input numeric mt-3')
        ->not->toContain('focus:outline')
        ->and($cosmeticTotalBatchInput[0] ?? '')
        ->not->toContain('sk-input')
        ->and($appStylesSource)
        ->toContain('input:not([type="range"]):not(.sk-formula-title-control):not(.sk-field-control):not(.sk-input)');
});

it('formats percentage totals with locale-aware two-decimal precision', function (): void {
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

const { createFormulaSection } = await import('./resources/js/recipe-workbench/sections/formula-section.js');

const numberLocales = {
    en: 'en-US',
    fr: 'fr-FR',
};

const createState = (numberLocale) => {
    const state = Object.create(createFormulaSection());

    Object.assign(state, {
        numberLocale,
        phaseItems: {},
        number(value) {
            if (typeof value === 'number') {
                return value;
            }

            const locale = numberLocales[this.numberLocale];
            const decimalSeparator = new Intl.NumberFormat(locale)
                .formatToParts(1.1)
                .find((part) => part.type === 'decimal')?.value ?? '.';
            const groupSeparator = new Intl.NumberFormat(locale)
                .formatToParts(1000)
                .find((part) => part.type === 'group')?.value ?? '';
            const normalizedValue = String(value)
                .trim()
                .replaceAll(groupSeparator, '')
                .replace(decimalSeparator, '.');

            return Number(normalizedValue);
        },
        format(value, decimals = 2) {
            return new Intl.NumberFormat(numberLocales[this.numberLocale], {
                maximumFractionDigits: decimals,
                minimumFractionDigits: decimals,
                useGrouping: false,
            }).format(this.number(value));
        },
    });

    return state;
};

const english = createState('en');
assert.equal(english.formatPercentageTotal(100), '100');
assert.equal(english.formatPercentageTotal(99.75), '99.75');
assert.equal(english.formatPercentageTotal(100.004), '100');
assert.equal(english.formatPercentageTotal(100.006), '100.01');

const french = createState('fr');
assert.equal(french.formatPercentageTotal(100), '100');
assert.equal(french.formatPercentageTotal(99.75), '99,75');
assert.equal(french.formatPercentageTotal(100.004), '100');
assert.equal(french.formatPercentageTotal(100.006), '100,01');
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );
    $process->mustRun();

    expect($process->getOutput())->toBe('');
});

it('maps cosmetic output names explicitly', function (): void {
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

const { createFormulaSection } = await import('./resources/js/recipe-workbench/sections/formula-section.js');
const { createPresentationSection } = await import('./resources/js/recipe-workbench/sections/presentation-section.js');

const makeState = ({ productFamilySlug, selectedIngredientListVariantKey }) => {
    const state = {};

    Object.defineProperties(state, Object.getOwnPropertyDescriptors(createFormulaSection()));
    Object.defineProperties(state, Object.getOwnPropertyDescriptors(createPresentationSection()));
    Object.assign(state, {
        productFamilySlug,
        selectedIngredientListVariantKey,
        backendLabeling: {
            list_variants: [
                { key: 'incorporated_ingredients' },
                { key: 'saponified_with_superfat' },
            ],
            default_variant_key: 'saponified_with_superfat',
        },
        phaseOrder: [{ key: 'phase_a', name: 'Phase A' }],
        oilWeight: 100,
        phaseItems: {
            phase_a: [
                { id: 'water', name: 'Water', inci_name: 'Aqua', percentage: 60, weight: 60 },
                { id: 'glycerin', name: 'Glycerin', inci_name: '', percentage: 40, weight: 40 },
            ],
        },
        number(value) {
            return Number(value) || 0;
        },
        rowWeight(row) {
            return row.weight;
        },
        humanizeKey(key) {
            return key;
        },
        t(path) {
            return `translated:${path}`;
        },
    });

    return state;
};

const cosmetic = makeState({
    productFamilySlug: 'cosmetic',
    selectedIngredientListVariantKey: 'incorporated_ingredients',
});

assert.deepEqual(
    cosmetic.cosmeticOutputIngredientRows.map(({ label_name, common_name }) => ({ label_name, common_name })),
    [
        { label_name: 'Aqua', common_name: 'Water' },
        { label_name: 'Glycerin', common_name: 'Glycerin' },
    ],
);
assert.equal(cosmetic.cosmeticFormulaWeightTotal(), cosmetic.number(cosmetic.oilWeight));
assert.equal(cosmetic.cosmeticOutputIngredientTotalWeight, cosmetic.cosmeticFormulaWeightTotal());
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
    expect($process->getOutput())->toBe('');
});

it('localizes the cosmetic and soap ingredient list helper branches', function (): void {
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

const { createFormulaSection } = await import('./resources/js/recipe-workbench/sections/formula-section.js');
const { createPresentationSection } = await import('./resources/js/recipe-workbench/sections/presentation-section.js');

const makeState = ({ productFamilySlug, selectedIngredientListVariantKey }) => {
    const state = {};

    Object.defineProperties(state, Object.getOwnPropertyDescriptors(createFormulaSection()));
    Object.defineProperties(state, Object.getOwnPropertyDescriptors(createPresentationSection()));
    Object.assign(state, {
        productFamilySlug,
        selectedIngredientListVariantKey,
        backendLabeling: {
            list_variants: [
                { key: 'incorporated_ingredients' },
                { key: 'saponified_with_superfat' },
            ],
            default_variant_key: 'saponified_with_superfat',
        },
        t(path) {
            return `translated:${path}`;
        },
    });

    return state;
};

const cosmetic = makeState({
    productFamilySlug: 'cosmetic',
    selectedIngredientListVariantKey: 'incorporated_ingredients',
});
assert.equal(cosmetic.ingredientListVariantHelperText, 'translated:output.lists.cosmetic_generated_help');

const soapAsAdded = makeState({
    productFamilySlug: 'soap',
    selectedIngredientListVariantKey: 'incorporated_ingredients',
});
assert.equal(soapAsAdded.ingredientListVariantHelperText, 'translated:output.lists.soap_as_added_help');

const soapSaponified = makeState({
    productFamilySlug: 'soap',
    selectedIngredientListVariantKey: 'saponified_with_superfat',
});
assert.equal(soapSaponified.ingredientListVariantHelperText, 'translated:output.lists.soap_saponified_help');
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
    expect($process->getOutput())->toBe('');
});

it('uses unit-aware precision for soap lye, liquids, additions, and batch totals', function (): void {
    $formulaSection = file_get_contents(resource_path('js/recipe-workbench/sections/formula-section.js'));
    $presentationSection = file_get_contents(resource_path('js/recipe-workbench/sections/presentation-section.js'));
    $reactionCore = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/reaction-core.blade.php'));
    $postReaction = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/post-reaction.blade.php'));
    $formulaSettings = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/formula-settings.blade.php'));
    $output = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/output-tab.blade.php'));

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
        ->toContain('this.calculatedMassDecimals(curedWeight)')
        ->and($output)
        ->toContain('formatPercentageTotal(curedSoapIngredientTotalPercent)')
        ->not->toContain('row.adjusted_weight')
        ->not->toContain('curedSoapMassDecimals')
        ->not->toContain('format(row.adjusted_weight, 2)');
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
