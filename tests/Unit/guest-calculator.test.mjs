import { registerHooks } from 'node:module';
import test from 'node:test';
import assert from 'node:assert/strict';

registerHooks({ resolve(specifier, context, next) {
    try { return next(specifier, context); } catch (error) {
        if (error.code === 'ERR_MODULE_NOT_FOUND' && specifier.startsWith('.') && !specifier.endsWith('.js')) { return next(`${specifier}.js`, context); }
        throw error;
    }
} });
const { createRecipeWorkbench } = await import('../../resources/js/recipe-workbench/component.js');
const { captureGuestDraft, hydrateGuestDraft, createGuestSection } = await import('../../resources/js/guest-calculator.js');
const { refreshCalculationPreview } = await import('../../resources/js/recipe-workbench/bridge.js');

globalThis.window = { location: { hash: '' }, localStorage: { getItem: () => null } };
const defaults = { name: '', notes: '', oil_unit: 'g', oil_weight: 100, editing_mode: 'percentage', lye_type: 'naoh', koh_purity_percentage: 100, dual_lye_koh_percentage: 0, water_mode: 'lye_concentration', water_value: 30, superfat: 5, cured_water_percentage: 11, phases: [{ key: 'phase_a', name: 'A' }], phase_items: { phase_a: [] } };
const storage = { read: () => ({ status: 'empty' }), write: () => ({ status: 'saved' }), clear: () => ({ status: 'cleared' }) };
function workbench(dependencies = {}) {
    const config = { key: 'cosmetics', defaults, catalogue: [], messages: {}, previewUrl: '/preview' };
    const payload = { productFamily: { slug: 'cosmetic' }, canEditRecipe: true, guestCalculator: config };
    const bench = createRecipeWorkbench(payload);
    Object.defineProperties(bench, Object.getOwnPropertyDescriptors(createGuestSection(payload, { storage, ...dependencies })));
    bench.$watch = () => {}; bench.$nextTick = (callback) => callback();
    bench.releasePendingPreview = () => {};
    bench.initializeGuestCalculator();
    return bench;
}

test('guest mode uses the shared workbench row editing and unit conversion', () => {
    const bench = workbench();
    bench.guestTemporaryName = 'My blend'; bench.addGuestTemporaryIngredient();
    assert.equal(bench.cosmeticFormulaRows().length, 1);
    assert.equal(bench.phaseItems.phase_a[0].percentage, 100);
    const draft = captureGuestDraft(bench);
    assert.equal(draft.phase_items.phase_a[0].name, 'My blend');
    assert.equal(draft.phase_items.phase_a[0].ingredient_id, null);
    assert.equal(draft.phase_items.phase_a[0].weight, 100);
    assert.equal(draft.regulatory_regime, undefined);
    assert.equal(bench.canPersist, false);
    assert.equal(bench.canWriteRecipe, true);
});

test('recovery hydrates only current catalogue chemistry and preserves temporary rows', () => {
    const draft = structuredClone(defaults);
    draft.phase_items.phase_a = [{ id: 'known', ingredient_id: 1, name: 'Old', percentage: 80, weight: 80 }, { id: 'temp', ingredient_id: null, name: 'Blend', percentage: 20, weight: 20 }];
    const hydrated = hydrateGuestDraft(draft, [{ id: 1, name: 'Current', koh_sap_value: 0.2 }]);
    assert.equal(hydrated.phaseItems.phase_a[0].name, 'Current');
    assert.equal(hydrated.phaseItems.phase_a[0].koh_sap_value, 0.2);
    assert.equal(hydrated.phaseItems.phase_a[1].name, 'Blend');
    assert.equal(hydrated.phaseItems.phase_a[1].koh_sap_value, undefined);
});

test('a restored guest calculation starts with its settings summarised, a first visit with them open', () => {
    const restored = workbench({ storage: { ...storage, read: () => ({ status: 'recovered', draft: structuredClone(defaults), references: {} }) } });
    assert.equal(restored.isFormulaSettingsOpen, false);
    assert.equal(restored.formulaSettingsOverflow, false);
    assert.equal(workbench().isFormulaSettingsOpen, true);
});

test('guest requests go through the shared preview bridge and reject stale results', async () => {
    let resolve;
    const pending = new Promise((done) => { resolve = done; });
    const bench = workbench({ fetch: () => pending });
    bench.guestTemporaryName = 'Original'; bench.addGuestTemporaryIngredient();
    const request = refreshCalculationPreview(bench);
    bench.phaseItems.phase_a[0].percentage = 50;
    resolve({ ok: true, json: async () => ({ calculation: { stale: true }, labeling: { text: 'Stale' }, warnings: [], references: {} }) });
    assert.equal(await request, false);
    assert.equal(bench.backendCalculation, null);
    assert.equal(bench.guestCanPrint, false);
});

test('successful preview enables printing current notes and name without account persistence', async () => {
    const bench = workbench({ fetch: async () => ({ ok: true, json: async () => ({ calculation: null, labeling: { text: '[Temporary: Blend]', incomplete: true }, document: {}, warnings: ['Unchecked'], references: {} }) }) });
    bench.guestTemporaryName = 'Blend'; bench.addGuestTemporaryIngredient();
    assert.equal(await refreshCalculationPreview(bench), true);
    bench.formulaName = 'My formula'; bench.guestNotes = 'Print this';
    assert.equal(bench.guestCanPrint, true);
    assert.equal(captureGuestDraft(bench).notes, 'Print this');
    assert.equal(bench.guestLabeling.incomplete, true);
    bench.phaseItems.phase_a[0].percentage = 90;
    assert.equal(bench.guestCanPrint, false);
});

