<section class="space-y-4">
 <div id="post-reaction-phases" class="overflow-hidden sk-card sk-phase-craft transition-shadow duration-300">
 <div class="sk-section-header border-b border-[var(--color-line)] px-5 py-4">
 <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
 <div>
 <p class="sk-eyebrow">Post-reaction phases</p>
 <h3 class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">Additives and aromatics</h3>
 </div>
 <span class="numeric rounded-full border border-[var(--color-line)] bg-[var(--color-panel)] px-3 py-1.5 text-xs font-medium text-[var(--color-ink-soft)]" x-text="`${format(totalAdditionPercentage(), 1)}% of base`"></span>
 </div>
 </div>

 <div class="space-y-5 p-5">
 <template x-if="additiveRows.length > 0">
 <div class="overflow-hidden sk-inset">
 <div class="border-b border-[var(--color-line)] px-4 py-3">
 <p class="font-medium text-[var(--color-ink-strong)]">Additives</p>
 <p class="mt-1 text-xs text-[var(--color-ink-soft)]">Colorants, preservatives, and other post-reaction functional materials. Drag to reorder the additives already in this phase.</p>
 </div>
 <div class="grid grid-cols-[2.75rem_minmax(0,1.8fr)_7rem_7rem_2.5rem] gap-px bg-[var(--color-line)] text-sm">
 <div class="bg-[var(--color-field-muted)] px-3 py-3"></div>
 <div class="bg-[var(--color-field-muted)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">Ingredient</div>
 <div class="bg-[var(--color-field-muted)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">% base</div>
 <div class="bg-[var(--color-field-muted)] px-4 py-3 font-medium text-[var(--color-ink-strong)]" x-text="`Weight (${oilUnit})`"></div>
 <div class="bg-[var(--color-field-muted)] px-4 py-3"></div>
 </div>

 <div class="divide-y divide-[var(--color-line)] bg-white">
 <template x-for="row in additiveRows" :key="row.id">
 <div @dragover="allowPhaseDrop('additives', $event, row.id)"
 @drop="dropDraggedRow('additives', $event, row.id)"
 :class="{
 'bg-[var(--color-accent-soft)]': isDropTarget('additives', row.id),
 'opacity-60': isDraggedRow('additives', row.id),
 }"
 class="grid grid-cols-[2.75rem_minmax(0,1.8fr)_7rem_7rem_2.5rem] gap-px bg-[var(--color-line)] transition">
 <div class="grid place-items-center bg-white px-2 py-3">
 <button type="button"
 draggable="true"
 @dragstart="beginRowDrag('additives', row.id, $event)"
 @dragend="endRowDrag()"
 class="grid size-7 cursor-grab place-items-center rounded-md text-[var(--color-ink-soft)] transition hover:bg-[var(--color-field-muted)] hover:text-[var(--color-ink-strong)] active:cursor-grabbing"
 aria-label="Drag to reorder or move this additive">
 <span class="text-sm leading-none">⋮⋮</span>
 </button>
 </div>
 <div class="flex flex-col justify-center bg-white px-4 py-3">
 <p class="font-medium text-[var(--color-ink-strong)]" x-text="row.name"></p>
 <p class="mt-1 text-xs text-[var(--color-ink-soft)]" x-text="row.inci_name"></p>
 </div>
 <div class="flex items-center bg-white px-3 py-3">
 <template x-if="editMode === 'percentage'">
 <input x-model="row.percentage" @keydown="handleDecimalKeydown($event)" @blur="normalizeDecimalBlur($event); row.percentage = nonNegativeNumber($event.target.value)" type="number" inputmode="decimal" step="0.1" class="numeric w-full rounded-xl border border-[var(--color-line)] bg-[var(--color-field)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]" />
 </template>
 <template x-if="editMode !== 'percentage'">
 <span class="numeric inline-flex min-h-10 items-center text-sm text-[var(--color-ink-soft)]" x-text="`${format(row.percentage, 2)}%`"></span>
 </template>
 </div>
 <div class="flex items-center bg-white px-3 py-3 text-sm text-[var(--color-ink-soft)]">
 <template x-if="editMode === 'weight'">
 <input :value="format(rowWeight(row), 3)" @input="updatePercentageFromWeight(row, $event.target.value)" @keydown="handleDecimalKeydown($event)" @blur="normalizeDecimalBlur($event)" type="number" inputmode="decimal" step="0.001" class="numeric w-full rounded-xl border border-[var(--color-line)] bg-[var(--color-field)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]" />
 </template>
 <template x-if="editMode !== 'weight'">
 <span class="numeric inline-flex min-h-10 items-center" x-text="`${format(rowWeight(row), 3)}`"></span>
 </template>
 </div>
 <div class="flex items-center justify-center bg-white px-2 py-3">
 <button type="button" @click="removeIngredient('additives', row.id)" class="grid size-8 place-items-center rounded-md text-base text-[var(--color-ink-soft)] transition hover:bg-[var(--color-danger-soft)] hover:text-[var(--color-danger-strong)]" aria-label="Remove additive">×</button>
 </div>
 </div>
 </template>
 </div>
 </div>
 </template>

 <template x-if="fragranceRows.length > 0">
 <div class="overflow-hidden sk-inset">
 <div class="border-b border-[var(--color-line)] px-4 py-3">
 <p class="font-medium text-[var(--color-ink-strong)]">Fragrance and aromatics</p>
 <p class="mt-1 text-xs text-[var(--color-ink-soft)]">Essential oils and aromatic extracts with their own compliance context. Drag to reorder inside this aromatic phase.</p>
 </div>
 <div class="grid grid-cols-[2.75rem_minmax(0,1.8fr)_7rem_7rem_2.5rem] gap-px bg-[var(--color-line)] text-sm">
 <div class="bg-[var(--color-field-muted)] px-3 py-3"></div>
 <div class="bg-[var(--color-field-muted)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">Ingredient</div>
 <div class="bg-[var(--color-field-muted)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">% base</div>
 <div class="bg-[var(--color-field-muted)] px-4 py-3 font-medium text-[var(--color-ink-strong)]" x-text="`Weight (${oilUnit})`"></div>
 <div class="bg-[var(--color-field-muted)] px-4 py-3"></div>
 </div>

 <div class="divide-y divide-[var(--color-line)] bg-white">
 <template x-for="row in fragranceRows" :key="row.id">
 <div @dragover="allowPhaseDrop('fragrance', $event, row.id)"
 @drop="dropDraggedRow('fragrance', $event, row.id)"
 :class="{
 'bg-[var(--color-accent-soft)]': isDropTarget('fragrance', row.id),
 'opacity-60': isDraggedRow('fragrance', row.id),
 }"
 class="grid grid-cols-[2.75rem_minmax(0,1.8fr)_7rem_7rem_2.5rem] gap-px bg-[var(--color-line)] transition">
 <div class="grid place-items-center bg-white px-2 py-3">
 <button type="button"
 draggable="true"
 @dragstart="beginRowDrag('fragrance', row.id, $event)"
 @dragend="endRowDrag()"
 class="grid size-7 cursor-grab place-items-center rounded-md text-[var(--color-ink-soft)] transition hover:bg-[var(--color-field-muted)] hover:text-[var(--color-ink-strong)] active:cursor-grabbing"
 aria-label="Drag to reorder this aromatic ingredient">
 <span class="text-sm leading-none">⋮⋮</span>
 </button>
 </div>
 <div class="flex flex-col justify-center bg-white px-4 py-3">
 <p class="font-medium text-[var(--color-ink-strong)]" x-text="row.name"></p>
 <p class="mt-1 text-xs text-[var(--color-ink-soft)]" x-text="row.inci_name"></p>
 </div>
 <div class="flex items-center bg-white px-3 py-3">
 <template x-if="editMode === 'percentage'">
 <input x-model="row.percentage" @keydown="handleDecimalKeydown($event)" @blur="normalizeDecimalBlur($event); row.percentage = nonNegativeNumber($event.target.value)" type="number" inputmode="decimal" step="0.1" class="numeric w-full rounded-xl border border-[var(--color-line)] bg-[var(--color-field)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]" />
 </template>
 <template x-if="editMode !== 'percentage'">
 <span class="numeric inline-flex min-h-10 items-center text-sm text-[var(--color-ink-soft)]" x-text="`${format(row.percentage, 2)}%`"></span>
 </template>
 </div>
 <div class="flex items-center bg-white px-3 py-3 text-sm text-[var(--color-ink-soft)]">
 <template x-if="editMode === 'weight'">
 <input :value="format(rowWeight(row), 3)" @input="updatePercentageFromWeight(row, $event.target.value)" @keydown="handleDecimalKeydown($event)" @blur="normalizeDecimalBlur($event)" type="number" inputmode="decimal" step="0.001" class="numeric w-full rounded-xl border border-[var(--color-line)] bg-[var(--color-field)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]" />
 </template>
 <template x-if="editMode !== 'weight'">
 <span class="numeric inline-flex min-h-10 items-center" x-text="`${format(rowWeight(row), 3)}`"></span>
 </template>
 </div>
 <div class="grid place-items-center bg-white px-2 py-3">
 <button type="button" @click="removeIngredient('fragrance', row.id)" class="grid size-8 place-items-center rounded-md text-base text-[var(--color-ink-soft)] transition hover:bg-[var(--color-danger-soft)] hover:text-[var(--color-danger-strong)]" aria-label="Remove aromatic ingredient">×</button>
 </div>
 </div>
 </template>
 </div>
 </div>
 </template>

 </div>
 </div>

 <div class="sk-card p-5">
 <div class="flex flex-col gap-2 lg:flex-row lg:items-center lg:justify-between">
 <div>
 <p class="sk-eyebrow">Batch totals</p>
 <p class="mt-1 text-sm text-[var(--color-ink-soft)]">A quick read of the current formula outputs without repeating the oil basis already shown above.</p>
 </div>
 </div>

 <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
 <template x-for="card in totalSummaryCards" :key="card.id">
 <div class="sk-inset flex h-full flex-col justify-between p-4">
 <div>
 <p class="sk-eyebrow" x-text="card.label"></p>
 </div>
 <p class="numeric pt-6 text-2xl font-semibold text-[var(--color-ink-strong)]" x-text="card.value"></p>
 </div>
 </template>
 </div>
 </div>

 <template x-if="Math.abs(totalOilPercentage() - 100) > 0.01">
 <div class="rounded-[1.5rem] border border-[var(--color-line-strong)] bg-[var(--color-accent-soft)] px-4 py-3 text-sm text-[var(--color-ink-strong)]">
 The saponified oils should total 100% in the base phase before the formula is considered balanced.
 </div>
 </template>
</section>
