<section class="overflow-hidden sk-card">
 <div class="border-b border-[var(--color-line)] px-5 py-4">
 <p class="sk-eyebrow">Formula ingredients</p>
 <div class="mt-1 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
 <div>
 <h3 class="text-lg font-semibold text-[var(--color-ink-strong)]">Phases and full formula basis</h3>
 <p class="mt-1 text-sm text-[var(--color-ink-soft)]">Enter percentages or weights against the full batch weight.</p>
 </div>
 <div :class="oilPercentageIsBalanced ? 'border-[var(--color-success-soft)] bg-[var(--color-success-soft)] text-[var(--color-success-strong)]' : 'border-[var(--color-warning-soft)] bg-[var(--color-warning-soft)] text-[var(--color-warning-strong)]'" class="inline-flex items-center gap-3 rounded-full border px-4 py-2 text-sm font-medium transition">
 <span x-text="oilPercentageStatusLabel"></span>
 <span class="numeric rounded-full bg-white px-3 py-1 text-sm font-semibold" x-text="`${format(totalOilPercentage(), 2)}%`"></span>
 </div>
 </div>
 </div>

 <div class="space-y-5 p-5">
 <template x-for="phase in phaseOrder" :key="phase.key">
 <div class="overflow-hidden sk-inset">
 <div class="border-b border-[var(--color-line)] px-4 py-3">
 <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
 <div class="min-w-0 flex-1">
 <p class="sk-eyebrow">Phase</p>
 <input x-model="phase.name" type="text" aria-label="Phase name" class="mt-1 w-full rounded-lg bg-[var(--color-field)] px-3 py-2 text-sm font-semibold text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]" />
 </div>
 <div class="flex flex-wrap items-center gap-2">
 <button type="button" @click="moveCosmeticPhase(phase.key, 'up')" :disabled="cosmeticPhaseIsFirst(phase.key)" :class="cosmeticPhaseIsFirst(phase.key) ? 'cursor-not-allowed border-[var(--color-line)] text-[var(--color-ink-soft)]' : 'border-[var(--color-line)] text-[var(--color-ink-strong)] hover:bg-white'" class="rounded-full border px-3 py-1.5 text-xs font-medium transition">
 Up
 </button>
 <button type="button" @click="moveCosmeticPhase(phase.key, 'down')" :disabled="cosmeticPhaseIsLast(phase.key)" :class="cosmeticPhaseIsLast(phase.key) ? 'cursor-not-allowed border-[var(--color-line)] text-[var(--color-ink-soft)]' : 'border-[var(--color-line)] text-[var(--color-ink-strong)] hover:bg-white'" class="rounded-full border px-3 py-1.5 text-xs font-medium transition">
 Down
 </button>
 <span class="numeric rounded-full border border-[var(--color-line)] bg-white px-3 py-1.5 text-xs font-medium text-[var(--color-ink-soft)]" x-text="`${format(cosmeticPhasePercentageTotal(phase.key), 2)}% of formula`"></span>
 <button type="button" x-show="phaseOrder.length > 1" @click="confirmRemoveCosmeticPhase(phase.key)" class="rounded-full border border-[var(--color-danger-soft)] px-3 py-1.5 text-xs font-medium text-[var(--color-danger-strong)] transition hover:bg-[var(--color-danger-soft)]">
 Remove phase
 </button>
 </div>
 </div>
 </div>

 <div class="grid grid-cols-[2.75rem_minmax(0,1.8fr)_8.5rem_8.5rem_2.5rem] gap-px bg-[var(--color-line)] text-sm">
 <div class="bg-[var(--color-field-muted)] px-3 py-3"></div>
 <div class="bg-[var(--color-field-muted)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">Ingredient</div>
 <div class="bg-[var(--color-field-muted)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">% formula</div>
 <div class="bg-[var(--color-field-muted)] px-4 py-3 font-medium text-[var(--color-ink-strong)]" x-text="`Weight (${oilUnit})`"></div>
 <div class="bg-[var(--color-field-muted)] px-4 py-3"></div>
 </div>

 <div class="divide-y divide-[var(--color-line)] bg-white">
 <template x-for="row in phaseItems[phase.key] ?? []" :key="row.id">
 <div @dragover="allowPhaseDrop(phase.key, $event, row.id)"
 @drop="dropDraggedRow(phase.key, $event, row.id)"
 :class="{
 'bg-[var(--color-accent-soft)]': isDropTarget(phase.key, row.id),
 'opacity-60': isDraggedRow(phase.key, row.id),
 }"
 class="grid grid-cols-[2.75rem_minmax(0,1.8fr)_8.5rem_8.5rem_2.5rem] gap-px bg-[var(--color-line)] transition">
 <div class="grid place-items-center bg-white px-2 py-3">
 <button type="button"
 draggable="true"
 @dragstart="beginRowDrag(phase.key, row.id, $event)"
 @dragend="endRowDrag()"
 class="grid size-7 cursor-grab place-items-center rounded-md text-[var(--color-ink-soft)] transition hover:bg-[var(--color-field-muted)] hover:text-[var(--color-ink-strong)] active:cursor-grabbing"
 aria-label="Drag to reorder this ingredient">
 <span class="text-sm leading-none">⋮⋮</span>
 </button>
 </div>
 <div class="flex items-center bg-white px-4 py-3">
 <div class="flex items-start justify-between gap-3">
 <div class="min-w-0">
 <p class="font-medium text-[var(--color-ink-strong)]" x-text="row.name"></p>
 <p class="mt-1 text-xs text-[var(--color-ink-soft)]" x-text="row.inci_name"></p>
 </div>
 <div x-data="{
 open: false,
 panelStyle: '',
 reposition() {
 const panelWidth = 256;
 const gutter = 16;
 const rect = this.$refs.trigger.getBoundingClientRect();
 const left = Math.max(gutter, Math.min(window.innerWidth - panelWidth - gutter, rect.right - panelWidth));
 const top = Math.min(window.innerHeight - gutter, rect.bottom + 8);

 this.panelStyle = `position: fixed; top: ${top}px; left: ${left}px; width: ${panelWidth}px;`;
 },
 }"
 class="relative shrink-0"
 x-cloak>
 <template x-if="ingredientHasInspector(row)">
 <button type="button"
 x-ref="trigger"
 @mouseenter="open = true; reposition()"
 @mouseleave="open = false"
 @focus="open = true; reposition()"
 @blur="open = false"
 @click.prevent="open = !open; if (open) { reposition(); }"
 class="grid size-6 place-items-center rounded-full border border-[var(--color-line)] bg-white text-[11px] font-semibold text-[var(--color-ink-soft)] transition hover:border-[var(--color-line-strong)] hover:text-[var(--color-ink-strong)]" aria-label="Show ingredient details">
 i
 </button>
 </template>
 <template x-teleport="body">
 <div x-show="open"
 x-transition.opacity
 @mouseenter="open = true"
 @mouseleave="open = false"
 @click.outside="open = false"
 @scroll.window="if (open) { reposition(); }"
 @resize.window="if (open) { reposition(); }"
 :style="panelStyle"
 class="z-[80] rounded-[1.25rem] border border-[var(--color-line)] bg-white p-3">
 <p class="sk-eyebrow">Material details</p>
 <div class="mt-2.5 space-y-1.5 text-xs text-[var(--color-ink-soft)]">
 <template x-for="detail in ingredientInspectorRows(row)" :key="detail.label">
 <div class="flex items-center justify-between gap-3 rounded-xl bg-[var(--color-panel)] px-3 py-2">
 <span x-text="detail.label"></span>
 <span class="numeric font-medium text-[var(--color-ink-strong)]" x-text="detail.value"></span>
 </div>
 </template>
 </div>
 </div>
 </template>
 </div>
 </div>
 </div>
 <div class="flex items-center bg-white px-3 py-3">
 <template x-if="editMode === 'percentage'">
 <input x-model="row.percentage" @keydown="handleDecimalKeydown($event)" @blur="normalizeDecimalBlur($event); row.percentage = format(clampPercentage($event.target.value), 2)" type="number" inputmode="decimal" min="0" max="100" step="0.1" :aria-label="'Percentage for ' + row.name" class="numeric w-full rounded-xl border border-[var(--color-line)] bg-[var(--color-field)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]" />
 </template>
 <template x-if="editMode !== 'percentage'">
 <span class="numeric inline-flex min-h-10 items-center text-sm text-[var(--color-ink-soft)]" x-text="`${format(row.percentage, 2)}%`"></span>
 </template>
 </div>
 <div class="flex items-center bg-white px-3 py-3 text-sm text-[var(--color-ink-soft)]">
 <template x-if="editMode === 'weight'">
 <input x-effect="if (document.activeElement !== $el) { $el.value = format(rowWeight(row), 3) }" @input="updateCosmeticPercentagesFromWeights(row, $event.target.value)" @keydown="handleDecimalKeydown($event)" @blur="normalizeDecimalBlur($event); $el.value = format(rowWeight(row), 3)" type="number" inputmode="decimal" step="0.5" :aria-label="'Weight for ' + row.name" class="numeric w-full rounded-xl border border-[var(--color-line)] bg-[var(--color-field)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]" />
 </template>
 <template x-if="editMode !== 'weight'">
 <span class="numeric inline-flex min-h-10 items-center" x-text="`${format(rowWeight(row), 3)}`"></span>
 </template>
 </div>
 <div class="flex items-center justify-center bg-white px-2 py-3">
 <button type="button" @click="removeIngredient(phase.key, row.id)" class="grid size-8 place-items-center rounded-md text-base text-[var(--color-ink-soft)] transition hover:bg-[var(--color-danger-soft)] hover:text-[var(--color-danger-strong)]" aria-label="Remove ingredient">×</button>
 </div>
 </div>
 </template>

	 <template x-if="(phaseItems[phase.key] ?? []).length === 0">
	 <div @dragover="allowPhaseDrop(phase.key, $event)"
	 @drop="dropDraggedRow(phase.key, $event)"
	 :class="isDropTarget(phase.key) ? 'bg-[var(--color-accent-soft)] text-[var(--color-ink-strong)]' : 'text-[var(--color-ink-soft)]'"
	 class="px-4 py-3 text-center text-xs font-medium transition">
	 Drop here
	 </div>
	 </template>

 <template x-if="(phaseItems[phase.key] ?? []).length > 0">
 <div @dragover="allowPhaseDrop(phase.key, $event)"
 @drop="dropDraggedRow(phase.key, $event)"
 :class="isDropTarget(phase.key) ? 'bg-[var(--color-accent-soft)] text-[var(--color-ink-strong)]' : 'bg-white text-[var(--color-ink-soft)]'"
 class="border-t border-[var(--color-line)] px-4 py-1.5 text-xs font-medium transition">
 Drop here
 </div>
 </template>
 </div>
 </div>
 </template>

	 <div class="overflow-hidden sk-inset">
	 <div class="grid grid-cols-[2.75rem_minmax(0,1.8fr)_8.5rem_8.5rem_2.5rem] gap-px bg-[var(--color-line)] text-sm">
	 <div :class="oilPercentageIsBalanced ? 'bg-[var(--color-field-muted)]' : 'bg-[var(--color-warning-soft)]'" class="px-3 py-3"></div>
	 <div :class="oilPercentageIsBalanced ? 'bg-[var(--color-field-muted)] text-[var(--color-ink-strong)]' : 'bg-[var(--color-warning-soft)] text-[var(--color-warning-strong)]'" class="px-4 py-3 font-medium">Formula total</div>
	 <div :class="oilPercentageIsBalanced ? 'bg-[var(--color-field-muted)] text-[var(--color-ink-strong)]' : 'bg-[var(--color-warning-soft)] text-[var(--color-warning-strong)]'" class="numeric px-4 py-3 font-medium" x-text="`${format(totalOilPercentage(), 2)}%`"></div>
	 <div :class="oilPercentageIsBalanced ? 'bg-[var(--color-field-muted)] text-[var(--color-ink-strong)]' : 'bg-[var(--color-warning-soft)] text-[var(--color-warning-strong)]'" class="numeric px-4 py-3 font-medium" x-text="`${format(cosmeticFormulaWeightTotal(), 3)} ${oilUnit}`"></div>
	 <div :class="oilPercentageIsBalanced ? 'bg-[var(--color-field-muted)]' : 'bg-[var(--color-warning-soft)]'" class="px-4 py-3"></div>
	 </div>
	 </div>

	 <div class="flex flex-wrap items-center gap-3">
	 <button type="button" @click="addCosmeticPhase()" class="rounded-full border border-[var(--color-line-strong)] bg-white px-4 py-2 text-sm font-medium text-[var(--color-ink-strong)] transition hover:bg-[var(--color-accent-soft)]">
	 Add phase
	 </button>
	 </div>
	 </div>
</section>