test('failed preview clears stale results and exposes the server validation message', async () => {
    const bench = workbench({ fetch: async () => ({ ok: false, json: async () => ({ errors: { draft: ['Must total 100%.'] } }) }) });
    bench.backendCalculation = { previous: true };
    assert.equal(await refreshCalculationPreview(bench), false);
    assert.equal(bench.backendCalculation, null);
    assert.equal(bench.calculationPreviewMessage, 'Must total 100%.');
    assert.equal(bench.guestCanPrint, false);
});

test('multiple temporary cosmetic rows can use the shared phase move interaction', () => {
    const bench = workbench();
    bench.guestTemporaryName = 'First'; bench.addGuestTemporaryIngredient();
    bench.guestTemporaryName = 'Second'; bench.addGuestTemporaryIngredient();
    bench.phaseItems.phase_b = []; bench.phaseOrder.push({ key: 'phase_b', name: 'B' });
    const [first, second] = bench.phaseItems.phase_a;
    assert.equal(bench.moveFormulaRow('phase_a', first.id, 'phase_b', 0), true);
    assert.equal(bench.moveFormulaRow('phase_a', second.id, 'phase_b', 1), true);
    assert.equal(bench.phaseItems.phase_b.length, 2);
});

test('guest soap starts with the shared starter oil and an open ingredient browser', () => {
    const draft = { ...structuredClone(defaults), oil_weight: 1000, phases: [{ key: 'saponified_oils', name: 'Oils' }, { key: 'additives', name: 'Additives' }, { key: 'fragrance', name: 'Fragrance' }], phase_items: { saponified_oils: [], additives: [], fragrance: [] } };
    const oil = { id: 1, name: 'Olive Oil', available_phases: ['saponified_oils', 'additives'], can_add_to_saponified_oils: true, category: 'lipids', koh_sap_value: .19 };
    const payload = { productFamily: { slug: 'soap' }, canEditRecipe: true, ingredients: [oil], guestCalculator: { key: 'solid-soap', defaults: draft, catalogue: [], messages: {} } };
    const bench = createRecipeWorkbench(payload);
    Object.defineProperties(bench, Object.getOwnPropertyDescriptors(createGuestSection(payload, { storage })));
    bench.$watch = () => {}; bench.$nextTick = (callback) => callback();
    bench.scheduleCalculationPreview = () => {}; bench.startEditingProtection = async () => {};

    bench.init();

    assert.equal(bench.ingredientBrowserOpen, true);
    assert.equal(bench.oilRows.length, 1);
    assert.equal(bench.oilRows[0].ingredient_id, 1);
    assert.equal(bench.oilRows[0].percentage, 100);
});


test('registered workbench keeps its existing narrow-screen selector default', () => {
    const bench = createRecipeWorkbench({ productFamily: { slug: 'soap' }, canEditRecipe: true });
    assert.equal(bench.ingredientBrowserOpen, false);
});

test('start new uses the same shared starter oil as a fresh guest visit', () => {
    const draft = { ...structuredClone(defaults), phases: [{ key: 'saponified_oils', name: 'Oils' }, { key: 'additives', name: 'Additives' }], phase_items: { saponified_oils: [], additives: [] } };
    const oil = { id: 7, name: 'Olive Oil', category: 'lipids', available_phases: ['saponified_oils'], can_add_to_saponified_oils: true };
    const payload = { productFamily: { slug: 'soap' }, ingredients: [oil], guestCalculator: { key: 'solid-soap', defaults: draft, messages: {} } };
    const bench = createRecipeWorkbench(payload);
    Object.defineProperties(bench, Object.getOwnPropertyDescriptors(createGuestSection(payload, { storage })));
    bench.$watch = () => {}; bench.$nextTick = (callback) => callback(); bench.scheduleCalculationPreview = () => {};
    bench.initializeGuestCalculator(); bench.addDefaultSoapOil();
    const initial = bench.oilRows.map((row) => ({ ingredient: row.ingredient_id, percentage: row.percentage }));
    bench.oilRows[0].percentage = 50;
    bench.resetGuestCalculation();
    assert.deepEqual(bench.oilRows.map((row) => ({ ingredient: row.ingredient_id, percentage: row.percentage })), initial);
    assert.equal(bench.guestCanPrint, false);
});

test('a throttled preview respects Retry-After and requests an automatic retry', async () => {
    const bench = workbench({ fetch: async () => ({ status: 429, headers: { get: () => '12' } }) });
    let scheduled = 0; bench.scheduleCalculationPreview = () => scheduled++;
    const before = Date.now();
    assert.equal(await refreshCalculationPreview(bench), false);
    assert.equal(bench.calculationPreviewMessage, 'preview_throttled');
    assert.ok(bench.guestPreviewRetryAt >= before + 12000);
    assert.equal(scheduled, 1);
    assert.equal(bench.guestCanPrint, false);
});

test('storage conflicts leave the current formula editable and prevent a destructive reset', () => {
    const bench = workbench({ storage: { ...storage, write: () => ({ status: 'conflict' }), clear: () => ({ status: 'conflict' }) } });
    bench.guestTemporaryName = 'Keep my work'; bench.addGuestTemporaryIngredient();
    bench.storeGuestDraft();
    assert.equal(bench.guestStorageMessage, 'storage_conflict');
    bench.resetGuestCalculation();
    assert.equal(bench.phaseItems.phase_a[0].name, 'Keep my work');
});
