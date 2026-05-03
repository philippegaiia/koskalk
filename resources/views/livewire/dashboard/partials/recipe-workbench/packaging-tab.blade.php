<div x-show="activeWorkbenchTab === 'packaging'" role="tabpanel" aria-labelledby="tab-packaging" id="panel-packaging" class="space-y-6">
 <section class="overflow-hidden sk-card">
 <div class="border-b border-[var(--color-line)] px-5 py-4">
 <div class="flex flex-col gap-4">
 <div>
 <p class="sk-eyebrow">Packaging plan</p>
 <h3 class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">Packaging plan</h3>
 <p class="mt-2 max-w-3xl text-sm text-[var(--color-ink-soft)]">Define what one finished unit uses. Prices stay in Costing so the official recipe structure stays clear.</p>
 </div>

 <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
 <template x-if="packagingCatalog.length > 0">
 <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
 <input x-model="packagingCatalogSearch" type="search" placeholder="Search packaging items" aria-label="Search packaging items" class="w-full rounded-lg bg-[var(--color-field)] px-4 py-2.5 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)] sm:w-72" />
 <select
 @change="if ($event.target.value) { addPackagingPlanRow(JSON.parse($event.target.value)); $event.target.value = ''; }"
 aria-label="Add from packaging catalog"
 class="rounded-full border border-[var(--color-line)] bg-white px-4 py-2 text-sm font-medium text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)] sm:min-w-72"
 >
 <option value="" x-text="filteredPackagingCatalog.length > 0 ? 'Add from catalog...' : 'No matching packaging items'"></option>
 <template x-for="item in filteredPackagingCatalog" :key="item.id">
 <option :value="JSON.stringify(item)" x-text="item.name"></option>
 </template>
 </select>
 </div>
 </template>
 <button type="button" @click="openPackagingCatalogModal()" class="rounded-full bg-[var(--color-accent)] px-4 py-2 text-sm font-medium text-white transition hover:bg-[var(--color-accent-hover)]">
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
 <div class="overflow-x-auto">
 <div class="min-w-[58rem]">
 <div class="grid grid-cols-[minmax(0,1.9fr)_9rem_minmax(0,1.3fr)_7rem] gap-px bg-[var(--color-line)] text-sm">
 <div class="bg-[var(--color-field-muted)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">Packaging item</div>
 <div class="bg-[var(--color-field-muted)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">Components per unit</div>
 <div class="bg-[var(--color-field-muted)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">Notes</div>
 <div class="bg-[var(--color-field-muted)] px-4 py-3"></div>
 </div>

 <div class="divide-y divide-[var(--color-line)] bg-white">
 <template x-for="row in packagingPlanRows" :key="row.id">
 <div class="grid grid-cols-[minmax(0,1.9fr)_9rem_minmax(0,1.3fr)_7rem] gap-px bg-[var(--color-line)] text-sm">
 <div class="flex items-center bg-white px-4 py-3">
 <p class="font-medium text-[var(--color-ink-strong)]" x-text="row.name"></p>
 </div>
 <div class="flex items-center bg-white px-3 py-3">
 <input :value="row.components_per_unit" @blur="normalizeDecimalBlur($event); updatePackagingPlanComponents(row, $event.target.value)" type="text" inputmode="decimal" :aria-label="'Components per unit for ' + row.name" class="numeric w-full rounded-xl border border-[var(--color-line)] bg-[var(--color-field)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]" />
 </div>
 <div class="flex items-center bg-white px-3 py-3">
 <input x-model="row.notes" type="text" :aria-label="'Notes for ' + row.name" class="w-full rounded-xl border border-[var(--color-line)] bg-[var(--color-field)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]" />
 </div>
 <div class="flex items-center justify-end bg-white px-4 py-3">
 <button type="button" @click="removePackagingPlanRow(row.id)" class="grid size-8 place-items-center rounded-md text-base text-[var(--color-ink-soft)] transition hover:bg-[var(--color-danger-soft)] hover:text-[var(--color-danger-strong)]" aria-label="Remove packaging item">×</button>
 </div>
 </div>
 </template>
 </div>
 </div>
 </div>
 </template>
 </section>
</div>
