import test from 'node:test';
import assert from 'node:assert/strict';
import { createListOutputDownload } from '../../resources/js/list-output-download.js';

function outputRoot({ hasResults = 'true', serverSearch = 'Cocoa' } = {}) {
    const input = { value: 'Cocoa' };
    const anchors = [];
    const root = {
        dataset: {
            exportUrl: '/dashboard/ingredients/export',
            exportParameters: JSON.stringify({
                workspace: 'workspace-public-id',
                ownershipFilter: 'priced',
                sortField: 'category',
                sortDirection: 'desc',
            }),
            exportSearchParameter: 'search',
            exportHasResults: hasResults,
            exportServerSearch: serverSearch,
            exportOwnershipFilter: 'priced',
            exportSortField: 'category',
            exportSortDirection: 'desc',
            exportFilenameFallback: 'ingredients.{format}',
            exportErrorAccess: 'Sign in to export.',
            exportErrorForbidden: 'Access changed.',
            exportErrorWorkspaceChanged: 'Workspace changed.',
            exportErrorInvalid: 'Narrow the filters.',
            exportErrorThrottled: 'Wait and retry.',
            exportErrorFailed: 'Export failed.',
        },
        querySelector: () => input,
    };
    const document = {
        createElement: () => {
            const anchor = {
                click() { this.clicked = true; },
                remove() { this.removed = true; },
            };
            anchors.push(anchor);

            return anchor;
        },
        body: { append: () => {} },
    };
    const revoked = [];
    const URL = {
        createObjectURL: () => 'blob:ingredient-export',
        revokeObjectURL: (url) => revoked.push(url),
    };

    return { input, root, anchors, document, URL, revoked };
}

test('exports the visible last-keystroke search with the current server filters', async () => {
    const fixture = outputRoot();
    fixture.input.value = 'Cocoa butter / last keystroke';
    const requests = [];
    const helper = createListOutputDownload({
        ...fixture,
        window: { location: { origin: 'https://koskalk.test' }, URL: fixture.URL },
        setTimeout: (callback) => callback(),
        fetch: async (url, options) => {
            requests.push({ url, options });

            return {
                ok: true,
                headers: { get: () => 'attachment; filename="ingredient-price-book.csv"' },
                blob: async () => new Blob(['csv']),
            };
        },
    });
    helper.$el = fixture.root;
    helper.init();
    helper.visibleSearch = 'Cocoa';

    assert.equal(await helper.exportList('csv'), true);

    const request = requests[0];
    const url = new URL(request.url);
    assert.equal(url.searchParams.get('search'), 'Cocoa butter / last keystroke');
    assert.equal(url.searchParams.get('workspace'), 'workspace-public-id');
    assert.equal(url.searchParams.get('ownershipFilter'), 'priced');
    assert.equal(url.searchParams.get('sortField'), 'category');
    assert.equal(url.searchParams.get('sortDirection'), 'desc');
    assert.equal(url.searchParams.get('format'), 'csv');
    assert.deepEqual(request.options, {
        method: 'GET',
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
    });
    assert.equal(fixture.anchors[0].download, 'ingredient-price-book.csv');
    assert.equal(fixture.anchors[0].clicked, true);
    assert.deepEqual(fixture.revoked, ['blob:ingredient-export']);
});

test('empty-state eligibility follows live search input and server result morphs, then disconnects', () => {
    const fixture = outputRoot({ hasResults: 'true', serverSearch: 'old query' });
    let observer;
    class FakeMutationObserver {
        constructor(callback) {
            this.callback = callback;
            observer = this;
        }

        observe(target, options) {
            this.target = target;
            this.options = options;
        }

        disconnect() {
            this.disconnected = true;
        }
    }
    const helper = createListOutputDownload({ MutationObserver: FakeMutationObserver });
    helper.$el = fixture.root;
    helper.init();

    fixture.input.value = 'new query';
    helper.visibleSearch = fixture.input.value;
    assert.equal(helper.currentResultsAreEmpty(), false);

    fixture.root.dataset.exportHasResults = 'false';
    fixture.root.dataset.exportServerSearch = 'new query';
    observer.callback([{ type: 'attributes' }]);
    assert.equal(helper.currentResultsAreEmpty(), true);
    assert.deepEqual(observer.options.attributeFilter, ['data-export-has-results', 'data-export-server-search', 'data-export-parameters']);

    fixture.root.dataset.exportHasResults = 'true';
    observer.callback([{ type: 'attributes' }]);
    assert.equal(helper.currentResultsAreEmpty(), false);

    helper.destroy();
    assert.equal(observer.disconnected, true);
});

test('keeps a pending export menu closed without relying on a native disabled trigger', () => {
    const fixture = outputRoot();
    const helper = createListOutputDownload();
    helper.$el = fixture.root;
    helper.init();

    assert.equal(helper.toggleMenu(), true);
    helper.outputMenuOpen = false;
    helper.outputPending = true;
    assert.equal(helper.toggleMenu(), false);
    assert.equal(helper.outputMenuOpen, false);

    helper.outputPending = false;
    assert.equal(helper.toggleMenu(), true);
    assert.equal(helper.outputMenuOpen, true);
});

test('shows the server validation detail for a 422 response', async () => {
    const fixture = outputRoot();
    const helper = createListOutputDownload({
        ...fixture,
        window: { location: { origin: 'https://koskalk.test' }, URL: fixture.URL },
        setTimeout: (callback) => callback(),
        fetch: async () => ({
            ok: false,
            status: 422,
            json: async () => ({ errors: { export: ['Narrow the search to stay under the export limit.'] } }),
        }),
    });
    helper.$el = fixture.root;
    helper.init();

    assert.equal(await helper.exportList('xlsx'), false);
    assert.equal(helper.outputError, 'Narrow the search to stay under the export limit.');
});

