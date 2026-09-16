import assert from 'node:assert/strict';
import test from 'node:test';

import { createPresentationSection } from '../../resources/js/recipe-workbench/sections/presentation-section.js';

function presentation(qualities = {}, applicability = {}) {
    const state = {
        backendCalculation: { properties: { qualities, quality_applicability: applicability } },
        number: value => Number(value ?? 0),
        format: (value, digits) => Number(value).toFixed(digits),
        t: key => key,
    };

    return Object.defineProperties(state, Object.getOwnPropertyDescriptors(createPresentationSection()));
}

test('keeps the advisable cleansing ceiling at forty and accepts high mildness', () => {
    const view = presentation({ cleansing_strength: 40 });
    assert.equal(view.qualityTone('cleansing_strength', 0), 'ideal');
    assert.equal(view.qualityTone('cleansing_strength', 40), 'ideal');
    assert.equal(view.qualityTone('cleansing_strength', 41), 'high');
    assert.equal(view.qualityExplanation('cleansing_strength', 40), 'qualities.cleansing_balanced');
    assert.equal(view.qualityExplanation('cleansing_strength', 41), 'qualities.cleansing_high');
    assert.equal(view.qualityTone('mildness', 95), 'ideal');
    assert.equal(view.qualityFlags().some(flag => flag.label === 'High cleansing'), false);
    view.backendCalculation.properties.qualities.cleansing_strength = 41;
    assert.equal(view.qualityFlags().some(flag => flag.label === 'High cleansing'), true);
});

test('shows supported liquid tendencies while omitting unavailable bar and skin predictions', () => {
    const unavailable = { applies: false, display: 'not_applicable' };
    const view = presentation({ bubble_volume: 50, cleansing_strength: 80 }, {
        ...Object.fromEntries([
            'unmolding_firmness', 'cured_hardness', 'longevity', 'cure_speed', 'dos_risk',
            'shrinkage_risk', 'cleansing_strength', 'mildness', 'conditioning_feel', 'slime_risk',
        ].map(key => [key, unavailable])),
        bubble_volume: { applies: true, display: 'tendency' },
        creamy_lather: { applies: true, display: 'tendency' },
        lather_stability: { applies: true, display: 'tendency' },
    });
    assert.deepEqual(view.barAndCureQualityRows(), []);
    assert.deepEqual(view.latherAndFeelQualityRows().map(row => row.key), [
        'bubble_volume', 'creamy_lather', 'lather_stability',
    ]);
    assert.equal(view.qualityDisplayValue({ key: 'bubble_volume', value: 50 }), '50.0 tendency');
    assert.equal(view.qualityTone('bubble_volume', 50), 'neutral');
    assert.equal(view.targetZoneStyle('bubble_volume'), null);
    assert.equal(view.qualityFlags().some(flag => flag.label === 'High cleansing'), false);
});

test('presents shrinkage as a localized risk tendency alongside four week hardness', () => {
    const view = presentation({ shrinkage_risk: 74, cured_hardness: 60 });
    const rows = view.barAndCureQualityRows();
    assert.equal(rows.find(row => row.key === 'cured_hardness').label, 'qualities.hardness_four_weeks');
    assert.equal(rows.find(row => row.key === 'shrinkage_risk').explanation, 'qualities.shrinkage_high');
    assert.equal(view.qualityTone('shrinkage_risk', 74), 'excess');
    assert.equal(view.qualityTone('shrinkage_risk', 16), 'ideal');
    assert.equal(view.qualityExplanation('shrinkage_risk', 16), 'qualities.shrinkage_low');
    assert.equal(view.qualityFlags().some(flag => flag.label === 'qualities.shrinkage_label'), true);
});
