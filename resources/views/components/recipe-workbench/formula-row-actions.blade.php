@props(['phaseKey' => null, 'phaseKeyExpression' => null])

@php
    $phaseExpression = $phaseKeyExpression ?? json_encode(
        $phaseKey,
        JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
    );
@endphp

<div
    x-data="{
        open: false,
        panelStyle: '',
        focusTrigger() {
            const trigger = row?.id
                ? document.getElementById(`formula-row-actions-trigger-${row.id}`)
                : null;

            (trigger ?? this.$refs.trigger)?.focus();
        },
        openMenu() {
            this.open = true;
            this.$nextTick(() => {
                this.reposition();
                this.$refs.menu?.querySelector('[role=menuitem]:not([disabled])')?.focus();
            });
        },
        closeMenu(shouldRestoreFocus = true) {
            this.open = false;

            if (shouldRestoreFocus) {
                this.$nextTick(() => this.focusTrigger());
            }
        },
        reposition() {
            const trigger = this.$refs.trigger;

            if (! trigger) {
                return;
            }

            const rect = trigger.getBoundingClientRect();
            const menuWidth = 224;
            const gutter = 16;
            const menuHeight = this.$refs.menu?.offsetHeight ?? 0;
            const left = Math.max(
                gutter,
                Math.min(window.innerWidth - menuWidth - gutter, rect.right - menuWidth),
            );
            const belowTop = rect.bottom + 8;
            const aboveTop = rect.top - menuHeight - 8;
            const top = belowTop + menuHeight > window.innerHeight - gutter
                ? Math.max(gutter, aboveTop)
                : belowTop;

            this.panelStyle = `top: ${top}px; left: ${left}px; width: ${menuWidth}px;`;
        },
    }"
    class="relative ml-auto shrink-0"
    @scroll.window="if (open) { reposition(); }"
    @resize.window="if (open) { reposition(); }"
    @formula-row-actions-opened.window="if (open && $event.detail.rowId !== row.id) { closeMenu(false); }"
    x-cloak
>
    <button
        x-ref="trigger"
        type="button"
        :id="`formula-row-actions-trigger-${row.id}`"
        @click.stop="if (open) { closeMenu(); } else { $dispatch('formula-row-actions-opened', { rowId: row.id }); openMenu(); }"
        @keydown.escape.prevent.stop="closeMenu()"
        :aria-expanded="open.toString()"
        :aria-controls="`formula-row-actions-${row.id}`"
        aria-haspopup="menu"
        :aria-label="t('row_actions.label', { ingredient: row.name })"
        class="grid min-h-11 min-w-11 place-items-center border-0 bg-transparent text-[var(--color-ink)] transition-colors duration-150 motion-reduce:transition-none hover:text-[var(--color-ink-strong)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)]"
    >
        <x-action-icon name="more-horizontal" />
    </button>

    <template x-teleport="body">
        <div
            x-show="open"
            x-transition.opacity
            x-cloak
            @click.outside="closeMenu(false)"
            @keydown.escape.window="if (open) { $event.preventDefault(); $event.stopPropagation(); closeMenu(); }"
            x-ref="menu"
            :id="`formula-row-actions-${row.id}`"
            :aria-labelledby="`formula-row-actions-trigger-${row.id}`"
            role="menu"
            :style="panelStyle"
            class="fixed z-[90] max-h-[min(24rem,calc(100dvh-2rem))] overflow-y-auto overscroll-contain rounded-lg border border-[var(--color-line)] bg-[var(--color-panel)] p-1.5 shadow-[0_12px_24px_rgba(60,50,30,0.12)]"
        >
            <button
                type="button"
                role="menuitem"
                @click.stop="moveFormulaRowBy({{ $phaseExpression }}, row.id, 'up'); closeMenu()"
                :disabled="! canMoveFormulaRowBy({{ $phaseExpression }}, row.id, 'up')"
                class="flex min-h-11 w-full items-center rounded-md px-3 py-2 text-left text-sm font-medium text-[var(--color-ink-strong)] transition-colors duration-150 motion-reduce:transition-none hover:bg-[var(--color-field-muted)] focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-[var(--color-accent)] disabled:cursor-not-allowed disabled:opacity-40"
            >
                <span x-text="t('row_actions.move_up')"></span>
            </button>
            <button
                type="button"
                role="menuitem"
                @click.stop="moveFormulaRowBy({{ $phaseExpression }}, row.id, 'down'); closeMenu()"
                :disabled="! canMoveFormulaRowBy({{ $phaseExpression }}, row.id, 'down')"
                class="flex min-h-11 w-full items-center rounded-md px-3 py-2 text-left text-sm font-medium text-[var(--color-ink-strong)] transition-colors duration-150 motion-reduce:transition-none hover:bg-[var(--color-field-muted)] focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-[var(--color-accent)] disabled:cursor-not-allowed disabled:opacity-40"
            >
                <span x-text="t('row_actions.move_down')"></span>
            </button>

            <div class="my-1 border-t border-[var(--color-line)]" role="separator"></div>

            <template x-for="targetPhase in formulaRowMoveTargets({{ $phaseExpression }}, row.id)" :key="`${row.id}-${targetPhase.key}`">
                <button
                    type="button"
                    role="menuitem"
                    @click.stop="moveFormulaRowToPhase({{ $phaseExpression }}, row.id, targetPhase.key); closeMenu()"
                    class="flex min-h-11 w-full items-center rounded-md px-3 py-2 text-left text-sm font-medium text-[var(--color-ink-strong)] transition-colors duration-150 motion-reduce:transition-none hover:bg-[var(--color-field-muted)] focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-[var(--color-accent)]"
                >
                    <span x-text="t('row_actions.move_to_phase', { phase: targetPhase.name })"></span>
                </button>
            </template>

            <div class="my-1 border-t border-[var(--color-line)]" role="separator"></div>

            <button
                type="button"
                role="menuitem"
                @click.stop="removeFormulaRowWithUndo({{ $phaseExpression }}, row.id); closeMenu()"
                class="flex min-h-11 w-full items-center rounded-md px-3 py-2 text-left text-sm font-medium text-[var(--color-danger-strong)] transition-colors duration-150 motion-reduce:transition-none hover:bg-[var(--color-danger-soft)] focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-[var(--color-danger-strong)]"
            >
                <span x-text="t('row_actions.remove')"></span>
            </button>
        </div>
    </template>
</div>
