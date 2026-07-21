@php($packagingCatalogCurrency = $workbench['defaultCurrency'] ?? 'EUR')

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
 <p class="sk-eyebrow">{{ __('workbench.packaging.modal.eyebrow') }}</p>
 <h3 id="packaging-catalog-heading" class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('workbench.packaging.modal.title') }}</h3>
 <p class="mt-2 text-sm text-[var(--color-ink-soft)]">{{ __('workbench.packaging.modal.help') }}</p>
 </div>
 <button type="button" @click="closePackagingCatalogModal()" class="rounded-full border border-[var(--color-line)] px-3 py-2 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel)]">{{ __('workbench.packaging.modal.cancel') }}</button>
 </div>

 <div class="mt-5 grid gap-3">
 <label class="sk-inset p-4">
 <span class="sk-eyebrow">{{ __('workbench.packaging.modal.name') }}</span>
 <input x-model="packagingCatalogForm.name" type="text" class="mt-3 w-full rounded-lg bg-[var(--color-field)] px-3 py-2.5 text-sm text-[var(--color-ink-strong)] transition" />
 </label>

 <label class="sk-inset p-4">
 <span class="sk-eyebrow" x-text="t('packaging.modal.effective_unit_price', { unit: packagingCatalogForm.currency || costingCurrency || defaultCurrency || @js($packagingCatalogCurrency) })">{{ __('workbench.packaging.modal.effective_unit_price', ['unit' => $packagingCatalogCurrency]) }}</span>
 <input x-model="packagingCatalogForm.unit_cost" @blur="normalizeDecimalBlur($event)" type="text" inputmode="decimal" class="numeric mt-3 w-full rounded-lg bg-[var(--color-field)] px-3 py-2.5 text-sm text-[var(--color-ink-strong)] transition" />
 </label>

 <label class="sk-inset p-4">
 <span class="sk-eyebrow">{{ __('workbench.packaging.modal.notes') }}</span>
 <textarea x-model="packagingCatalogForm.notes" rows="4" class="mt-3 w-full rounded-lg bg-[var(--color-field)] px-3 py-2.5 text-sm text-[var(--color-ink-strong)] transition"></textarea>
 </label>
 </div>

 <template x-if="packagingCatalogMessage">
 <p class="mt-4 text-sm text-[var(--color-ink-soft)]" x-text="packagingCatalogMessage"></p>
 </template>

 <div class="mt-5 flex flex-wrap justify-end gap-2">
 <button type="button" @click="closePackagingCatalogModal()" class="rounded-full border border-[var(--color-line)] px-4 py-2.5 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel)]">{{ __('workbench.packaging.modal.cancel') }}</button>
 <button type="button" @click="savePackagingCatalogItemOnly()" class="rounded-full border border-[var(--color-line)] bg-white px-4 py-2.5 text-sm font-medium text-[var(--color-ink-strong)] transition hover:bg-[var(--color-panel)]">{{ __('workbench.packaging.modal.save_to_library') }}</button>
 <button type="button" @click="savePackagingCatalogItemAndAdd()" class="rounded-full bg-[var(--color-accent)] px-4 py-2.5 text-sm font-medium text-[var(--color-on-accent)] transition hover:bg-[var(--color-accent-hover)]">
 {{ __('workbench.packaging.modal.save_and_add') }}
 </button>
 </div>
 </div>
</div>
