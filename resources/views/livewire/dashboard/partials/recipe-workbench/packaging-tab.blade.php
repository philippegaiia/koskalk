<div x-show="activeWorkbenchTab === 'packaging'" role="tabpanel" aria-labelledby="tab-packaging" id="panel-packaging" class="space-y-6">
 <section class="overflow-hidden sk-card">
 <div class="border-b border-[var(--color-line)] px-5 py-4">
 <div class="flex flex-col gap-4">
 <div>
 <p class="sk-eyebrow">Packaging plan</p>
 <h3 class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">Packaging plan</h3>
 <p class="mt-2 max-w-3xl text-sm text-[var(--color-ink-soft)]">Define what one finished unit uses. Prices stay in Costing so the formula structure stays clear.</p>
 </div>

 <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
 <template x-if="packagingCatalog.length > 0">
 <div class="relative w-full sm:w-72 sm:min-w-72" @click.outside="closePackagingCatalogSelect()">
 <div class="flex items-center rounded-lg bg-[var(--color-field)] transition focus-within:outline-2 focus-within:outline-[var(--color-accent)]">
 <input
 x-model="packagingCatalogSearch"
 @focus="openPackagingCatalogSelect()"
 @input="openPackagingCatalogSelect()"
 @keydown.enter.prevent="selectFirstFilteredPackagingCatalogItem()"
 @keydown.escape.prevent="closePackagingCatalogSelect()"
 @keydown.arrow-down.prevent="openPackagingCatalogSelect()"
 type="search"
 role="combobox"
 aria-label="Search and add packaging item"
 aria-controls="packaging-catalog-select-options"
 :aria-expanded="packagingCatalogSelectOpen.toString()"
 placeholder="Search or choose packaging item"
 class="min-w-0 flex-1 rounded-lg bg-transparent px-4 py-2.5 text-sm text-[var(--color-ink-strong)] outline-none placeholder:text-[var(--color-ink-soft)]"
 />
 <button type="button" @click="packagingCatalogSelectOpen ? closePackagingCatalogSelect() : openPackagingCatalogSelect()" class="grid size-10 shrink-0 place-items-center rounded-md text-[var(--color-ink-soft)] transition hover:bg-[var(--color-field-muted)]" aria-label="Toggle packaging catalog options">
 <span aria-hidden="true" class="text-xs">⌄</span>
 </button>
 </div>
 <div
 x-cloak
 x-show="packagingCatalogSelectOpen"
 x-transition.opacity
 id="packaging-catalog-select-options"
 role="listbox"
 class="absolute left-0 right-0 top-[calc(100%+0.35rem)] z-20 max-h-72 overflow-y-auto rounded-lg border border-[var(--color-line)] bg-[var(--color-panel)] p-1 shadow-[0_10px_30px_color-mix(in_oklch,var(--color-ink-strong)_14%,transparent)]"
 >
 <template x-if="filteredPackagingCatalog.length === 0">
 <p class="px-3 py-2.5 text-sm text-[var(--color-ink-soft)]">No matching packaging items</p>
 </template>
 <template x-for="item in filteredPackagingCatalog" :key="item.id">
 <button type="button" role="option" @click="selectPackagingCatalogItem(item)" class="flex w-full items-start justify-between gap-3 rounded-md px-3 py-2.5 text-left text-sm transition hover:bg-[var(--color-field-muted)] focus:bg-[var(--color-field-muted)] focus:outline-none">
 <span>
 <span class="block font-medium text-[var(--color-ink-strong)]" x-text="item.name"></span>
 <span x-show="item.notes" class="mt-0.5 block line-clamp-1 text-xs text-[var(--color-ink-soft)]" x-text="item.notes"></span>
 </span>
 <span class="shrink-0 text-xs font-medium text-[var(--color-accent-strong)]">Add</span>
 </button>
 </template>
 </div>
 </div>
 </template>
 <button type="button" @click="openPackagingCatalogModal()" class="rounded-full bg-[var(--color-accent)] px-4 py-2 text-sm font-medium text-[var(--color-on-accent)] transition hover:bg-[var(--color-accent-hover)]">
 New packaging item
 </button>
 </div>
 </div>
 </div>

 <template x-if="packagingPlanRows.length === 0">
 <div class="px-5 py-8 text-sm text-[var(--color-ink-soft)]">
 <p class="font-medium text-[var(--color-ink-strong)]">No packaging planned yet.</p>
 <p class="mt-2">Add boxes, jars, labels, stickers, or other components used by one finished unit.</p>
 </div>
 </template>

 <template x-if="packagingPlanRows.length > 0">
	 <div class="overflow-hidden touch-pan-x">
	 <div>
	 <div class="hidden text-sm lg:grid lg:grid-cols-[minmax(0,1.9fr)_9rem_minmax(0,1.3fr)_7rem] lg:gap-px lg:bg-[var(--color-line)]">
 <div class="bg-[var(--color-field-muted)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">Packaging item</div>
 <div class="bg-[var(--color-field-muted)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">Components per unit</div>
 <div class="bg-[var(--color-field-muted)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">Notes</div>
 <div class="bg-[var(--color-field-muted)] px-4 py-3"></div>
 </div>

 <div class="divide-y divide-[var(--color-line)] bg-white">
 <template x-for="row in packagingPlanRows" :key="row.id">
	 <div class="grid grid-cols-1 gap-3 bg-white p-3 text-sm lg:grid-cols-[minmax(0,1.9fr)_9rem_minmax(0,1.3fr)_7rem] lg:gap-px lg:bg-[var(--color-line)] lg:p-0">
	 <div class="flex items-center bg-white lg:px-4 lg:py-3">
 <p class="font-medium text-[var(--color-ink-strong)]" x-text="row.name"></p>
 </div>
	 <div class="flex flex-col gap-2 bg-white lg:flex-row lg:items-center lg:px-3 lg:py-3">
	 <span class="sk-eyebrow lg:hidden">Components per unit</span>
 <input :value="row.components_per_unit" @blur="normalizeDecimalBlur($event); updatePackagingPlanComponents(row, $event.target.value)" type="text" inputmode="decimal" :aria-label="'Components per unit for ' + row.name" class="numeric w-full rounded-xl border border-[var(--color-line)] bg-[var(--color-field)] px-3 py-2 text-sm text-[var(--color-ink-strong)] transition" />
 </div>
	 <div class="flex flex-col gap-2 bg-white lg:flex-row lg:items-center lg:px-3 lg:py-3">
	 <span class="sk-eyebrow lg:hidden">Notes</span>
 <input x-model="row.notes" type="text" :aria-label="'Notes for ' + row.name" class="w-full rounded-xl border border-[var(--color-line)] bg-[var(--color-field)] px-3 py-2 text-sm text-[var(--color-ink-strong)] transition" />
 </div>
	 <div class="flex items-center justify-end bg-white lg:px-4 lg:py-3">
	 <button type="button" @click="removePackagingPlanRow(row.id)" class="grid size-10 place-items-center rounded-md text-base text-[var(--color-ink-soft)] transition hover:bg-[var(--color-danger-soft)] hover:text-[var(--color-danger-strong)]" aria-label="Remove packaging item">×</button>
 </div>
 </div>
 </template>
 </div>
 </div>
 </div>
 </template>
 </section>
</div>
