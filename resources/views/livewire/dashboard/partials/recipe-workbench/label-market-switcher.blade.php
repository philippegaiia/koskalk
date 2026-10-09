<div class="flex flex-col gap-1 lg:items-end">
 <p class="sk-eyebrow">{{ __('workbench.output.common.label_market') }}</p>
 <div role="radiogroup" aria-label="{{ __('workbench.output.common.label_market') }}" class="flex flex-wrap gap-2">
 <button
 type="button"
 role="radio"
 data-market-none
 :aria-checked="(regulatoryRegime === 'none').toString()"
 @click="regulatoryRegime = 'none'"
 title="{{ __('workbench.output.market_check.coverage_none') }}"
 :class="regulatoryRegime === 'none'
 ? 'border-[var(--color-active)] bg-[var(--color-active-soft)] text-[var(--color-active-strong)]'
 : 'border-[var(--color-line)] bg-[var(--color-panel)] text-[var(--color-ink-soft)] hover:bg-[var(--color-panel-strong)]'"
 class="rounded-full border px-4 py-2.5 text-xs font-medium transition disabled:cursor-not-allowed disabled:opacity-50"
 >{{ __('workbench.output.market_check.none_option') }}</button>
 <template x-for="regime in regulatoryRegimes" :key="regime.code">
 <button
 type="button"
 role="radio"
 :aria-checked="(regulatoryRegime === regime.code).toString()"
 @click="regulatoryRegime = regime.code"
 :title="regime.version_label ? `${regime.name} - ${regime.version_label}` : regime.name"
 :class="regulatoryRegime === regime.code
 ? 'border-[var(--color-active)] bg-[var(--color-active-soft)] text-[var(--color-active-strong)]'
 : 'border-[var(--color-line)] bg-[var(--color-panel)] text-[var(--color-ink-soft)] hover:bg-[var(--color-panel-strong)]'"
 class="rounded-full border px-4 py-2.5 text-xs font-medium transition disabled:cursor-not-allowed disabled:opacity-50"
 x-text="regime.name"
 ></button>
 </template>
 </div>
 <p x-show="regulatoryRegimeMilestoneHint" class="max-w-md text-xs leading-5 text-[var(--color-ink-soft)]" x-text="regulatoryRegimeMilestoneHint"></p>
 <div x-show="isLockedMarketPreview" x-cloak role="status" class="flex max-w-md flex-wrap items-center gap-2 text-xs leading-5 text-[var(--color-ink-soft)] lg:justify-end">
 <span x-text="t('output.market_check.locked_preview', { market: savedRegulatoryRegimeLabel })"></span>
 <button type="button" @click="regulatoryRegime = savedRegulatoryRegime" class="rounded-full border border-[var(--color-line)] bg-[var(--color-panel)] px-3 py-2.5 text-xs font-medium text-[var(--color-ink-strong)] transition hover:bg-[var(--color-panel-strong)]">{{ __('workbench.output.market_check.back_to_saved') }}</button>
 </div>
</div>
