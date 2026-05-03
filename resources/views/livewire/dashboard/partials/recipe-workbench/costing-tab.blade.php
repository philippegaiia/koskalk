<div x-show="activeWorkbenchTab === 'costing'" role="tabpanel" aria-labelledby="tab-costing" id="panel-costing" class="space-y-6">
 <section class="sk-card p-5">
 <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
 <div>
 <p class="sk-eyebrow">Costing settings</p>
 <h3 class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">Business view without cluttering the formula bench</h3>
 <p class="mt-2 text-sm text-[var(--color-ink-soft)]">Ingredient identity stays shared. The price memory stays private to the current user and can be refreshed later if supplier rates move.</p>
 </div>
 <div class="rounded-[1.25rem] border border-[var(--color-line)] bg-[var(--color-panel)] px-4 py-3 text-sm">
 <p class="font-medium text-[var(--color-ink-strong)]" x-text="costingSaveStatus === 'success' ? 'Costing kept' : (costingSaveStatus === 'warning' ? 'Preview only' : 'Auto-save ready')"></p>
 <p class="mt-1 text-[var(--color-ink-soft)]" x-text="costingSaveMessage || 'Changes here save independently from the formula content.'"></p>
 </div>
 </div>

 <div class="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
 <label class="sk-inset p-4">
 <span class="sk-eyebrow">Oil weight for costing</span>
 <input
 x-model="costingOilWeight"
 @blur="normalizeDecimalBlur($event); scheduleCostingSave()"
 type="text"
 inputmode="decimal"
 class="numeric mt-3 w-full rounded-lg bg-[var(--color-field)] px-3 py-2.5 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]"
 />
 </label>

 <div class="sk-inset p-4">
 <span class="sk-eyebrow">Unit</span>
 <div class="mt-3 flex flex-wrap gap-2" role="radiogroup" aria-label="Costing weight unit">
 <button type="button" role="radio" :aria-checked="costingOilUnit === 'g'" @click="costingOilUnit = 'g'; scheduleCostingSave()" :class="costingOilUnit === 'g' ? 'bg-[var(--color-accent)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-4 py-2.5 text-xs font-medium transition">g</button>
 <button type="button" role="radio" :aria-checked="costingOilUnit === 'kg'" @click="costingOilUnit = 'kg'; scheduleCostingSave()" :class="costingOilUnit === 'kg' ? 'bg-[var(--color-accent)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-4 py-2.5 text-xs font-medium transition">kg</button>
 <button type="button" role="radio" :aria-checked="costingOilUnit === 'oz'" @click="costingOilUnit = 'oz'; scheduleCostingSave()" :class="costingOilUnit === 'oz' ? 'bg-[var(--color-accent)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-4 py-2.5 text-xs font-medium transition">oz</button>
 <button type="button" role="radio" :aria-checked="costingOilUnit === 'lb'" @click="costingOilUnit = 'lb'; scheduleCostingSave()" :class="costingOilUnit === 'lb' ? 'bg-[var(--color-accent)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-4 py-2.5 text-xs font-medium transition">lb</button>
 </div>
 </div>

 <label class="sk-inset p-4">
 <span class="sk-eyebrow">Units produced</span>
 <input
 x-model="costingUnitsProduced"
 @blur="normalizeDecimalBlur($event); scheduleCostingSave()"
 type="text"
 inputmode="numeric"
 class="numeric mt-3 w-full rounded-lg bg-[var(--color-field)] px-3 py-2.5 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]"
 placeholder="e.g. 50"
 />
 </label>

 <div class="sk-inset p-4">
 <span class="sk-eyebrow" id="costing-currency-label">Currency</span>
 <select
 x-model="costingCurrency"
 @change="scheduleCostingSave()"
 aria-labelledby="costing-currency-label"
 class="mt-3 w-full rounded-lg bg-[var(--color-field)] px-3 py-2.5 text-sm font-medium text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]"
 >
 @foreach($workbench['currencies'] ?? ['EUR' => 'Euro', 'USD' => 'US Dollar', 'CHF' => 'Swiss Franc'] as $code => $name)
 <option value="{{ $code }}">{{ $code }} — {{ $name }}</option>
 @endforeach
 </select>
 <p class="mt-2 text-xs leading-5 text-[var(--color-ink-soft)]">Ingredient prices are stored per kilogram and reused here as your default.</p>
 </div>
 </div>
 </section>

 <section class="overflow-hidden sk-card">
 <div class="border-b border-[var(--color-line)] px-5 py-4">
 <p class="sk-eyebrow">Ingredient costing</p>
 <div class="mt-1 flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
 <p class="text-sm text-[var(--color-ink-soft)]">Formula rows stay read-only here except for price per kilo, so development and costing each get their own space.</p>
 <span class="numeric rounded-full border border-[var(--color-line)] bg-[var(--color-panel)] px-3 py-1 text-xs font-medium text-[var(--color-ink-soft)]" x-text="`${costingFormulaRows.length} priced rows`"></span>
 </div>
 </div>

 <template x-if="costingFormulaRows.length > 0">
 <div class="overflow-x-auto">
 <div class="min-w-[52rem]">
 <div class="grid grid-cols-[8rem_minmax(0,1.8fr)_5rem_7rem_8rem_8rem] gap-px bg-[var(--color-line)] text-sm">
 <div class="bg-[var(--color-field-muted)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">Phase</div>
 <div class="bg-[var(--color-field-muted)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">Ingredient</div>
 <div class="bg-[var(--color-field-muted)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">%</div>
 <div class="bg-[var(--color-field-muted)] px-4 py-3 font-medium text-[var(--color-ink-strong)]" x-text="`Weight (${costingBaseOilUnit})`"></div>
 <div class="bg-[var(--color-field-muted)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">Price / kg</div>
 <div class="bg-[var(--color-field-muted)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">Line cost</div>
 </div>

 <div class="divide-y divide-[var(--color-line)] bg-white">
 <template x-for="row in costingFormulaRows" :key="`${row.phaseKey}-${row.rowId}`">
 <div class="grid grid-cols-[8rem_minmax(0,1.8fr)_5rem_7rem_8rem_8rem] gap-px bg-[var(--color-line)] text-sm">
 <div class="bg-white px-4 py-3 text-[var(--color-ink-soft)]" x-text="row.phaseLabel"></div>
 <div class="bg-white px-4 py-3">
 <p class="font-medium text-[var(--color-ink-strong)]" x-text="row.name"></p>
 </div>
 <div class="numeric bg-white px-4 py-3 text-[var(--color-ink-soft)]" x-text="`${format(row.percentage, 2)}%`"></div>
 <div class="numeric bg-white px-4 py-3 text-[var(--color-ink-soft)]" x-text="`${format(row.weight, 2)}`"></div>
 <div class="bg-white px-3 py-3">
 <input
 :value="costingPriceForRow(row) ?? ''"
 @change="updateCostingPrice(row, $event.target.value)"
 @blur="updateCostingPrice(row, $event.target.value)"
 type="text"
 inputmode="decimal"
 :aria-label="'Price per kilogram for ' + row.name"
 class="numeric w-full rounded-xl border border-[var(--color-line)] bg-[var(--color-field)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]"
 />
 </div>
 <div class="numeric bg-white px-4 py-3 font-medium text-[var(--color-ink-strong)]" x-text="`${costingCurrency} ${format(lineCostForRow(row), 2)}`"></div>
 </div>
 </template>

 <div class="grid grid-cols-[8rem_minmax(0,1.8fr)_5rem_7rem_8rem_8rem] gap-px bg-[var(--color-line)] text-sm">
 <div class="bg-[var(--color-field-muted)] px-4 py-3"></div>
 <div class="bg-[var(--color-field-muted)] px-4 py-3 font-semibold text-[var(--color-ink-strong)]">Ingredient subtotal</div>
 <div class="bg-[var(--color-field-muted)] px-4 py-3"></div>
 <div class="bg-[var(--color-field-muted)] px-4 py-3"></div>
 <div class="bg-[var(--color-field-muted)] px-4 py-3"></div>
 <div class="numeric bg-[var(--color-field-muted)] px-4 py-3 font-semibold text-[var(--color-ink-strong)]" x-text="`${costingCurrency} ${format(ingredientCostTotal, 2)}`"></div>
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

 <section class="overflow-hidden sk-card">
 <div class="border-b border-[var(--color-line)] px-5 py-4">
 <div>
 <p class="sk-eyebrow">Packaging costing</p>
 <h3 class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">Packaging prices</h3>
 <p class="mt-2 text-sm text-[var(--color-ink-soft)]">Packaging structure comes from the Packaging tab. Costing only updates the effective unit price.</p>
 </div>
 </div>

 <template x-if="packagingCostRows.length === 0">
 <div class="px-5 py-8 text-sm text-[var(--color-ink-soft)]">
 <p class="font-medium text-[var(--color-ink-strong)]">No packaging planned yet.</p>
 <p class="mt-2">Add packaging on the Packaging tab, then save the draft to price it here.</p>
 </div>
 </template>

 <template x-if="packagingCostRows.length > 0">
 <div class="overflow-x-auto">
 <div class="min-w-[58rem]">
 <div class="grid grid-cols-[minmax(0,1.8fr)_9rem_9rem_9rem_9rem] gap-px bg-[var(--color-line)] text-sm">
 <div class="bg-[var(--color-field-muted)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">Packaging item</div>
 <div class="bg-[var(--color-field-muted)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">Components per unit</div>
 <div class="bg-[var(--color-field-muted)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">Unit price</div>
 <div class="bg-[var(--color-field-muted)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">Cost per unit</div>
 <div class="bg-[var(--color-field-muted)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">Batch cost</div>
 </div>

 <div class="divide-y divide-[var(--color-line)] bg-white">
 <template x-for="row in packagingCostRows" :key="row.id">
 <div class="grid grid-cols-[minmax(0,1.8fr)_9rem_9rem_9rem_9rem] gap-px bg-[var(--color-line)] text-sm">
 <div class="flex items-center bg-white px-4 py-3">
 <p class="font-medium text-[var(--color-ink-strong)]" x-text="row.name"></p>
 </div>
 <div class="numeric flex items-center bg-white px-4 py-3 text-[var(--color-ink-soft)]">
 <span x-text="format(row.quantity, 3)"></span>
 </div>
 <div class="flex items-center bg-white px-3 py-3">
 <input x-model="row.unit_cost" @blur="normalizeDecimalBlur($event); scheduleCostingSave()" type="text" inputmode="decimal" :aria-label="'Unit price for ' + row.name" class="numeric w-full rounded-xl border border-[var(--color-line)] bg-[var(--color-field)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]" />
 </div>
 <div class="numeric flex items-center bg-white px-4 py-3 font-medium text-[var(--color-ink-strong)]" x-text="`${costingCurrency} ${format(packagingCostPerFinishedUnitForRow(row), 2)}`"></div>
 <div class="numeric flex items-center bg-white px-4 py-3 font-medium text-[var(--color-ink-strong)]" x-text="costingUnitsProducedValue > 0 ? `${costingCurrency} ${format(packagingBatchCostForRow(row), 2)}` : 'Set units produced'"></div>
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

 <section class="sk-card p-5">
 <p class="sk-eyebrow">Cost summary</p>
 <div class="mt-4 grid gap-2 lg:grid-cols-2 xl:grid-cols-4 xl:gap-3 text-sm">
 <div class="flex items-center justify-between rounded-lg bg-[var(--color-field)] px-4 py-3 xl:flex-col xl:items-start xl:justify-start xl:gap-2">
 <span class="text-[var(--color-ink-soft)]">Ingredients</span>
 <span class="numeric font-medium text-[var(--color-ink-strong)]" x-text="`${costingCurrency} ${format(ingredientCostTotal, 2)}`"></span>
 </div>
 <div class="flex items-center justify-between rounded-lg bg-[var(--color-field)] px-4 py-3 xl:flex-col xl:items-start xl:justify-start xl:gap-2">
 <span class="text-[var(--color-ink-soft)]">Packaging</span>
 <span class="numeric font-medium text-[var(--color-ink-strong)]" x-text="packagingCostTotal !== null ? `${costingCurrency} ${format(packagingCostTotal, 2)}` : 'Set units produced'"></span>
 </div>
 <div class="flex items-center justify-between rounded-lg border border-[var(--color-line-strong)] bg-[var(--color-accent-soft)] px-4 py-3 xl:flex-col xl:items-start xl:justify-start xl:gap-2">
 <span class="text-[var(--color-ink-strong)]">Total batch cost</span>
 <span class="numeric font-semibold text-[var(--color-ink-strong)]" x-text="totalBatchCost !== null ? `${costingCurrency} ${format(totalBatchCost, 2)}` : 'Set units produced'"></span>
 </div>
 <div class="flex items-center justify-between rounded-lg bg-[var(--color-field)] px-4 py-3 xl:flex-col xl:items-start xl:justify-start xl:gap-2">
 <span class="text-[var(--color-ink-soft)]">Cost per unit</span>
 <span class="numeric font-medium text-[var(--color-ink-strong)]" x-text="costingUnitsProducedValue > 0 ? `${costingCurrency} ${format(costPerUnit, 2)}` : 'Set units produced'"></span>
 </div>
 </div>
 </section>

 <template x-if="!hasSavedRecipe">
 <div class="rounded-[1.5rem] border border-[var(--color-line-strong)] bg-[var(--color-accent-soft)] px-4 py-3 text-sm text-[var(--color-ink-strong)]">
 Save the first draft to keep ingredient prices, packaging rows, and costing settings for this formula.
 </div>
 </template>
</div>
