import assert from 'node:assert/strict';
import test from 'node:test';

import { createProductionCostPreview } from '../../resources/js/production-cost-preview.js';

test('updates production costs as the finished unit count changes', () => {
    const preview = createProductionCostPreview({
        unitsProduced: '12',
        ingredientCost: 12.75,
        packagingCostPerUnit: 0.5,
        currency: 'EUR',
    });

    assert.equal(preview.money(preview.totalCost), '18.75 EUR');
    assert.equal(preview.money(preview.costPerUnit), '1.56 EUR');

    preview.unitsProduced = '24';

    assert.equal(preview.money(preview.packagingCost), '12 EUR');
    assert.equal(preview.money(preview.totalCost), '24.75 EUR');
    assert.equal(preview.money(preview.costPerUnit), '1.03 EUR');

    for (const value of ['', '0', '1.5', '1e2']) {
        preview.unitsProduced = value;
        assert.equal(preview.unitCount, null);
        assert.equal(preview.costPerUnit, null);
        assert.equal(preview.money(preview.totalCost), '12.75 EUR');
    }
});