test('captures immediate Livewire filters and allows a changed query from an empty result', async () => {
    const fixture = outputRoot({ hasResults: 'false' });
    fixture.root.dataset.exportLivewireFilters = JSON.stringify(['lotScope', 'lotSort']);
    fixture.root.dataset.exportParameters = JSON.stringify({ workspace: 'origin', lotScope: 'exhausted', lotSort: 'newest' });
    const state = { lotScope: 'open', lotSort: 'code' };
    let captured;
    const helper = createListOutputDownload({ ...fixture, window: { location: { origin: 'https://koskalk.test' } }, setTimeout: (fn) => fn(), fetch: async (url) => {
        captured = new URL(url);
        return { ok: true, headers: { get: () => null }, blob: async () => new Blob(['csv']) };
    } });
    helper.$el = fixture.root;
    helper.$wire = { $get: (key) => state[key] };
    helper.init();
    assert.equal(helper.currentResultsAreEmpty(), false);
    assert.equal(await helper.exportList('csv'), true);
    assert.equal(captured.searchParams.get('lotScope'), 'open');
    assert.equal(captured.searchParams.get('lotSort'), 'code');
    assert.equal(captured.searchParams.get('workspace'), 'origin');
});

for (const [status, message] of [[403, 'Access changed.'], [409, 'Workspace changed.'], [429, 'Wait and retry.']]) {
    test(`shows the ${status} download failure next to its actions`, async () => {
        const fixture = outputRoot();
        const helper = createListOutputDownload({ ...fixture, window: { location: { origin: 'https://koskalk.test' } }, fetch: async () => ({ ok: false, status, json: async () => ({}) }) });
        helper.$el = fixture.root;
        helper.init();
        assert.equal(await helper.exportList('csv'), false);
        assert.equal(helper.outputError, message);
        assert.equal(helper.outputPending, false);
    });
}

test('opens print synchronously, captures visible filters and writes the successful preview', async () => {
    const fixture = outputRoot();
    fixture.root.dataset.printUrl = '/inventory/count/print';
    fixture.root.dataset.exportPreparing = 'Preparing';
    const order = [];
    const popup = { document: { createElement: () => ({}), body: { replaceChildren() {} }, open() {}, write(html) { this.html = html; }, close() {} }, focus() { this.focused = true; } };
    const helper = createListOutputDownload({ ...fixture, window: { location: { origin: 'https://koskalk.test' }, open() { order.push('open'); return popup; } }, fetch: async (url) => {
        order.push('fetch');
        assert.equal(new URL(url).searchParams.get('search'), 'Immediate');
        return { ok: true, text: async () => '<html>Count sheet</html>' };
    } });
    helper.$el = fixture.root;
    helper.init();
    fixture.input.value = 'Immediate';
    assert.equal(await helper.printList(), true);
    assert.deepEqual(order, ['open', 'fetch']);
    assert.equal(popup.document.html, '<html>Count sheet</html>');
    assert.equal(popup.focused, true);
});

test('blocked popup avoids the request and failed print closes its placeholder', async () => {
    const fixture = outputRoot();
    fixture.root.dataset.exportErrorPopup = 'Allow popups';
    fixture.root.dataset.printUrl = '/inventory/count/print';
    let requests = 0;
    let popup = null;
    const helper = createListOutputDownload({ ...fixture, window: { location: { origin: 'https://koskalk.test' }, open: () => popup }, fetch: async () => { requests++; return { ok: false, status: 409, json: async () => ({}) }; } });
    helper.$el = fixture.root;
    helper.init();
    assert.equal(await helper.printList(), false);
    assert.equal(requests, 0);
    assert.equal(helper.outputError, 'Allow popups');
    popup = { document: { createElement: () => ({}), body: { replaceChildren() {} } }, close() { this.closed = true; } };
    assert.equal(await helper.printList(), false);
    assert.equal(popup.closed, true);
    assert.equal(helper.outputError, 'Workspace changed.');
    assert.equal(helper.outputPending, false);
});


test('captures a selected or cleared lot material before its server callback updates derived filters', () => {
    const fixture = outputRoot({ hasResults: 'false' });
    fixture.root.dataset.exportMaterialSelection = 'lotFilters.lotMaterialSelection';
    fixture.root.dataset.exportLivewireFilters = JSON.stringify(['lotMaterial', 'lotMaterialType']);
    fixture.root.dataset.exportParameters = JSON.stringify({ workspace: 'origin', lotMaterial: 'old-id', lotMaterialType: 'ingredient' });
    let selection = 'packaging:new-id';
    const helper = createListOutputDownload();
    helper.$el = fixture.root;
    helper.$wire = { $get: (key) => key === 'lotFilters.lotMaterialSelection' ? selection : ({ lotMaterial: 'old-id', lotMaterialType: 'ingredient' })[key] };
    helper.init();
    assert.equal(helper.captureParameters().lotMaterial, 'new-id');
    assert.equal(helper.captureParameters().lotMaterialType, 'packaging');
    assert.equal(helper.currentResultsAreEmpty(), false);
    selection = null;
    assert.equal(helper.captureParameters().lotMaterial, '');
    assert.equal(helper.captureParameters().lotMaterialType, '');
    assert.equal(helper.currentResultsAreEmpty(), false);
});
