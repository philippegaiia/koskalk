import { nonNegativeNumber, parseDecimalInput, roundTo } from '../utils';

export function createPackagingSection() {
    return {
        get filteredPackagingCatalog() {
            const search = `${this.packagingCatalogSearch ?? ''}`.trim().toLowerCase();

            if (search === '') {
                return this.packagingCatalog;
            }

            return this.packagingCatalog.filter((item) => {
                const haystack = [
                    item?.name ?? '',
                    item?.notes ?? '',
                ].join(' ').toLowerCase();

                return haystack.includes(search);
            });
        },

        addPackagingPlanRow(packagingItem = null) {
            this.packagingPlanRows = [
                ...this.packagingPlanRows,
                {
                    id: this.makeLocalPackagingPlanRowId(),
                    user_packaging_item_id: packagingItem?.id ?? null,
                    name: packagingItem?.name ?? '',
                    components_per_unit: 1,
                    notes: '',
                },
            ];
        },

        removePackagingPlanRow(rowId) {
            this.packagingPlanRows = this.packagingPlanRows.filter((row) => row.id !== rowId);
        },

        updatePackagingPlanComponents(row, value) {
            row.components_per_unit = roundTo(nonNegativeNumber(parseDecimalInput(value)), 3);
        },

        makeLocalPackagingPlanRowId() {
            return `packaging-plan-${Date.now()}-${Math.random().toString(16).slice(2)}`;
        },
    };
}
