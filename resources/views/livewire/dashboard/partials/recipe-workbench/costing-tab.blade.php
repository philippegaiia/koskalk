<div x-show="activeWorkbenchTab === 'costing'" class="space-y-6">
 <section class="rounded-xl bg-[var(--color-panel)] shadow-[0_2px_4px_rgba(60,50,30,0.04),0_12px_24px_rgba(60,50,30,0.08)] p-5">
 <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
 <div>
 <p class="text-[0.6875rem] font-medium tracking-[0.05em] text-[var(--color-ink-soft)] uppercase">Costing settings</p>
 <h3 class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">Business view without cluttering the formula bench</h3>
 <p class="mt-2 text-sm text-[var(--color-ink-soft)]">Ingredient identity stays shared. The price memory stays private to the current user and can be refreshed later if supplier rates move.</p>
 </div>
 <div class="rounded-[1.25rem] border border-[var(--color-line)] bg-[var(--color-panel)] px-4 py-3 text-sm">
 <p class="font-medium text-[var(--color-ink-strong)]" x-text="costingSaveStatus === 'success' ? 'Costing kept' : (costingSaveStatus === 'warning' ? 'Preview only' : 'Auto-save ready')"></p>
 <p class="mt-1 text-[var(--color-ink-soft)]" x-text="costingSaveMessage || 'Changes here save independently from the formula content.'"></p>
 </div>
 </div>

 <div class="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
 <label class="rounded-lg bg-[var(--color-panel-strong)] p-4">
 <span class="text-[0.6875rem] font-medium tracking-[0.05em] text-[var(--color-ink-soft)] uppercase">Oil weight for costing</span>
 <input
 x-model="costingOilWeight"
 @blur="normalizeDecimalBlur($event); scheduleCostingSave()"
 type="text"
 inputmode="decimal"
 class="mt-3 w-full rounded-lg bg-[var(--color-panel-strong)] px-3 py-2.5 text-sm text-[var(--color-ink-strong)] outline-none"
 />
 </label>

 <div class="rounded-lg bg-[var(--color-panel-strong)] p-4">
 <span class="text-[0.6875rem] font-medium tracking-[0.05em] text-[var(--color-ink-soft)] uppercase">Unit</span>
 <div class="mt-3 flex flex-wrap gap-2">
 <button type="button" @click="costingOilUnit = 'g'; scheduleCostingSave()" :class="costingOilUnit === 'g' ? 'bg-[var(--color-accent)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-3 py-2 text-xs font-medium transition">g</button>
 <button type="button" @click="costingOilUnit = 'kg'; scheduleCostingSave()" :class="costingOilUnit === 'kg' ? 'bg-[var(--color-accent)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-3 py-2 text-xs font-medium transition">kg</button>
 <button type="button" @click="costingOilUnit = 'oz'; scheduleCostingSave()" :class="costingOilUnit === 'oz' ? 'bg-[var(--color-accent)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-3 py-2 text-xs font-medium transition">oz</button>
 <button type="button" @click="costingOilUnit = 'lb'; scheduleCostingSave()" :class="costingOilUnit === 'lb' ? 'bg-[var(--color-accent)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-3 py-2 text-xs font-medium transition">lb</button>
 </div>
 </div>

 <label class="rounded-lg bg-[var(--color-panel-strong)] p-4">
 <span class="text-[0.6875rem] font-medium tracking-[0.05em] text-[var(--color-ink-soft)] uppercase">Units produced</span>
 <input
 x-model="costingUnitsProduced"
 @blur="normalizeDecimalBlur($event); scheduleCostingSave()"
 type="text"
 inputmode="numeric"
 class="mt-3 w-full rounded-lg bg-[var(--color-panel-strong)] px-3 py-2.5 text-sm text-[var(--color-ink-strong)] outline-none"
 placeholder="e.g. 50"
 />
 </label>

 <div class="rounded-lg bg-[var(--color-panel-strong)] p-4">
 <span class="text-[0.6875rem] font-medium tracking-[0.05em] text-[var(--color-ink-soft)] uppercase">Currency</span>
 <select
 x-model="costingCurrency"
 @change="scheduleCostingSave()"
 class="mt-3 w-full rounded-lg bg-[var(--color-panel-strong)] px-3 py-2.5 text-sm font-medium text-[var(--color-ink-strong)] outline-none"
 >
 @foreach($workbench['currencies'] ?? ['EUR' => 'Euro', 'USD' => 'US Dollar', 'CHF' => 'Swiss Franc'] as $code => $name)
 <option value="{{ $code }}">{{ $code }} — {{ $name }}</option>
 @endforeach
 </select>
 <p class="mt-2 text-xs leading-5 text-[var(--color-ink-soft)]">Ingredient prices are stored per kilogram and reused here as your default.</p>
 </div>
 </div>
 </section>

 <section class="overflow-hidden rounded-xl bg-[var(--color-panel)] shadow-[0_2px_4px_rgba(60,50,30,0.04),0_12px_24px_rgba(60,50,30,0.08)]">
 <div class="border-b border-[var(--color-line)] px-5 py-4">
 <p class="text-[0.6875rem] font-medium tracking-[0.05em] text-[var(--color-ink-soft)] uppercase">Ingredient costing</p>
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
 type="text"
 inputmode="decimal"
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

 <section class="overflow-hidden rounded-xl bg-[var(--color-panel)] shadow-[0_2px_4px_rgba(60,50,30,0.04),0_12px_24px_rgba(60,50,30,0.08)]">
 <div class="border-b border-[var(--color-line)] px-5 py-4">
 <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
 <div>
 <p class="text-[0.6875rem] font-medium tracking-[0.05em] text-[var(--color-ink-soft)] uppercase">Packaging</p>
 <h3 class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">Packaging</h3>
 <p class="mt-2 text-sm text-[var(--color-ink-soft)]">Add reusable packaging items used for one finished unit.</p>
 </div>

 <div class="flex flex-wrap items-center gap-2">
 <template x-if="unusedPackagingCatalogItems.length > 0">
 <select
 @change="if ($event.target.value) { addPackagingCostRow(JSON.parse($event.target.value)); $event.target.value = ''; }"
 class="rounded-full border border-[var(--color-line)] bg-white px-4 py-2 text-sm font-medium text-[var(--color-ink-strong)] outline-none"
 >
 <option value="">Add from catalog...</option>
 <template x-for="item in unusedPackagingCatalogItems" :key="item.id">
 <option :value="JSON.stringify(item)" x-text="`${item.name} (${costingCurrency} ${format(item.unit_cost, 4)})`"></option>
 </template>
 </select>
 </template>
 <button type="button" @click="openPackagingCatalogModal()" class="rounded-full bg-[var(--color-accent)] px-4 py-2 text-sm font-medium text-white transition hover:bg-[var(--color-accent-hover)]">
 New packaging item
 </button>
 </div>
 </div>
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
 <div class="flex items-center bg-white px-4 py-3">
 <p class="font-medium text-[var(--color-ink-strong)]" x-text="row.name"></p>
 </div>
 <div class="flex items-center bg-white px-3 py-3">
 <input x-model="row.quantity" @blur="normalizeDecimalBlur($event); scheduleCostingSave()" type="text" inputmode="decimal" class="w-full rounded-xl border border-[var(--color-line)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline-none" />
 </div>
 <div class="flex items-center bg-white px-3 py-3">
 <input x-model="row.unit_cost" @blur="normalizeDecimalBlur($event); scheduleCostingSave()" type="text" inputmode="decimal" class="w-full rounded-xl border border-[var(--color-line)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline-none" />
 </div>
 <div class="flex items-center bg-white px-4 py-3 font-medium text-[var(--color-ink-strong)]" x-text="`${costingCurrency} ${format(packagingCostPerFinishedUnitForRow(row), 2)}`"></div>
 <div class="flex items-center bg-white px-4 py-3 font-medium text-[var(--color-ink-strong)]" x-text="costingUnitsProducedValue > 0 ? `${costingCurrency} ${format(packagingBatchCostForRow(row), 2)}` : 'Set units produced'"></div>
 <div class="flex items-center justify-end bg-white px-4 py-3">
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

 <section class="rounded-xl bg-[var(--color-panel)] shadow-[0_2px_4px_rgba(60,50,30,0.04),0_12px_24px_rgba(60,50,30,0.08)] p-5">
 <p class="text-[0.6875rem] font-medium tracking-[0.05em] text-[var(--color-ink-soft)] uppercase">Cost summary</p>
 <div class="mt-4 grid gap-2 lg:grid-cols-2 xl:grid-cols-4 xl:gap-3 text-sm">
 <div class="flex items-center justify-between rounded-lg bg-[var(--color-panel-strong)] px-4 py-3 xl:flex-col xl:items-start xl:justify-start xl:gap-2">
 <span class="text-[var(--color-ink-soft)]">Ingredients</span>
 <span class="font-medium text-[var(--color-ink-strong)]" x-text="`${costingCurrency} ${format(ingredientCostTotal, 2)}`"></span>
 </div>
 <div class="flex items-center justify-between rounded-lg bg-[var(--color-panel-strong)] px-4 py-3 xl:flex-col xl:items-start xl:justify-start xl:gap-2">
 <span class="text-[var(--color-ink-soft)]">Packaging</span>
 <span class="font-medium text-[var(--color-ink-strong)]" x-text="packagingCostTotal !== null ? `${costingCurrency} ${format(packagingCostTotal, 2)}` : 'Set units produced'"></span>
 </div>
 <div class="flex items-center justify-between rounded-lg border border-[var(--color-line-strong)] bg-[var(--color-accent-soft)] px-4 py-3 xl:flex-col xl:items-start xl:justify-start xl:gap-2">
 <span class="text-[var(--color-ink-strong)]">Total batch cost</span>
 <span class="font-semibold text-[var(--color-ink-strong)]" x-text="totalBatchCost !== null ? `${costingCurrency} ${format(totalBatchCost, 2)}` : 'Set units produced'"></span>
 </div>
 <div class="flex items-center justify-between rounded-lg bg-[var(--color-panel-strong)] px-4 py-3 xl:flex-col xl:items-start xl:justify-start xl:gap-2">
 <span class="text-[var(--color-ink-soft)]">Cost per unit</span>
 <span class="font-medium text-[var(--color-ink-strong)]" x-text="costingUnitsProducedValue > 0 ? `${costingCurrency} ${format(costPerUnit, 2)}` : 'Set units produced'"></span>
 </div>
 </div>
 </section>

 <div
 x-cloak
 x-show="packagingCatalogModalOpen"
 x-transition.opacity
 @keydown.escape.window="closePackagingCatalogModal()"
 class="fixed inset-0 z-40 flex items-center justify-center bg-[color:oklch(from_var(--color-surface-strong)_l_c_h_/_0.55)] px-4 py-6"
 >
 <div @click.away="closePackagingCatalogModal()" class="w-full max-w-xl rounded-xl bg-[var(--color-panel)] shadow-[0_2px_4px_rgba(60,50,30,0.04),0_12px_24px_rgba(60,50,30,0.08)] p-6">
 <div class="flex items-start justify-between gap-4">
 <div>
 <p class="text-[0.6875rem] font-medium tracking-[0.05em] text-[var(--color-ink-soft)] uppercase">Packaging item</p>
 <h3 class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">New packaging item</h3>
 <p class="mt-2 text-sm text-[var(--color-ink-soft)]">Save a reusable catalog item here, then optionally add it straight into this costing at one component per finished unit.</p>
 </div>
 <button type="button" @click="closePackagingCatalogModal()" class="rounded-full border border-[var(--color-line)] px-3 py-1.5 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel)]">Cancel</button>
 </div>

 <div class="mt-5 grid gap-3">
 <label class="rounded-lg bg-[var(--color-panel-strong)] p-4">
 <span class="text-[0.6875rem] font-medium tracking-[0.05em] text-[var(--color-ink-soft)] uppercase">Name</span>
 <input x-model="packagingCatalogForm.name" type="text" class="mt-3 w-full rounded-lg bg-[var(--color-panel-strong)] px-3 py-2.5 text-sm text-[var(--color-ink-strong)] outline-none" />
 </label>

 <label class="rounded-lg bg-[var(--color-panel-strong)] p-4">
 <span class="text-[0.6875rem] font-medium tracking-[0.05em] text-[var(--color-ink-soft)] uppercase">Effective unit price</span>
 <input x-model="packagingCatalogForm.unit_cost" @blur="normalizeDecimalBlur($event)" type="text" inputmode="decimal" class="mt-3 w-full rounded-lg bg-[var(--color-panel-strong)] px-3 py-2.5 text-sm text-[var(--color-ink-strong)] outline-none" />
 </label>

 <label class="rounded-lg bg-[var(--color-panel-strong)] p-4">
 <span class="text-[0.6875rem] font-medium tracking-[0.05em] text-[var(--color-ink-soft)] uppercase">Notes</span>
 <textarea x-model="packagingCatalogForm.notes" rows="4" class="mt-3 w-full rounded-lg bg-[var(--color-panel-strong)] px-3 py-2.5 text-sm text-[var(--color-ink-strong)] outline-none"></textarea>
 </label>
 </div>

 <template x-if="packagingCatalogMessage">
 <p class="mt-4 text-sm text-[var(--color-ink-soft)]" x-text="packagingCatalogMessage"></p>
 </template>

 <div class="mt-5 flex flex-wrap justify-end gap-2">
 <button type="button" @click="closePackagingCatalogModal()" class="rounded-full border border-[var(--color-line)] px-4 py-2 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel)]">Cancel</button>
 <button type="button" @click="savePackagingCatalogItemOnly()" class="rounded-full border border-[var(--color-line)] bg-white px-4 py-2 text-sm font-medium text-[var(--color-ink-strong)] transition hover:bg-[var(--color-panel)]">Save only</button>
 <button type="button" @click="savePackagingCatalogItemAndAddToCosting()" class="rounded-full bg-[var(--color-accent)] px-4 py-2 text-sm font-medium text-white transition hover:bg-[var(--color-accent-hover)]">Save and add</button>
 </div>
 </div>
 </div>

 <template x-if="!hasSavedRecipe">
 <div class="rounded-[1.5rem] border border-[var(--color-line-strong)] bg-[var(--color-accent-soft)] px-4 py-3 text-sm text-[var(--color-ink-strong)]">
 Save the first draft to keep ingredient prices, packaging rows, and costing settings for this formula.
 </div>
 </template>
</div>
