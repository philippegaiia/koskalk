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

    <div class="grid gap-4 xl:grid-cols-[minmax(0,1.65fr)_24rem]">
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

        <aside class="space-y-4">
            <section class="rounded-[2rem] border border-[var(--color-line)] bg-white p-5">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Saved packaging items</p>
                        <p class="mt-1 text-sm text-[var(--color-ink-soft)]">Build your own little packaging catalog, then pull items into this costing one by one.</p>
                    </div>
                    <span class="rounded-full border border-[var(--color-line)] bg-[var(--color-panel)] px-3 py-1 text-xs font-medium text-[var(--color-ink-soft)]" x-text="`${packagingCatalog.length} saved`"></span>
                </div>

                <div class="mt-4 grid gap-2">
                    <input x-model="packagingCatalogForm.name" type="text" placeholder="Bottle 100 g" class="rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] px-3 py-2.5 text-sm text-[var(--color-ink-strong)] outline-none" />
                    <div class="grid gap-2 sm:grid-cols-[minmax(0,1fr)_auto_auto]">
                        <input x-model.number="packagingCatalogForm.unit_cost" type="number" min="0" step="0.0001" placeholder="Unit cost" class="rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] px-3 py-2.5 text-sm text-[var(--color-ink-strong)] outline-none" />
                        <button type="button" @click="savePackagingCatalogItem()" class="rounded-full bg-[var(--color-accent-strong)] px-4 py-2 text-sm font-medium text-white transition hover:bg-[var(--color-accent)]">Save item</button>
                        <button type="button" @click="resetPackagingCatalogForm()" class="rounded-full border border-[var(--color-line)] px-4 py-2 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel)]">Reset</button>
                    </div>
                    <template x-if="packagingCatalogMessage">
                        <p class="text-xs text-[var(--color-ink-soft)]" x-text="packagingCatalogMessage"></p>
                    </template>
                </div>

                <div class="mt-4 space-y-2">
                    <template x-for="item in packagingCatalog" :key="item.id">
                        <div class="rounded-[1.25rem] border border-[var(--color-line)] bg-[var(--color-panel)] p-3">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="font-medium text-[var(--color-ink-strong)]" x-text="item.name"></p>
                                    <p class="mt-1 text-xs text-[var(--color-ink-soft)]" x-text="`${item.currency} ${format(item.unit_cost, 4)} each`"></p>
                                </div>
                                <div class="flex shrink-0 items-center gap-2">
                                    <button type="button" @click="addPackagingCostRow(item)" class="rounded-full bg-white px-3 py-1.5 text-xs font-medium text-[var(--color-ink-strong)] transition hover:border-[var(--color-line-strong)]">Use</button>
                                    <button type="button" @click="editPackagingCatalogItem(item)" class="rounded-full border border-[var(--color-line)] px-3 py-1.5 text-xs font-medium text-[var(--color-ink-soft)] transition hover:bg-white">Edit</button>
                                    <button type="button" @click="deletePackagingCatalogItem(item.id)" class="rounded-full border border-[var(--color-line)] px-3 py-1.5 text-xs font-medium text-[var(--color-ink-soft)] transition hover:bg-white">Delete</button>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </section>

            <section class="rounded-[2rem] border border-[var(--color-line)] bg-white p-5">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Packaging in this costing</p>
                        <p class="mt-1 text-sm text-[var(--color-ink-soft)]">Mix saved packaging items with one-off custom rows when a formula needs a different presentation.</p>
                    </div>
                    <button type="button" @click="addPackagingCostRow()" class="rounded-full bg-[var(--color-accent-soft)] px-4 py-2 text-sm font-medium text-[var(--color-ink-strong)] transition hover:bg-white">Add custom row</button>
                </div>

                <div class="mt-4 space-y-3">
                    <template x-for="row in packagingCostRows" :key="row.id">
                        <div class="rounded-[1.4rem] border border-[var(--color-line)] bg-[var(--color-panel)] p-3">
                            <div class="grid gap-2">
                                <input x-model="row.name" @change="scheduleCostingSave()" type="text" placeholder="Label / box / bottle" class="rounded-2xl border border-[var(--color-line)] bg-white px-3 py-2.5 text-sm text-[var(--color-ink-strong)] outline-none" />
                                <div class="grid gap-2 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto]">
                                    <input x-model.number="row.unit_cost" @change="scheduleCostingSave()" type="number" min="0" step="0.0001" placeholder="Unit cost" class="rounded-2xl border border-[var(--color-line)] bg-white px-3 py-2.5 text-sm text-[var(--color-ink-strong)] outline-none" />
                                    <input x-model.number="row.quantity" @change="scheduleCostingSave()" type="number" min="0" step="0.001" placeholder="Quantity" class="rounded-2xl border border-[var(--color-line)] bg-white px-3 py-2.5 text-sm text-[var(--color-ink-strong)] outline-none" />
                                    <button type="button" @click="removePackagingCostRow(row.id)" class="rounded-full border border-[var(--color-line)] px-4 py-2 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-white">Remove</button>
                                </div>
                            </div>
                            <p class="mt-2 text-xs text-[var(--color-ink-soft)]" x-text="`${costingCurrency} ${format(nonNegativeNumber(row.unit_cost) * nonNegativeNumber(row.quantity), 2)}`"></p>
                        </div>
                    </template>

                    <template x-if="packagingCostRows.length === 0">
                        <div class="rounded-[1.5rem] border border-dashed border-[var(--color-line)] bg-[var(--color-panel)] px-4 py-5 text-sm text-[var(--color-ink-soft)]">
                            No packaging added yet. Use a saved item or add a custom row.
                        </div>
                    </template>
                </div>
            </section>

            <section class="rounded-[2rem] border border-[var(--color-line)] bg-white p-5">
                <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Cost summary</p>
                <div class="mt-4 space-y-2 text-sm">
                    <div class="flex items-center justify-between rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] px-4 py-3">
                        <span class="text-[var(--color-ink-soft)]">Ingredients</span>
                        <span class="font-medium text-[var(--color-ink-strong)]" x-text="`${costingCurrency} ${format(ingredientCostTotal, 2)}`"></span>
                    </div>
                    <div class="flex items-center justify-between rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] px-4 py-3">
                        <span class="text-[var(--color-ink-soft)]">Packaging</span>
                        <span class="font-medium text-[var(--color-ink-strong)]" x-text="`${costingCurrency} ${format(packagingCostTotal, 2)}`"></span>
                    </div>
                    <div class="flex items-center justify-between rounded-2xl border border-[var(--color-line-strong)] bg-[var(--color-accent-soft)] px-4 py-3">
                        <span class="text-[var(--color-ink-strong)]">Total batch cost</span>
                        <span class="font-semibold text-[var(--color-ink-strong)]" x-text="`${costingCurrency} ${format(totalBatchCost, 2)}`"></span>
                    </div>
                    <div class="flex items-center justify-between rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] px-4 py-3">
                        <span class="text-[var(--color-ink-soft)]">Cost per unit</span>
                        <span class="font-medium text-[var(--color-ink-strong)]" x-text="costingUnitsProducedValue > 0 ? `${costingCurrency} ${format(costPerUnit, 2)}` : 'Set units produced'"></span>
                    </div>
                    <div class="flex items-center justify-between rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] px-4 py-3">
                        <span class="text-[var(--color-ink-soft)]">Cost per kg</span>
                        <span class="font-medium text-[var(--color-ink-strong)]" x-text="totalBatchWeightKg > 0 ? `${costingCurrency} ${format(costPerKg, 2)}` : 'Unavailable'"></span>
                    </div>
                </div>
            </section>
        </aside>
    </div>

    <template x-if="!hasSavedRecipe">
        <div class="rounded-[1.5rem] border border-[var(--color-line-strong)] bg-[var(--color-accent-soft)] px-4 py-3 text-sm text-[var(--color-ink-strong)]">
            Save the first draft to keep ingredient prices, packaging rows, and costing settings for this formula.
        </div>
    </template>
</div>
