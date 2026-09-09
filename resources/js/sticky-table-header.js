export function createStickyTableHeader() {
    return {
        animationFrame: null,
        geometryDirty: true,
        observedTable: null,
        originalHeader: null,
        overlay: null,
        overlayHeader: null,
        overlayTable: null,
        resizeObserver: null,

        init() {
            if (typeof ResizeObserver !== 'undefined') {
                this.resizeObserver = new ResizeObserver(() => {
                    this.geometryDirty = true;
                    this.scheduleUpdate();
                });
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
                this.removeOverlay();

                return;
            }

            header.style.transform = '';

            const table = header.closest('table');

            if (!table) {
                this.removeOverlay();

                return;
            }

            const containerBounds = this.$el.getBoundingClientRect();
            const headerHeight = header.getBoundingClientRect().height;
            const containerBottom = containerBounds.top + containerBounds.height;

            if (containerBounds.top < 0 && containerBottom > headerHeight) {
                this.activateOverlay(header, table, containerBounds);

                return;
            }

            this.deactivateOverlay();
        },

        activateOverlay(header, table, containerBounds) {
            this.ensureOverlay(header, table);

            header.style.opacity = '0';
            this.overlay.hidden = false;
            this.overlay.style.left = `${containerBounds.left}px`;
            this.overlay.style.width = `${containerBounds.width}px`;

            if (this.geometryDirty) {
                this.synchronizeColumnWidths(header, table);
            }

            this.overlayTable.style.transform = `translate3d(${-this.$el.scrollLeft}px, 0, 0)`;
        },

        ensureOverlay(header, table) {
            if (this.overlay && this.originalHeader === header) {
                return;
            }

            this.removeOverlay();

            const overlay = document.createElement('div');
            const overlayTable = table.cloneNode(false);
            const overlayHeader = header.cloneNode(true);

            overlay.hidden = true;
            overlay.dataset.stickyTableOverlay = '';
            overlay.setAttribute('aria-hidden', 'true');
            overlay.setAttribute('wire:ignore', '');
            Object.assign(overlay.style, {
                overflow: 'hidden',
                pointerEvents: 'none',
                position: 'fixed',
                top: '0',
                zIndex: '20',
            });

            this.sanitizeClone(overlayHeader);
            overlayHeader.style.opacity = '';
            overlayHeader.style.transform = '';
            overlayTable.style.margin = '0';
            overlayTable.style.tableLayout = 'fixed';
            overlayTable.style.transformOrigin = 'top left';
            overlayTable.append(overlayHeader);
            overlay.append(overlayTable);
            document.body.append(overlay);

            this.originalHeader = header;
            this.overlay = overlay;
            this.overlayHeader = overlayHeader;
            this.overlayTable = overlayTable;
            this.geometryDirty = true;
            this.observeTable(table);
        },

        sanitizeClone(header) {
            [header, ...header.querySelectorAll('*')].forEach((element) => {
                element.removeAttribute('id');

                [...element.attributes].forEach((attribute) => {
                    if (
                        attribute.name.startsWith('wire:')
                        || attribute.name.startsWith('x-')
                        || attribute.name.startsWith('@')
                    ) {
                        element.removeAttribute(attribute.name);
                    }
                });
            });
        },

        synchronizeColumnWidths(header, table) {
            const tableWidth = table.getBoundingClientRect().width;
            const originalCells = [...header.querySelectorAll('th')];
            const overlayCells = [...this.overlayHeader.querySelectorAll('th')];

            this.overlayTable.style.width = `${tableWidth}px`;
            this.overlayTable.style.minWidth = `${tableWidth}px`;
            this.overlayTable.style.maxWidth = `${tableWidth}px`;

            originalCells.forEach((cell, index) => {
                const overlayCell = overlayCells[index];

                if (!overlayCell) {
                    return;
                }

                const width = `${cell.getBoundingClientRect().width}px`;

                overlayCell.style.width = width;
                overlayCell.style.minWidth = width;
                overlayCell.style.maxWidth = width;
            });

            this.geometryDirty = false;
        },

        observeTable(table) {
            if (!this.resizeObserver || this.observedTable === table) {
                return;
            }

            if (this.observedTable) {
                this.resizeObserver.unobserve(this.observedTable);
            }

            this.observedTable = table;
            this.resizeObserver.observe(table);
        },

        deactivateOverlay() {
            if (this.originalHeader) {
                this.originalHeader.style.opacity = '';
                this.originalHeader.style.transform = '';
            }

            if (this.overlay) {
                this.overlay.hidden = true;
            }
        },

        removeOverlay() {
            this.deactivateOverlay();

            if (this.observedTable && this.resizeObserver) {
                this.resizeObserver.unobserve(this.observedTable);
            }

            this.overlay?.remove();
            this.geometryDirty = true;
            this.observedTable = null;
            this.originalHeader = null;
            this.overlay = null;
            this.overlayHeader = null;
            this.overlayTable = null;
        },

        destroy() {
            if (this.animationFrame !== null) {
                window.cancelAnimationFrame(this.animationFrame);
            }

            this.resizeObserver?.disconnect();
            this.removeOverlay();
        },
    };
}
