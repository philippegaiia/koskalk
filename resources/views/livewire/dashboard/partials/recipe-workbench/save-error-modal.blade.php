<div
    x-cloak
    x-show="saveErrorMessages.length > 0"
    x-data="{ returnFocusTo: null }"
    x-effect="if (saveErrorMessages.length > 0 && returnFocusTo === null) { returnFocusTo = document.activeElement; $nextTick(() => $refs.saveErrorClose?.focus()); }"
    @click.self="saveErrorMessages = []; $nextTick(() => { returnFocusTo?.focus?.(); returnFocusTo = null; })"
    class="fixed inset-0 z-[80] grid place-items-center bg-[color:oklch(from_var(--color-surface-strong)_l_c_h_/_0.55)] p-4"
    role="dialog"
    aria-modal="true"
    aria-labelledby="formula-save-error-heading"
    aria-describedby="formula-save-error-description"
>
    <div
        x-trap.inert.noscroll="saveErrorMessages.length > 0"
        @keydown.escape.prevent.stop="saveErrorMessages = []; $nextTick(() => { returnFocusTo?.focus?.(); returnFocusTo = null; })"
        class="flex max-h-[calc(100dvh-2rem)] w-full max-w-lg flex-col rounded-[1.25rem] border border-[var(--color-line)] bg-[var(--color-panel)] p-5 shadow-[0_18px_48px_rgba(60,50,30,0.18)] sm:p-6"
        @click.stop
    >
        <h2 id="formula-save-error-heading" class="text-lg font-semibold text-[var(--color-ink-strong)]">
            {{ __('workbench.save_error.title') }}
        </h2>
        <p id="formula-save-error-description" class="mt-2 text-sm leading-6 text-[var(--color-ink-soft)]">
            {{ __('workbench.save_error.description') }}
        </p>
        <ul role="alert" class="mt-3 min-h-0 flex-1 list-disc space-y-1 overflow-y-auto overscroll-contain pl-5 text-sm leading-6 text-[var(--color-danger-strong)]">
            <template x-for="(message, index) in saveErrorMessages" :key="`save-error-${index}`">
                <li x-text="message"></li>
            </template>
        </ul>

        <div class="mt-6 flex justify-end border-t border-[var(--color-line)] pt-4">
            <button
                x-ref="saveErrorClose"
                type="button"
                @click="saveErrorMessages = []; $nextTick(() => { returnFocusTo?.focus?.(); returnFocusTo = null; })"
                class="sk-btn sk-btn-primary min-h-11 py-2.5 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)]"
            >
                {{ __('workbench.save_error.close') }}
            </button>
        </div>
    </div>
</div>
