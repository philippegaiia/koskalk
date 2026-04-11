<section class="rounded-xl bg-[var(--color-panel)] shadow-[0_2px_4px_rgba(60,50,30,0.04),0_12px_24px_rgba(60,50,30,0.08)]">
 <div class="p-5">
 <div class="grid gap-4 xl:grid-cols-5">
 <div class="rounded-lg bg-[var(--color-panel-strong)] p-4">
 <p class="text-[0.6875rem] font-medium tracking-[0.05em] text-[var(--color-ink-soft)] uppercase">Lye type</p>
 <div class="mt-3 flex flex-wrap gap-2">
 <button type="button" @click="lyeType = 'naoh'" :class="lyeType === 'naoh' ? 'bg-[var(--color-accent)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-3 py-2 text-xs font-medium transition">NaOH</button>
 <button type="button" @click="lyeType = 'koh'" :class="lyeType === 'koh' ? 'bg-[var(--color-accent)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-3 py-2 text-xs font-medium transition">KOH</button>
 <button type="button" @click="lyeType = 'dual'" :class="lyeType === 'dual' ? 'bg-[var(--color-accent)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-3 py-2 text-xs font-medium transition">Dual Lye</button>
 </div>
 <template x-if="lyeType === 'dual'">
 <div class="mt-3 rounded-lg bg-[var(--color-panel-strong)] p-3">
 <div class="flex items-center justify-between gap-3">
 <span class="text-xs font-medium text-[var(--color-ink-soft)]">NaOH <span x-text="`${format(dualNaohPercentage, 1)}%`"></span></span>
 <span class="text-xs font-medium text-[var(--color-ink-soft)]">KOH <span x-text="`${format(dualKohPercentage, 1)}%`"></span></span>
 </div>
 <input x-model.number="dualKohPercentage" type="range" min="0" max="100" step="1" class="mt-3 w-full accent-[var(--color-accent)]" />
 </div>
 </template>
 <template x-if="lyeType === 'koh' || lyeType === 'dual'">
 <div class="mt-3 flex flex-wrap gap-2">
 <button type="button" @click="kohPurity = 100" :class="kohPurity === 100 ? 'bg-[var(--color-accent)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-3 py-2 text-xs font-medium transition">KOH 100%</button>
 <button type="button" @click="kohPurity = 90" :class="kohPurity === 90 ? 'bg-[var(--color-accent)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-3 py-2 text-xs font-medium transition">KOH 90%</button>
 </div>
 </template>
 </div>
 <div class="rounded-lg bg-[var(--color-panel-strong)] p-4">
 <p class="text-[0.6875rem] font-medium tracking-[0.05em] text-[var(--color-ink-soft)] uppercase">Weight of oils</p>
 <div class="mt-3 flex gap-2">
 <button type="button" @click="oilUnit = 'g'" :class="oilUnit === 'g' ? 'bg-[var(--color-accent)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-3 py-2 text-xs font-medium transition">g</button>
 <button type="button" @click="oilUnit = 'oz'" :class="oilUnit === 'oz' ? 'bg-[var(--color-accent)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-3 py-2 text-xs font-medium transition">oz</button>
 <button type="button" @click="oilUnit = 'lb'" :class="oilUnit === 'lb' ? 'bg-[var(--color-accent)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-3 py-2 text-xs font-medium transition">lb</button>
 </div>
 <input x-model="oilWeight" @keydown="handleDecimalKeydown($event)" @blur="normalizeDecimalBlur($event); oilWeight = nonNegativeNumber($event.target.value)" type="text" inputmode="decimal" class="mt-3 w-full rounded-lg bg-[var(--color-panel-strong)] px-4 py-3 text-sm text-[var(--color-ink-strong)] outline-none" />
 <div class="mt-4 border-t border-[var(--color-line)] pt-4">
 <p class="text-[0.6875rem] font-medium tracking-[0.05em] text-[var(--color-ink-soft)] uppercase">Entry mode</p>
 <div class="mt-3 flex flex-wrap gap-2">
 <button type="button" @click="editMode = 'percentage'" :class="editMode === 'percentage' ? 'bg-[var(--color-accent)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-3 py-2 text-xs font-medium transition">% of oils</button>
 <button type="button" @click="editMode = 'weight'" :class="editMode === 'weight' ? 'bg-[var(--color-accent)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-3 py-2 text-xs font-medium transition">Weight</button>
 </div>
 </div>
 </div>
 <div class="rounded-lg bg-[var(--color-panel-strong)] p-4">
 <p class="text-[0.6875rem] font-medium tracking-[0.05em] text-[var(--color-ink-soft)] uppercase">Water mode</p>
 <div class="mt-3 grid gap-2">
 <button type="button" @click="waterMode = 'percent_of_oils'" :class="waterMode === 'percent_of_oils' ? 'bg-[var(--color-accent)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-lg px-3 py-2 text-left text-xs font-medium transition">Water as % of oils</button>
 <button type="button" @click="waterMode = 'lye_ratio'" :class="waterMode === 'lye_ratio' ? 'bg-[var(--color-accent)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-lg px-3 py-2 text-left text-xs font-medium transition">Water : lye ratio</button>
 <button type="button" @click="waterMode = 'lye_concentration'" :class="waterMode === 'lye_concentration' ? 'bg-[var(--color-accent)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-lg px-3 py-2 text-left text-xs font-medium transition">Lye concentration</button>
 </div>
 <input x-model="waterValue" @keydown="handleDecimalKeydown($event)" @blur="normalizeDecimalBlur($event); waterValue = nonNegativeNumber($event.target.value)" type="text" inputmode="decimal" class="mt-3 w-full rounded-lg bg-[var(--color-panel-strong)] px-4 py-3 text-sm text-[var(--color-ink-strong)] outline-none" />
 </div>
 <div class="rounded-lg bg-[var(--color-panel-strong)] p-4">
 <p class="text-[0.6875rem] font-medium tracking-[0.05em] text-[var(--color-ink-soft)] uppercase">Superfat</p>
 <div class="mt-3 flex items-center justify-between gap-3 text-sm">
 <span :class="superfat < 0 ? 'text-[var(--color-danger-strong)]' : 'text-[var(--color-ink-soft)]'" class="font-medium">Current</span>
 <span :class="superfat < 0 ? 'text-[var(--color-danger-strong)]' : 'text-[var(--color-ink-strong)]'" class="font-semibold" x-text="`${format(superfat, 1)}%`"></span>
 </div>
 <input x-model.number="superfat" @change="confirmNegativeSuperfat($event)" type="range" min="-20" max="20" step="0.5" :class="superfat < 0 ? 'accent-[var(--color-danger)]' : 'accent-[var(--color-accent)]'" class="mt-3 w-full" />
 <input x-model="superfat" @keydown="handleDecimalKeydown($event)" @blur="normalizeDecimalBlur($event)" @change="confirmNegativeSuperfat($event)" type="text" inputmode="decimal" :class="superfat < 0 ? 'border-[var(--color-danger-soft)] text-[var(--color-danger-strong)]' : 'border-[var(--color-line)] text-[var(--color-ink-strong)]'" class="mt-3 w-full rounded-lg border bg-white px-4 py-3 text-sm outline-none" />
 </div>
 <div class="rounded-lg bg-[var(--color-panel-strong)] p-4">
 <p class="text-[0.6875rem] font-medium tracking-[0.05em] text-[var(--color-ink-soft)] uppercase">Exposure</p>
 <div class="mt-3 flex flex-wrap gap-2">
 <button type="button" @click="exposureMode = 'rinse_off'" :class="exposureMode === 'rinse_off' ? 'bg-[var(--color-ink-strong)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-3 py-2 text-xs font-medium transition">Rinse-off</button>
 <button type="button" @click="exposureMode = 'leave_on'" :class="exposureMode === 'leave_on' ? 'bg-[var(--color-ink-strong)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-3 py-2 text-xs font-medium transition">Leave-on</button>
 </div>
 <div class="mt-4 border-t border-[var(--color-line)] pt-4">
 <p class="text-[0.6875rem] font-medium tracking-[0.05em] text-[var(--color-ink-soft)] uppercase">IFRA context</p>
 <template x-if="$data.ifraProductCategories?.length">
 <select x-model="selectedIfraProductCategoryId" class="mt-3 w-full rounded-lg bg-[var(--color-panel-strong)] px-3 py-2.5 text-sm text-[var(--color-ink-strong)] outline-none transition focus:border-[var(--color-line-strong)]">
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
</section>
