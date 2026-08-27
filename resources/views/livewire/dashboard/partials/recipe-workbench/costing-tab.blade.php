<div x-show="activeWorkbenchTab === 'costing'" role="tabpanel" aria-labelledby="tab-costing" id="panel-costing" class="space-y-6 pb-24">
 <section class="sk-card p-5">
 <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
 <div>
 <p class="sk-eyebrow">{{ __('workbench.costing.settings.title') }}</p>
 <h3 class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('workbench.costing.settings.title') }}</h3>
 <p class="mt-2 text-sm text-[var(--color-ink-soft)]">{{ __('workbench.costing.settings.help') }}</p>
 </div>
 <div class="rounded-[1.25rem] border border-[var(--color-line)] bg-[var(--color-panel)] px-4 py-3 text-sm">
 <p class="font-medium text-[var(--color-ink-strong)]" x-text="costingSaveStatus === 'error' ? t('costing.settings.could_not_save') : (costingSaveStatus === 'warning' ? t('costing.settings.save_product_first') : t('costing.settings.saved_automatically'))"></p>
 <p class="mt-1 text-[var(--color-ink-soft)]" x-text="costingSaveMessage || t('costing.settings.automatic_help')"></p>
 </div>
 </div>

 <div class="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
 <label class="sk-inset p-4">
 <span class="sk-eyebrow" x-text="isCosmeticFormula ? t('costing.settings.batch_quantity') : t('costing.settings.oil_quantity')"></span>
 <input
 :value="costingOilWeight === null || costingOilWeight === '' ? '' : format(costingOilWeight, 2)"
 @change="updateCostingOilWeight($event)"
 @blur="$event.target.value = costingOilWeight === null || costingOilWeight === '' ? '' : format(costingOilWeight, 2)"
 type="text"
 inputmode="decimal"
 class="numeric mt-3 w-full rounded-lg bg-[var(--color-field)] px-3 py-2.5 text-sm text-[var(--color-ink-strong)] transition"
 />
 </label>

 <div class="sk-inset p-4">
 <span class="sk-eyebrow">{{ __('workbench.costing.settings.unit') }}</span>
 <div class="mt-3 flex flex-wrap gap-2" role="radiogroup" aria-label="{{ __('workbench.costing.settings.weight_unit') }}">
 <button type="button" role="radio" :aria-checked="costingOilUnit === 'g'" @click="changeCostingUnit('g')" :class="costingOilUnit === 'g' ? 'bg-[var(--color-active)] text-[var(--color-on-active)] shadow-sm' : 'bg-[var(--color-control)] text-[var(--color-ink-soft)] hover:bg-[var(--color-panel)]'" class="rounded-full px-4 py-2.5 text-xs font-medium transition">{{ __('workbench.costing.settings.units.g') }}</button>
 <button type="button" role="radio" :aria-checked="costingOilUnit === 'kg'" @click="changeCostingUnit('kg')" :class="costingOilUnit === 'kg' ? 'bg-[var(--color-active)] text-[var(--color-on-active)] shadow-sm' : 'bg-[var(--color-control)] text-[var(--color-ink-soft)] hover:bg-[var(--color-panel)]'" class="rounded-full px-4 py-2.5 text-xs font-medium transition">{{ __('workbench.costing.settings.units.kg') }}</button>
 <button type="button" role="radio" :aria-checked="costingOilUnit === 'oz'" @click="changeCostingUnit('oz')" :class="costingOilUnit === 'oz' ? 'bg-[var(--color-active)] text-[var(--color-on-active)] shadow-sm' : 'bg-[var(--color-control)] text-[var(--color-ink-soft)] hover:bg-[var(--color-panel)]'" class="rounded-full px-4 py-2.5 text-xs font-medium transition">{{ __('workbench.costing.settings.units.oz') }}</button>
 <button type="button" role="radio" :aria-checked="costingOilUnit === 'lb'" @click="changeCostingUnit('lb')" :class="costingOilUnit === 'lb' ? 'bg-[var(--color-active)] text-[var(--color-on-active)] shadow-sm' : 'bg-[var(--color-control)] text-[var(--color-ink-soft)] hover:bg-[var(--color-panel)]'" class="rounded-full px-4 py-2.5 text-xs font-medium transition">{{ __('workbench.costing.settings.units.lb') }}</button>
 </div>
 </div>

 <label class="sk-inset p-4">
 <span class="sk-eyebrow">{{ __('workbench.costing.settings.finished_units') }}</span>
 <input
 x-model="costingUnitsProduced"
 @blur="normalizeDecimalBlur($event); scheduleCostingSave()"
 type="text"
 inputmode="numeric"
 class="numeric mt-3 w-full rounded-lg bg-[var(--color-field)] px-3 py-2.5 text-sm text-[var(--color-ink-strong)] transition"
 placeholder="{{ __('workbench.costing.settings.finished_units_placeholder') }}"
 />
 </label>

 <div class="sk-inset p-4">
 <span class="sk-eyebrow">{{ __('workbench.costing.settings.currency') }}</span>
 <div
     id="costing-currency-search"
     class="mt-3 flex items-center justify-between rounded-xl border border-[var(--color-line)] bg-[var(--color-field-muted)] px-3 py-2.5"
     role="status"
     data-costing-currency
 >
     <span class="text-sm text-[var(--color-ink-soft)]">{{ __('workbench.costing.settings.currency_label') }}</span>
     <span class="numeric font-semibold text-[var(--color-ink-strong)]">{{ $workbench['defaultCurrency'] ?? 'EUR' }}</span>
 </div>
 <p class="mt-2 text-xs leading-5 text-[var(--color-ink-soft)]">
     {{ __('workbench.costing.settings.price_help') }}
     <a href="{{ route('settings') }}" class="font-medium text-[var(--color-accent-strong)] underline decoration-[var(--color-line-strong)] underline-offset-2 hover:decoration-current">{{ __('workbench.costing.settings.change_currency') }}</a>
 </p>
 </div>
 </div>
 </section>

 <section class="overflow-hidden sk-card">
 <div class="border-b border-[var(--color-line)] px-5 py-4">
 <p class="sk-eyebrow">{{ __('workbench.costing.ingredients.title') }}</p>
 <div class="mt-1 flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
 <span class="numeric rounded-full border border-[var(--color-line)] bg-[var(--color-panel)] px-3 py-1 text-xs font-medium text-[var(--color-ink-soft)]" x-text="t('costing.ingredients.count', { count: costingFormulaRows.length })"></span>
 </div>
 </div>

 <template x-if="costingFormulaRows.length > 0">
 <div class="overflow-x-auto">
 <div class="min-w-[56rem]">
 <div class="grid grid-cols-[8rem_minmax(0,1.6fr)_8rem_8rem_8rem_9rem] gap-px bg-[var(--color-line)] text-sm">
 <div class="bg-[var(--color-field-muted)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">{{ __('workbench.costing.ingredients.phase') }}</div>
 <div class="bg-[var(--color-field-muted)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">{{ __('workbench.costing.ingredients.ingredient') }}</div>
 <div class="bg-[var(--color-field-muted)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">{{ __('workbench.costing.ingredients.percentage') }}</div>
 <div class="bg-[var(--color-field-muted)] px-4 py-3 font-medium text-[var(--color-ink-strong)]" x-text="t('costing.ingredients.weight', { unit: costingBaseOilUnit })"></div>
 <div class="bg-[var(--color-field-muted)] px-4 py-3 font-medium text-[var(--color-ink-strong)]" x-text="t('costing.ingredients.price', { unit: costingPriceUnit })">{{ __('workbench.costing.ingredients.price', ['unit' => 'kg']) }}</div>
 <div class="bg-[var(--color-field-muted)] px-4 py-3 font-medium text-[var(--color-ink-strong)]" x-text="t('costing.ingredients.line_cost', { currency: costingCurrency })"></div>
 </div>

 <div class="divide-y divide-[var(--color-line)] bg-white">
 <template x-for="row in costingFormulaRows" :key="`${row.phaseKey}-${row.rowId}`">
 <div class="grid grid-cols-[8rem_minmax(0,1.6fr)_8rem_8rem_8rem_9rem] gap-px bg-[var(--color-line)] text-sm">
 <div class="flex items-center bg-white px-4 py-3 text-[var(--color-ink-soft)]" x-text="row.phaseLabel"></div>
 <div class="flex items-center bg-white px-4 py-3">
 <div>
 <p class="font-medium text-[var(--color-ink-strong)]" x-text="row.name"></p>
 </div>
 </div>
 <div class="numeric flex items-center bg-white px-4 py-3 text-[var(--color-ink-soft)]"><span class="sk-decimal-aligned" :style="decimalAlignmentStyle(row.percentage)" x-text="`${format(row.percentage, 2)}${row.percentageLabel}`"></span></div>
 <div class="numeric flex items-center bg-white px-4 py-3 text-[var(--color-ink-soft)]"><span class="sk-decimal-aligned" :style="decimalAlignmentStyle(row.weight)" x-text="format(row.weight, 2)"></span></div>
 <div class="flex items-center bg-white px-3 py-3">
 <input
 :value="costingPriceForRow(row) === null ? '' : format(costingPriceForRow(row), 2)"
 @change="updateCostingPrice(row, $event.target.value)"
 @blur="$event.target.value = costingPriceForRow(row) === null ? '' : format(costingPriceForRow(row), 2)"
 type="text"
 inputmode="decimal"
 :aria-label="t('costing.accessibility.price_for', { item: row.name, unit: costingPriceUnit })"
 :style="decimalAlignmentStyle(costingPriceForRow(row))"
 class="numeric sk-decimal-aligned w-full rounded-xl border border-[var(--color-line)] bg-[var(--color-field)] py-2 text-sm text-[var(--color-ink-strong)] transition"
 />
 </div>
 <div class="numeric flex items-center bg-white px-4 py-3 font-medium text-[var(--color-ink-strong)]"><span class="sk-decimal-aligned" :style="decimalAlignmentStyle(lineCostForRow(row))" x-text="format(lineCostForRow(row), 2)"></span></div>
 </div>
 </template>

 <div class="grid grid-cols-[8rem_minmax(0,1.6fr)_8rem_8rem_8rem_9rem] gap-px bg-[var(--color-line)] text-sm">
 <div class="flex items-center bg-[var(--color-field-muted)] px-4 py-3"></div>
 <div class="flex items-center bg-[var(--color-field-muted)] px-4 py-3 font-semibold text-[var(--color-ink-strong)]">{{ __('workbench.costing.ingredients.subtotal') }}</div>
 <div class="flex items-center bg-[var(--color-field-muted)] px-4 py-3"></div>
 <div class="flex items-center bg-[var(--color-field-muted)] px-4 py-3"></div>
 <div class="flex items-center bg-[var(--color-field-muted)] px-4 py-3"></div>
 <div class="numeric flex items-center bg-[var(--color-field-muted)] px-4 py-3 font-semibold text-[var(--color-ink-strong)]"><span class="sk-decimal-aligned" :style="decimalAlignmentStyle(ingredientCostTotal)" x-text="format(ingredientCostTotal, 2)"></span></div>
 </div>
 </div>
 </div>
 </div>
 </template>

 <template x-if="costingFormulaRows.length === 0">
 <div class="px-5 py-8 text-sm text-[var(--color-ink-soft)]">
 {{ __('workbench.costing.ingredients.empty') }}
 </div>
 </template>
 </section>

 <section class="overflow-hidden sk-card">
 <div class="border-b border-[var(--color-line)] px-5 py-4">
 <div>
 <p class="sk-eyebrow">{{ __('workbench.costing.packaging.title') }}</p>
 <h3 class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('workbench.costing.packaging.title') }}</h3>
 </div>
 </div>

 <template x-if="displayedPackagingCostRows.length === 0">
 <div class="px-5 py-8 text-sm text-[var(--color-ink-soft)]">
 <p class="font-medium text-[var(--color-ink-strong)]">{{ __('workbench.costing.packaging.empty') }}</p>
 <p class="mt-2">{{ __('workbench.costing.packaging.empty_help') }}</p>
 </div>
 </template>

 <template x-if="displayedPackagingCostRows.length > 0">
 <div class="overflow-x-auto">
 <div class="min-w-[58rem]">
 <div class="grid grid-cols-[minmax(0,1.8fr)_9rem_9rem_9rem_9rem] gap-px bg-[var(--color-line)] text-sm">
 <div class="bg-[var(--color-field-muted)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">{{ __('workbench.costing.packaging.item') }}</div>
 <div class="bg-[var(--color-field-muted)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">{{ __('workbench.costing.packaging.quantity_per_unit') }}</div>
 <div class="bg-[var(--color-field-muted)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">{{ __('workbench.costing.packaging.unit_price') }}</div>
 <div class="bg-[var(--color-field-muted)] px-4 py-3 font-medium text-[var(--color-ink-strong)]" x-text="t('costing.packaging.cost_per_unit', { currency: costingCurrency })"></div>
 <div class="bg-[var(--color-field-muted)] px-4 py-3 font-medium text-[var(--color-ink-strong)]" x-text="t('costing.packaging.batch_cost', { currency: costingCurrency })"></div>
 </div>

 <div class="divide-y divide-[var(--color-line)] bg-white">
 <template x-for="row in displayedPackagingCostRows" :key="row.id">
 <div class="grid grid-cols-[minmax(0,1.8fr)_9rem_9rem_9rem_9rem] gap-px bg-[var(--color-line)] text-sm">
 <div class="flex items-center bg-white px-4 py-3">
 <p class="font-medium text-[var(--color-ink-strong)]" x-text="row.name"></p>
 <p x-show="row.material_code" x-text="row.material_code" class="mt-1 font-mono text-xs font-medium text-[var(--color-ink-soft)]"></p>
 </div>
 <div class="numeric flex items-center bg-white px-4 py-3 text-[var(--color-ink-soft)]">
 <span class="sk-decimal-aligned" :style="decimalAlignmentStyle(row.quantity)" x-text="formatPackagingQuantity(row.quantity)"></span>
 </div>
 <div class="flex items-center bg-white px-3 py-3">
 <input
 :value="row.unit_cost === null || row.unit_cost === '' ? '' : format(row.unit_cost, 2)"
 @change="updatePackagingUnitCost(row, $event.target.value)"
 @blur="$event.target.value = row.unit_cost === null || row.unit_cost === '' ? '' : format(row.unit_cost, 2)"
 type="text"
 inputmode="decimal"
 :aria-label="t('costing.accessibility.unit_price_for', { item: row.name })"
 :style="decimalAlignmentStyle(row.unit_cost)"
 class="numeric sk-decimal-aligned w-full rounded-xl border border-[var(--color-line)] bg-[var(--color-field)] py-2 text-sm text-[var(--color-ink-strong)] transition"
 />
 </div>
 <div class="numeric flex items-center bg-white px-4 py-3 font-medium text-[var(--color-ink-strong)]"><span class="sk-decimal-aligned" :style="decimalAlignmentStyle(packagingCostPerFinishedUnitForRow(row))" x-text="format(packagingCostPerFinishedUnitForRow(row), 2)"></span></div>
 <div class="numeric flex items-center bg-white px-4 py-3 font-medium text-[var(--color-ink-strong)]">
     <template x-if="costingUnitsProducedValue > 0"><span class="sk-decimal-aligned inline-flex" :style="decimalAlignmentStyle(packagingBatchCostForRow(row))" x-text="format(packagingBatchCostForRow(row), 2)"></span></template>
     <template x-if="costingUnitsProducedValue <= 0"><span x-text="t('costing.summary.enter_finished_units')"></span></template>
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

 <section class="sk-card p-5">
 <p class="sk-eyebrow">{{ __('workbench.costing.summary.title') }}</p>
 <div class="mt-4 grid gap-2 lg:grid-cols-2 xl:grid-cols-4 xl:gap-3 text-sm">
 <div class="flex items-center justify-between rounded-lg bg-[var(--color-field)] px-4 py-3 xl:flex-col xl:items-start xl:justify-start xl:gap-2">
 <span class="text-[var(--color-ink-soft)]">{{ __('workbench.costing.summary.ingredients') }}</span>
 <span class="numeric inline-flex items-center gap-1 font-medium text-[var(--color-ink-strong)]"><span x-text="costingCurrency"></span><span class="sk-decimal-aligned" :style="decimalAlignmentStyle(ingredientCostTotal)" x-text="format(ingredientCostTotal, 2)"></span></span>
 </div>
 <div class="flex items-center justify-between rounded-lg bg-[var(--color-field)] px-4 py-3 xl:flex-col xl:items-start xl:justify-start xl:gap-2">
 <span class="text-[var(--color-ink-soft)]">{{ __('workbench.costing.summary.packaging') }}</span>
 <span class="numeric font-medium text-[var(--color-ink-strong)]" x-text="packagingCostTotal !== null ? `${costingCurrency} ${format(packagingCostTotal, 2)}` : t('costing.summary.enter_finished_units')"></span>
 </div>
 <div class="flex items-center justify-between rounded-lg border border-[var(--color-line-strong)] bg-[var(--color-panel-strong)] px-4 py-3 xl:flex-col xl:items-start xl:justify-start xl:gap-2">
 <span class="text-[var(--color-ink-strong)]">{{ __('workbench.costing.summary.total_batch_cost') }}</span>
 <span class="numeric font-semibold text-[var(--color-ink-strong)]" x-text="totalBatchCost !== null ? `${costingCurrency} ${format(totalBatchCost, 2)}` : t('costing.summary.enter_finished_units')"></span>
 </div>
 <div class="flex items-center justify-between rounded-lg bg-[var(--color-field)] px-4 py-3 xl:flex-col xl:items-start xl:justify-start xl:gap-2">
 <span class="text-[var(--color-ink-soft)]">{{ __('workbench.costing.summary.cost_per_unit') }}</span>
 <span class="numeric font-medium text-[var(--color-ink-strong)]" x-text="costingUnitsProducedValue > 0 ? `${costingCurrency} ${format(costPerUnit, 2)}` : t('costing.summary.enter_finished_units')"></span>
 </div>
 </div>
 </section>

 <template x-if="!hasSavedRecipe">
 <div class="rounded-[1.5rem] border border-[var(--color-line)] bg-[var(--color-panel-strong)] px-4 py-3 text-sm text-[var(--color-ink-strong)]">
 {{ __('workbench.costing.messages.save_product') }}
 </div>
 </template>

    <x-workflow-action-bar max-width="max-w-app" data-costing-save-bar>
 <x-slot:leading>
 <p class="text-sm text-[var(--color-ink-soft)]" role="status" x-text="costingSaveMessage || t('costing.settings.saved_automatically')"></p>
 </x-slot:leading>
 <button type="button" @click="persistCosting()" :disabled="!hasCurrentFormula || isSavingCosting" class="sk-btn sk-btn-primary">
 <span x-text="isSavingCosting ? t('header.saving') : t('header.save')"></span>
 </button>
 </x-workflow-action-bar>
</div>
