<div class="flex flex-col gap-1 lg:items-end">
 <p class="sk-eyebrow">{{ __('workbench.output.common.label_market') }}</p>
 <div role="radiogroup" aria-label="{{ __('workbench.output.common.label_market') }}" class="flex flex-wrap gap-2">
 <template x-for="regime in regulatoryRegimes" :key="regime.code">
 <button
 type="button"
 role="radio"
 :aria-checked="(regulatoryRegime === regime.code).toString()"
 :disabled="isFormulaLocked"
 @click="regulatoryRegime = regime.code"
 :title="regime.version_label ? `${regime.name} - ${regime.version_label}` : regime.name"
 :class="regulatoryRegime === regime.code
 ? 'border-[var(--color-active)] bg-[var(--color-active-soft)] text-[var(--color-active-strong)]'
 : 'border-[var(--color-line)] bg-[var(--color-panel)] text-[var(--color-ink-soft)] hover:bg-[var(--color-panel-strong)]'"
 class="rounded-full border px-4 py-2 text-xs font-medium transition disabled:cursor-not-allowed disabled:opacity-50"
 x-text="regime.name"
 ></button>
 </template>
 </div>
 <p x-show="regulatoryRegimeMilestoneHint" class="max-w-md text-xs leading-5 text-[var(--color-ink-soft)]" x-text="regulatoryRegimeMilestoneHint"></p>
</div>
