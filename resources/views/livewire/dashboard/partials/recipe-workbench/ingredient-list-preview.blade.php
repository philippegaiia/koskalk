@php($isCosmeticWorkbench = $isCosmeticWorkbench ?? false)

<section class="sk-card p-5" aria-labelledby="ingredient-list-preview-heading">
 <div class="min-w-0">
 <h2 id="ingredient-list-preview-heading" class="sk-eyebrow">Ingredient lists</h2>
 <p class="mt-1 max-w-3xl text-sm text-[var(--color-ink-soft)]">Choose a list, then copy it or edit a final version.</p>
 </div>

 <div class="mt-5 grid items-stretch gap-5 xl:grid-cols-2">
 <div class="flex flex-col gap-5">
 <section class="sk-inset flex flex-1 flex-col px-5 py-4" aria-labelledby="generated-inci-list-heading">
 <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
 <div class="min-w-0 sm:max-w-64">
 <h3 id="generated-inci-list-heading" class="text-base font-semibold text-[var(--color-ink-strong)]">INCI ingredient list</h3>
 <p class="mt-1 text-xs leading-5 text-[var(--color-ink-soft)]" x-text="ingredientListVariantHelperText"></p>
 </div>
 <div class="flex shrink-0 flex-nowrap items-center gap-2">
 <template x-if="curedSoapOutputListText">
 <button type="button" @click="copyIngredientList(curedSoapOutputListText, 'generated-inci')" class="sk-btn sk-btn-outline">
 Copy list
 </button>
 </template>
 <template x-if="curedSoapOutputListText">
 <button type="button" @click="useGeneratedIngredientListAsFinal()" class="sk-btn sk-btn-outline">
 Use generated
 </button>
 </template>
 <template x-if="ingredientListCopyTarget === 'generated-inci' && ingredientListCopyMessage">
 <span role="status" aria-live="polite" class="text-xs text-[var(--color-ink-soft)]" x-text="ingredientListCopyMessage"></span>
 </template>
 </div>
 </div>

 <template x-if="ingredientListVariants.length > 1">
 <div class="mt-4 flex flex-wrap gap-2" role="radiogroup" aria-label="Ingredient list format">
 <template x-for="variant in ingredientListVariants" :key="variant.key">
 <button
 type="button"
 role="radio"
 :aria-checked="(activeIngredientListVariantKey === variant.key).toString()"
 @click="selectIngredientListVariant(variant.key)"
 :class="activeIngredientListVariantKey === variant.key
 ? 'border-[var(--color-active)] bg-[var(--color-active-soft)] text-[var(--color-active-strong)]'
 : 'border-[var(--color-line)] bg-[var(--color-panel)] text-[var(--color-ink-soft)] hover:bg-[var(--color-panel-strong)]'"
 class="rounded-full border px-4 py-2.5 text-xs font-medium transition"
 x-text="variant.key === 'incorporated_ingredients' ? 'Ingredients as added' : 'Saponified oils + superfat'"
 ></button>
 </template>
 </div>
 </template>

 <div class="mt-4 flex-1 rounded-lg bg-[var(--color-field)] px-4 py-4">
 <template x-if="curedSoapOutputListText">
 <p class="text-sm leading-7 font-medium text-[var(--color-ink-strong)]" x-text="curedSoapOutputListText"></p>
 </template>
 <template x-if="!curedSoapOutputListText">
 <p class="text-sm text-[var(--color-ink-soft)]">The generated ingredient list will appear when the formula has enough data.</p>
 </template>
 </div>
 </section>

 <section class="sk-inset px-5 py-4" aria-labelledby="final-inci-list-heading">
 <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
 <h3 id="final-inci-list-heading" class="text-base font-semibold text-[var(--color-ink-strong)]">Final INCI list</h3>
 <div class="flex flex-wrap items-center gap-2">
 <template x-if="finalIngredientList">
 <button type="button" @click="copyIngredientList(finalIngredientList, 'final-inci')" class="sk-btn sk-btn-outline">
 Copy list
 </button>
 </template>
 <template x-if="finalIngredientList">
 <button type="button" @click="clearFinalIngredientList()" class="sk-btn sk-btn-ghost">
 Clear
 </button>
 </template>
 <template x-if="ingredientListCopyTarget === 'final-inci' && ingredientListCopyMessage">
 <span role="status" aria-live="polite" class="text-xs text-[var(--color-ink-soft)]" x-text="ingredientListCopyMessage"></span>
 </template>
 </div>
 </div>
 <template x-if="finalIngredientListIsOutdated">
 <div class="mt-3 rounded-lg border border-[var(--color-warning-soft)] bg-[var(--color-warning-soft)] px-4 py-3 text-xs font-medium text-[var(--color-warning-strong)]">
 Formula changed after this list was saved.
 </div>
 </template>
 <textarea
 x-model="finalIngredientList"
 @input="touchFinalIngredientList()"
 rows="5"
 aria-label="Final INCI ingredient list"
 class="mt-3 w-full resize-y rounded-lg border border-[var(--color-line)] bg-white px-4 py-3 text-sm leading-6 text-[var(--color-ink-strong)] outline-none transition focus:border-[var(--color-line-strong)]"
 placeholder="Final INCI ingredient list"
 ></textarea>
 </section>
 </div>

 <div class="flex flex-col gap-5">
 <section class="sk-inset flex flex-1 flex-col px-5 py-4" aria-labelledby="generated-plain-list-heading">
 <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
 <div class="min-w-0">
 <h3 id="generated-plain-list-heading" class="text-base font-semibold text-[var(--color-ink-strong)]">Plain-language ingredient list</h3>
 <p class="mt-1 text-xs leading-5 text-[var(--color-ink-soft)]">Common names, highest amount first.</p>
 </div>
 <div class="flex flex-wrap items-center gap-2">
 <template x-if="generatedPlainLanguageListText">
 <button type="button" @click="copyIngredientList(generatedPlainLanguageListText, 'generated-plain')" class="sk-btn sk-btn-outline">
 Copy list
 </button>
 </template>
 <template x-if="generatedPlainLanguageListText">
 <button type="button" @click="useGeneratedPlainIngredientListAsFinal()" class="sk-btn sk-btn-outline">
 Use generated
 </button>
 </template>
 <template x-if="ingredientListCopyTarget === 'generated-plain' && ingredientListCopyMessage">
 <span role="status" aria-live="polite" class="text-xs text-[var(--color-ink-soft)]" x-text="ingredientListCopyMessage"></span>
 </template>
 </div>
 </div>
 <div class="mt-4 flex-1 rounded-lg bg-[var(--color-field)] px-4 py-4">
 <template x-if="generatedPlainLanguageListText">
 <p class="text-sm leading-7 font-medium text-[var(--color-ink-strong)]" x-text="generatedPlainLanguageListText"></p>
 </template>
 <template x-if="!generatedPlainLanguageListText">
 <p class="text-sm text-[var(--color-ink-soft)]">No plain-language list is available yet.</p>
 </template>
 </div>
 </section>

 <section class="sk-inset px-5 py-4" aria-labelledby="final-plain-list-heading">
 <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
 <h3 id="final-plain-list-heading" class="text-base font-semibold text-[var(--color-ink-strong)]">Final plain-language list</h3>
 <div class="flex flex-wrap items-center gap-2">
 <template x-if="finalPlainIngredientList">
 <button type="button" @click="copyIngredientList(finalPlainIngredientList, 'final-plain')" class="sk-btn sk-btn-outline">
 Copy list
 </button>
 </template>
 <template x-if="finalPlainIngredientList">
 <button type="button" @click="clearFinalPlainIngredientList()" class="sk-btn sk-btn-ghost">
 Clear
 </button>
 </template>
 <template x-if="ingredientListCopyTarget === 'final-plain' && ingredientListCopyMessage">
 <span role="status" aria-live="polite" class="text-xs text-[var(--color-ink-soft)]" x-text="ingredientListCopyMessage"></span>
 </template>
 </div>
 </div>
 <template x-if="finalPlainIngredientListIsOutdated">
 <div class="mt-3 rounded-lg border border-[var(--color-warning-soft)] bg-[var(--color-warning-soft)] px-4 py-3 text-xs font-medium text-[var(--color-warning-strong)]">
 Formula changed after this list was saved.
 </div>
 </template>
 <textarea
 x-model="finalPlainIngredientList"
 @input="touchFinalPlainIngredientList()"
 rows="5"
 aria-label="Final plain-language ingredient list"
 class="mt-3 w-full resize-y rounded-lg border border-[var(--color-line)] bg-white px-4 py-3 text-sm leading-6 text-[var(--color-ink-strong)] outline-none transition focus:border-[var(--color-line-strong)]"
 placeholder="Final plain-language ingredient list"
 ></textarea>
 </section>
 </div>
 </div>

 <template x-if="labelingWarnings.length > 0">
 <div class="mt-4 space-y-2" role="alert">
 <template x-for="warning in labelingWarnings" :key="warning">
 <div class="rounded-[1.25rem] border border-[var(--color-warning-soft)] bg-[var(--color-warning-soft)] px-4 py-3 text-sm text-[var(--color-warning-strong)]" x-text="warning"></div>
 </template>
 </div>
 </template>

 <div class="mt-5 overflow-hidden sk-inset">
 <div class="border-b border-[var(--color-line)] px-4 py-3">
 <p class="font-medium text-[var(--color-ink-strong)]">Declaration details</p>
 @if ($isCosmeticWorkbench)
 <p class="mt-1 text-xs text-[var(--color-ink-soft)]">Recorded fragrance declarations are listed with their estimated contribution to the formula basis.</p>
 @else
 <p class="mt-1 text-xs text-[var(--color-ink-soft)]">All recorded fragrance declarations are listed here with their estimated contribution to the cured-bar basis and whether they are appended to the selected ingredient list.</p>
 @endif
 </div>

 <template x-if="curedSoapDeclarationRows.length > 0">
 <div class="overflow-x-auto">
 <table class="min-w-full divide-y divide-[var(--color-line)] text-sm">
 <thead class="bg-[var(--color-panel)] text-left text-xs font-semibold tracking-[0.14em] text-[var(--color-ink-soft)] uppercase">
 <tr>
 <th class="px-4 py-3">Label</th>
 <th class="px-4 py-3">Sources</th>
 <th class="px-4 py-3">{{ $isCosmeticWorkbench ? 'Formula %' : '% SOAP' }}</th>
 <th class="px-4 py-3">Threshold</th>
 <th class="px-4 py-3">Status</th>
 <th class="px-4 py-3">Notes</th>
 </tr>
 </thead>
 <tbody class="divide-y divide-[var(--color-line)] bg-white text-[var(--color-ink-soft)]">
 <template x-for="row in curedSoapDeclarationRows" :key="row.label">
 <tr>
 <td class="px-4 py-3 font-medium text-[var(--color-ink-strong)]" x-text="row.label"></td>
 <td class="px-4 py-3">
 <template x-for="(source, idx) in row.source_ingredients" :key="idx">
 <span class="mr-2 inline-flex items-center gap-1"><span x-show="row.source_is_user_owned?.[idx]" class="inline-block size-1.5 rounded-full bg-[var(--color-ink-soft)] opacity-60" title="User-created or user-modified ingredient"></span><span x-text="source"></span></span>
 </template>
 </td>
 <td class="numeric px-4 py-3 font-medium text-[var(--color-ink-strong)]" x-text="`${format(row.percent_of_cured_basis, 4)}%`"></td>
 <td class="numeric px-4 py-3" x-text="`${format(row.threshold_percent, 3)}%`"></td>
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

 <template x-if="curedSoapDeclarationRows.length === 0">
 <div class="px-4 py-6 text-sm text-[var(--color-ink-soft)]">
 No declaration rows are available yet. Add aromatic ingredients with recorded declaration data to see the threshold breakdown here.
 </div>
 </template>
 </div>
</section>
