export function createProductionCostPreview({ unitsProduced, ingredientCost, packagingCostPerUnit, currency }) {
    const formatter = new Intl.NumberFormat('en', {
        useGrouping: false,
        maximumFractionDigits: 2,
    });

    return {
        unitsProduced,

        get unitCount() {
            const value = this.unitsProduced.trim();
            const count = Number(value);

            return /^\+?[1-9][0-9]*$/.test(value) && Number.isSafeInteger(count) ? count : null;
        },

        get packagingCost() {
            return packagingCostPerUnit * (this.unitCount ?? 0);
        },

        get totalCost() {
            return ingredientCost + this.packagingCost;
        },

        get costPerUnit() {
            const count = this.unitCount;

            return count === null ? null : this.totalCost / count;
        },

        money(amount) {
            return formatter.format(amount) + ' ' + currency;
        },
    };
}
