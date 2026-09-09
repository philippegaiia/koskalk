import assert from 'node:assert/strict';
import test from 'node:test';

import { createStickyTableHeader } from '../../resources/js/sticky-table-header.js';

test('keeps the header aligned at fractional scroll positions', () => {
    const header = {
        style: {},
        getBoundingClientRect: () => ({ height: 40 }),
    };
    const component = createStickyTableHeader();
    let containerTop = -100.4;

    component.$el = {
        querySelector: () => header,
        getBoundingClientRect: () => ({
            height: 500,
            top: containerTop,
        }),
    };

    component.update();
    assert.equal(header.style.transform, 'translate3d(0, 100.4px, 0)');

    containerTop = -100.6;
    component.update();
    assert.equal(header.style.transform, 'translate3d(0, 100.6px, 0)');
});
