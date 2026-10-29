const VERSION = 1;
const MAX_BYTES = 100000;
const CALCULATORS = new Set(['solid-soap', 'liquid-soap', 'cosmetics']);
const RESERVED_KEYS = new Set(['__proto__', 'constructor', 'prototype']);
const isObject = (value) => value !== null && typeof value === 'object' && !Array.isArray(value);
const isNumber = (value) => typeof value === 'number' && Number.isFinite(value);
const isNullableNumber = (value) => value === null || isNumber(value);
const isText = (value, max) => typeof value === 'string' && value.length <= max;
const isNullableText = (value, max) => value === null || value === undefined || isText(value, max);
const validKey = (key) => typeof key === 'string' && key.length <= 64 && /^[a-z][a-z0-9_-]*$/.test(key) && !RESERVED_KEYS.has(key);
const onlyKeys = (value, keys) => Object.keys(value).every((key) => keys.includes(key));
const byteSize = (raw) => new TextEncoder().encode(raw).length;

function validDraft(draft, calculator) {
    if (!isObject(draft) || !onlyKeys(draft, ['name', 'notes', 'oil_unit', 'oil_weight', 'editing_mode', 'lye_type', 'koh_purity_percentage', 'dual_lye_koh_percentage', 'water_mode', 'water_value', 'superfat', 'cured_water_percentage', 'liquid_process', 'phases', 'phase_items']) || !isNullableText(draft.name, 160) || !isNullableText(draft.notes, 4000)
        || !['g', 'kg', 'oz', 'lb'].includes(draft.oil_unit)
        || !['percentage', 'weight'].includes(draft.editing_mode)
        || !['naoh', 'koh', 'dual'].includes(draft.lye_type)
        || ![90, 100].includes(draft.koh_purity_percentage)
        || !['percent_of_oils', 'lye_ratio', 'lye_concentration'].includes(draft.water_mode)
        || !['oil_weight', 'dual_lye_koh_percentage', 'water_value', 'superfat', 'cured_water_percentage'].every((key) => isNullableNumber(draft[key]))
        || !Array.isArray(draft.phases) || draft.phases.length < 1 || draft.phases.length > 12
        || !isObject(draft.phase_items)) {
        return false;
    }
    const keys = draft.phases.map((phase) => phase?.key);
    if (new Set(keys).size !== keys.length || !draft.phases.every((phase) => isObject(phase) && onlyKeys(phase, ['key', 'name']) && validKey(phase.key) && isText(phase.name, 100))) {
        return false;
    }
    if (Object.keys(draft.phase_items).length !== keys.length || !keys.every((key) => Object.hasOwn(draft.phase_items, key))) {
        return false;
    }
    let count = 0;
    for (const rows of Object.values(draft.phase_items)) {
        if (!Array.isArray(rows)) { return false; }
        count += rows.length;
        if (count > 100 || !rows.every((row) => isObject(row) && onlyKeys(row, ['id', 'ingredient_id', 'name', 'percentage', 'weight', 'note', 'fingerprint', 'final_percentage']) && isText(row.id, 80)
            && (row.ingredient_id === null || (Number.isInteger(row.ingredient_id) && row.ingredient_id > 0))
            && (row.final_percentage == null || (isNumber(row.final_percentage) && row.final_percentage >= 0 && row.final_percentage <= 100))
            && isText(row.name, 160) && isNullableText(row.note, 1000) && isNullableText(row.fingerprint, 64)
            && ['percentage', 'weight'].every((key) => isNumber(row[key]) && row[key] >= 0 && row[key] <= 1000000))) {
            return false;
        }
    }
    if (draft.liquid_process !== undefined && draft.liquid_process !== null) {
        if (!isObject(draft.liquid_process) || !onlyKeys(draft.liquid_process, ['first_dilution_percentage', 'final_dilution_percentage', 'neutralizer_solution_percentage', 'measured_paste_weight']) || !['first_dilution_percentage', 'final_dilution_percentage', 'neutralizer_solution_percentage', 'measured_paste_weight'].every((key) => isNullableNumber(draft.liquid_process[key]))) {
            return false;
        }
    }
    return calculator !== 'liquid-soap' || isObject(draft.liquid_process);
}

function validReferences(references) {
    return isObject(references) && Object.keys(references).length <= 100
        && Object.entries(references).every(([key, value]) => /^[1-9][0-9]*$/.test(key)
            && isObject(value) && onlyKeys(value, ['name', 'fingerprint'])
            && isText(value.name, 160) && isNullableText(value.fingerprint, 64));
}

export function createCalculatorStorage(calculator, providedStorage) {
    if (!CALCULATORS.has(calculator)) {
        throw new Error('Unknown calculator');
    }
    const key = `soapkraft:calculator:v1:${calculator}`;
    let observed;
    const storage = () => providedStorage === undefined ? globalThis.localStorage : providedStorage;

    return {
        key,
        read(catalogue = []) {
            let raw;
            try {
                raw = storage().getItem(key);
                observed = raw;
            } catch {
                return { status: 'unavailable' };
            }
            if (raw === null) {
                return { status: 'empty' };
            }
            try {
                if (byteSize(raw) > MAX_BYTES) {
                    return { status: 'corrupt' };
                }
                const saved = JSON.parse(raw);
                if (saved.version !== VERSION || !validDraft(saved.draft, calculator) || !validReferences(saved.references)) {
                    return { status: 'corrupt' };
                }
                const current = new Map(catalogue.map((row) => [String(row.id), row]));
                const changed = []; const retired = [];
                const references = saved.references;
                for (const rows of Object.values(saved.draft.phase_items)) {
                    for (const row of rows) {
                        if (row.ingredient_id === null || row.ingredient_id === undefined) {
                            continue;
                        }
                        const reference = references[row.ingredient_id] ?? row;
                        const ingredient = current.get(String(row.ingredient_id));
                        if (!ingredient) {
                            retired.push(reference.name ?? row.name);
                        } else if (reference.fingerprint && ingredient.fingerprint !== reference.fingerprint) {
                            changed.push(reference.name ?? row.name);
                        }
                    }
                }
                return { status: 'recovered', draft: saved.draft, references, changed: [...new Set(changed)], retired: [...new Set(retired)] };
            } catch {
                return { status: 'corrupt' };
            }
        },
        write(draft, references) {
            try {
                if (!validDraft(draft, calculator) || !validReferences(references)) { return { status: 'unavailable' }; }
                const raw = JSON.stringify({ version: VERSION, draft, references });
                if (byteSize(raw) > MAX_BYTES) {
                    return { status: 'unavailable' };
                }
                if (observed !== undefined && storage().getItem(key) !== observed) { return { status: 'conflict' }; }
                storage().setItem(key, raw);
                observed = raw;
                return { status: 'saved' };
            } catch {
                return { status: 'unavailable' };
            }
        },
        hasConflict() {
            try { return observed !== undefined && storage().getItem(key) !== observed; } catch { return false; }
        },
        clear() {
            try {
                if (observed !== undefined && storage().getItem(key) !== observed) { return { status: 'conflict' }; }
                storage().removeItem(key);
                observed = null;
                return { status: 'cleared' };
            } catch {
                return { status: 'unavailable' };
            }
        },
    };
}
