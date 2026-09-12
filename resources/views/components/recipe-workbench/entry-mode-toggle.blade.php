<section class="flex flex-col gap-2 rounded-lg bg-[var(--color-field-muted)] px-3 py-2.5 sm:flex-row sm:items-center sm:justify-between" aria-labelledby="formula-entry-mode-heading">
    <div class="min-w-0">
        <p id="formula-entry-mode-heading" class="sk-eyebrow">{{ __('workbench.settings.entry_mode') }}</p>
        <p class="mt-1 text-xs leading-5 text-[var(--color-ink-soft)]" x-text="entryModeHelperText"></p>
    </div>

    <div role="radiogroup" aria-labelledby="formula-entry-mode-heading" class="inline-flex shrink-0 rounded-lg bg-[var(--color-control)] p-1">
        <button
            type="button"
            role="radio"
            :aria-checked="editMode === 'percentage'"
            @click="editMode = 'percentage'"
            :class="editMode === 'percentage' ? 'bg-[var(--color-active)] text-[var(--color-on-active)] shadow-sm' : 'bg-[var(--color-control)] text-[var(--color-ink-soft)] hover:bg-[var(--color-panel)]'"
            class="inline-flex min-h-11 min-w-16 items-center justify-center rounded-md px-3 py-2 text-xs font-medium transition-colors duration-150 motion-reduce:transition-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-active)]"
        >
            <span x-text="isCosmeticFormula ? t('common.formula_percent') : t('settings.soap_percentage_entry_label')"></span>
        </button>
        <button
            type="button"
            role="radio"
            :aria-checked="editMode === 'weight'"
            @click="editMode = 'weight'"
            :class="editMode === 'weight' ? 'bg-[var(--color-active)] text-[var(--color-on-active)] shadow-sm' : 'bg-[var(--color-control)] text-[var(--color-ink-soft)] hover:bg-[var(--color-panel)]'"
            class="inline-flex min-h-11 min-w-16 items-center justify-center rounded-md px-3 py-2 text-xs font-medium transition-colors duration-150 motion-reduce:transition-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-active)]"
        >
            <span>{{ __('workbench.common.weight') }}</span>
        </button>
    </div>
</section>
