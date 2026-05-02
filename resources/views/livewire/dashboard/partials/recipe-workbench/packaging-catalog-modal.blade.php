<div
 x-cloak
 x-show="packagingCatalogModalOpen"
 x-transition.opacity
 role="dialog" aria-modal="true" aria-labelledby="packaging-catalog-heading"
 class="fixed inset-0 z-40 flex items-center justify-center bg-[color:oklch(from_var(--color-surface-strong)_l_c_h_/_0.55)] px-4 py-6"
>
 <div @click.away="closePackagingCatalogModal()" @keydown.escape="closePackagingCatalogModal()" class="w-full max-w-xl sk-card p-6">
 <div class="flex items-start justify-between gap-4">
 <div>
 <p class="sk-eyebrow">Packaging item</p>
 <h3 id="packaging-catalog-heading" class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">New packaging item</h3>
 <p class="mt-2 text-sm text-[var(--color-ink-soft)]">Save a reusable packaging item, then add it to the packaging plan for one finished unit.</p>
 </div>
 <button type="button" @click="closePackagingCatalogModal()" class="rounded-full border border-[var(--color-line)] px-3 py-2 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel)]">Cancel</button>
 </div>

 <div class="mt-5 grid gap-3">
 <label class="sk-inset p-4">
 <span class="sk-eyebrow">Name</span>
 <input x-model="packagingCatalogForm.name" type="text" class="mt-3 w-full rounded-lg bg-[var(--color-field)] px-3 py-2.5 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]" />
 </label>

 <label class="sk-inset p-4">
 <span class="sk-eyebrow">Effective unit price</span>
 <input x-model="packagingCatalogForm.unit_cost" @blur="normalizeDecimalBlur($event)" type="text" inputmode="decimal" class="numeric mt-3 w-full rounded-lg bg-[var(--color-field)] px-3 py-2.5 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]" />
 </label>

 <label class="sk-inset p-4">
 <span class="sk-eyebrow">Notes</span>
 <textarea x-model="packagingCatalogForm.notes" rows="4" class="mt-3 w-full rounded-lg bg-[var(--color-field)] px-3 py-2.5 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]"></textarea>
 </label>
 </div>

 <template x-if="packagingCatalogMessage">
 <p class="mt-4 text-sm text-[var(--color-ink-soft)]" x-text="packagingCatalogMessage"></p>
 </template>

 <div class="mt-5 flex flex-wrap justify-end gap-2">
 <button type="button" @click="closePackagingCatalogModal()" class="rounded-full border border-[var(--color-line)] px-4 py-2.5 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel)]">Cancel</button>
 <button type="button" @click="savePackagingCatalogItemOnly()" class="rounded-full border border-[var(--color-line)] bg-white px-4 py-2.5 text-sm font-medium text-[var(--color-ink-strong)] transition hover:bg-[var(--color-panel)]">Save only</button>
 <button type="button" @click="savePackagingCatalogItemAndAdd()" class="rounded-full bg-[var(--color-accent)] px-4 py-2.5 text-sm font-medium text-white transition hover:bg-[var(--color-accent-hover)]">
 Save and add to plan
 </button>
 </div>
 </div>
</div>
