import assert from 'node:assert/strict';
import test from 'node:test';

import { createStickyTableHeader } from '../../resources/js/sticky-table-header.js';

test('keeps the action header pinned at the right edge while the overlay scrolls', () => {
    const cell = { style: {} };
    const component = createStickyTableHeader();
    component.$el = { scrollLeft: 0 };
    component.overlay = { style: {} };
    component.overlayHeader = { querySelectorAll: () => [cell] };
    component.overlayTable = { style: { width: '1200px' } };
    component.geometryDirty = false;
    component.ensureOverlay = () => {};

    for (const scrollLeft of [0, 150, 400]) {
        component.$el.scrollLeft = scrollLeft;
        component.activateOverlay({ style: {} }, {}, { left: 24, width: 800 });

        assert.equal(cell.style.position, 'relative');
        assert.equal(cell.style.transform, `translateX(${-(400 - scrollLeft)}px)`);
        assert.equal(component.overlayTable.style.transform, `translate3d(${-scrollLeft}px, 0, 0)`);
    }
});

test('uses a fixed header layer instead of moving the live header during page scroll', () => {
    const table = {};
    const header = {
        style: {
            transform: 'translate3d(0, 100.4px, 0)',
        },
        closest: () => table,
        getBoundingClientRect: () => ({ height: 40 }),
    };
    const component = createStickyTableHeader();
    let activatedHeader = null;

    component.activateOverlay = (candidate) => {
        activatedHeader = candidate;
    };
    component.deactivateOverlay = () => {};

    component.$el = {
        querySelector: () => header,
        getBoundingClientRect: () => ({
            height: 500,
            top: -100.4,
        }),
    };

    component.update();

    assert.equal(activatedHeader, header);
    assert.equal(header.style.transform, '');
});

test('keeps the original header in the table and synchronizes horizontal scroll', () => {
    const header = { style: {} };
    const component = createStickyTableHeader();

    component.$el = { scrollLeft: 37.5 };
    component.overlay = { hidden: true, style: {} };
    component.overlayHeader = { querySelectorAll: () => [] };
    component.overlayTable = { style: {} };
    component.geometryDirty = false;
    component.ensureOverlay = () => {
        component.originalHeader = header;
    };

    component.activateOverlay(header, {}, { left: 24, width: 800 });

    assert.equal(header.style.opacity, '0');
    assert.equal(component.overlay.hidden, false);
    assert.equal(component.overlay.style.left, '24px');
    assert.equal(component.overlay.style.width, '800px');
    assert.equal(component.overlayTable.style.transform, 'translate3d(-37.5px, 0, 0)');

    component.deactivateOverlay();
    assert.equal(header.style.opacity, '');
    assert.equal(component.overlay.hidden, true);
});
