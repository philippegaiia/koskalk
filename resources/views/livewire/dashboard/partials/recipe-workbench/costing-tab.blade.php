<div x-show="activeWorkbenchTab === 'costing'" class="space-y-6">
    <section class="rounded-[2rem] border border-[var(--color-line)] bg-white p-5">
        <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
            <div>
                <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Costing settings</p>
                <h3 class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">Business view without cluttering the formula bench</h3>
                <p class="mt-2 text-sm text-[var(--color-ink-soft)]">Ingredient identity stays shared. The price memory stays private to the current user and can be refreshed later if supplier rates move.</p>
            </div>
            <div class="rounded-[1.25rem] border border-[var(--color-line)] bg-[var(--color-panel)] px-4 py-3 text-sm">
                <p class="font-medium text-[var(--color-ink-strong)]" x-text="costingSaveStatus === 'success' ? 'Costing kept' : (costingSaveStatus === 'warning' ? 'Preview only' : 'Auto-save ready')"></p>
                <p class="mt-1 text-[var(--color-ink-soft)]" x-text="costingSaveMessage || 'Changes here save independently from the formula content.'"></p>
            </div>
        </div>

        <div class="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
            <label class="rounded-[1.5rem] border border-[var(--color-line)] bg-[var(--color-panel)] p-4">
                <span class="text-xs font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase">Oil weight for costing</span>
                <input
                    x-model.number="costingOilWeight"
                    @change="scheduleCostingSave()"
                    type="number"
                    min="0"
                    step="0.1"
                    class="mt-3 w-full rounded-2xl border border-[var(--color-line)] bg-white px-3 py-2.5 text-sm text-[var(--color-ink-strong)] outline-none"
                />
            </label>

            <label class="rounded-[1.5rem] border border-[var(--color-line)] bg-[var(--color-panel)] p-4">
                <span class="text-xs font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase">Unit</span>
                <select
                    x-model="costingOilUnit"
                    @change="scheduleCostingSave()"
                    class="mt-3 w-full rounded-2xl border border-[var(--color-line)] bg-white px-3 py-2.5 text-sm text-[var(--color-ink-strong)] outline-none"
                >
                    <option value="g">g</option>
                    <option value="kg">kg</option>
                    <option value="oz">oz</option>
                    <option value="lb">lb</option>
                </select>
            </label>

            <label class="rounded-[1.5rem] border border-[var(--color-line)] bg-[var(--color-panel)] p-4">
                <span class="text-xs font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase">Units produced</span>
                <input
                    x-model.number="costingUnitsProduced"
                    @change="scheduleCostingSave()"
                    type="number"
                    min="0"
                    step="1"
                    class="mt-3 w-full rounded-2xl border border-[var(--color-line)] bg-white px-3 py-2.5 text-sm text-[var(--color-ink-strong)] outline-none"
                    placeholder="e.g. 50"
                />
            </label>

            <div class="rounded-[1.5rem] border border-[var(--color-line)] bg-[var(--color-panel)] p-4">
                <span class="text-xs font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase">Currency</span>
                <div class="mt-3 rounded-2xl border border-[var(--color-line)] bg-white px-3 py-2.5 text-sm font-medium text-[var(--color-ink-strong)]" x-text="costingCurrency"></div>
                <p class="mt-2 text-xs leading-5 text-[var(--color-ink-soft)]">Ingredient prices are stored per kilogram and reused here as your default.</p>
            </div>
        </div>
    </section>

    <section class="overflow-hidden rounded-[2rem] border border-[var(--color-line)] bg-white">
        <div class="border-b border-[var(--color-line)] px-5 py-4">
            <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Ingredient costing</p>
            <div class="mt-1 flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                <p class="text-sm text-[var(--color-ink-soft)]">Formula rows stay read-only here except for price per kilo, so development and costing each get their own space.</p>
                <span class="rounded-full border border-[var(--color-line)] bg-[var(--color-panel)] px-3 py-1 text-xs font-medium text-[var(--color-ink-soft)]" x-text="`${costingFormulaRows.length} priced rows`"></span>
            </div>
        </div>

        <template x-if="costingFormulaRows.length > 0">
            <div class="overflow-x-auto">
                <div class="min-w-[52rem]">
                    <div class="grid grid-cols-[8rem_minmax(0,1.8fr)_5rem_7rem_8rem_8rem] gap-px bg-[var(--color-line)] text-sm">
                        <div class="bg-[var(--color-panel)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">Phase</div>
                        <div class="bg-[var(--color-panel)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">Ingredient</div>
                        <div class="bg-[var(--color-panel)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">%</div>
                        <div class="bg-[var(--color-panel)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">Weight</div>
                        <div class="bg-[var(--color-panel)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">Price / kg</div>
                        <div class="bg-[var(--color-panel)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">Line cost</div>
                    </div>

                    <div class="divide-y divide-[var(--color-line)] bg-white">
                        <template x-for="row in costingFormulaRows" :key="`${row.phaseKey}-${row.rowId}`">
                            <div class="grid grid-cols-[8rem_minmax(0,1.8fr)_5rem_7rem_8rem_8rem] gap-px bg-[var(--color-line)] text-sm">
                                <div class="bg-white px-4 py-3 text-[var(--color-ink-soft)]" x-text="row.phaseLabel"></div>
                                <div class="bg-white px-4 py-3">
                                    <p class="font-medium text-[var(--color-ink-strong)]" x-text="row.name"></p>
                                </div>
                                <div class="bg-white px-4 py-3 text-[var(--color-ink-soft)]" x-text="`${format(row.percentage, 2)}%`"></div>
                                <div class="bg-white px-4 py-3 text-[var(--color-ink-soft)]" x-text="`${format(row.weight, 2)} ${row.weightUnit}`"></div>
                                <div class="bg-white px-3 py-3">
                                    <input
                                        :value="costingPriceForRow(row) ?? ''"
                                        @change="updateCostingPrice(row, $event.target.value)"
                                        @blur="updateCostingPrice(row, $event.target.value)"
                                        type="number"
                                        min="0"
                                        step="0.0001"
                                        class="w-full rounded-xl border border-[var(--color-line)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline-none"
                                    />
                                </div>
                                <div class="bg-white px-4 py-3 font-medium text-[var(--color-ink-strong)]" x-text="`${costingCurrency} ${format(lineCostForRow(row), 2)}`"></div>
                            </div>
                        </template>

                        <div class="grid grid-cols-[8rem_minmax(0,1.8fr)_5rem_7rem_8rem_8rem] gap-px bg-[var(--color-line)] text-sm">
                            <div class="bg-[var(--color-panel)] px-4 py-3"></div>
                            <div class="bg-[var(--color-panel)] px-4 py-3 font-semibold text-[var(--color-ink-strong)]">Ingredient subtotal</div>
                            <div class="bg-[var(--color-panel)] px-4 py-3"></div>
                            <div class="bg-[var(--color-panel)] px-4 py-3"></div>
                            <div class="bg-[var(--color-panel)] px-4 py-3"></div>
                            <div class="bg-[var(--color-panel)] px-4 py-3 font-semibold text-[var(--color-ink-strong)]" x-text="`${costingCurrency} ${format(ingredientCostTotal, 2)}`"></div>
                        </div>
                    </div>
                </div>
            </div>
        </template>

        <template x-if="costingFormulaRows.length === 0">
            <div class="px-5 py-8 text-sm text-[var(--color-ink-soft)]">
                Add ingredients on the Formula tab to start costing them here.
            </div>
        </template>
    </section>

    <section class="overflow-hidden rounded-[2rem] border border-[var(--color-line)] bg-white">
        <div class="border-b border-[var(--color-line)] px-5 py-4">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Packaging</p>
                    <h3 class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">Packaging</h3>
                    <p class="mt-2 text-sm text-[var(--color-ink-soft)]">Add reusable packaging items used for one finished unit.</p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" @click="openPackagingPicker = ! openPackagingPicker" class="rounded-full border border-[var(--color-line)] px-4 py-2 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel)]">
                        Add packaging item
                    </button>
                    <button type="button" @click="openPackagingCatalogModal()" class="rounded-full bg-[var(--color-accent-strong)] px-4 py-2 text-sm font-medium text-white transition hover:bg-[var(--color-accent)]">
                        New packaging item
                    </button>
                </div>
            </div>
        </div>

        <div x-cloak x-show="openPackagingPicker" class="border-b border-[var(--color-line)] bg-[var(--color-panel)] px-5 py-4">
            <div class="flex flex-wrap items-center gap-2">
                <template x-for="item in packagingCatalog" :key="item.id">
                    <button type="button" @click="addPackagingCostRow(item); openPackagingPicker = false" class="rounded-full border border-[var(--color-line)] bg-white px-4 py-2 text-sm font-medium text-[var(--color-ink-strong)] transition hover:bg-[var(--color-accent-soft)]" x-text="item.name"></button>
                </template>

                <template x-if="packagingCatalog.length === 0">
                    <p class="text-sm text-[var(--color-ink-soft)]">No saved packaging items yet. Use “New packaging item” to create one.</p>
                </template>
            </div>

            <template x-if="packagingCatalog.length > 0">
                <p class="mt-3 text-xs text-[var(--color-ink-soft)]">Pick an existing catalog item to add it here with one component per unit by default.</p>
            </template>
        </div>

        <template x-if="packagingCostRows.length === 0">
            <div class="px-5 py-8 text-sm text-[var(--color-ink-soft)]">
                <p class="font-medium text-[var(--color-ink-strong)]">No packaging added yet.</p>
                <p class="mt-2">Add a reusable packaging item to include boxes, labels, stickers, and other unit-level packaging in this costing.</p>
            </div>
        </template>

        <template x-if="packagingCostRows.length > 0">
            <div class="overflow-x-auto">
                <div class="min-w-[58rem]">
                    <div class="grid grid-cols-[minmax(0,1.8fr)_9rem_9rem_9rem_9rem_7rem] gap-px bg-[var(--color-line)] text-sm">
                        <div class="bg-[var(--color-panel)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">Packaging item</div>
                        <div class="bg-[var(--color-panel)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">Components per unit</div>
                        <div class="bg-[var(--color-panel)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">Unit price</div>
                        <div class="bg-[var(--color-panel)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">Cost per unit</div>
                        <div class="bg-[var(--color-panel)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">Batch cost</div>
                        <div class="bg-[var(--color-panel)] px-4 py-3"></div>
                    </div>

                    <div class="divide-y divide-[var(--color-line)] bg-white">
                        <template x-for="row in packagingCostRows" :key="row.id">
                            <div class="grid grid-cols-[minmax(0,1.8fr)_9rem_9rem_9rem_9rem_7rem] gap-px bg-[var(--color-line)] text-sm">
                                <div class="bg-white px-4 py-3">
                                    <p class="font-medium text-[var(--color-ink-strong)]" x-text="row.name"></p>
                                </div>
                                <div class="bg-white px-3 py-3">
                                    <input x-model.number="row.quantity" @change="scheduleCostingSave()" type="number" min="0" step="0.001" class="w-full rounded-xl border border-[var(--color-line)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline-none" />
                                </div>
                                <div class="bg-white px-3 py-3">
                                    <input x-model.number="row.unit_cost" @change="scheduleCostingSave()" type="number" min="0" step="0.0001" class="w-full rounded-xl border border-[var(--color-line)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline-none" />
                                </div>
                                <div class="bg-white px-4 py-3 font-medium text-[var(--color-ink-strong)]" x-text="`${costingCurrency} ${format(packagingCostPerFinishedUnitForRow(row), 2)}`"></div>
                                <div class="bg-white px-4 py-3 font-medium text-[var(--color-ink-strong)]" x-text="costingUnitsProducedValue > 0 ? `${costingCurrency} ${format(packagingBatchCostForRow(row), 2)}` : 'Set units produced'"></div>
                                <div class="bg-white px-4 py-3 text-right">
                                    <button type="button" @click="removePackagingCostRow(row.id)" class="rounded-full border border-[var(--color-line)] px-3 py-1.5 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel)]">
                                        Remove
                                    </button>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </template>

        <template x-if="packagingCatalogMessage">
            <p class="border-t border-[var(--color-line)] px-5 py-3 text-sm text-[var(--color-ink-soft)]" x-text="packagingCatalogMessage"></p>
        </template>
    </section>

    <section class="rounded-[2rem] border border-[var(--color-line)] bg-white p-5">
        <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Cost summary</p>
        <div class="mt-4 grid gap-2 lg:grid-cols-2 xl:grid-cols-5 xl:gap-3 text-sm">
            <div class="flex items-center justify-between rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] px-4 py-3 xl:flex-col xl:items-start xl:justify-start xl:gap-2">
                <span class="text-[var(--color-ink-soft)]">Ingredients</span>
                <span class="font-medium text-[var(--color-ink-strong)]" x-text="`${costingCurrency} ${format(ingredientCostTotal, 2)}`"></span>
            </div>
            <div class="flex items-center justify-between rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] px-4 py-3 xl:flex-col xl:items-start xl:justify-start xl:gap-2">
                <span class="text-[var(--color-ink-soft)]">Packaging</span>
                <span class="font-medium text-[var(--color-ink-strong)]" x-text="packagingCostTotal !== null ? `${costingCurrency} ${format(packagingCostTotal, 2)}` : 'Set units produced'"></span>
            </div>
            <div class="flex items-center justify-between rounded-2xl border border-[var(--color-line-strong)] bg-[var(--color-accent-soft)] px-4 py-3 xl:flex-col xl:items-start xl:justify-start xl:gap-2">
                <span class="text-[var(--color-ink-strong)]">Total batch cost</span>
                <span class="font-semibold text-[var(--color-ink-strong)]" x-text="totalBatchCost !== null ? `${costingCurrency} ${format(totalBatchCost, 2)}` : 'Set units produced'"></span>
            </div>
            <div class="flex items-center justify-between rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] px-4 py-3 xl:flex-col xl:items-start xl:justify-start xl:gap-2">
                <span class="text-[var(--color-ink-soft)]">Cost per unit</span>
                <span class="font-medium text-[var(--color-ink-strong)]" x-text="costingUnitsProducedValue > 0 ? `${costingCurrency} ${format(costPerUnit, 2)}` : 'Set units produced'"></span>
            </div>
            <div class="flex items-center justify-between rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] px-4 py-3 xl:flex-col xl:items-start xl:justify-start xl:gap-2">
                <span class="text-[var(--color-ink-soft)]">Cost per kg</span>
                <span class="font-medium text-[var(--color-ink-strong)]" x-text="costPerKg !== null ? `${costingCurrency} ${format(costPerKg, 2)}` : 'Set units produced'"></span>
            </div>
        </div>
    </section>

    <div
        x-cloak
        x-show="packagingCatalogModalOpen"
        x-transition.opacity
        @keydown.escape.window="closePackagingCatalogModal()"
        class="fixed inset-0 z-40 flex items-center justify-center bg-[color:rgb(15_23_42/0.45)] px-4 py-6"
    >
        <div @click.away="closePackagingCatalogModal()" class="w-full max-w-xl rounded-[2rem] border border-[var(--color-line)] bg-white p-6 shadow-2xl">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Packaging item</p>
                    <h3 class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">New packaging item</h3>
                    <p class="mt-2 text-sm text-[var(--color-ink-soft)]">Save a reusable catalog item here, then optionally add it straight into this costing at one component per finished unit.</p>
                </div>
                <button type="button" @click="closePackagingCatalogModal()" class="rounded-full border border-[var(--color-line)] px-3 py-1.5 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel)]">Cancel</button>
            </div>

            <div class="mt-5 grid gap-3">
                <label class="rounded-[1.5rem] border border-[var(--color-line)] bg-[var(--color-panel)] p-4">
                    <span class="text-xs font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase">Name</span>
                    <input x-model="packagingCatalogForm.name" type="text" class="mt-3 w-full rounded-2xl border border-[var(--color-line)] bg-white px-3 py-2.5 text-sm text-[var(--color-ink-strong)] outline-none" />
                </label>

                <label class="rounded-[1.5rem] border border-[var(--color-line)] bg-[var(--color-panel)] p-4">
                    <span class="text-xs font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase">Effective unit price</span>
                    <input x-model.number="packagingCatalogForm.unit_cost" type="number" min="0" step="0.0001" class="mt-3 w-full rounded-2xl border border-[var(--color-line)] bg-white px-3 py-2.5 text-sm text-[var(--color-ink-strong)] outline-none" />
                </label>

                <label class="rounded-[1.5rem] border border-[var(--color-line)] bg-[var(--color-panel)] p-4">
                    <span class="text-xs font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase">Notes</span>
                    <textarea x-model="packagingCatalogForm.notes" rows="4" class="mt-3 w-full rounded-2xl border border-[var(--color-line)] bg-white px-3 py-2.5 text-sm text-[var(--color-ink-strong)] outline-none"></textarea>
                </label>
            </div>

            <template x-if="packagingCatalogMessage">
                <p class="mt-4 text-sm text-[var(--color-ink-soft)]" x-text="packagingCatalogMessage"></p>
            </template>

            <div class="mt-5 flex flex-wrap justify-end gap-2">
                <button type="button" @click="closePackagingCatalogModal()" class="rounded-full border border-[var(--color-line)] px-4 py-2 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel)]">Cancel</button>
                <button type="button" @click="savePackagingCatalogItemOnly()" class="rounded-full border border-[var(--color-line)] bg-white px-4 py-2 text-sm font-medium text-[var(--color-ink-strong)] transition hover:bg-[var(--color-panel)]">Save only</button>
                <button type="button" @click="savePackagingCatalogItemAndAddToCosting()" class="rounded-full bg-[var(--color-accent-strong)] px-4 py-2 text-sm font-medium text-white transition hover:bg-[var(--color-accent)]">Save and add</button>
            </div>
        </div>
    </div>

    <template x-if="!hasSavedRecipe">
        <div class="rounded-[1.5rem] border border-[var(--color-line-strong)] bg-[var(--color-accent-soft)] px-4 py-3 text-sm text-[var(--color-ink-strong)]">
            Save the first draft to keep ingredient prices, packaging rows, and costing settings for this formula.
        </div>
    </template>
</div>
