import { declaredContentBounds, declaredContentInRange, declaredContentValue, makeProductSizeKey, nonNegativeNumber, parseDecimalInput, retainedProductSizeKey, roundTo } from '../utils';

/** Matches RecipeWorkbenchPayloadNormalizer::MaxProductSizes. */
const MaxProductSizes = 50;

/**
 * Owns the Product's sizes and their packaging lines. Each packaging line
 * belongs to exactly one size; costing reads both but never writes them.
 */
export function createPackagingSection() {
    return {
        get selectedProductSize() {
            return (this.productSizes ?? []).find((size) => size.key === this.selectedProductSizeKey) ?? null;
        },

        get selectedSizePackagingRows() {
            return (this.packagingPlanRows ?? []).filter((row) => this.packagingRowSizeKey(row) === this.selectedProductSizeKey);
        },

        packagingRowCountForSize(sizeKey) {
            return (this.packagingPlanRows ?? []).filter((row) => this.packagingRowSizeKey(row) === sizeKey).length;
        },

        /** A line with a missing or unknown size belongs to the first size, as on the server. */
        packagingRowSizeKey(row) {
            return retainedProductSizeKey(this.productSizes ?? [], row?.product_size_key ?? null);
        },

        productSizeLabel(size, index = null) {
            if (!size) {
                return '';
            }

            const name = `${size.name ?? ''}`.trim();

            if (name !== '') {
                return name;
            }

            if (size.declared_content_value !== null && size.declared_content_value !== undefined) {
                return `${this.formatDeclaredContent(size.declared_content_value)} ${this.t(`packaging.sizes.units.${size.declared_content_unit}`)}`;
            }

            const position = index ?? (this.productSizes ?? []).findIndex((candidate) => candidate.key === size.key);

            return this.t('packaging.sizes.unnamed', { number: position + 1 });
        },

        /** Whole amounts show without decimals; others keep up to four. */
        formatDeclaredContent(value) {
            if (value === null || value === undefined || value === '') {
                return '';
            }

            const rounded = roundTo(value, 4);
            const decimals = Number.isInteger(rounded) ? 0 : `${rounded}`.split('.')[1].length;

            return this.format(rounded, decimals);
        },

        get maxProductSizes() {
            return MaxProductSizes;
        },

        get canAddProductSize() {
            return (this.productSizes ?? []).length < MaxProductSizes;
        },

        /** An inline error for declared content the server would refuse, or ''. */
        productSizeContentError(size) {
            if (!size) {
                return '';
            }

            if (typeof size.declared_content_input === 'string') {
                return this.t('packaging.sizes.validation.declared_content_number');
            }

            if (declaredContentInRange(size.declared_content_value)) {
                return '';
            }

            return this.t('packaging.sizes.validation.declared_content_range', {
                min: this.format(declaredContentBounds.min, 4),
                max: this.format(declaredContentBounds.max, 4),
            });
        },

        addProductSize() {
            if (!this.canAddProductSize) {
                return null;
            }

            const size = {
                key: makeProductSizeKey(),
                name: '',
                declared_content_value: null,
                declared_content_unit: this.isCosmeticFormula ? 'ml' : 'g',
            };

            this.productSizes = [...(this.productSizes ?? []), size];
            this.selectedProductSizeKey = size.key;

            return size;
        },

        selectProductSize(sizeKey) {
            this.selectedProductSizeKey = retainedProductSizeKey(this.productSizes ?? [], sizeKey);
        },

        removeProductSize(sizeKey) {
            this.productSizes = (this.productSizes ?? []).filter((size) => size.key !== sizeKey);
            this.packagingPlanRows = (this.packagingPlanRows ?? []).filter((row) => row.product_size_key !== sizeKey);
            this.selectedProductSizeKey = retainedProductSizeKey(this.productSizes, this.selectedProductSizeKey);
            this.reconcilePackagingCosts();
        },

        updateProductSizeName(size, value) {
            size.name = `${value ?? ''}`.slice(0, 120);
        },

        /**
         * Text that is not a number stays in the field, with an error, until
         * the maker corrects it; it is never cut down to its leading digits.
         */
        updateProductSizeContent(size, value) {
            const text = `${value ?? ''}`.trim();
            const parsed = declaredContentValue(text);

            size.declared_content_input = text !== '' && parsed === null ? text : null;
            size.declared_content_value = parsed;
        },

        declaredContentFieldText(size) {
            return size?.declared_content_input ?? this.formatDeclaredContent(size?.declared_content_value);
        },

        updateProductSizeUnit(size, unit) {
            size.declared_content_unit = unit;
        },

        /** Packaging always lands on a size; a Product without one gets a first size. */
        addPackagingPlanRow(packagingItem = null) {
            const sizeKey = this.selectedProductSize?.key ?? this.addProductSize()?.key ?? null;

            if (sizeKey === null) {
                return;
            }

            this.packagingPlanRows = [
                ...this.packagingPlanRows,
                {
                    id: this.makeLocalPackagingPlanRowId(),
                    product_size_key: sizeKey,
                    packaging_item_id: packagingItem?.id ?? null,
                    name: packagingItem?.name ?? '',
                    material_code: packagingItem?.material_code ?? null,
                    components_per_unit: 1,
                    notes: '',
                },
            ];
            this.reconcilePackagingCosts();
        },

        selectPackagingCatalogItem(packagingItem) {
            if (!packagingItem) {
                return;
            }

            this.addPackagingPlanRow(packagingItem);
        },

        removePackagingPlanRow(rowId) {
            this.packagingPlanRows = this.packagingPlanRows.filter((row) => row.id !== rowId);
            this.reconcilePackagingCosts();
        },

        updatePackagingPlanComponents(row, value) {
            row.components_per_unit = roundTo(nonNegativeNumber(parseDecimalInput(value)), 3);
            this.reconcilePackagingCosts();
        },

        makeLocalPackagingPlanRowId() {
            return `packaging-plan-${Date.now()}-${Math.random().toString(16).slice(2)}`;
        },
    };
}
