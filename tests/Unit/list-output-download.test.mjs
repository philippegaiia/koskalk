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
    assert.deepEqual(observer.options.attributeFilter, ['data-export-has-results', 'data-export-server-search']);

    fixture.root.dataset.exportHasResults = 'true';
    observer.callback([{ type: 'attributes' }]);
    assert.equal(helper.currentResultsAreEmpty(), false);

    helper.destroy();
    assert.equal(observer.disconnected, true);
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
