@props(['instance', 'productionId', 'productionName'])

@php
    $actionKey = $instance.'-'.$productionId;
    $triggerId = 'production-row-actions-trigger-'.$actionKey;
    $menuId = 'production-row-actions-'.$actionKey;
@endphp

{{--
    Overflow menu for a production row. The panel is teleported to the body and positioned
    as a fixed layer so it escapes the horizontal scroll container and paints above the
    sticky table header.

    The broadcast event is named `bench-production-row-actions-opened` rather than
    `production-...` because Blade compiles a leading `@production` as its environment
    directive and then demands an `@endproduction`.
--}}
<div
    x-data="{
        open: false,
        panelStyle: '',
        focusTrigger() {
            this.$nextTick(() => this.$refs.trigger?.focus());
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
                this.focusTrigger();
            }
        },
        reposition() {
            const trigger = this.$refs.trigger;

            if (! trigger) {
                return;
            }

            const rect = trigger.getBoundingClientRect();
            const menuWidth = 208;
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
    class="relative inline-block align-middle"
    @scroll.window="if (open) { reposition(); }"
    @resize.window="if (open) { reposition(); }"
    @bench-production-row-actions-opened.window="if (open && $event.detail.actionKey !== @js($actionKey)) { closeMenu(false); }"
    x-cloak
>
    <button
        x-ref="trigger"
        type="button"
        id="{{ $triggerId }}"
        @click.stop="if (open) { closeMenu(); } else { $dispatch('bench-production-row-actions-opened', { actionKey: @js($actionKey) }); openMenu(); }"
        @keydown.escape.prevent.stop="closeMenu()"
        :aria-expanded="open.toString()"
        aria-controls="{{ $menuId }}"
        aria-haspopup="menu"
        aria-label="{{ __('production_bench.production.row_actions', ['name' => $productionName]) }}"
        class="grid min-h-11 min-w-11 place-items-center rounded-md border-0 bg-transparent text-[var(--color-ink)] transition-colors duration-150 motion-reduce:transition-none hover:bg-[var(--color-field-muted)] hover:text-[var(--color-ink-strong)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)]"
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
            id="{{ $menuId }}"
            aria-labelledby="{{ $triggerId }}"
            role="menu"
            :style="panelStyle"
            class="fixed z-[90] max-h-[min(24rem,calc(100dvh-2rem))] overflow-y-auto overscroll-contain rounded-lg border border-[var(--color-line)] bg-[var(--color-panel)] p-1.5 shadow-[0_12px_24px_rgba(60,50,30,0.12)]"
        >
            {{ $slot }}
        </div>
    </template>
</div>
