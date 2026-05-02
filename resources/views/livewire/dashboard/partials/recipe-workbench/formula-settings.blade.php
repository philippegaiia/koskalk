@php($isCosmeticWorkbench = $isCosmeticWorkbench ?? false)

<section class="sk-card">
@if ($isCosmeticWorkbench)
	 <div class="p-5">
	 <div class="grid gap-4 lg:grid-cols-2 xl:grid-cols-4">
	 <div class="sk-inset p-4">
	 <p id="setting-batch-weight" class="sk-eyebrow">Batch weight</p>
	 <div role="radiogroup" aria-label="Weight unit" class="mt-3 flex gap-2">
	 <button type="button" role="radio" :aria-checked="oilUnit === 'g'" @click="oilUnit = 'g'" :class="oilUnit === 'g' ? 'bg-[var(--color-accent)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-4 py-2.5 text-xs font-medium transition">g</button>
	 <button type="button" role="radio" :aria-checked="oilUnit === 'oz'" @click="oilUnit = 'oz'" :class="oilUnit === 'oz' ? 'bg-[var(--color-accent)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-4 py-2.5 text-xs font-medium transition">oz</button>
	 <button type="button" role="radio" :aria-checked="oilUnit === 'lb'" @click="oilUnit = 'lb'" :class="oilUnit === 'lb' ? 'bg-[var(--color-accent)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-4 py-2.5 text-xs font-medium transition">lb</button>
	 </div>
	 <input aria-labelledby="setting-batch-weight" x-model="oilWeight" @keydown="handleDecimalKeydown($event)" @blur="normalizeDecimalBlur($event); oilWeight = nonNegativeNumber($event.target.value)" type="text" inputmode="decimal" class="numeric mt-3 w-full rounded-lg bg-[var(--color-field)] px-4 py-3 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]" />
	 </div>
	 <div class="sk-inset p-4">
	 <p id="setting-entry-mode" class="sk-eyebrow">Entry mode</p>
	 <div role="radiogroup" aria-label="Entry mode" class="mt-3 flex flex-wrap gap-2">
	 <button type="button" role="radio" :aria-checked="editMode === 'percentage'" @click="editMode = 'percentage'" :class="editMode === 'percentage' ? 'bg-[var(--color-accent)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-4 py-2.5 text-xs font-medium transition">% formula</button>
	 <button type="button" role="radio" :aria-checked="editMode === 'weight'" @click="editMode = 'weight'" :class="editMode === 'weight' ? 'bg-[var(--color-accent)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-4 py-2.5 text-xs font-medium transition">Weight</button>
	 </div>
	 </div>
	 <div class="sk-inset p-4">
	 <p id="setting-exposure" class="sk-eyebrow">Exposure</p>
	 <div role="radiogroup" aria-label="Exposure type" class="mt-3 flex flex-wrap gap-2">
	 <button type="button" role="radio" :aria-checked="exposureMode === 'rinse_off'" @click="exposureMode = 'rinse_off'" :class="exposureMode === 'rinse_off' ? 'bg-[var(--color-accent)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-4 py-2.5 text-xs font-medium transition">Rinse-off</button>
	 <button type="button" role="radio" :aria-checked="exposureMode === 'leave_on'" @click="exposureMode = 'leave_on'" :class="exposureMode === 'leave_on' ? 'bg-[var(--color-accent)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-4 py-2.5 text-xs font-medium transition">Leave-on</button>
	 </div>
	 </div>
	 <div class="sk-inset p-4">
	 <p id="setting-ifra" class="sk-eyebrow">IFRA context</p>
	 <template x-if="$data.ifraProductCategories?.length">
	 <select aria-labelledby="setting-ifra" :value="`${selectedIfraProductCategoryId ?? ''}`" @change="selectedIfraProductCategoryId = $event.target.value" class="mt-3 w-full rounded-lg bg-[var(--color-field)] px-3 py-2.5 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]">
	 <option value="">No IFRA context</option>
 <template x-for="category in $data.ifraProductCategories" :key="category.id">
 <option :value="String(category.id)" x-text="category.short_name ? `Cat ${category.code} - ${category.short_name}` : `Cat ${category.code}`"></option>
 </template>
 </select>
 </template>
 <template x-if="! $data.ifraProductCategories?.length">
 <p class="mt-3 text-xs text-[var(--color-ink-soft)]">IFRA categories appear once the compliance catalog is populated.</p>
 </template>
 <template x-if="selectedIfraProductCategory">
	 <span class="mt-2 inline-block rounded-full border border-[var(--color-accent)] bg-[var(--color-accent-soft)] px-2.5 py-1 text-xs font-medium text-[var(--color-ink-strong)]" x-text="`Cat ${selectedIfraProductCategory.code}`"></span>
	 </template>
	 </div>
	 </div>
	 </div>
