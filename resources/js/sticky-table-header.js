export function stickyTableHeaderOffset(containerTop, containerHeight, headerHeight) {
    const maximumOffset = Math.max(containerHeight - headerHeight, 0);

    return Math.min(Math.max(-containerTop, 0), maximumOffset);
}

export function createStickyTableHeader() {
    return {
        animationFrame: null,
        resizeObserver: null,

        init() {
            if (typeof ResizeObserver !== 'undefined') {
                this.resizeObserver = new ResizeObserver(() => this.scheduleUpdate());
                this.resizeObserver.observe(this.$el);
            }

            this.scheduleUpdate();
        },

        scheduleUpdate() {
            if (this.animationFrame !== null) {
                return;
            }

            this.animationFrame = window.requestAnimationFrame(() => {
                this.animationFrame = null;
                this.update();
            });
        },

        update() {
            const header = this.$el.querySelector('[data-sticky-table-header]');

            if (!header) {
                return;
            }

            const containerBounds = this.$el.getBoundingClientRect();
            const headerHeight = header.getBoundingClientRect().height;
            const offset = stickyTableHeaderOffset(
                containerBounds.top,
                containerBounds.height,
                headerHeight,
            );

            header.style.transform = `translateY(${Math.round(offset)}px)`;
        },

        destroy() {
            if (this.animationFrame !== null) {
                window.cancelAnimationFrame(this.animationFrame);
            }

            this.resizeObserver?.disconnect();
        },
    };
}
