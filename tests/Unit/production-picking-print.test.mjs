import test from 'node:test';
import assert from 'node:assert/strict';
import { capturePickingAllocations, createProductionPickingPrint } from '../../resources/js/production-picking-print.js';
function root() {
    const input = { value: '12,3456789', dataset: { pickingManualLot: '8' } };
    const auto = { dataset: { pickingAutoLot: '7', pickingQuantity: '0.000123456' } };
    return { input, dataset: { pickingWorkspace: 'w', pickingRuns: '["run"]', pickingUrl: '/print', pickingPopup: 'popup', pickingAccess: 'access', pickingChanged: 'changed', pickingInvalid: 'invalid', pickingThrottled: 'throttled', pickingFailed: 'failed' }, querySelectorAll: () => [
        { dataset: { pickingRequirement: '1', pickingManual: 'false' }, querySelectorAll: () => [auto] },
        { dataset: { pickingRequirement: '2', pickingManual: 'true' }, querySelectorAll: (selector) => selector.includes('manual') ? [input] : [auto] },
    ] };
}
test('captures raw automatic precision and the final DOM keystroke, replacing automatic manual-mode rows', () => {
    const el = root(); el.input.value = '99,123456789';
    assert.deepEqual(capturePickingAllocations(el).allocations, [{ requirement_id: 1, stock_lot_id: 7, quantity: '0.000123456' }, { requirement_id: 2, stock_lot_id: 8, quantity: '99,123456789' }]);
});
for (const [status, message] of [[403, 'access'], [409, 'changed'], [422, 'invalid'], [429, 'throttled'], [500, 'failed']]) {
    test(`cleans popup and preserves input on ${status}`, async () => {
        const el = root(); let closed = false; let opened = false;
        const component = createProductionPickingPrint({ window: { open: () => { opened = true; return { closed: false, close: () => { closed = true; } }; } }, document: { querySelector: () => ({ content: 'csrf' }) }, fetch: async (_, options) => { assert.equal(opened, true); assert.equal(JSON.parse(options.body).allocations[1].quantity, el.input.value); return { ok: false, status }; } });
        component.$el = el;
        assert.equal(await component.printPicking(), false); assert.equal(component.error, message); assert.equal(component.pending, false); assert.equal(closed, true); assert.equal(el.input.value, '12,3456789');
    });
}
test('blocked popup sends no request', async () => {
    let fetched = false;
    const component = createProductionPickingPrint({ window: { open: () => null }, fetch: () => { fetched = true; } }); component.$el = root();
    assert.equal(await component.printPicking(), false); assert.equal(fetched, false); assert.equal(component.error, 'popup');
});
test('successful response fills synchronously opened window', async () => {
    let html;
    const component = createProductionPickingPrint({ window: { open: () => ({ closed: false, focus() {}, document: { open() {}, write(value) { html = value; }, close() {} } }) }, document: { querySelector: () => ({ content: 'csrf' }) }, fetch: async () => ({ ok: true, text: async () => '<html>sheet</html>' }) }); component.$el = root();
    assert.equal(await component.printPicking(), true); assert.equal(html, '<html>sheet</html>'); assert.equal(component.pending, false);
});
test('reads the component root after init when Alpine passes the print button as $el', async () => {
    let requestedUrl;
    const component = createProductionPickingPrint({ window: { open: () => ({ closed: false, focus() {}, document: { open() {}, write() {}, close() {} } }) }, document: { querySelector: () => ({ content: 'csrf' }) }, fetch: async (url) => { requestedUrl = url; return { ok: true, text: async () => '<html></html>' }; } });
    component.$el = root(); component.init(); component.$el = { dataset: {} };
    assert.equal(await component.printPicking(), true); assert.equal(requestedUrl, '/print');
});
