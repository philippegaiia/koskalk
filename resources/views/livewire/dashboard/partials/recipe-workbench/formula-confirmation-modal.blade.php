<div
    x-cloak
    x-show="pendingCosmeticPhaseRemoval"
    x-init="$watch('pendingCosmeticPhaseRemoval', (pending) => { if (pending) { $nextTick(() => $refs.cancelAction?.focus()); } })"
    @keydown.escape.window="if (pendingCosmeticPhaseRemoval) { $event.preventDefault(); $event.stopPropagation(); cancelCosmeticPhaseRemoval(); }"
    @click.self="cancelCosmeticPhaseRemoval()"
    class="fixed inset-0 z-[80] grid place-items-center bg-[color:oklch(from_var(--color-surface-strong)_l_c_h_/_0.55)] p-4"
    role="dialog"
    aria-modal="true"
    aria-labelledby="formula-phase-removal-heading"
    aria-describedby="formula-phase-removal-description"
>
    <div
        x-trap.inert.noscroll="Boolean(pendingCosmeticPhaseRemoval)"
        class="w-full max-w-lg rounded-[1.25rem] border border-[var(--color-line)] bg-[var(--color-panel)] p-5 shadow-[0_18px_48px_rgba(60,50,30,0.18)] sm:p-6"
        @click.stop
    >
        <p class="sk-eyebrow">{{ __('workbench.cosmetic.phase') }}</p>
        <h2 id="formula-phase-removal-heading" class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">
            {{ __('workbench.phase_removal.title') }}
        </h2>
        <p
            id="formula-phase-removal-description"
            class="mt-2 text-sm leading-6 text-[var(--color-ink-soft)]"
            x-text="pendingCosmeticPhaseRemoval?.rowCount > 0
                ? (pendingCosmeticPhaseRemoval.rowCount === 1
                    ? t('phase_removal.description_one', { phase: pendingCosmeticPhaseRemoval.phaseName, count: pendingCosmeticPhaseRemoval.rowCount })
                    : t('phase_removal.description_many', { phase: pendingCosmeticPhaseRemoval.phaseName, count: pendingCosmeticPhaseRemoval.rowCount }))
                : t('phase_removal.description_empty', { phase: pendingCosmeticPhaseRemoval?.phaseName ?? '' })"
        ></p>

        <div class="mt-6 flex flex-wrap justify-end gap-2 border-t border-[var(--color-line)] pt-4">
            <button
                x-ref="cancelAction"
                type="button"
                @click="cancelCosmeticPhaseRemoval()"
                class="sk-btn sk-btn-ghost min-h-11 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)]"
            >
                {{ __('workbench.phase_removal.cancel') }}
            </button>
            <button
                type="button"
                @click="confirmCosmeticPhaseRemoval()"
                class="sk-btn min-h-11 bg-[var(--color-danger-strong)] text-[var(--color-inverse)] hover:bg-[var(--color-danger)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-danger-strong)]"
            >
                {{ __('workbench.phase_removal.confirm') }}
            </button>
        </div>
    </div>
</div>
