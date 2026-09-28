<?php

use Symfony\Component\Process\Process;

it('captures a detached current draft and nullable costing without saving or rebasing state', function (): void {
    $process = new Process(['node', '--experimental-vm-modules', '--input-type=module', '--eval', <<<'JS'
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';

const modules = new Map();
function loadModule(filename) {
    filename = path.resolve(filename);
    if (!modules.has(filename)) {
        modules.set(filename, new vm.SourceTextModule(fs.readFileSync(filename, 'utf8'), { identifier: filename }));
    }
    return modules.get(filename);
}

const entry = loadModule('resources/js/recipe-workbench/outputs.js');
await entry.link((specifier, referencing) => {
    const filename = path.resolve(path.dirname(referencing.identifier), specifier);
    return loadModule(path.extname(filename) ? filename : `${filename}.js`);
});
await entry.evaluate();

let saveCalls = 0;
let flushCalls = 0;
const state = {
    formulaName: 'Working Soap Formula',
    oilUnit: 'g',
    oilWeight: 750,
    manufacturingMode: 'saponify_in_formula',
    exposureMode: 'rinse_off',
    regulatoryRegime: 'eu',
    editMode: 'percentage',
    lyeType: 'naoh',
    kohPurity: 90,
    dualKohPercentage: 40,
    waterMode: 'percent_of_oils',
    waterValue: 38,
    superfat: 5,
    ifraCategorySelectionMode: 'automatic',
    selectedIfraProductCategoryId: '',
    finalIngredientList: '',
    finalIngredientListBasisHash: '',
    finalPlainIngredientList: '',
    finalPlainIngredientListBasisHash: '',
    phaseOrder: [{ key: 'saponified_oils', name: 'Saponified Oils' }],
    phaseItems: { saponified_oils: [{ id: 'oil-row', ingredient_id: 12, percentage: 100, note: 'live' }], lye_water: [] },
    packagingPlanRows: [],
    productionOutputType: 'finished_product',
    outputIngredientId: '',
    costingOilWeight: 750,
    costingOilUnit: 'g',
    costingUnitsProduced: 3,
    costingCurrency: 'EUR',
    costingFormulaRows: [{ id: 88, ingredient_id: 12, phaseKey: 'saponified_oils', position: 1 }],
    packagingCostRows: [
        { packaging_item_id: 7, name: 'Jar', unit_cost: null, quantity: 1 },
        { packaging_item_id: 7, name: 'Jar', unit_cost: null, quantity: 2 },
    ],
    hasLoadedCosting: true,
    canonicalPricePerKg() { return null; },
    editingRecipeRevision: 9,
    save() { saveCalls++; },
    flushCostingSave() { flushCalls++; },
    async $nextTick() {
        this.$wire.data.manufacturing_instructions = { type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Latest procedure' }] }] };
    },
    $wire: { data: { manufacturing_instructions: { type: 'doc', content: [] } } },
};

const captured = await entry.namespace.captureRecipeWorkbenchOutput(state, {
    workspace: 'workspace-public-id',
    recipe: 'recipe-public-id',
    productFamily: 'soap',
    printUrl: '/dashboard/formula-outputs/print',
    exportUrl: '/dashboard/formula-outputs/export',
}, { format: 'csv', printContext: 'instructions' });

assert.equal(captured.workspace, 'workspace-public-id');
assert.equal(captured.recipe, 'recipe-public-id');
assert.equal(captured.product_family, 'soap');
assert.equal(captured.draft.oil_weight, 750);
assert.equal(captured.manufacturing_instructions.content[0].content[0].text, 'Latest procedure');
assert.equal(captured.costing.oil_weight_for_costing, 750);
assert.equal(captured.costing.oil_unit_for_costing, 'g');
assert.equal(captured.costing.units_produced, 3);
assert.equal(captured.costing.currency, 'EUR');
assert.equal(captured.costing.items[0].id, 88);
assert.equal(captured.costing.items[0].ingredient_id, 12);
assert.equal(captured.costing.items[0].phase_key, 'saponified_oils');
assert.equal(captured.costing.items[0].position, 1);
assert.equal(captured.costing.items[0].price_per_kg, null);
assert.equal(captured.costing.packaging_items.length, 2);
assert.equal(captured.costing.packaging_items[0].unit_cost, null);
assert.equal(state.editingRecipeRevision, 9);
assert.equal(saveCalls, 0);
assert.equal(flushCalls, 0);

state.phaseItems.saponified_oils[0].percentage = 1;
state.$wire.data.manufacturing_instructions.content[0].content[0].text = 'changed after capture';
state.packagingCostRows[0].quantity = 99;
assert.equal(captured.draft.phase_items.saponified_oils[0].percentage, 100);
assert.equal(captured.manufacturing_instructions.content[0].content[0].text, 'Latest procedure');
assert.equal(captured.costing.packaging_items[0].components_per_unit, 1);

const rawState = { ...state };
delete rawState.$wire;
delete rawState.$nextTick;
const section = entry.namespace.createRecipeWorkbenchOutputSection({
    workspace: 'workspace-public-id',
    recipe: 'recipe-public-id',
    productFamily: 'soap',
    printUrl: '/dashboard/formula-outputs/print',
    exportUrl: '/dashboard/formula-outputs/export',
});
const invocationProxy = {
    ...rawState,
    ...section,
    $wire: { data: { manufacturing_instructions: { type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Proxy procedure' }] }] } } },
    async $nextTick() {},
};
const proxyCapture = await invocationProxy.captureOutput({ format: 'csv' });
assert.equal(proxyCapture.manufacturing_instructions.content[0].content[0].text, 'Proxy procedure');

const unloadedCosting = await entry.namespace.captureRecipeWorkbenchOutput({ ...state, hasLoadedCosting: false }, {
    workspace: 'workspace-public-id', productFamily: 'soap', printUrl: '/print', exportUrl: '/export',
}, { format: 'csv' });
assert.equal(unloadedCosting.costing, null);
JS], base_path());

    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
});

it('keeps output transport read only and gives translated feedback for failures and downloads', function (): void {
    $process = new Process(['node', '--experimental-vm-modules', '--input-type=module', '--eval', <<<'JS'
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';

const events = [];
const popups = [];
const anchors = [];
const scheduled = [];
const createdUrls = [];
const revokedUrls = [];
let allowPopup = true;
let fetchHandler = async () => response({ text: '<main>Formula sheet</main>' });
let tickResolvers = [];

function createPopup() {
    const popup = {
        closed: false,
        focused: false,
        close() { this.closed = true; },
        focus() { this.focused = true; },
        document: {
            title: '',
            body: {
                children: [],
                replaceChildren(...children) { this.children = children; },
            },
            writes: [],
            createElement(tag) { return { tagName: tag, textContent: '' }; },
            open() { events.push('popup-document-open'); },
            write(html) { this.writes.push(html); },
            close() { events.push('popup-document-close'); },
        },
    };
    popups.push(popup);
    return popup;
}

function response({ status = 200, body = {}, text = '', disposition = '' } = {}) {
    return {
        ok: status >= 200 && status < 300,
        status,
        async json() { return body; },
        async text() { return text; },
        async blob() { return { type: 'application/octet-stream' }; },
        headers: {
            get(name) { return name.toLowerCase() === 'content-disposition' ? disposition : null; },
        },
    };
}

const documentMock = {
    querySelector() { return { content: 'csrf-token' }; },
    body: { append(anchor) { events.push('anchor-appended'); } },
    createElement(tag) {
        const anchor = {
            tagName: tag,
            href: '',
            download: '',
            hidden: false,
            clicked: false,
            removed: false,
            click() { this.clicked = true; events.push('anchor-clicked'); },
            remove() { this.removed = true; events.push('anchor-removed'); },
        };
        anchors.push(anchor);
        return anchor;
    },
};

const context = vm.createContext({
    document: documentMock,
    window: {
        open() {
            events.push('popup-open');
            return allowPopup ? createPopup() : null;
        },
    },
    fetch(...args) {
        events.push('fetch');
        events.push('request:' + args[1].body);
        return fetchHandler(...args);
    },
    URL: {
        createObjectURL(blob) {
            const url = 'blob:formula-output-' + (createdUrls.length + 1);
            createdUrls.push({ url, blob });
            return url;
        },
        revokeObjectURL(url) { revokedUrls.push(url); },
    },
    Blob: class FakeBlob {
        constructor(parts, options) {
            this.parts = parts;
            this.options = options;
        }
    },
    setTimeout(callback) { scheduled.push(callback); },
});

const modules = new Map();
function loadModule(filename) {
    filename = path.resolve(filename);
    if (!modules.has(filename)) {
        modules.set(filename, new vm.SourceTextModule(fs.readFileSync(filename, 'utf8'), { identifier: filename, context }));
    }
    return modules.get(filename);
}

const entry = loadModule('resources/js/recipe-workbench/outputs.js');
await entry.link((specifier, referencing) => {
    const filename = path.resolve(path.dirname(referencing.identifier), specifier);
    return loadModule(path.extname(filename) ? filename : filename + '.js');
});
await entry.evaluate();

const messages = {
    accessRequired: 'Accès requis.',
    forbidden: 'Accès refusé.',
    notFound: 'Formule absente.',
    workspaceChanged: 'Espace modifié.',
    invalidFormula: 'Formule à vérifier.',
    throttled: 'Patientez un instant.',
    failed: 'Sortie impossible.',
    preparingTitle: '<em>Préparation</em>',
    preparingSheet: '<strong>Préparation…</strong>',
    browserBlocked: 'Le navigateur a bloqué l’onglet.',
    fallbackLink: 'Ouvrir la fiche préparée',
    printFailed: 'Impression impossible.',
    exportFailed: 'Export impossible.',
    defaultFilenameXlsx: 'formule-courante.xlsx',
    defaultFilenameCsv: 'formule-courante.csv',
    pending: 'Préparation en cours…',
};

let saves = 0;
let flushes = 0;
function createComponent(deferNextTick = false) {
    const state = {
        formulaName: 'Formula under edit',
        oilUnit: 'g',
        oilWeight: 750,
        manufacturingMode: 'saponify_in_formula',
        exposureMode: 'rinse_off',
        regulatoryRegime: 'eu',
        editMode: 'percentage',
        lyeType: 'naoh',
        kohPurity: 90,
        dualKohPercentage: 40,
        waterMode: 'percent_of_oils',
        waterValue: 38,
        superfat: 5,
        ifraCategorySelectionMode: 'automatic',
        selectedIfraProductCategoryId: '',
        finalIngredientList: '',
        finalIngredientListBasisHash: '',
        finalPlainIngredientList: '',
        finalPlainIngredientListBasisHash: '',
        phaseOrder: [{ key: 'saponified_oils', name: 'Saponified Oils' }],
        phaseItems: { saponified_oils: [{ id: 'oil-row', ingredient_id: 12, percentage: 100, note: 'live note' }] },
        packagingPlanRows: [],
        productionOutputType: 'finished_product',
        outputIngredientId: '',
        hasLoadedCosting: false,
        editingRecipeRevision: 13,
        recipeContentStatus: 'dirty',
        save() { saves++; },
        flushCostingSave() { flushes++; },
        $wire: { data: { manufacturing_instructions: { type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Pending procedure edit' }] }] } } },
        $nextTick() {
            if (!deferNextTick) {
                return Promise.resolve();
            }

            return new Promise((resolve) => tickResolvers.push(resolve));
        },
    };
    const section = entry.namespace.createRecipeWorkbenchOutputSection({
        workspace: 'workspace-public-id',
        recipe: 'recipe-public-id',
        productFamily: 'soap',
        printUrl: '/dashboard/formula-outputs/print',
        exportUrl: '/dashboard/formula-outputs/export',
        messages,
    });

    return { state, component: Object.assign(state, section) };
}

const { state, component } = createComponent(true);
const printPromise = component.printCurrentFormula({ printContext: 'instructions' });
assert.equal(events[0], 'popup-open');
assert.equal(popups.length, 1);
assert.equal(component.outputPending, true);
assert.equal(popups[0].document.title, messages.preparingTitle);
assert.equal(popups[0].document.body.children[0].textContent, messages.preparingSheet);
assert.deepEqual(popups[0].document.writes, []);

const duplicatePrint = component.printCurrentFormula({ printContext: 'instructions' });
assert.equal(await duplicatePrint, false);
assert.equal(popups.length, 1);
tickResolvers.forEach((resolve) => resolve());
assert.equal(await printPromise, true);
assert.equal(events.includes('fetch'), true);
assert.equal(popups[0].document.writes[0], '<main>Formula sheet</main>');
assert.equal(popups[0].focused, true);
assert.equal(component.outputPending, false);
assert.equal(state.editingRecipeRevision, 13);
assert.equal(state.recipeContentStatus, 'dirty');
assert.equal(saves, 0);
assert.equal(flushes, 0);

const printPayload = JSON.parse(events.find((event) => event.startsWith('request:')).slice('request:'.length));
assert.equal(printPayload.print_context, 'instructions');
assert.equal(printPayload.draft.oil_weight, 750);
assert.equal(printPayload.manufacturing_instructions.content[0].content[0].text, 'Pending procedure edit');

const statusCases = [
    [401, messages.accessRequired],
    [403, messages.forbidden],
    [404, messages.notFound],
    [409, messages.workspaceChanged],
    [422, messages.invalidFormula],
    [429, messages.throttled],
];
for (const [status, expectedMessage] of statusCases) {
    fetchHandler = async () => response({ status });
    const failed = createComponent();
    const before = {
        name: failed.state.formulaName,
        weight: failed.state.oilWeight,
        revision: failed.state.editingRecipeRevision,
        contentStatus: failed.state.recipeContentStatus,
    };

    assert.equal(await failed.component.printCurrentFormula(), false);
    assert.equal(popups.at(-1).closed, true);
    assert.equal(failed.component.outputError, expectedMessage);
    assert.equal(failed.component.outputPending, false);
    assert.deepEqual({
        name: failed.state.formulaName,
        weight: failed.state.oilWeight,
        revision: failed.state.editingRecipeRevision,
        contentStatus: failed.state.recipeContentStatus,
    }, before);
}

fetchHandler = async () => { throw new TypeError('network failure'); };
const networkFailure = createComponent();
assert.equal(await networkFailure.component.printCurrentFormula(), false);
assert.equal(popups.at(-1).closed, true);
assert.equal(networkFailure.component.outputError, messages.printFailed);
assert.equal(networkFailure.component.outputPending, false);
assert.equal(saves, 0);
assert.equal(flushes, 0);

allowPopup = false;
fetchHandler = async () => response({ text: '<main>Fallback formula sheet</main>' });
const blockedPopup = createComponent();
assert.equal(await blockedPopup.component.printCurrentFormula(), true);
assert.equal(blockedPopup.component.outputError, messages.browserBlocked);
assert.equal(blockedPopup.component.outputFallbackLabel, messages.fallbackLink);
assert.equal(blockedPopup.component.outputFallbackUrl.startsWith('blob:'), true);
assert.equal(createdUrls.at(-1).blob.options.type, 'text/html;charset=utf-8');
assert.equal(blockedPopup.component.outputPending, false);

fetchHandler = async () => response({
    disposition: "attachment; filename*=UTF-8''formule-%C3%A9t%C3%A9.xlsx",
});
const exportComponent = createComponent();
assert.equal(await exportComponent.component.exportCurrentFormula('xlsx'), true);
assert.equal(anchors.at(-1).download, 'formule-été.xlsx');
assert.equal(anchors.at(-1).clicked, true);
assert.equal(anchors.at(-1).removed, true);
assert.equal(exportComponent.component.outputPending, false);
assert.equal(createdUrls.at(-1).url, anchors.at(-1).href);
scheduled.forEach((callback) => callback());
assert.equal(revokedUrls.includes(anchors.at(-1).href), true);

fetchHandler = async () => response({ disposition: '' });
const defaultNameExport = createComponent();
assert.equal(await defaultNameExport.component.exportCurrentFormula('csv'), true);
assert.equal(anchors.at(-1).download, messages.defaultFilenameCsv);

fetchHandler = async () => response({ status: 422, body: { errors: { draft: ['Translated validation message.'] } } });
const failedExport = createComponent();
assert.equal(await failedExport.component.exportCurrentFormula('xlsx'), false);
assert.equal(failedExport.component.outputError, 'Translated validation message.');
assert.equal(failedExport.component.outputPending, false);
assert.equal(failedExport.state.oilWeight, 750);
assert.equal(failedExport.state.editingRecipeRevision, 13);
assert.equal(failedExport.state.recipeContentStatus, 'dirty');

fetchHandler = async () => { throw new TypeError('network failure'); };
const networkExportFailure = createComponent();
assert.equal(await networkExportFailure.component.exportCurrentFormula('csv'), false);
assert.equal(networkExportFailure.component.outputError, messages.exportFailed);
assert.equal(networkExportFailure.component.outputPending, false);
assert.equal(networkExportFailure.state.oilWeight, 750);
assert.equal(networkExportFailure.state.editingRecipeRevision, 13);
assert.equal(networkExportFailure.state.recipeContentStatus, 'dirty');
assert.equal(saves, 0);
assert.equal(flushes, 0);
JS], base_path());

    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
});
