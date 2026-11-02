export function createSearchCombobox(config) {
    return {
        activeIndex: -1,
        open: false,
        query: config.selectedLabel ?? '',
        selectedId: config.selectedId ?? null,
        selectedLabel: config.selectedLabel ?? '',
        options: config.options ?? [],
        retainSelection: config.retainSelection ?? false,
        allowEmpty: config.allowEmpty ?? true,
        panelStyle: {},

        init() {
            this.syncSelection(this.selectedId);

            if (config.floatingOptions) {
                this.$watch('open', () => this.$nextTick(() => this.repositionOptions()));
                this.$watch('query', () => this.$nextTick(() => this.repositionOptions()));
            }
        },

        repositionOptions() {
            if (!config.floatingOptions || !this.open || !this.$refs.control) {
                return;
            }

            const rect = this.$refs.control.getBoundingClientRect();
            const gutter = 16;
            const gap = 6;
            const width = Math.min(rect.width, window.innerWidth - gutter * 2);
            const desiredHeight = Math.min(288, this.$refs.options?.scrollHeight ?? 288);
            const below = Math.max(0, window.innerHeight - rect.bottom - gap - gutter);
            const above = Math.max(0, rect.top - gap - gutter);
            const opensAbove = below < desiredHeight && above > below;
            const height = Math.min(desiredHeight, opensAbove ? above : below);

            this.panelStyle = {
                top: `${opensAbove ? rect.top - gap - height : rect.bottom + gap}px`,
                left: `${Math.max(gutter, Math.min(rect.left, window.innerWidth - width - gutter))}px`,
                width: `${width}px`,
                maxHeight: `${height}px`,
            };
        },

        sameId(left, right) {
            return left !== null && left !== undefined
                && right !== null && right !== undefined
                && String(left) === String(right);
        },

        get filteredOptions() {
            const term = this.query.trim().toLocaleLowerCase();

            if (term === '' || (this.selectedId !== null && term === this.selectedLabel.trim().toLocaleLowerCase())) {
                return this.options;
            }

            return this.options.filter((option) => {
                return [option.label, option.description, option.searchText]
                    .filter(Boolean)
                    .some((value) => value.toLocaleLowerCase().includes(term));
            });
        },

        moveActive(direction) {
            const optionCount = this.filteredOptions.length;

            if (optionCount === 0) {
                this.activeIndex = -1;

                return;
            }

            if (this.activeIndex === -1) {
                this.activeIndex = direction > 0 ? 0 : optionCount - 1;

                return;
            }

            this.activeIndex = (this.activeIndex + direction + optionCount) % optionCount;
        },

        handleInput() {
            this.$dispatch('search-combobox-query', {
                comboboxId: config.id,
                query: this.query,
            });

            if (!this.allowEmpty) {
                this.activeIndex = -1;
                this.open = true;

                return;
            }

            const hadSelection = this.selectedId !== null;

            this.activeIndex = -1;
            this.open = true;
            this.selectedId = null;
            this.selectedLabel = '';

            if (hadSelection) {
                this.$dispatch('search-combobox-cleared', { comboboxId: config.id });
            }
        },

        selectActiveOption() {
            const option = this.filteredOptions[this.activeIndex] ?? this.filteredOptions[0];

            if (option) {
                this.selectOption(option);
            }
        },

        selectOption(option) {
            if (config.floatingOptions && this.$refs.input?.matches(':disabled')) {
                return;
            }

            if (this.retainSelection) {
                this.selectedId = option.id;
                this.selectedLabel = option.label;
                this.query = option.label;
            } else {
                this.selectedId = null;
                this.selectedLabel = '';
                this.query = '';
            }

            this.activeIndex = -1;
            this.open = false;
            this.$dispatch('search-combobox-selected', {
                comboboxId: config.id,
                id: option.id,
                label: option.label,
            });
        },

        closeOptions() {
            this.open = false;
            this.activeIndex = -1;

            if (!this.allowEmpty && this.selectedLabel !== '') {
                this.query = this.selectedLabel;
            }
        },

        clear() {
            if (!this.allowEmpty) {
                this.query = '';
                this.open = true;
                this.activeIndex = -1;

                return;
            }

            const hadSelection = this.selectedId !== null;

            this.activeIndex = -1;
            this.open = true;
            this.query = '';
            this.selectedId = null;
            this.selectedLabel = '';

            if (hadSelection) {
                this.$dispatch('search-combobox-cleared', { comboboxId: config.id });
            }
        },

        syncSelection(selectedId) {
            const option = this.options.find((candidate) => this.sameId(candidate.id, selectedId));

            this.selectedId = option?.id ?? null;
            this.selectedLabel = option?.label ?? '';

            if (!this.open) {
                this.query = this.selectedLabel;
            }
        },

        replaceOptions(options) {
            this.options = Array.isArray(options) ? options : [];
            this.syncSelection(this.selectedId);
        },

        registerOption(detail, idKey, labelKey, descriptionKey = 'description', searchTextKey = 'searchText') {
            const optionId = detail[idKey];
            const optionLabel = detail[labelKey];

            if (optionId === null || optionId === undefined || optionId === '' || !optionLabel || this.options.some((option) => this.sameId(option.id, optionId))) {
                return;
            }

            this.options.push({
                id: optionId,
                label: optionLabel,
                description: detail[descriptionKey] ?? '',
                searchText: detail[searchTextKey] ?? '',
            });
            this.options.sort((left, right) => left.label.localeCompare(right.label));
            this.activeIndex = -1;
            this.open = false;
            this.query = '';
        },
    };
}
