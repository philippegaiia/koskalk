<section class="space-y-4" aria-labelledby="post-reaction-heading">
 <div id="post-reaction-phases" class="overflow-hidden sk-card sk-phase-craft sk-tone-summary transition-shadow duration-300">
 <div class="sk-section-header border-b border-[var(--color-line)] px-5 py-4">
 <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
 <div>
 <p class="sk-eyebrow">Post-reaction phases</p>
 <h3 id="post-reaction-heading" class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">Additives and aromatics</h3>
 </div>
 <span class="numeric rounded-full border border-[var(--color-line)] bg-[var(--color-panel)] px-3 py-1.5 text-xs font-medium text-[var(--color-ink-soft)]" x-text="`${format(totalAdditionPercentage(), 1)}% of oils`"></span>
 </div>
 </div>

 <div class="space-y-5 p-5">
 <template x-if="additiveRows.length > 0 || canDropRowInPhase('additives')">
 <div class="overflow-hidden sk-inset">
 <div class="border-b border-[var(--color-line)] px-4 py-3">
 <p class="font-medium text-[var(--color-ink-strong)]">Additives</p>
 <p class="mt-1 text-xs text-[var(--color-ink-soft)]">Colorants, preservatives, and other post-reaction functional materials. Drag to reorder the additives already in this phase.</p>
 </div>
	 <div class="hidden touch-pan-x text-sm lg:grid lg:grid-cols-[2.75rem_minmax(0,1.8fr)_8.5rem_8.5rem_2.5rem] lg:gap-px lg:bg-[var(--color-line)]">
	 <div class="bg-[var(--color-field-muted)] px-3 py-2.5 sk-formula-table-y"></div>
	 <div class="bg-[var(--color-field-muted)] px-4 py-2.5 sk-formula-table-y font-medium text-[var(--color-ink-strong)]">Ingredient</div>
	 <div class="bg-[var(--color-field-muted)] px-4 py-2.5 sk-formula-table-y font-medium text-[var(--color-ink-strong)]">% oils</div>
	 <div class="bg-[var(--color-field-muted)] px-4 py-2.5 sk-formula-table-y font-medium text-[var(--color-ink-strong)]" x-text="`Weight (${oilUnit})`"></div>
	 <div class="bg-[var(--color-field-muted)] px-4 py-2.5 sk-formula-table-y"></div>
 </div>

 <div class="divide-y divide-[var(--color-line)] bg-white">
 <template x-for="row in additiveRows" :key="row.id">
 <div @dragover="allowPhaseDrop('additives', $event, row.id)"
 @drop="dropDraggedRow('additives', $event, row.id)"
 :class="{
 'bg-[var(--color-active-soft)]': isDropTarget('additives', row.id),
 'opacity-60': isDraggedRow('additives', row.id),
 }"
 :data-workbench-row-id="row.id"
 x-effect="animateAddedIngredientRow($el, row.id)"
	 class="grid grid-cols-1 gap-3 bg-white px-2.5 py-2.5 text-sm sk-formula-table-row transition motion-safe:will-change-transform lg:grid-cols-[2.75rem_minmax(0,1.8fr)_8.5rem_8.5rem_2.5rem] lg:gap-px lg:bg-[var(--color-line)] lg:p-0">
		 <div class="flex items-center justify-start bg-white py-2.5 sk-formula-table-handle-cell lg:justify-center lg:px-2">
 <button type="button"
 draggable="true"
 @dragstart="beginRowDrag('additives', row.id, $event)"
 @dragend="endRowDrag()"
	 class="grid size-10 cursor-grab place-items-center rounded-md text-[var(--color-ink-soft)] transition hover:bg-[var(--color-field-muted)] hover:text-[var(--color-ink-strong)] active:cursor-grabbing"
 aria-label="Drag to reorder or move this additive">
 <span class="text-sm leading-none">⋮⋮</span>
 </button>
 </div>
		 <div class="flex flex-col justify-center bg-white py-2.5 sk-formula-table-cell lg:px-4">
 <p class="flex items-center gap-1.5 font-medium text-[var(--color-ink-strong)]"><span x-text="row.name"></span><span x-show="row.is_user_owned" class="inline-block size-1.5 rounded-full bg-[var(--color-ink-soft)] opacity-60" title="User-created or user-modified ingredient"></span></p>
 <p class="mt-1 text-xs text-[var(--color-ink-soft)]" x-text="row.inci_name"></p>
 </div>
		 <div class="flex flex-col gap-2 bg-white py-2.5 sk-formula-table-cell lg:flex-row lg:items-center lg:px-3">
	 <span class="sk-eyebrow lg:hidden">% oils</span>
 <template x-if="editMode === 'percentage'">
 <input x-model="row.percentage" @blur="normalizeDecimalBlur($event); row.percentage = format(clampPercentage($event.target.value), 2)" type="text" inputmode="decimal" :aria-label="'Percentage for ' + row.name" class="numeric w-full rounded-xl border border-[var(--color-line)] bg-[var(--color-field)] px-3 py-2 text-sm text-[var(--color-ink-strong)] transition" />
 </template>
 <template x-if="editMode !== 'percentage'">
 <span class="numeric inline-flex min-h-10 items-center text-sm text-[var(--color-ink-soft)]" x-text="`${format(row.percentage, 2)}%`"></span>
 </template>
 </div>
		 <div class="flex flex-col gap-2 bg-white py-2.5 sk-formula-table-cell text-sm text-[var(--color-ink-soft)] lg:flex-row lg:items-center lg:px-3">
	 <span class="sk-eyebrow lg:hidden" x-text="`Weight (${oilUnit})`"></span>
 <template x-if="editMode === 'weight'">
 <input x-effect="if (document.activeElement !== $el) { $el.value = format(rowWeight(row), 3) }" @input="updatePercentageFromWeight(row, $event.target.value)" @blur="normalizeDecimalBlur($event); $el.value = format(rowWeight(row), 3)" type="text" inputmode="decimal" :aria-label="'Weight for ' + row.name" class="numeric w-full rounded-xl border border-[var(--color-line)] bg-[var(--color-field)] px-3 py-2 text-sm text-[var(--color-ink-strong)] transition" />
 </template>
 <template x-if="editMode !== 'weight'">
 <span class="numeric inline-flex min-h-10 items-center" x-text="`${format(rowWeight(row), 3)}`"></span>
 </template>
 </div>
		 <div class="flex items-center justify-end bg-white py-2.5 sk-formula-table-cell lg:justify-center lg:px-2">
	 <button type="button" @click="removeIngredient('additives', row.id)" class="grid size-10 place-items-center rounded-md text-base text-[var(--color-ink-soft)] transition hover:bg-[var(--color-danger-soft)] hover:text-[var(--color-danger-strong)]" aria-label="Remove additive">×</button>
 </div>
 </div>
 </template>
 </div>

 <template x-if="additiveRows.length === 0">
 <div @dragover="allowPhaseDrop('additives', $event)"
 @drop="dropDraggedRow('additives', $event)"
 :class="isDropTarget('additives') ? 'bg-[var(--color-active-soft)] text-[var(--color-active-strong)]' : 'bg-white text-[var(--color-ink-soft)]'"
	 class="px-4 py-2.5 sk-formula-table-y text-center text-xs font-medium transition">
 Drop carrier oil here to use it as an additive
 </div>
 </template>
 </div>
 </template>

 <template x-if="fragranceRows.length > 0">
 <div class="overflow-hidden sk-inset">
 <div class="border-b border-[var(--color-line)] px-4 py-3">
 <p class="font-medium text-[var(--color-ink-strong)]">Fragrance and aromatics</p>
 <p class="mt-1 text-xs text-[var(--color-ink-soft)]">Essential oils and aromatic extracts with their own compliance context. Drag to reorder inside this aromatic phase.</p>
 </div>
	 <div class="hidden touch-pan-x text-sm lg:grid lg:grid-cols-[2.75rem_minmax(0,1.8fr)_8.5rem_8.5rem_2.5rem] lg:gap-px lg:bg-[var(--color-line)]">
	 <div class="bg-[var(--color-field-muted)] px-3 py-2.5 sk-formula-table-y"></div>
	 <div class="bg-[var(--color-field-muted)] px-4 py-2.5 sk-formula-table-y font-medium text-[var(--color-ink-strong)]">Ingredient</div>
	 <div class="bg-[var(--color-field-muted)] px-4 py-2.5 sk-formula-table-y font-medium text-[var(--color-ink-strong)]">% oils</div>
	 <div class="bg-[var(--color-field-muted)] px-4 py-2.5 sk-formula-table-y font-medium text-[var(--color-ink-strong)]" x-text="`Weight (${oilUnit})`"></div>
	 <div class="bg-[var(--color-field-muted)] px-4 py-2.5 sk-formula-table-y"></div>
 </div>

 <div class="divide-y divide-[var(--color-line)] bg-white">
 <template x-for="row in fragranceRows" :key="row.id">
 <div @dragover="allowPhaseDrop('fragrance', $event, row.id)"
 @drop="dropDraggedRow('fragrance', $event, row.id)"
 :class="{
 'bg-[var(--color-active-soft)]': isDropTarget('fragrance', row.id),
 'opacity-60': isDraggedRow('fragrance', row.id),
 }"
 :data-workbench-row-id="row.id"
 x-effect="animateAddedIngredientRow($el, row.id)"
	 class="grid grid-cols-1 gap-3 bg-white px-2.5 py-2.5 text-sm sk-formula-table-row transition motion-safe:will-change-transform lg:grid-cols-[2.75rem_minmax(0,1.8fr)_8.5rem_8.5rem_2.5rem] lg:gap-px lg:bg-[var(--color-line)] lg:p-0">
		 <div class="flex items-center justify-start bg-white py-2.5 sk-formula-table-handle-cell lg:justify-center lg:px-2">
 <button type="button"
 draggable="true"
 @dragstart="beginRowDrag('fragrance', row.id, $event)"
 @dragend="endRowDrag()"
	 class="grid size-10 cursor-grab place-items-center rounded-md text-[var(--color-ink-soft)] transition hover:bg-[var(--color-field-muted)] hover:text-[var(--color-ink-strong)] active:cursor-grabbing"
 aria-label="Drag to reorder this aromatic ingredient">
 <span class="text-sm leading-none">⋮⋮</span>
 </button>
 </div>
		 <div class="flex flex-col justify-center bg-white py-2.5 sk-formula-table-cell lg:px-4">
 <p class="flex items-center gap-1.5 font-medium text-[var(--color-ink-strong)]"><span x-text="row.name"></span><span x-show="row.is_user_owned" class="inline-block size-1.5 rounded-full bg-[var(--color-ink-soft)] opacity-60" title="User-created or user-modified ingredient"></span></p>
 <p class="mt-1 text-xs text-[var(--color-ink-soft)]" x-text="row.inci_name"></p>
 </div>
		 <div class="flex flex-col gap-2 bg-white py-2.5 sk-formula-table-cell lg:flex-row lg:items-center lg:px-3">
	 <span class="sk-eyebrow lg:hidden">% oils</span>
 <template x-if="editMode === 'percentage'">
 <input x-model="row.percentage" @blur="normalizeDecimalBlur($event); row.percentage = format(clampPercentage($event.target.value), 2)" type="text" inputmode="decimal" :aria-label="'Percentage for ' + row.name" class="numeric w-full rounded-xl border border-[var(--color-line)] bg-[var(--color-field)] px-3 py-2 text-sm text-[var(--color-ink-strong)] transition" />
 </template>
 <template x-if="editMode !== 'percentage'">
 <span class="numeric inline-flex min-h-10 items-center text-sm text-[var(--color-ink-soft)]" x-text="`${format(row.percentage, 2)}%`"></span>
 </template>
 </div>
		 <div class="flex flex-col gap-2 bg-white py-2.5 sk-formula-table-cell text-sm text-[var(--color-ink-soft)] lg:flex-row lg:items-center lg:px-3">
	 <span class="sk-eyebrow lg:hidden" x-text="`Weight (${oilUnit})`"></span>
 <template x-if="editMode === 'weight'">
 <input x-effect="if (document.activeElement !== $el) { $el.value = format(rowWeight(row), 3) }" @input="updatePercentageFromWeight(row, $event.target.value)" @blur="normalizeDecimalBlur($event); $el.value = format(rowWeight(row), 3)" type="text" inputmode="decimal" :aria-label="'Weight for ' + row.name" class="numeric w-full rounded-xl border border-[var(--color-line)] bg-[var(--color-field)] px-3 py-2 text-sm text-[var(--color-ink-strong)] transition" />
 </template>
 <template x-if="editMode !== 'weight'">
 <span class="numeric inline-flex min-h-10 items-center" x-text="`${format(rowWeight(row), 3)}`"></span>
 </template>
 </div>
		 <div class="flex items-center justify-end bg-white py-2.5 sk-formula-table-cell lg:justify-center lg:px-2">
	 <button type="button" @click="removeIngredient('fragrance', row.id)" class="grid size-10 place-items-center rounded-md text-base text-[var(--color-ink-soft)] transition hover:bg-[var(--color-danger-soft)] hover:text-[var(--color-danger-strong)]" aria-label="Remove aromatic ingredient">×</button>
 </div>
 </div>
 </template>
 </div>
 </div>
 </template>

 </div>
 </div>

 <div class="sk-card sk-tone-summary overflow-hidden">
 <div class="sk-section-header border-b px-5 py-4">
 <p class="sk-eyebrow">Batch totals</p>
 </div>

 <div class="grid gap-px bg-[var(--color-line)] sm:grid-cols-2 xl:grid-cols-4">
 <template x-for="card in totalSummaryCards" :key="card.id">
 <div class="flex min-h-24 flex-col justify-between bg-[var(--color-panel)] px-4 py-3">
 <p class="sk-eyebrow" x-text="card.label"></p>
 <p class="numeric mt-3 text-xl font-semibold text-[var(--color-ink-strong)]" x-text="card.value"></p>
 </div>
 </template>
 </div>
 </div>

 <template x-if="Math.abs(totalOilPercentage() - 100) > 0.01">
 <div class="rounded-[1.5rem] border border-[var(--color-line-strong)] bg-[var(--color-accent-soft)] px-4 py-3 text-sm text-[var(--color-ink-strong)]" role="alert">
 The saponified oils should total 100% in the base phase before the formula is considered balanced.
 </div>
 </template>
</section>
