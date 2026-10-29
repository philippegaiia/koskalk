import test from 'node:test';
import assert from 'node:assert/strict';
import { createCalculatorStorage } from '../../resources/js/guest-calculator-storage.js';

const draft = () => ({ name: 'Trial', notes: '', oil_unit: 'g', oil_weight: 500, editing_mode: 'percentage', lye_type: 'naoh', koh_purity_percentage: 90, dual_lye_koh_percentage: 40, water_mode: 'lye_concentration', water_value: 30, superfat: 5, cured_water_percentage: 11, phases: [{ key: 'oils', name: 'Oils' }], phase_items: { oils: [{ id: 'one', ingredient_id: 7, name: 'Olive', percentage: 100, weight: 500 }] } });
const memory = () => {
    const values = new Map();
    return { getItem: (key) => values.get(key) ?? null, setItem: (key, value) => values.set(key, value), removeItem: (key) => values.delete(key), values };
};

test('recovers the calculator draft and reports changed and retired references without losing rows', () => {
    const storage = memory();
    const slot = createCalculatorStorage('solid-soap', storage);
    slot.write(draft(), { 7: { name: 'Olive', fingerprint: 'old' } });

    const changed = slot.read([{ id: 7, fingerprint: 'new' }]);
    const retired = slot.read([]);

    assert.equal(changed.draft.phase_items.oils[0].weight, 500);
    assert.deepEqual(changed.changed, ['Olive']);
    assert.deepEqual(retired.retired, ['Olive']);
    assert.equal(retired.draft.phase_items.oils[0].ingredient_id, 7);
});

test('clears only its calculator slot and does not overwrite it while reading', () => {
    const storage = memory();
    const soap = createCalculatorStorage('solid-soap', storage);
    const cosmetic = createCalculatorStorage('cosmetics', storage);
    soap.write(draft(), {});
    cosmetic.write(draft(), {});
    const before = storage.values.get(cosmetic.key);

    soap.clear();
    cosmetic.read([]);

    assert.equal(storage.getItem(soap.key), null);
    assert.equal(storage.getItem(cosmetic.key), before);
});

test('reports malformed, oversized and incompatible recovery data without using it', () => {
    for (const value of ['{', 'x'.repeat(300001), JSON.stringify({ version: 9, draft: draft() }), JSON.stringify({ version: 1, draft: { phases: [] } })]) {
        const storage = memory();
        const slot = createCalculatorStorage('cosmetics', storage);
        storage.setItem(slot.key, value);
        assert.equal(slot.read([]).status, 'corrupt');
    }
});

test('reports unavailable storage explicitly for read write and clear', () => {
    const storage = { getItem() { throw new Error('denied'); }, setItem() { throw new Error('quota'); }, removeItem() { throw new Error('denied'); } };
    const slot = createCalculatorStorage('liquid-soap', storage);
    assert.equal(slot.read([]).status, 'unavailable');
    assert.equal(slot.write(draft(), {}).status, 'unavailable');
    assert.equal(slot.clear().status, 'unavailable');
});

test('rejects unsafe phase keys, too many phases, and more than 100 total rows', () => {
    const invalid = [];
    for (const key of ['constructor', '__proto__', 'prototype', 'a'.repeat(65), 'phase.bad', 'Upper']) {
        invalid.push({ ...draft(), phases: [{ key, name: 'Phase' }], phase_items: { [key]: [] } });
    }
    invalid.push({ ...draft(), phases: Array.from({ length: 13 }, (_, index) => ({ key: `phase_${index}`, name: 'Phase' })) });
    invalid.push({ ...draft(), phases: [{ key: 'a', name: 'A' }, { key: 'b', name: 'B' }], phase_items: { a: Array(51).fill(draft().phase_items.oils[0]), b: Array(50).fill(draft().phase_items.oils[0]) } });
    for (const value of invalid) {
        const storage = memory(); const slot = createCalculatorStorage('cosmetics', storage);
        storage.setItem(slot.key, JSON.stringify({ version: 1, draft: value, references: {} }));
        assert.equal(slot.read([]).status, 'corrupt');
    }
});

test('rejects malformed row amounts and nested field types before restoring a draft', () => {
    const invalid = [
        { ...draft(), phase_items: { oils: [{ ...draft().phase_items.oils[0], percentage: '100' }] } },
        { ...draft(), phase_items: { oils: [{ ...draft().phase_items.oils[0], weight: null }] } },
        { ...draft(), phase_items: { oils: [{ ...draft().phase_items.oils[0], note: {} }] } },
        { ...draft(), liquid_process: 'broken' },
        { ...draft(), unknown: { nested: 'unsupported' } },
        { ...draft(), phase_items: { unknown: [] } },
    ];
    for (const value of invalid) {
        const storage = memory(); const slot = createCalculatorStorage('cosmetics', storage);
        storage.setItem(slot.key, JSON.stringify({ version: 1, draft: value, references: {} }));
        assert.equal(slot.read([]).status, 'corrupt');
    }
});

test('bounds recovery by encoded byte size and rejects nonfinite numbers when writing', () => {
    const storage = memory(); const slot = createCalculatorStorage('cosmetics', storage);
    const oversized = { ...draft(), notes: 'é'.repeat(60000) };
    storage.setItem(slot.key, JSON.stringify({ version: 1, draft: oversized, references: {} }));
    assert.equal(slot.read([]).status, 'corrupt');
    const invalid = draft(); invalid.phase_items.oils[0].weight = Infinity;
    assert.equal(slot.write(invalid, {}).status, 'unavailable');
});

test('recovers a full liquid draft and accepts exactly twelve phases and one hundred rows', () => {
    const storage = memory(); const liquid = createCalculatorStorage('liquid-soap', storage);
    const liquidDraft = { ...draft(), lye_type: 'koh', liquid_process: { first_dilution_percentage: 35, final_dilution_percentage: 25, neutralizer_solution_percentage: 20, measured_paste_weight: null } };
    assert.equal(liquid.write(liquidDraft, {}).status, 'saved');
    assert.equal(liquid.read([]).draft.liquid_process.first_dilution_percentage, 35);

    const maximum = draft();
    maximum.phases = Array.from({ length: 12 }, (_, index) => ({ key: `phase_${index}`, name: 'Phase' }));
    maximum.phase_items = Object.fromEntries(maximum.phases.map((phase, index) => [phase.key, index === 0 ? Array.from({ length: 100 }, (_, row) => ({ ...draft().phase_items.oils[0], id: String(row) })) : []]));
    const cosmetic = createCalculatorStorage('cosmetics', storage);
    assert.equal(cosmetic.write(maximum, {}).status, 'saved');
    assert.equal(cosmetic.read([]).draft.phase_items.phase_0.length, 100);
});

test('an older tab cannot overwrite or clear a draft updated by another tab', () => {
    const browser = memory();
    const first = createCalculatorStorage('solid-soap', browser);
    const second = createCalculatorStorage('solid-soap', browser);
    first.read([]); second.read([]);
    const latest = { ...draft(), name: 'Latest tab' };
    assert.equal(first.write(latest, {}).status, 'saved');
    assert.equal(second.write({ ...draft(), name: 'Older tab' }, {}).status, 'conflict');
    assert.equal(second.clear().status, 'conflict');
    assert.equal(JSON.parse(browser.getItem(first.key)).draft.name, 'Latest tab');
    assert.equal(second.read([]).draft.name, 'Latest tab');
    assert.equal(second.write({ ...draft(), name: 'Reviewed latest' }, {}).status, 'saved');
});
