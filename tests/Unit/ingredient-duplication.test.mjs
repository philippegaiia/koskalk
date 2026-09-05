import assert from 'node:assert/strict';
import test from 'node:test';

import { createIngredientDuplicationModal } from '../../resources/js/ingredient-duplication.js';

function response(payload, status = 200, headers = {}) {
    return {
        ok: status >= 200 && status < 300,
        status,
        headers,
        async json() {
            if (payload instanceof Error) {
                throw payload;
            }

            return payload;
        },
    };
}

function candidate(overrides = {}) {
    return {
        id: 7,
        name: 'Olive Oil',
        inci_name: 'OLEA EUROPAEA FRUIT OIL',
        category: 'Lipids',
        identifiers: [],
        aliases: [],
        duplication: {
            available: true,
            reason: null,
            inherits_soap_chemistry: true,
        },
        ...overrides,
    };
}

const messages = {
    searchFailed: 'Search failed. Refresh the page and try again.',
    authExpired: 'Your session expired. Refresh the page and sign in again.',
    duplicateFailed: 'The private copy could not be created. Refresh the page and try again.',
    invalidResponse: 'The server returned an unexpected response. Refresh the page and try again.',
    reloadGuidance: 'Refresh the page and try again.',
};

test('never lets an older search replace newer results', async () => {
    const pending = [];
    const modal = createIngredientDuplicationModal({
        fetch: (url, options) => new Promise((resolve) => pending.push({ url, options, resolve })),
        messages,
    });

    modal.query = 'olive';
    const olderSearch = modal.search();

    modal.query = '8001-25-00';
    const newerSearch = modal.search();

    assert.equal(pending.length, 2);
    assert.equal(pending[0].options.signal.aborted, true);

    pending[0].resolve(response([candidate({ id: 1, name: 'Old result' })]));
    await olderSearch;

    assert.deepEqual(modal.results, []);
    assert.equal(modal.loading, true);

    pending[1].resolve(response([candidate({ id: 2, name: 'Identifier result' })]));
    await newerSearch;

    assert.deepEqual(modal.results.map((item) => item.name), ['Identifier result']);
    assert.equal(modal.loading, false);
});

test('selecting a result opens its preview without making a write request', () => {
    const calls = [];
    const modal = createIngredientDuplicationModal({
        fetch: (...args) => calls.push(args),
        messages,
    });

    modal.selectCandidate(candidate());

    assert.equal(modal.open, true);
    assert.equal(modal.selected.id, 7);
    assert.equal(calls.length, 0);
});

test('prevents a second confirmation while the first request is in flight', async () => {
    let resolveRequest;
    const redirects = [];
    const modal = createIngredientDuplicationModal({
        fetch: () => new Promise((resolve) => {
            resolveRequest = resolve;
        }),
        navigate: (url) => redirects.push(url),
        messages,
    });
    modal.selectCandidate(candidate());

    const firstConfirmation = modal.confirmDuplicate();
    assert.equal(modal.confirming, true);

    const secondConfirmation = await modal.confirmDuplicate();
    assert.equal(secondConfirmation, null);

    resolveRequest(response({ ok: true, redirect: '/dashboard/ingredients/42' }));
    await firstConfirmation;

    assert.deepEqual(redirects, ['/dashboard/ingredients/42']);
    assert.equal(modal.confirming, false);
    assert.equal(modal.redirecting, true);
    assert.equal(await modal.confirmDuplicate(), null);
});

test('keeps one confirmation owner when dismissal and reselection are attempted', async () => {
    let resolveRequest;
    const calls = [];
    const redirects = [];
    const firstCandidate = candidate({ id: 7, name: 'Olive Oil' });
    const secondCandidate = candidate({ id: 8, name: 'Coconut Oil' });
    const modal = createIngredientDuplicationModal({
        fetch: (...args) => {
            calls.push(args);

            return new Promise((resolve) => {
                resolveRequest = resolve;
            });
        },
        navigate: (url) => redirects.push(url),
        messages,
    });
    modal.selectCandidate(firstCandidate);

    const firstConfirmation = modal.confirmDuplicate();

    assert.equal(modal.confirming, true);
    assert.equal(modal.closeModal(), false);
    modal.openModal();
    modal.chooseAnother();
    modal.selectCandidate(secondCandidate);
    assert.equal(modal.selected.id, firstCandidate.id);
    assert.equal(await modal.confirmDuplicate(), null);
    assert.equal(calls.length, 1);

    resolveRequest(response({ ok: true, redirect: '/dashboard/ingredients/7' }));
    await firstConfirmation;

    assert.deepEqual(redirects, ['/dashboard/ingredients/7']);
    assert.equal(modal.confirming, false);
});

