<section id="liquid-soap-process" class="overflow-hidden sk-card sk-phase-craft sk-tone-summary" aria-labelledby="liquid-soap-process-heading">
 <div class="sk-section-header sk-section-header-formula border-b border-[var(--color-line)] px-5 py-4">
 <h3 id="liquid-soap-process-heading" class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('workbench.liquid.title') }}</h3>
 <p class="mt-1 text-xs text-[var(--color-ink-soft)]">{{ __('workbench.liquid.help') }}</p>
 </div>

 <div class="space-y-5 p-5">
 <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 @5xl/workbench:grid-cols-4">
 <label class="block">
 <span id="liquid-first-dilution-label" class="sk-eyebrow">{{ __('workbench.liquid.first_dilution') }}</span>
 <div class="mt-2 flex items-center gap-2">
 <input aria-labelledby="liquid-first-dilution-label" x-model="liquidProcess.firstDilutionPercentage" @blur="normalizeDecimalBlur($event)" type="text" inputmode="decimal" class="sk-input numeric" />
 <span class="text-sm text-[var(--color-ink-soft)]">%</span>
 </div>
 <span class="mt-1 block text-xs text-[var(--color-ink-soft)]">{{ __('workbench.liquid.first_dilution_help') }}</span>
 </label>
 <label class="block">
 <span id="liquid-final-dilution-label" class="sk-eyebrow">{{ __('workbench.liquid.final_dilution') }}</span>
 <div class="mt-2 flex items-center gap-2">
 <input aria-labelledby="liquid-final-dilution-label" x-model="liquidProcess.finalDilutionPercentage" @blur="normalizeDecimalBlur($event)" type="text" inputmode="decimal" class="sk-input numeric" />
 <span class="text-sm text-[var(--color-ink-soft)]">%</span>
 </div>
 <span class="mt-1 block text-xs text-[var(--color-ink-soft)]">{{ __('workbench.liquid.final_dilution_help') }}</span>
 </label>
 <label class="block">
 <span id="liquid-solution-label" class="sk-eyebrow">{{ __('workbench.liquid.solution_strength') }}</span>
 <div class="mt-2 flex items-center gap-2">
 <input aria-labelledby="liquid-solution-label" x-model="liquidProcess.neutralizerSolutionPercentage" @blur="normalizeDecimalBlur($event)" type="text" inputmode="decimal" class="sk-input numeric" />
 <span class="text-sm text-[var(--color-ink-soft)]">%</span>
 </div>
 <span class="mt-1 block text-xs text-[var(--color-ink-soft)]">{{ __('workbench.liquid.solution_strength_help') }}</span>
 </label>
 <label class="block">
 <span id="liquid-weighed-paste-label" class="sk-eyebrow">{{ __('workbench.liquid.weighed_paste') }}</span>
 <div class="mt-2 flex items-center gap-2">
 <input aria-labelledby="liquid-weighed-paste-label" x-model="liquidProcess.measuredPasteWeight" @blur="normalizeDecimalBlur($event)" type="text" inputmode="decimal" :placeholder="liquidProcessIsReady ? format(liquidProcessPreview.paste.theoretical_weight, calculatedMassDecimals(liquidProcessPreview.paste.theoretical_weight)) : ''" class="sk-input numeric" />
 <span class="text-sm text-[var(--color-ink-soft)]" x-text="oilUnit"></span>
 </div>
 <span class="mt-1 block text-xs text-[var(--color-ink-soft)]">{{ __('workbench.liquid.weighed_paste_help') }}</span>
 </label>
 </div>

 <div class="sk-inset space-y-3 p-4">
 <div class="flex flex-col gap-3 lg:flex-row lg:items-end">
 <label class="block min-w-0 flex-1">
 <span id="liquid-neutralizer-label" class="sk-eyebrow">{{ __('workbench.liquid.neutralizer') }}</span>
 <select aria-labelledby="liquid-neutralizer-label" :value="liquidNeutralizerRow?.ingredient_id ?? ''" @change="chooseLiquidNeutralizer($event.target.value)" class="sk-input mt-2">
 <option value="">{{ __('workbench.liquid.neutralizer_none') }}</option>
 <template x-for="option in liquidNeutralizerOptions" :key="option.id">
 <option :value="option.id" :selected="liquidNeutralizerRow?.ingredient_id === option.id" x-text="option.name"></option>
 </template>
 </select>
 </label>
 <template x-if="liquidNeutralizerRow !== null">
 <label class="block lg:w-48">
 <span id="liquid-neutralizer-amount-label" class="sk-eyebrow" x-text="t('liquid.neutralizer_amount', { unit: oilUnit })"></span>
 <input aria-labelledby="liquid-neutralizer-amount-label" x-effect="syncFormattedInput($el, liquidNeutralizerWeight(), additionWeightDecimals(liquidNeutralizerWeight()))" @input="setLiquidNeutralizerWeight($event.target.value)" @blur="normalizeDecimalBlur($event); $el.value = format(liquidNeutralizerWeight(), additionWeightDecimals(liquidNeutralizerWeight()))" type="text" inputmode="decimal" class="sk-input numeric mt-2" />
 </label>
 </template>
 <template x-if="liquidNeutralizerRow !== null && liquidProcessPreview?.neutralizer">
 <button type="button" @click="useRecommendedLiquidNeutralizer()" class="sk-btn sk-btn-outline min-h-11 whitespace-nowrap">{{ __('workbench.liquid.use_recommended') }}</button>
 </template>
 </div>

 <template x-if="liquidNeutralizerOptions.length === 0">
 <p class="text-xs text-[var(--color-ink-soft)]">{{ __('workbench.liquid.no_neutralizers') }}</p>
 </template>
 <template x-if="liquidProcessIsReady && liquidProcessPreview.neutralizer">
 <p class="numeric text-sm text-[var(--color-ink-strong)]" x-text="t('liquid.recommended', { amount: format(liquidProcessPreview.neutralizer.recommended_weight, additionWeightDecimals(liquidProcessPreview.neutralizer.recommended_weight)), unit: oilUnit })"></p>
 </template>
 <template x-if="liquidProcessIsReady && number(liquidProcessPreview.paste_composition?.free_koh_weight) <= 0">
 <p class="text-sm text-[var(--color-ink-soft)]">{{ __('workbench.liquid.no_free_koh') }}</p>
 </template>
 <template x-if="liquidProcessIsReady">
 <ul class="space-y-1">
 <template x-for="warning in liquidProcessPreview.warnings" :key="warning">
 <li role="alert" :class="warning === 'neutralizer_amount_is_theoretical' ? 'text-[var(--color-ink-soft)]' : 'text-[var(--color-warning-strong)]'" class="text-xs" x-text="t(`liquid.warnings.${warning}`)"></li>
 </template>
 </ul>
 </template>
 </div>

 <template x-if="liquidProcessPreview?.status === 'invalid'">
 <p role="alert" class="rounded-lg bg-[var(--color-warning-soft)] p-3 text-sm text-[var(--color-warning-strong)]" x-text="liquidProcessPreview.message"></p>
 </template>
 <template x-if="liquidProcessPreview === null">
 <p class="text-sm text-[var(--color-ink-soft)]">{{ __('workbench.liquid.waiting') }}</p>
 </template>

 <template x-if="liquidProcessIsReady">
 <div class="overflow-hidden rounded-lg border border-[var(--color-line)]" role="region" aria-label="{{ __('workbench.liquid.stages_label') }}">
 <div class="grid grid-cols-[minmax(0,1.6fr)_repeat(3,minmax(0,1fr))] gap-px bg-[var(--color-line)] text-sm">
 <div class="bg-[var(--color-field-muted)] px-4 py-2.5 font-medium text-[var(--color-ink-strong)]">{{ __('workbench.liquid.stage') }}</div>
 <div class="bg-[var(--color-field-muted)] px-4 py-2.5 text-right font-medium text-[var(--color-ink-strong)]" x-text="t('liquid.add', { unit: oilUnit })"></div>
 <div class="bg-[var(--color-field-muted)] px-4 py-2.5 text-right font-medium text-[var(--color-ink-strong)]" x-text="t('liquid.running_total', { unit: oilUnit })"></div>
 <div class="bg-[var(--color-field-muted)] px-4 py-2.5 text-right font-medium text-[var(--color-ink-strong)]">{{ __('workbench.liquid.anhydrous') }}</div>
 <template x-for="stage in liquidStageRows()" :key="stage.key">
 <div class="contents">
 <div class="bg-white px-4 py-2.5">
 <p class="font-medium text-[var(--color-ink-strong)]" x-text="t(`liquid.stages.${stage.key}`)"></p>
 <template x-if="stage.status !== 'ok'">
 <p role="alert" class="mt-1 text-xs text-[var(--color-warning-strong)]" x-text="t(`liquid.status.${stage.status}`)"></p>
 </template>
 </div>
 <div class="numeric bg-white px-4 py-2.5 text-right text-[var(--color-ink-strong)]" x-text="stage.add === null ? '—' : format(stage.add, calculatedMassDecimals(stage.add))"></div>
 <div class="numeric bg-white px-4 py-2.5 text-right text-[var(--color-ink-strong)]" x-text="stage.mass === null ? '—' : format(stage.mass, calculatedMassDecimals(stage.mass))"></div>
 <div class="numeric bg-white px-4 py-2.5 text-right font-medium text-[var(--color-ink-strong)]" x-text="stage.anhydrous === null ? '—' : `${format(stage.anhydrous, 1)}%`"></div>
 </div>
 </template>
 </div>
 </div>
 </template>

 <template x-if="liquidProcessIsReady && liquidCompositionRows().length > 0">
 <section aria-labelledby="liquid-composition-heading">
 <div class="flex items-baseline justify-between gap-3">
 <p id="liquid-composition-heading" class="sk-eyebrow">{{ __('workbench.liquid.composition') }}</p>
 <p class="numeric text-sm font-semibold text-[var(--color-ink-strong)]" x-text="t('liquid.final_batch', { amount: format(liquidProcessPreview.final_weight, calculatedMassDecimals(liquidProcessPreview.final_weight)), unit: oilUnit })"></p>
 </div>
 <div class="mt-3 flex h-3 w-full gap-px overflow-hidden rounded-full bg-[var(--color-field-muted)]" aria-hidden="true">
 <template x-for="component in liquidCompositionRows()" :key="component.key">
 <div class="h-full" :style="`width: ${number(component.share)}%; background: ${component.color}`"></div>
 </template>
 </div>
 <div class="mt-4 grid gap-4 @3xl/workbench:grid-cols-[minmax(0,15rem)_minmax(0,1fr)]">
 <div class="sk-inset px-4 py-3">
 <p class="sk-eyebrow">{{ __('workbench.liquid.anhydrous') }}</p>
 <p class="numeric mt-2 text-2xl font-semibold leading-none text-[var(--color-ink-strong)]" x-text="`${format(liquidAnhydrousSoap().percentage, 1)}%`"></p>
 <p class="numeric mt-1.5 text-sm text-[var(--color-ink-strong)]" x-text="`${format(liquidAnhydrousSoap().weight, calculatedMassDecimals(liquidAnhydrousSoap().weight))} ${oilUnit}`"></p>
 <template x-if="liquidAnhydrousSoap().pasteWeight !== null">
 <p class="numeric mt-0.5 text-xs text-[var(--color-ink-soft)]" x-text="t('liquid.anhydrous_from_paste', { amount: format(liquidAnhydrousSoap().pasteWeight, calculatedMassDecimals(liquidAnhydrousSoap().pasteWeight)), unit: oilUnit })"></p>
 </template>
 <p class="mt-2 text-xs text-[var(--color-ink-soft)]">{{ __('workbench.liquid.anhydrous_parts') }}</p>
 </div>
 <dl class="grid content-start gap-x-6 sm:grid-cols-2">
 <template x-for="component in liquidCompositionRows()" :key="component.key">
 <div class="flex items-baseline justify-between gap-3 border-b border-[var(--color-line)] py-2 text-sm">
 <dt class="flex min-w-0 items-center gap-2 text-[var(--color-ink-strong)]">
 <span class="size-2.5 shrink-0 rounded-sm" :style="`background: ${component.color}`" aria-hidden="true"></span>
 <span class="truncate" x-text="t(`liquid.components.${component.key}`)"></span>
 </dt>
 <dd class="numeric shrink-0 text-right">
 <span class="font-semibold text-[var(--color-ink-strong)]" x-text="`${format(component.share, 1)}%`"></span>
 <span class="ml-2 text-xs text-[var(--color-ink-soft)]" x-text="`${format(component.weight, calculatedMassDecimals(component.weight))} ${oilUnit}`"></span>
 </dd>
 </div>
 </template>
 </dl>
 </div>
 </section>
 </template>
 </div>
</section>
