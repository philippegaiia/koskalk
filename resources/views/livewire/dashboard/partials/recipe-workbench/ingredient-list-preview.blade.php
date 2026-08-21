@php($isCosmeticWorkbench = $isCosmeticWorkbench ?? false)

<section class="sk-card p-5" aria-labelledby="ingredient-list-preview-heading">
 <div class="min-w-0">
 <h2 id="ingredient-list-preview-heading" class="sk-eyebrow">{{ __('workbench.output.lists.title') }}</h2>
 <p class="mt-1 max-w-3xl text-sm text-[var(--color-ink-soft)]">{{ __('workbench.output.lists.help') }}</p>
 </div>

 <div class="mt-5 grid items-stretch gap-5 xl:grid-cols-2">
 <div class="flex flex-col gap-5">
 <section class="sk-inset flex flex-1 flex-col px-5 py-4" aria-labelledby="generated-inci-list-heading">
 <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
 <div class="min-w-0 sm:max-w-64">
 <h3 id="generated-inci-list-heading" class="text-base font-semibold text-[var(--color-ink-strong)]">{{ __('workbench.output.lists.inci') }}</h3>
 <p class="mt-1 text-xs leading-5 text-[var(--color-ink-soft)]" x-text="ingredientListVariantHelperText"></p>
 </div>
 <div class="flex shrink-0 flex-nowrap items-center gap-2">
 <template x-if="curedSoapOutputListText">
 <button type="button" @click="copyIngredientList(curedSoapOutputListText, 'generated-inci')" class="sk-btn sk-btn-outline">
 {{ __('workbench.output.lists.copy') }}
 </button>
 </template>
 <template x-if="curedSoapOutputListText">
 <button type="button" @click="useGeneratedIngredientListAsFinal()" class="sk-btn sk-btn-outline">
 {{ __('workbench.output.lists.use_generated') }}
 </button>
 </template>
 <template x-if="ingredientListCopyTarget === 'generated-inci' && ingredientListCopyMessage">
 <span role="status" aria-live="polite" class="text-xs text-[var(--color-ink-soft)]" x-text="ingredientListCopyMessage"></span>
 </template>
 </div>
 </div>

 <template x-if="ingredientListVariants.length > 1">
 <div class="mt-4 flex flex-wrap gap-2" role="radiogroup" aria-label="{{ __('workbench.output.lists.format') }}">
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
 x-text="variant.key === 'incorporated_ingredients' ? t('output.lists.as_added') : t('output.lists.saponified')"
 ></button>
 </template>
 </div>
 </template>

 <div class="mt-4 flex-1 rounded-lg bg-[var(--color-field)] px-4 py-4">
 <template x-if="curedSoapOutputListText">
 <p class="text-sm leading-7 font-medium text-[var(--color-ink-strong)]" x-text="curedSoapOutputListText"></p>
 </template>
 <template x-if="!curedSoapOutputListText">
 <p class="text-sm text-[var(--color-ink-soft)]">{{ __('workbench.output.lists.generated_empty') }}</p>
 </template>
 </div>
 </section>

 <section class="sk-inset px-5 py-4" aria-labelledby="final-inci-list-heading">
 <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
 <h3 id="final-inci-list-heading" class="text-base font-semibold text-[var(--color-ink-strong)]">{{ __('workbench.output.lists.final_inci') }}</h3>
 <div class="flex flex-wrap items-center gap-2">
 <template x-if="finalIngredientList">
 <button type="button" @click="copyIngredientList(finalIngredientList, 'final-inci')" class="sk-btn sk-btn-outline">
 {{ __('workbench.output.lists.copy') }}
 </button>
 </template>
 <template x-if="finalIngredientList">
 <button type="button" @click="clearFinalIngredientList()" class="sk-btn sk-btn-ghost">
 {{ __('workbench.output.lists.clear') }}
 </button>
 </template>
 <template x-if="ingredientListCopyTarget === 'final-inci' && ingredientListCopyMessage">
 <span role="status" aria-live="polite" class="text-xs text-[var(--color-ink-soft)]" x-text="ingredientListCopyMessage"></span>
 </template>
 </div>
 </div>
 <template x-if="finalIngredientListIsOutdated">
 <div class="mt-3 rounded-lg border border-[var(--color-warning-soft)] bg-[var(--color-warning-soft)] px-4 py-3 text-xs font-medium text-[var(--color-warning-strong)]">
 {{ __('workbench.output.lists.outdated') }}
 </div>
 </template>
 <textarea
 x-model="finalIngredientList"
 @input="touchFinalIngredientList()"
 rows="5"
 aria-label="{{ __('workbench.output.lists.final_inci') }}"
 class="mt-3 w-full resize-y rounded-lg border border-[var(--color-line)] bg-white px-4 py-3 text-sm leading-6 text-[var(--color-ink-strong)] outline-none transition focus:border-[var(--color-line-strong)]"
 placeholder="{{ __('workbench.output.lists.final_inci') }}"
 ></textarea>
 </section>
 </div>

 <div class="flex flex-col gap-5">
 <section class="sk-inset flex flex-1 flex-col px-5 py-4" aria-labelledby="generated-plain-list-heading">
 <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
 <div class="min-w-0">
 <h3 id="generated-plain-list-heading" class="text-base font-semibold text-[var(--color-ink-strong)]">{{ __('workbench.output.lists.plain') }}</h3>
 <p class="mt-1 text-xs leading-5 text-[var(--color-ink-soft)]">{{ __('workbench.output.lists.plain_help') }}</p>
 </div>
 <div class="flex flex-wrap items-center gap-2">
 <template x-if="generatedPlainLanguageListText">
 <button type="button" @click="copyIngredientList(generatedPlainLanguageListText, 'generated-plain')" class="sk-btn sk-btn-outline">
 {{ __('workbench.output.lists.copy') }}
 </button>
 </template>
 <template x-if="generatedPlainLanguageListText">
 <button type="button" @click="useGeneratedPlainIngredientListAsFinal()" class="sk-btn sk-btn-outline">
 {{ __('workbench.output.lists.use_generated') }}
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
 <p class="text-sm text-[var(--color-ink-soft)]">{{ __('workbench.output.lists.plain_empty') }}</p>
 </template>
 </div>
 </section>

 <section class="sk-inset px-5 py-4" aria-labelledby="final-plain-list-heading">
 <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
 <h3 id="final-plain-list-heading" class="text-base font-semibold text-[var(--color-ink-strong)]">{{ __('workbench.output.lists.final_plain') }}</h3>
 <div class="flex flex-wrap items-center gap-2">
 <template x-if="finalPlainIngredientList">
 <button type="button" @click="copyIngredientList(finalPlainIngredientList, 'final-plain')" class="sk-btn sk-btn-outline">
 {{ __('workbench.output.lists.copy') }}
 </button>
 </template>
 <template x-if="finalPlainIngredientList">
 <button type="button" @click="clearFinalPlainIngredientList()" class="sk-btn sk-btn-ghost">
 {{ __('workbench.output.lists.clear') }}
 </button>
 </template>
 <template x-if="ingredientListCopyTarget === 'final-plain' && ingredientListCopyMessage">
 <span role="status" aria-live="polite" class="text-xs text-[var(--color-ink-soft)]" x-text="ingredientListCopyMessage"></span>
 </template>
 </div>
 </div>
 <template x-if="finalPlainIngredientListIsOutdated">
 <div class="mt-3 rounded-lg border border-[var(--color-warning-soft)] bg-[var(--color-warning-soft)] px-4 py-3 text-xs font-medium text-[var(--color-warning-strong)]">
 {{ __('workbench.output.lists.outdated') }}
 </div>
 </template>
 <textarea
 x-model="finalPlainIngredientList"
 @input="touchFinalPlainIngredientList()"
 rows="5"
 aria-label="{{ __('workbench.output.lists.final_plain') }}"
 class="mt-3 w-full resize-y rounded-lg border border-[var(--color-line)] bg-white px-4 py-3 text-sm leading-6 text-[var(--color-ink-strong)] outline-none transition focus:border-[var(--color-line-strong)]"
 placeholder="{{ __('workbench.output.lists.final_plain') }}"
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
 <p class="font-medium text-[var(--color-ink-strong)]">{{ __('workbench.output.declarations.title') }}</p>
 @if ($isCosmeticWorkbench)
 <p class="mt-1 text-xs text-[var(--color-ink-soft)]">{{ __('workbench.output.declarations.cosmetic_help') }}</p>
 @else
 <p class="mt-1 text-xs text-[var(--color-ink-soft)]">{{ __('workbench.output.declarations.soap_help') }}</p>
 @endif
 </div>

 <template x-if="curedSoapDeclarationRows.length > 0">
 <div class="overflow-x-auto">
 <table class="min-w-full divide-y divide-[var(--color-line)] text-sm">
 <thead class="bg-[var(--color-panel)] text-left text-xs font-semibold tracking-[0.14em] text-[var(--color-ink-soft)] uppercase">
 <tr>
 <th class="px-4 py-3">{{ __('workbench.output.common.label') }}</th>
 <th class="px-4 py-3">{{ __('workbench.output.common.sources') }}</th>
 <th class="px-4 py-3">{{ $isCosmeticWorkbench ? __('workbench.output.declarations.formula_percent') : __('workbench.output.common.soap_percent') }}</th>
 <th class="px-4 py-3">{{ __('workbench.output.declarations.threshold') }}</th>
 <th class="px-4 py-3">{{ __('workbench.output.common.status') }}</th>
 <th class="px-4 py-3">{{ __('workbench.output.declarations.notes') }}</th>
 </tr>
 </thead>
 <tbody class="divide-y divide-[var(--color-line)] bg-white text-[var(--color-ink-soft)]">
 <template x-for="row in curedSoapDeclarationRows" :key="row.label">
 <tr>
 <td class="px-4 py-3 font-medium text-[var(--color-ink-strong)]" x-text="row.label"></td>
 <td class="px-4 py-3">
 <template x-for="(source, idx) in row.source_ingredients" :key="idx">
 <span class="mr-2 inline-flex items-center gap-1"><span x-show="row.source_is_user_owned?.[idx]" class="inline-block size-1.5 rounded-full bg-[var(--color-ink-soft)] opacity-60" title="{{ __('workbench.output.common.user_owned') }}"></span><span x-text="source"></span></span>
 </template>
 </td>
 <td class="numeric px-4 py-3 font-medium text-[var(--color-ink-strong)]"><span class="sk-decimal-aligned" :style="decimalAlignmentStyle(row.percent_of_cured_basis)" x-text="`${format(row.percent_of_cured_basis, 4)}%`"></span></td>
 <td class="numeric px-4 py-3"><span class="sk-decimal-aligned" :style="decimalAlignmentStyle(row.threshold_percent)" x-text="`${format(row.threshold_percent, 3)}%`"></span></td>
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
 {{ __('workbench.output.declarations.empty') }}
 </div>
 </template>
 </div>
</section>