test('clears a pending search when the dialog closes and reopens', async () => {
    let resolveSearch;
    const modal = createIngredientDuplicationModal({
        fetch: () => new Promise((resolve) => {
            resolveSearch = resolve;
        }),
        messages,
    });

    modal.openModal();
    modal.query = 'olive';
    const search = modal.search();

    assert.equal(modal.loading, true);
    assert.notEqual(modal.searchController, null);

    assert.equal(modal.closeModal(), true);
    assert.equal(modal.loading, false);
    assert.equal(modal.searchController, null);

    modal.openModal();
    assert.equal(modal.loading, false);
    assert.equal(modal.query, '');
    assert.deepEqual(modal.results, []);

    resolveSearch(response([candidate()]));
    await search;

    assert.equal(modal.loading, false);
    assert.deepEqual(modal.results, []);
});

test('keeps the selected preview open after a validation failure', async () => {
    const selected = candidate();
    const modal = createIngredientDuplicationModal({
        fetch: async () => response({
            message: 'The source changed while you were reviewing it.',
            errors: { ingredient: ['The source changed while you were reviewing it.'] },
        }, 422),
        messages,
    });
    modal.selectCandidate(selected);

    await modal.confirmDuplicate();

    assert.equal(modal.open, true);
    assert.equal(modal.selected.id, selected.id);
    assert.equal(modal.confirming, false);
    assert.match(modal.duplicateError, /source changed/);
    assert.match(modal.duplicateError, /Refresh the page/);
});

test('turns auth expiry, non-JSON, and network failures into actionable inline errors', async (context) => {
    const cases = [
        {
            name: 'auth expiry',
            fetch: async () => response(new Error('not json'), 419),
            expected: messages.authExpired,
        },
        {
            name: 'non-JSON response',
            fetch: async () => response(new Error('not json'), 500),
            expected: messages.duplicateFailed,
        },
        {
            name: 'network exception',
            fetch: async () => {
                throw new Error('offline');
            },
            expected: messages.duplicateFailed,
        },
    ];

    for (const currentCase of cases) {
        await context.test(currentCase.name, async () => {
            const modal = createIngredientDuplicationModal({
                fetch: currentCase.fetch,
                messages,
            });
            modal.selectCandidate(candidate());

            await modal.confirmDuplicate();

            assert.equal(modal.open, true);
            assert.equal(modal.selected.id, 7);
            assert.equal(modal.confirming, false);
            assert.match(modal.duplicateError, new RegExp(currentCase.expected.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')));
        });
    }
});

test('only exposes chemistry messaging for relevant lipid candidates', () => {
    const modal = createIngredientDuplicationModal({
        lipidCategoryLabel: 'Lipids',
        messages,
    });

    assert.equal(modal.chemistryState(candidate()), 'inherited');
    assert.equal(modal.chemistryState(candidate({
        duplication: { available: true, reason: null, inherits_soap_chemistry: false },
    })), 'untrusted');
    assert.equal(modal.chemistryState(candidate({
        category: 'Aromatic materials',
        duplication: { available: true, reason: null, inherits_soap_chemistry: false },
    })), null);
    assert.equal(modal.chemistryState(candidate({
        duplication: { available: false, reason: 'SAP is missing', inherits_soap_chemistry: false },
    })), null);
});

test('keeps trusted chemistry limit metadata bounded in the preview', () => {
    const modal = createIngredientDuplicationModal({ messages });

    modal.selectCandidate(candidate({
        duplication: {
            available: true,
            reason: null,
            inherits_soap_chemistry: true,
            chemistry: {
                koh_sap: { minimum: '0.182360', maximum: '0.193640', original: '0.188000' },
                naoh_sap: { minimum: '0.130023', maximum: '0.138065', original: '0.134044' },
                fatty_acid_total: { minimum: '80.0', maximum: '100.0' },
                fatty_acids: Array.from({ length: 25 }, (_, index) => ({
                    id: index + 1,
                    name: `Acid ${index + 1}`,
                    minimum: '56.0',
                    maximum: '84.0',
                    original: '70.0',
                    display: `Acid ${index + 1}: 56.0%–84.0% (source 70.0%).`,
                })),
                source_data: 'must not reach the preview',
            },
        },
    }));

    assert.equal(modal.chemistryState(), 'inherited');
    assert.equal(modal.selected.duplication.chemistry.fatty_acids.length, 20);
    assert.equal(modal.selected.duplication.chemistry.koh_sap.minimum, '0.182360');
    assert.equal(modal.selected.duplication.chemistry.fatty_acids[0].display, 'Acid 1: 56.0%–84.0% (source 70.0%).');
    assert.equal(Object.hasOwn(modal.selected.duplication.chemistry, 'source_data'), false);
});

test('bounds and normalizes optional preview metadata', async () => {
    let resolveSearch;
    const modal = createIngredientDuplicationModal({
        fetch: () => new Promise((resolve) => {
            resolveSearch = resolve;
        }),
        messages,
    });

    modal.query = 'oil';
    const search = modal.search();
    const identifiers = Array.from({ length: 6 }, (_, index) => ({ scheme: `id-${index}`, value: `${index}` }));

    resolveSearch(response([candidate({ identifiers: [null, ...identifiers], aliases: ['one', 'two', 'three', 'four', 'five'] })]));
    await search;

    assert.equal(modal.results[0].identifiers.length, 4);
    assert.equal(modal.results[0].identifiers[0].scheme, 'id-0');
    assert.equal(modal.results[0].aliases.length, 4);
});
