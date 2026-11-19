<?php

it('gives packaging added to a product without sizes a first size, and costs it', function (): void {
    runRecipeWorkbenchScenario(<<<'JS'
assert.deepEqual(workbench.productSizes, []);
workbench.addPackagingPlanRow({ id: 7, name: 'Soap box' });

assert.equal(workbench.productSizes.length, 1);
assert.match(workbench.productSizes[0].key, /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
assert.equal(workbench.selectedProductSizeKey, workbench.productSizes[0].key);
assert.equal(workbench.selectedSizePackagingRows.length, 1);
assert.equal(workbench.costingProductSize.key, workbench.productSizes[0].key);
assert.equal(workbench.displayedPackagingCostRows.length, 1);
JS);
});

it('keeps each size\'s packaging apart in the sizes tab and in costing', function (): void {
    runRecipeWorkbenchScenario(<<<'JS'
const large = workbench.addProductSize();
workbench.updateProductSizeName(large, '100 g bar');
workbench.updateProductSizeContent(large, '100');
workbench.addPackagingPlanRow({ id: 7, name: 'Large box' });
const small = workbench.addProductSize();
workbench.updateProductSizeContent(small, '50');
workbench.addPackagingPlanRow({ id: 8, name: 'Small box' });
workbench.addPackagingPlanRow({ id: 9, name: 'Label' });

assert.deepEqual(workbench.selectedSizePackagingRows.map((row) => row.name), ['Small box', 'Label']);
workbench.selectProductSize(large.key);
assert.deepEqual(workbench.selectedSizePackagingRows.map((row) => row.name), ['Large box']);
assert.equal(workbench.packagingRowCountForSize(small.key), 2);

workbench.packagingCostRows.forEach((row) => { row.unit_cost = 0.5; });
assert.deepEqual(workbench.displayedPackagingCostRows.map((row) => row.name), ['Large box']);
assert.equal(workbench.packagingCostPerFinishedUnitTotal, 0.5);
workbench.selectCostingProductSize(small.key);
assert.deepEqual(workbench.displayedPackagingCostRows.map((row) => row.name), ['Small box', 'Label']);
assert.equal(workbench.packagingCostPerFinishedUnitTotal, 1);
assert.equal(workbench.packagingCostRows.length, 3);
JS);
});

it('keeps independently entered yields while switching between sizes', function (): void {
    runRecipeWorkbenchScenario(<<<'JS'
const large = workbench.addProductSize();
const small = workbench.addProductSize();
workbench.applyCostingPayload({ settings: { sizeKeys: [large.key, small.key], unitsProducedBySize: { [large.key]: 8 } } });
workbench.scheduleCostingSave = () => {};

assert.equal(workbench.costingUnitsProducedValue, 8);
workbench.selectCostingProductSize(small.key);
assert.equal(workbench.costingSelectedUnitsProduced, null);
workbench.updateCostingUnitsProduced('15');
assert.equal(workbench.costingUnitsProducedValue, 15);
workbench.selectCostingProductSize(large.key);
assert.equal(workbench.costingUnitsProducedValue, 8);
const { serializeCosting } = loadModule('resources/js/recipe-workbench/payload.js').namespace;
assert.deepEqual(serializeCosting(workbench).units_produced_by_size, { [large.key]: 8, [small.key]: 15 });
JS);
});

it('removes a size together with its packaging and falls back to the remaining size', function (): void {
    runRecipeWorkbenchScenario(<<<'JS'
const large = workbench.addProductSize();
workbench.addPackagingPlanRow({ id: 7, name: 'Large box' });
const small = workbench.addProductSize();
workbench.addPackagingPlanRow({ id: 8, name: 'Small box' });
workbench.selectCostingProductSize(small.key);

workbench.removeProductSize(small.key);

assert.deepEqual(workbench.productSizes.map((size) => size.key), [large.key]);
assert.deepEqual(workbench.packagingPlanRows.map((row) => row.name), ['Large box']);
assert.deepEqual(workbench.packagingCostRows.map((row) => row.name), ['Large box']);
assert.equal(workbench.selectedProductSizeKey, large.key);
assert.equal(workbench.costingProductSize.key, large.key);
JS);
});

it('saves sizes with the formula and shows a line without a known size under the first size', function (): void {
    runRecipeWorkbenchScenario(<<<'JS'
workbench.applyDraft({
    productSizes: [
        { key: 'size-a', name: '100 g bar', declared_content_value: 100, declared_content_unit: 'g' },
        { key: 'size-b', name: '', declared_content_value: null, declared_content_unit: 'ml' },
    ],
    packagingItems: [
        { id: 'p1', product_size_key: 'size-b', packaging_item_id: 8, name: 'Jar', components_per_unit: 1 },
        { id: 'p2', product_size_key: null, packaging_item_id: 7, name: 'Box', components_per_unit: 1 },
    ],
});

const draft = workbench.serializeDraft();

assert.deepEqual(draft.product_sizes, [
    { key: 'size-a', name: '100 g bar', declared_content_value: 100, declared_content_unit: 'g', position: 1 },
    { key: 'size-b', name: null, declared_content_value: null, declared_content_unit: null, position: 2 },
]);
assert.deepEqual(draft.packaging_items.map((row) => row.product_size_key), ['size-b', null]);
assert.equal(workbench.selectedProductSizeKey, 'size-a');
assert.deepEqual(workbench.selectedSizePackagingRows.map((row) => row.name), ['Box']);

workbench.selectProductSize('size-b');
workbench.applyDraft({ productSizes: [{ key: 'size-b', name: 'Jar size', declared_content_value: 30, declared_content_unit: 'ml' }], packagingItems: [] });
assert.equal(workbench.selectedProductSizeKey, 'size-b');
JS);
});

it('stops adding sizes at the limit the server accepts', function (): void {
    runRecipeWorkbenchScenario(<<<'JS'
for (let count = 0; count < workbench.maxProductSizes; count += 1) {
    workbench.addProductSize();
}

assert.equal(workbench.productSizes.length, 50);
assert.equal(workbench.canAddProductSize, false);
assert.equal(workbench.addProductSize(), null);
assert.equal(workbench.productSizes.length, 50);
JS);
});

it('flags declared content that rounds outside what can be stored', function (): void {
    runRecipeWorkbenchScenario(<<<'JS'
const size = workbench.addProductSize();

workbench.updateProductSizeContent(size, '0.00001');
assert.equal(size.declared_content_value, 0);
assert.notEqual(workbench.productSizeContentError(size), '');

workbench.updateProductSizeContent(size, '99999999.99996');
assert.notEqual(workbench.productSizeContentError(size), '');

workbench.updateProductSizeContent(size, '99999999.9999');
assert.equal(workbench.productSizeContentError(size), '');

workbench.updateProductSizeContent(size, '');
assert.equal(size.declared_content_value, null);
assert.equal(workbench.productSizeContentError(size), '');
JS);
});

it('keeps declared content that is not a number for the maker to correct', function (): void {
    runRecipeWorkbenchScenario(<<<'JS'
const { serializeDraft } = loadModule('resources/js/recipe-workbench/payload.js').namespace;
const size = workbench.addProductSize();
size.declared_content_unit = 'g';

workbench.updateProductSizeContent(size, '30 ml');
assert.equal(size.declared_content_value, null);
assert.equal(workbench.declaredContentFieldText(size), '30 ml');
assert.equal(workbench.productSizeContentError(size), workbench.t('packaging.sizes.validation.declared_content_number'));
assert.equal(serializeDraft(workbench).product_sizes[0].declared_content_value, '30 ml');

workbench.updateProductSizeContent(size, '1 234,5');
assert.equal(size.declared_content_value, 1234.5);
assert.equal(workbench.declaredContentFieldText(size), workbench.formatDeclaredContent(1234.5));
assert.equal(workbench.productSizeContentError(size), '');
assert.equal(serializeDraft(workbench).product_sizes[0].declared_content_value, 1234.5);
JS);
});

it('gives each size back its own saved price when two sizes use the same item', function (): void {
    runRecipeWorkbenchScenario(<<<'JS'
workbench.applyDraft({
    productSizes: [
        { key: 'large', name: 'Large', declared_content_value: null, declared_content_unit: 'g' },
        { key: 'small', name: 'Small', declared_content_value: null, declared_content_unit: 'g' },
    ],
    packagingItems: [
        { id: 'p1', product_size_key: 'large', packaging_item_id: null, name: 'Label', components_per_unit: 1 },
        { id: 'p2', product_size_key: 'small', packaging_item_id: null, name: 'Label', components_per_unit: 1 },
    ],
});

workbench.applyCostingPayload({
    settings: { unitsProduced: 20 },
    item_prices: [],
    packaging_items: [
        { id: 2, product_size_key: 'small', packaging_item_id: null, name: 'Label', unit_cost: 0.8, components_per_unit: 1 },
        { id: 1, product_size_key: 'large', packaging_item_id: null, name: 'Label', unit_cost: 0.1, components_per_unit: 1 },
    ],
});

assert.deepEqual(workbench.packagingCostRows.map((row) => [row.product_size_key, row.unit_cost]), [['large', 0.1], ['small', 0.8]]);
const { serializeCosting } = loadModule('resources/js/recipe-workbench/payload.js').namespace;
assert.deepEqual(serializeCosting(workbench).packaging_items.map((row) => row.product_size_key), ['large', 'small']);
JS);
});

it('sends only the costed size to print and export', function (): void {
    runRecipeWorkbenchScenario(<<<'JS'
const outputs = loadModule('resources/js/recipe-workbench/outputs.js').namespace;
workbench.applyDraft({
    productSizes: [
        { key: 'large', name: 'Large', declared_content_value: null, declared_content_unit: 'g' },
        { key: 'small', name: 'Small', declared_content_value: null, declared_content_unit: 'g' },
    ],
    packagingItems: [
        { id: 'p1', product_size_key: null, packaging_item_id: null, name: 'Large box', components_per_unit: 1 },
        { id: 'p2', product_size_key: 'small', packaging_item_id: null, name: 'Small box', components_per_unit: 1 },
    ],
});
workbench.hasLoadedCosting = true;
workbench.selectCostingProductSize('large');

const payload = await outputs.captureRecipeWorkbenchOutput(workbench, {}, { kind: 'formula', format: 'print', printContext: 'costing' });

assert.equal(payload.costing.product_size_key, 'large');
assert.deepEqual(payload.costing.packaging_items.map((row) => row.name), ['Large box']);
assert.deepEqual(payload.draft.packaging_items.map((row) => row.product_size_key), ['large', 'small']);
JS);
});
