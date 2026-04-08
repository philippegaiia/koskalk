<section class="rounded-[2rem] border border-[var(--color-line)] bg-white">
    <div class="border-b border-[var(--color-line)] px-5 py-4">
        <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Formula settings</p>
        <div class="mt-3 rounded-[1.5rem] border border-[var(--color-line)] bg-[var(--color-panel)] p-4">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div class="space-y-2">
                    <p class="text-xs font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase">Formula context</p>
                    <p class="text-sm text-[var(--color-ink-soft)]">Rinse-off versus leave-on is saved on the formula version because it affects compliance interpretation and future INCI behavior.</p>
                    <div class="flex flex-wrap gap-2 text-xs text-[var(--color-ink-soft)]">
                        <span class="rounded-full border border-[var(--color-line)] bg-white px-3 py-1.5 font-medium" x-text="manufacturingModeLabel"></span>
                        <span class="rounded-full border border-[var(--color-line)] bg-white px-3 py-1.5 font-medium" x-text="`Regime: ${regulatoryRegime.toUpperCase()}`"></span>
                    </div>
                </div>
                <div class="min-w-0 rounded-[1.25rem] border border-[var(--color-line)] bg-white p-3">
                    <p class="text-xs font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase">Exposure mode</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <button type="button" @click="exposureMode = 'rinse_off'" :class="exposureMode === 'rinse_off' ? 'bg-[var(--color-ink-strong)] text-white' : 'bg-[var(--color-panel)] text-[var(--color-ink-soft)]'" class="rounded-full px-3 py-2 text-xs font-medium transition">Rinse-off</button>
                        <button type="button" @click="exposureMode = 'leave_on'" :class="exposureMode === 'leave_on' ? 'bg-[var(--color-ink-strong)] text-white' : 'bg-[var(--color-panel)] text-[var(--color-ink-soft)]'" class="rounded-full px-3 py-2 text-xs font-medium transition">Leave-on</button>
                    </div>
                </div>
            </div>
            <div class="mt-4 rounded-[1.25rem] border border-[var(--color-line)] bg-white p-4">
                <details class="group" x-data="{ open: false }" @toggle="open = $event.target.open">
                    <summary class="flex cursor-pointer list-none items-center justify-between gap-4">
                        <div>
                            <p class="text-xs font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase">IFRA context</p>
                            <p class="mt-1 text-sm text-[var(--color-ink-soft)]" x-text="selectedIfraProductCategory ? `Optional screening for soap-oriented IFRA categories. Current: Cat ${selectedIfraProductCategory.code}${selectedIfraProductCategory.short_name ? ` - ${selectedIfraProductCategory.short_name}` : ''}.` : 'Optional screening only. Open this when you want IFRA-oriented guidance on the formula.'"></p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <template x-if="selectedIfraProductCategory">
                                <span class="rounded-full border border-[var(--color-accent)] bg-[var(--color-accent-soft)] px-3 py-1.5 text-xs font-medium text-[var(--color-ink-strong)]" x-text="`Cat ${selectedIfraProductCategory.code}`"></span>
                            </template>
                            <span class="rounded-full border border-[var(--color-line)] bg-[var(--color-panel)] px-3 py-1.5 text-xs font-medium text-[var(--color-ink-soft)]" x-text="open ? 'Hide' : 'Open'"></span>
                        </div>
                    </summary>

                    <template x-if="$data.ifraProductCategories?.length">
                        <div class="mt-4 border-t border-[var(--color-line)] pt-4">
                            <select x-model="selectedIfraProductCategoryId" class="w-full rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] px-4 py-3 text-sm text-[var(--color-ink-strong)] outline-none transition focus:border-[var(--color-line-strong)]">
                                <option value="">No IFRA context</option>
                                <template x-for="category in $data.ifraProductCategories" :key="category.id">
                                    <option :value="String(category.id)" x-text="category.short_name ? `Cat ${category.code} - ${category.short_name}` : `Cat ${category.code}`"></option>
                                </template>
                            </select>
                            <p class="mt-3 text-xs leading-5 text-[var(--color-ink-soft)]">For soap workbench use, the list is intentionally reduced to the most relevant categories: 6, 7A, 8, 9, and 10A.</p>
                            <template x-if="selectedIfraProductCategory?.description">
                                <p class="mt-3 text-sm leading-6 text-[var(--color-ink-soft)]" x-text="selectedIfraProductCategory.description"></p>
                            </template>
                        </div>
                    </template>
                    <template x-if="! $data.ifraProductCategories?.length">
                        <p class="mt-3 text-sm text-[var(--color-ink-soft)]">IFRA categories can be selected once the compliance catalog is populated.</p>
                    </template>
                </details>
            </div>
        </div>

        <div class="mt-4 grid gap-4 xl:grid-cols-4">
            <div class="rounded-[1.5rem] border border-[var(--color-line)] bg-[var(--color-panel)] p-4">
                <p class="text-xs font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase">Lye type</p>
                <div class="mt-3 flex flex-wrap gap-2">
                    <button type="button" @click="lyeType = 'naoh'" :class="lyeType === 'naoh' ? 'bg-[var(--color-accent-strong)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-3 py-2 text-xs font-medium transition">NaOH</button>
                    <button type="button" @click="lyeType = 'koh'" :class="lyeType === 'koh' ? 'bg-[var(--color-accent-strong)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-3 py-2 text-xs font-medium transition">KOH</button>
                    <button type="button" @click="lyeType = 'dual'" :class="lyeType === 'dual' ? 'bg-[var(--color-accent-strong)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-3 py-2 text-xs font-medium transition">Dual Lye</button>
                </div>
                <template x-if="lyeType === 'dual'">
                    <div class="mt-3 rounded-2xl border border-[var(--color-line)] bg-white p-3">
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-xs font-medium text-[var(--color-ink-soft)]">NaOH <span x-text="`${format(dualNaohPercentage, 1)}%`"></span></span>
                            <span class="text-xs font-medium text-[var(--color-ink-soft)]">KOH <span x-text="`${format(dualKohPercentage, 1)}%`"></span></span>
                        </div>
                        <input x-model.number="dualKohPercentage" type="range" min="0" max="100" step="1" class="mt-3 w-full accent-[var(--color-accent-strong)]" />
                    </div>
                </template>
                <template x-if="lyeType === 'koh' || lyeType === 'dual'">
                    <div class="mt-3 flex flex-wrap gap-2">
                        <button type="button" @click="kohPurity = 100" :class="kohPurity === 100 ? 'bg-[var(--color-accent-strong)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-3 py-2 text-xs font-medium transition">KOH 100%</button>
                        <button type="button" @click="kohPurity = 90" :class="kohPurity === 90 ? 'bg-[var(--color-accent-strong)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-3 py-2 text-xs font-medium transition">KOH 90%</button>
                    </div>
                </template>
            </div>
            <div class="rounded-[1.5rem] border border-[var(--color-line)] bg-[var(--color-panel)] p-4">
                <p class="text-xs font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase">Weight of oils</p>
                <div class="mt-3 flex gap-2">
                    <button type="button" @click="oilUnit = 'g'" :class="oilUnit === 'g' ? 'bg-[var(--color-accent-strong)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-3 py-2 text-xs font-medium transition">g</button>
                    <button type="button" @click="oilUnit = 'oz'" :class="oilUnit === 'oz' ? 'bg-[var(--color-accent-strong)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-3 py-2 text-xs font-medium transition">oz</button>
                    <button type="button" @click="oilUnit = 'lb'" :class="oilUnit === 'lb' ? 'bg-[var(--color-accent-strong)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-3 py-2 text-xs font-medium transition">lb</button>
                </div>
                <input x-model.number="oilWeight" type="number" min="0" step="1" class="mt-3 w-full rounded-2xl border border-[var(--color-line)] bg-white px-4 py-3 text-sm text-[var(--color-ink-strong)] outline-none" />
                <div class="mt-4 border-t border-[var(--color-line)] pt-4">
                    <p class="text-xs font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase">Entry mode</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <button type="button" @click="editMode = 'percentage'" :class="editMode === 'percentage' ? 'bg-[var(--color-accent-strong)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-3 py-2 text-xs font-medium transition">% of oils</button>
                        <button type="button" @click="editMode = 'weight'" :class="editMode === 'weight' ? 'bg-[var(--color-accent-strong)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-3 py-2 text-xs font-medium transition">Weight</button>
                    </div>
                </div>
            </div>
            <div class="rounded-[1.5rem] border border-[var(--color-line)] bg-[var(--color-panel)] p-4">
                <p class="text-xs font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase">Water mode</p>
                <div class="mt-3 grid gap-2">
                    <button type="button" @click="waterMode = 'percent_of_oils'" :class="waterMode === 'percent_of_oils' ? 'bg-[var(--color-accent-strong)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-2xl px-3 py-2 text-left text-xs font-medium transition">Water as % of oils</button>
                    <button type="button" @click="waterMode = 'lye_ratio'" :class="waterMode === 'lye_ratio' ? 'bg-[var(--color-accent-strong)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-2xl px-3 py-2 text-left text-xs font-medium transition">Water : lye ratio</button>
                    <button type="button" @click="waterMode = 'lye_concentration'" :class="waterMode === 'lye_concentration' ? 'bg-[var(--color-accent-strong)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-2xl px-3 py-2 text-left text-xs font-medium transition">Lye concentration</button>
                </div>
                <input x-model.number="waterValue" type="number" min="0" step="0.1" class="mt-3 w-full rounded-2xl border border-[var(--color-line)] bg-white px-4 py-3 text-sm text-[var(--color-ink-strong)] outline-none" />
            </div>
            <div class="rounded-[1.5rem] border border-[var(--color-line)] bg-[var(--color-panel)] p-4">
                <p class="text-xs font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase">Superfat</p>
                <div class="mt-3 flex items-center justify-between gap-3 text-sm">
                    <span :class="superfat < 0 ? 'text-red-600' : 'text-[var(--color-ink-soft)]'" class="font-medium">Current</span>
                    <span :class="superfat < 0 ? 'text-red-600' : 'text-[var(--color-ink-strong)]'" class="font-semibold" x-text="`${format(superfat, 1)}%`"></span>
                </div>
                <input x-model.number="superfat" type="range" min="-20" max="20" step="0.5" :class="superfat < 0 ? 'accent-red-600' : 'accent-[var(--color-accent-strong)]'" class="mt-3 w-full" />
                <input x-model.number="superfat" type="number" min="-20" max="20" step="0.5" :class="superfat < 0 ? 'border-red-200 text-red-600' : 'border-[var(--color-line)] text-[var(--color-ink-strong)]'" class="mt-3 w-full rounded-2xl border bg-white px-4 py-3 text-sm outline-none" />
            </div>
        </div>
    </div>
</section>
