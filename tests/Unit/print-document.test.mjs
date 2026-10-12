import assert from 'node:assert/strict';
import test from 'node:test';

import { initializePrintDocument } from '../../resources/js/print-document.js';

class FakeTarget {
    constructor(selector) {
        this.selector = selector;
    }

    closest(selector) {
        return this.selector === selector ? this : null;
    }
}

class FakeCheckbox extends FakeTarget {
    constructor(value, checked) {
        super('[data-formula-section-toggle]');
        this.value = value;
        this.checked = checked;
        this.attributes = { 'data-formula-section-toggle': value };
    }

    getAttribute(name) {
        return this.attributes[name] ?? null;
    }
}

class FakeSection {
    constructor(id) {
        this.id = id;
        this.hidden = false;
    }

    getAttribute(name) {
        return name === 'data-formula-section' ? this.id : null;
    }
}

function fakeDocument({ toggles = [], sections = [], status = null } = {}) {
    const listeners = new Map();

    return {
        addEventListener(name, callback) {
            listeners.set(name, callback);
        },
        querySelectorAll(selector) {
            if (selector === '[data-formula-section-toggle]') {
                return toggles;
            }

            if (selector === '[data-formula-section]') {
                return sections;
            }

            return [];
        },
        querySelector(selector) {
            return selector === '[data-formula-section-status]' ? status : null;
        },
        dispatch(name, event) {
            listeners.get(name)?.(event);
        },
    };
}

test('changes formula section visibility as checkboxes change', () => {
    const formula = new FakeCheckbox('formula', true);
    const notes = new FakeCheckbox('notes', true);
    const formulaSection = new FakeSection('formula');
    const notesSection = new FakeSection('notes');
    const documentRef = fakeDocument({
        toggles: [formula, notes],
        sections: [formulaSection, notesSection],
    });
    initializePrintDocument(documentRef, { print() {} });

    formula.checked = false;
    documentRef.dispatch('change', { target: formula });

    assert.equal(formulaSection.hidden, true);
    assert.equal(notesSection.hidden, false);

    notes.checked = false;
    documentRef.dispatch('change', { target: notes });

    assert.equal(notes.checked, true);
    assert.equal(notesSection.hidden, false);
});

test('keeps the last selected section and announces the empty-selection message', () => {
    const formula = new FakeCheckbox('formula', true);
    const formulaSection = new FakeSection('formula');
    const status = {
        textContent: '',
        getAttribute(name) {
            return name === 'data-empty-selection-message' ? 'Select at least one section.' : null;
        },
    };
    const documentRef = fakeDocument({
        toggles: [formula],
        sections: [formulaSection],
        status,
    });
    initializePrintDocument(documentRef, { print() {} });

    formula.checked = false;
    documentRef.dispatch('change', { target: formula });

    assert.equal(formula.checked, true);
    assert.equal(formulaSection.hidden, false);
    assert.equal(status.textContent, 'Select at least one section.');
});

test('keeps the print action working in documents without formula section controls', () => {
    const documentRef = fakeDocument();
    const button = new FakeTarget('[data-print-document]');
    let printCalls = 0;
    initializePrintDocument(documentRef, {
        print() {
            printCalls += 1;
        },
    });

    documentRef.dispatch('click', { target: button });

    assert.equal(printCalls, 1);
});


test('returns to the open workbench without reloading its unsaved formula', () => {
    const documentRef = fakeDocument();
    const link = new FakeTarget('[data-return-to-workbench]');
    link.href = 'https://koskalk.test/dashboard/recipes/abc#formula';
    let focusCalls = 0;
    let closeCalls = 0;
    let prevented = false;
    initializePrintDocument(documentRef, {
        opener: {
            closed: false,
            location: { href: 'https://koskalk.test/dashboard/recipes/abc' },
            focus() { focusCalls += 1; },
        },
        close() { closeCalls += 1; },
    });

    documentRef.dispatch('click', { target: link, preventDefault() { prevented = true; } });

    assert.equal(prevented, true);
    assert.equal(focusCalls, 1);
    assert.equal(closeCalls, 1);
});

for (const [name, opener] of [
    ['missing', null],
    ['closed', { closed: true }],
    ['on another formula', { closed: false, location: { href: 'https://koskalk.test/dashboard/recipes/other' } }],
    ['on another site', { closed: false, get location() { throw new Error('Cross-origin access'); } }],
    ['on another new product type', { closed: false, location: { href: 'https://koskalk.test/dashboard/recipes/new?family=cosmetic&type=cream' } }],
]) {
    test(`uses the workbench link when the original window is ${name}`, () => {
        const documentRef = fakeDocument();
        const link = new FakeTarget('[data-return-to-workbench]');
        link.href = 'https://koskalk.test/dashboard/recipes/new?family=soap&type=bar-soap#formula';
        let prevented = false;
        initializePrintDocument(documentRef, {
            opener,
            close() { assert.fail('The print window must stay open for normal navigation'); },
        });

        documentRef.dispatch('click', { target: link, preventDefault() { prevented = true; } });

        assert.equal(prevented, false);
    });
}

test('returns to an unsaved workbench with the same product type regardless of query order', () => {
    const documentRef = fakeDocument();
    const link = new FakeTarget('[data-return-to-workbench]');
    link.href = 'https://koskalk.test/dashboard/recipes/new?family=soap&type=bar-soap#formula';
    let prevented = false;
    let focused = false;
    initializePrintDocument(documentRef, {
        opener: {
            closed: false,
            location: { href: 'https://koskalk.test/dashboard/recipes/new?type=bar-soap&family=soap' },
            focus() { focused = true; },
        },
        close() {},
    });

    documentRef.dispatch('click', { target: link, preventDefault() { prevented = true; } });

    assert.equal(prevented, true);
    assert.equal(focused, true);
});
