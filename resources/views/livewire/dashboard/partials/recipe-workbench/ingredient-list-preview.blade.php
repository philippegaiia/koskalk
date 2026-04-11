<section class="rounded-xl bg-[var(--color-panel)] shadow-[0_2px_4px_rgba(60,50,30,0.04),0_12px_24px_rgba(60,50,30,0.08)] p-5">
 <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
 <div class="min-w-0">
 <p class="text-[0.6875rem] font-medium tracking-[0.05em] text-[var(--color-ink-soft)] uppercase">Ingredient list preview</p>
 <p class="mt-1 text-sm text-[var(--color-ink-soft)]">Generated from the selected ingredient-list variant and normalized to the cured-bar basis so the label output stays aligned with the dry soap view.</p>
 </div>
 <div class="flex flex-wrap items-center gap-2">
 <span class="rounded-full border border-[var(--color-line)] bg-[var(--color-panel)] px-3 py-1.5 text-xs font-medium text-[var(--color-ink-soft)]">Dry soap basis</span>
 <template x-if="activeIngredientListVariant?.label">
 <span class="rounded-full border border-[var(--color-line)] bg-white px-3 py-1.5 text-xs font-medium text-[var(--color-ink-soft)]" x-text="activeIngredientListVariant.label"></span>
 </template>
 <template x-if="drySoapOutputListText">
 <button type="button" @click="copyGeneratedIngredientList()" class="rounded-full border border-[var(--color-line-strong)] bg-[var(--color-accent-soft)] px-4 py-2 text-xs font-medium text-[var(--color-ink-strong)] transition hover:bg-white">
 Copy list
 </button>
 </template>
 <template x-if="inciCopyMessage">
 <span class="rounded-full border border-[var(--color-line)] bg-white px-3 py-1.5 text-xs font-medium text-[var(--color-ink-soft)]" x-text="inciCopyMessage"></span>
 </template>
 </div>
 </div>

 <template x-if="ingredientListVariants.length > 1">
 <div class="mt-4 flex flex-wrap gap-2">
 <template x-for="variant in ingredientListVariants" :key="variant.key">
 <button
 type="button"
 @click="selectIngredientListVariant(variant.key)"
 :class="activeIngredientListVariantKey === variant.key
 ? 'border-[var(--color-line-strong)] bg-[var(--color-accent-soft)] text-[var(--color-ink-strong)]'
 : 'border-[var(--color-line)] bg-white text-[var(--color-ink-soft)] hover:bg-[var(--color-panel)]'"
 class="rounded-full border px-4 py-2 text-xs font-medium transition"
 x-text="variant.label"
 ></button>
 </template>
 </div>
 </template>

 <div class="mt-4 rounded-lg bg-[var(--color-panel-strong)] px-5 py-4">
 <template x-if="activeIngredientListVariant?.note">
 <p class="mb-3 text-xs leading-5 text-[var(--color-ink-soft)]" x-text="activeIngredientListVariant.note"></p>
 </template>
 <template x-if="drySoapOutputListText">
 <p class="text-[0.95rem] leading-8 font-medium tracking-[0.01em] [font-stretch:88%] text-[var(--color-ink-strong)]" x-text="drySoapOutputListText"></p>
 </template>
 <template x-if="!drySoapOutputListText">
 <p class="text-sm text-[var(--color-ink-soft)]">The generated ingredient list will appear here once the formula has enough data to resolve a preview.</p>
 </template>
 </div>

 <template x-if="labelingWarnings.length > 0">
 <div class="mt-4 space-y-2">
 <template x-for="warning in labelingWarnings" :key="warning">
 <div class="rounded-[1.25rem] border border-[var(--color-warning-soft)] bg-[var(--color-warning-soft)] px-4 py-3 text-sm text-[var(--color-warning-strong)]" x-text="warning"></div>
 </template>
 </div>
 </template>

 <div class="mt-5 overflow-hidden rounded-lg bg-[var(--color-panel-strong)]">
 <div class="border-b border-[var(--color-line)] px-4 py-3">
 <p class="font-medium text-[var(--color-ink-strong)]">Declaration details</p>
 <p class="mt-1 text-xs text-[var(--color-ink-soft)]">All recorded fragrance declarations are listed here with their estimated contribution to the cured-bar basis and whether they are appended to the selected ingredient list.</p>
 </div>

 <template x-if="drySoapDeclarationRows.length > 0">
 <div class="overflow-x-auto">
 <table class="min-w-full divide-y divide-[var(--color-line)] text-sm">
 <thead class="bg-[var(--color-panel)] text-left text-xs font-semibold tracking-[0.14em] text-[var(--color-ink-soft)] uppercase">
 <tr>
 <th class="px-4 py-3">Label</th>
 <th class="px-4 py-3">Sources</th>
 <th class="px-4 py-3">Dry soap %</th>
 <th class="px-4 py-3">Threshold</th>
 <th class="px-4 py-3">Status</th>
 <th class="px-4 py-3">Notes</th>
 </tr>
 </thead>
 <tbody class="divide-y divide-[var(--color-line)] bg-white text-[var(--color-ink-soft)]">
 <template x-for="row in drySoapDeclarationRows" :key="row.label">
 <tr>
 <td class="px-4 py-3 font-medium text-[var(--color-ink-strong)]" x-text="row.label"></td>
 <td class="px-4 py-3" x-text="row.source_ingredients.join(', ')"></td>
 <td class="px-4 py-3 font-medium text-[var(--color-ink-strong)]" x-text="`${format(row.percent_of_dry_basis, 4)}%`"></td>
 <td class="px-4 py-3" x-text="`${format(row.threshold_percent, 3)}%`"></td>
 <td class="px-4 py-3">
 <span :class="declarationStatusClasses(row)" class="inline-flex rounded-full border px-3 py-1 text-xs font-medium" x-text="row.status_label"></span>
 </td>
 <td class="px-4 py-3 leading-6" x-text="row.notes"></td>
 </tr>
 </template>
 </tbody>
 </table>
 </div>
 </template>

 <template x-if="drySoapDeclarationRows.length === 0">
 <div class="px-4 py-6 text-sm text-[var(--color-ink-soft)]">
 No declaration rows are available yet. Add aromatic ingredients with recorded declaration data to see the threshold breakdown here.
 </div>
 </template>
 </div>
</section>