@else
 <div class="p-5">
 <div class="grid gap-4 xl:grid-cols-5">
 <div class="sk-inset p-4">
 <p id="setting-lye-type" class="sk-eyebrow">Lye type</p>
 <div role="radiogroup" aria-label="Lye type" class="mt-3 flex flex-wrap gap-2">
 <button type="button" role="radio" :aria-checked="lyeType === 'naoh'" @click="lyeType = 'naoh'" :class="lyeType === 'naoh' ? 'bg-[var(--color-accent)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-4 py-2.5 text-xs font-medium transition">NaOH</button>
 <button type="button" role="radio" :aria-checked="lyeType === 'koh'" @click="lyeType = 'koh'" :class="lyeType === 'koh' ? 'bg-[var(--color-accent)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-4 py-2.5 text-xs font-medium transition">KOH</button>
 <button type="button" role="radio" :aria-checked="lyeType === 'dual'" @click="lyeType = 'dual'" :class="lyeType === 'dual' ? 'bg-[var(--color-accent)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-4 py-2.5 text-xs font-medium transition">Dual Lye</button>
 </div>
 <template x-if="lyeType === 'dual'">
 <div class="mt-3 sk-inset p-3">
 <div class="flex items-center justify-between gap-3">
 <span class="text-xs font-medium text-[var(--color-ink-soft)]">NaOH <span class="numeric" x-text="`${format(dualNaohPercentage, 1)}%`"></span></span>
 <span class="text-xs font-medium text-[var(--color-ink-soft)]">KOH <span class="numeric" x-text="`${format(dualKohPercentage, 1)}%`"></span></span>
 </div>
 <input aria-label="NaOH to KOH ratio" x-model.number="dualKohPercentage" type="range" min="0" max="100" step="1" class="mt-3 w-full accent-[var(--color-accent)]" />
 </div>
 </template>
 <template x-if="lyeType === 'koh' || lyeType === 'dual'">
 <div role="radiogroup" aria-label="KOH purity" class="mt-3 flex flex-wrap gap-2">
 <button type="button" role="radio" :aria-checked="kohPurity === 100" @click="kohPurity = 100" :class="kohPurity === 100 ? 'bg-[var(--color-accent)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-4 py-2.5 text-xs font-medium transition">KOH 100%</button>
 <button type="button" role="radio" :aria-checked="kohPurity === 90" @click="kohPurity = 90" :class="kohPurity === 90 ? 'bg-[var(--color-accent)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-4 py-2.5 text-xs font-medium transition">KOH 90%</button>
 </div>
 </template>
 </div>
 <div class="sk-inset p-4">
 <p id="setting-base-weight" class="sk-eyebrow">Base ingredient weight</p>
 <div role="radiogroup" aria-label="Weight unit" class="mt-3 flex gap-2">
 <button type="button" role="radio" :aria-checked="oilUnit === 'g'" @click="oilUnit = 'g'" :class="oilUnit === 'g' ? 'bg-[var(--color-accent)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-4 py-2.5 text-xs font-medium transition">g</button>
 <button type="button" role="radio" :aria-checked="oilUnit === 'oz'" @click="oilUnit = 'oz'" :class="oilUnit === 'oz' ? 'bg-[var(--color-accent)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-4 py-2.5 text-xs font-medium transition">oz</button>
 <button type="button" role="radio" :aria-checked="oilUnit === 'lb'" @click="oilUnit = 'lb'" :class="oilUnit === 'lb' ? 'bg-[var(--color-accent)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-4 py-2.5 text-xs font-medium transition">lb</button>
 </div>
 <input aria-labelledby="setting-base-weight" x-model="oilWeight" @keydown="handleDecimalKeydown($event)" @blur="normalizeDecimalBlur($event); oilWeight = nonNegativeNumber($event.target.value)" type="text" inputmode="decimal" class="numeric mt-3 w-full rounded-lg bg-[var(--color-field)] px-4 py-3 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]" />
 <div class="mt-4 border-t border-[var(--color-line)] pt-4">
 <p id="setting-entry-mode-soap" class="sk-eyebrow">Entry mode</p>
 <div role="radiogroup" aria-label="Entry mode" class="mt-3 flex flex-wrap gap-2">
 <button type="button" role="radio" :aria-checked="editMode === 'percentage'" @click="editMode = 'percentage'" :class="editMode === 'percentage' ? 'bg-[var(--color-accent)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-4 py-2.5 text-xs font-medium transition">% of base</button>
 <button type="button" role="radio" :aria-checked="editMode === 'weight'" @click="editMode = 'weight'" :class="editMode === 'weight' ? 'bg-[var(--color-accent)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-4 py-2.5 text-xs font-medium transition">Weight</button>
 </div>
 </div>
 </div>
 <div class="sk-inset p-4">
 <p id="setting-water-mode" class="sk-eyebrow">Water mode</p>
 <div role="radiogroup" aria-label="Water calculation mode" class="mt-3 grid gap-2">
 <button type="button" role="radio" :aria-checked="waterMode === 'percent_of_oils'" @click="waterMode = 'percent_of_oils'" :class="waterMode === 'percent_of_oils' ? 'bg-[var(--color-accent)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-lg px-4 py-2.5 text-left text-xs font-medium transition">Water as % of oils</button>
 <button type="button" role="radio" :aria-checked="waterMode === 'lye_ratio'" @click="waterMode = 'lye_ratio'" :class="waterMode === 'lye_ratio' ? 'bg-[var(--color-accent)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-lg px-4 py-2.5 text-left text-xs font-medium transition">Water : lye ratio</button>
 <button type="button" role="radio" :aria-checked="waterMode === 'lye_concentration'" @click="waterMode = 'lye_concentration'" :class="waterMode === 'lye_concentration' ? 'bg-[var(--color-accent)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-lg px-4 py-2.5 text-left text-xs font-medium transition">Lye concentration</button>
 </div>
 <input aria-labelledby="setting-water-mode" x-model="waterValue" @keydown="handleDecimalKeydown($event)" @blur="normalizeDecimalBlur($event); waterValue = nonNegativeNumber($event.target.value)" type="text" inputmode="decimal" class="numeric mt-3 w-full rounded-lg bg-[var(--color-field)] px-4 py-3 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]" />
 </div>
 <div class="sk-inset p-4">
 <p id="setting-superfat" class="sk-eyebrow">Superfat</p>
 <div class="mt-3 flex items-center justify-between gap-3 text-sm">
 <span :class="superfat < 0 ? 'text-[var(--color-danger-strong)]' : 'text-[var(--color-ink-soft)]'" class="font-medium">Current</span>
 <span :class="superfat < 0 ? 'text-[var(--color-danger-strong)]' : 'text-[var(--color-ink-strong)]'" class="numeric font-semibold" x-text="`${format(superfat, 1)}%`"></span>
 </div>
 <input aria-labelledby="setting-superfat" x-model.number="superfat" @change="confirmNegativeSuperfat($event)" type="range" min="-20" max="20" step="0.5" :class="superfat < 0 ? 'accent-[var(--color-danger)]' : 'accent-[var(--color-accent)]'" class="mt-3 w-full" />
 <input aria-labelledby="setting-superfat" x-model="superfat" @keydown="handleDecimalKeydown($event)" @blur="normalizeDecimalBlur($event)" @change="confirmNegativeSuperfat($event)" type="text" inputmode="decimal" :class="superfat < 0 ? 'border-[var(--color-danger-soft)] text-[var(--color-danger-strong)]' : 'border-[var(--color-line)] text-[var(--color-ink-strong)]'" class="numeric mt-3 w-full rounded-lg border bg-[var(--color-field)] px-4 py-3 text-sm outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]" />
 </div>
 <div class="sk-inset p-4">
 <p id="setting-exposure-soap" class="sk-eyebrow">Exposure</p>
 <div role="radiogroup" aria-label="Exposure type" class="mt-3 flex flex-wrap gap-2">
 <button type="button" role="radio" :aria-checked="exposureMode === 'rinse_off'" @click="exposureMode = 'rinse_off'" :class="exposureMode === 'rinse_off' ? 'bg-[var(--color-accent)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-4 py-2.5 text-xs font-medium transition">Rinse-off</button>
 <button type="button" role="radio" :aria-checked="exposureMode === 'leave_on'" @click="exposureMode = 'leave_on'" :class="exposureMode === 'leave_on' ? 'bg-[var(--color-accent)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-4 py-2.5 text-xs font-medium transition">Leave-on</button>
 </div>
 <div class="mt-4 border-t border-[var(--color-line)] pt-4">
 <p id="setting-ifra-soap" class="sk-eyebrow">IFRA context</p>
 <template x-if="$data.ifraProductCategories?.length">
 <select aria-labelledby="setting-ifra-soap" :value="`${selectedIfraProductCategoryId ?? ''}`" @change="selectedIfraProductCategoryId = $event.target.value" class="mt-3 w-full rounded-lg bg-[var(--color-field)] px-3 py-2.5 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]">
 <option value="">No IFRA context</option>
 <template x-for="category in $data.ifraProductCategories" :key="category.id">
 <option :value="String(category.id)" x-text="category.short_name ? `Cat ${category.code} - ${category.short_name}` : `Cat ${category.code}`"></option>
 </template>
 </select>
 </template>
 <template x-if="! $data.ifraProductCategories?.length">
 <p class="mt-3 text-xs text-[var(--color-ink-soft)]">IFRA categories appear once the compliance catalog is populated.</p>
 </template>
 <template x-if="selectedIfraProductCategory">
 <span class="mt-2 inline-block rounded-full border border-[var(--color-accent)] bg-[var(--color-accent-soft)] px-2.5 py-1 text-xs font-medium text-[var(--color-ink-strong)]" x-text="`Cat ${selectedIfraProductCategory.code}`"></span>
 </template>
 </div>
 </div>
 </div>
 </div>
@endif
</section>
