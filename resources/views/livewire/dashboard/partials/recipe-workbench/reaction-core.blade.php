<section class="overflow-hidden rounded-[2rem] border border-[var(--color-line)] bg-white">
    <div class="border-b border-[var(--color-line)] px-5 py-4">
        <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Reaction core</p>
        <div class="mt-1 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <h3 class="text-lg font-semibold text-[var(--color-ink-strong)]">Saponified oils + lye water</h3>
            <div :class="oilPercentageIsBalanced ? 'border-[var(--color-success-soft)] bg-[var(--color-success-soft)] text-[var(--color-success-strong)]' : 'border-[var(--color-danger-soft)] bg-[var(--color-danger-soft)] text-[var(--color-danger-strong)]'" class="inline-flex items-center gap-3 rounded-full border px-4 py-2 text-sm font-medium transition">
                <span x-text="oilPercentageStatusLabel"></span>
                <span class="rounded-full bg-white px-3 py-1 text-sm font-semibold" x-text="`${format(totalOilPercentage(), 1)}%`"></span>
            </div>
        </div>
    </div>
    <div class="p-5">
        <div class="relative rounded-[1.75rem] border border-[var(--color-line)]">
            <div class="overflow-hidden rounded-[calc(1.75rem-1px)]">
                <div class="grid grid-cols-[2.75rem_minmax(0,1.8fr)_7rem_7rem_2.5rem] gap-px bg-[var(--color-line)] text-sm">
                    <div class="bg-[var(--color-panel)] px-3 py-3"></div>
                    <div class="bg-[var(--color-panel)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">Oil</div>
                    <div class="bg-[var(--color-panel)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">% oils</div>
                    <div class="bg-[var(--color-panel)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">Weight</div>
                    <div class="bg-[var(--color-panel)] px-4 py-3"></div>
                </div>

                <div class="divide-y divide-[var(--color-line)] bg-white">
                    <template x-for="row in oilRows" :key="row.id">
                        <div @dragover="allowPhaseDrop('saponified_oils', $event, row.id)"
                            @drop="dropDraggedRow('saponified_oils', $event, row.id)"
                            :class="{
                                'bg-[var(--color-accent-soft)]': isDropTarget('saponified_oils', row.id),
                                'opacity-60': isDraggedRow('saponified_oils', row.id),
                            }"
                            class="grid grid-cols-[2.75rem_minmax(0,1.8fr)_7rem_7rem_2.5rem] gap-px bg-[var(--color-line)] transition">
                            <div class="grid place-items-center bg-white px-2 py-3">
                                <button type="button"
                                    draggable="true"
                                    @dragstart="beginRowDrag('saponified_oils', row.id, $event)"
                                    @dragend="endRowDrag()"
                                    class="grid size-8 cursor-grab place-items-center rounded-full border border-[var(--color-line)] text-[var(--color-ink-soft)] transition hover:border-[var(--color-line-strong)] hover:text-[var(--color-ink-strong)] active:cursor-grabbing"
                                    aria-label="Drag to reorder or move this oil">
                                    <span class="text-base leading-none">⋮⋮</span>
                                </button>
                            </div>
                            <div class="bg-white px-4 py-3">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="font-medium text-[var(--color-ink-strong)]" x-text="row.name"></p>
                                    </div>
                                    <div x-data="{
                                            open: false,
                                            panelStyle: '',
                                            reposition() {
                                                const panelWidth = 256;
                                                const gutter = 16;
                                                const rect = this.$refs.trigger.getBoundingClientRect();
                                                const left = Math.max(gutter, Math.min(window.innerWidth - panelWidth - gutter, rect.right - panelWidth));
                                                const top = Math.min(window.innerHeight - gutter, rect.bottom + 8);

                                                this.panelStyle = `position: fixed; top: ${top}px; left: ${left}px; width: ${panelWidth}px;`;
                                            },
                                        }"
                                        class="relative shrink-0"
                                        x-cloak>
                                        <template x-if="ingredientHasInspector(row)">
                                            <button type="button"
                                                x-ref="trigger"
                                                @mouseenter="open = true; reposition()"
                                                @mouseleave="open = false"
                                                @focus="open = true; reposition()"
                                                @blur="open = false"
                                                @click.prevent="open = !open; if (open) { reposition(); }"
                                                class="grid size-6 place-items-center rounded-full border border-[var(--color-line)] bg-white text-[11px] font-semibold text-[var(--color-ink-soft)] transition hover:border-[var(--color-line-strong)] hover:text-[var(--color-ink-strong)]">
                                                i
                                            </button>
                                        </template>
                                        <template x-teleport="body">
                                            <div x-show="open"
                                                x-transition.opacity
                                                @mouseenter="open = true"
                                                @mouseleave="open = false"
                                                @click.outside="open = false"
                                                @scroll.window="if (open) { reposition(); }"
                                                @resize.window="if (open) { reposition(); }"
                                                :style="panelStyle"
                                                class="z-[80] rounded-[1.25rem] border border-[var(--color-line)] bg-white p-3 shadow-lg">
                                                <p class="text-[11px] font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase">Material details</p>
                                                <div class="mt-2.5 space-y-1.5 text-xs text-[var(--color-ink-soft)]">
                                                    <template x-for="detail in ingredientInspectorRows(row)" :key="detail.label">
                                                        <div class="flex items-center justify-between gap-3 rounded-xl bg-[var(--color-panel)] px-3 py-2">
                                                            <span x-text="detail.label"></span>
                                                            <span class="font-medium text-[var(--color-ink-strong)]" x-text="detail.value"></span>
                                                        </div>
                                                    </template>
                                                </div>
                                                <template x-if="ingredientFattyAcidRows(row).length > 0">
                                                    <div class="mt-3">
                                                        <p class="text-[11px] font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase">Fatty acids</p>
                                                        <div class="mt-2 max-h-40 space-y-1 overflow-y-auto pr-1 text-xs text-[var(--color-ink-soft)]">
                                                            <template x-for="fattyAcid in ingredientFattyAcidRows(row)" :key="fattyAcid.key">
                                                                <div class="flex items-center justify-between gap-3 rounded-xl border border-[var(--color-line)] px-3 py-2">
                                                                    <span x-text="fattyAcid.label"></span>
                                                                    <span class="font-medium text-[var(--color-ink-strong)]" x-text="`${format(fattyAcid.value, 1)}%`"></span>
                                                                </div>
                                                            </template>
                                                        </div>
                                                    </div>
                                                </template>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                                <p class="mt-1 text-xs text-[var(--color-ink-soft)]" x-text="row.inci_name"></p>
                            </div>
                            <div class="bg-white px-3 py-3">
                                <template x-if="editMode === 'percentage'">
                                    <input x-model.number="row.percentage" @input="row.percentage = nonNegativeNumber($event.target.value)" type="number" min="0" step="0.1" class="w-full rounded-xl border border-[var(--color-line)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline-none" />
                                </template>
                                <template x-if="editMode !== 'percentage'">
                                    <span class="inline-flex min-h-10 items-center text-sm text-[var(--color-ink-soft)]" x-text="`${format(row.percentage, 2)}%`"></span>
                                </template>
                            </div>
                            <div class="bg-white px-3 py-3 text-sm text-[var(--color-ink-soft)]">
                                <template x-if="editMode === 'weight'">
                                    <input :value="format(rowWeight(row), 1)" @input="updateOilPercentagesFromWeights(row, $event.target.value)" type="number" min="0" step="0.1" class="w-full rounded-xl border border-[var(--color-line)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline-none" />
                                </template>
                                <template x-if="editMode !== 'weight'">
                                    <span class="inline-flex min-h-10 items-center" x-text="`${format(rowWeight(row), 1)} ${oilUnit}`"></span>
                                </template>
                            </div>
                            <div class="grid place-items-center bg-white px-2 py-3">
                                <button type="button" @click="removeIngredient('saponified_oils', row.id)" class="grid size-8 place-items-center rounded-full border border-[var(--color-line)] text-[var(--color-ink-soft)] transition hover:border-[var(--color-line-strong)] hover:text-[var(--color-ink-strong)]">×</button>
                            </div>
                        </div>
                    </template>

                    <div @dragover="allowPhaseDrop('saponified_oils', $event)"
                        @drop="dropDraggedRow('saponified_oils', $event)"
                        :class="isDropTarget('saponified_oils') ? 'bg-[var(--color-accent-soft)] text-[var(--color-ink-strong)]' : 'bg-[var(--color-panel)] text-[var(--color-ink-soft)]'"
                        class="px-4 py-2 text-xs font-medium transition">
                        Drag here to place an oil at the end of the reaction core.
                    </div>
                </div>

                <div class="grid grid-cols-[2.75rem_minmax(0,1.8fr)_7rem_7rem_2.5rem] gap-px bg-[var(--color-line)] text-sm">
                    <div :class="oilPercentageIsBalanced ? 'bg-[var(--color-panel)]' : 'bg-red-50'" class="px-3 py-3"></div>
                    <div :class="oilPercentageIsBalanced ? 'bg-[var(--color-panel)] text-[var(--color-ink-strong)]' : 'bg-red-50 text-red-700'" class="px-4 py-3 font-medium">Oil total</div>
                    <div :class="oilPercentageIsBalanced ? 'bg-[var(--color-panel)] text-[var(--color-ink-strong)]' : 'bg-red-50 text-red-700'" class="px-4 py-3 font-medium" x-text="`${format(totalOilPercentage(), 1)}%`"></div>
                    <div :class="oilPercentageIsBalanced ? 'bg-[var(--color-panel)] text-[var(--color-ink-strong)]' : 'bg-red-50 text-red-700'" class="px-4 py-3 font-medium" x-text="`${format(oilWeightTotal(), 1)} ${oilUnit}`"></div>
                    <div :class="oilPercentageIsBalanced ? 'bg-[var(--color-panel)]' : 'bg-red-50'" class="px-4 py-3"></div>
                </div>
            </div>
        </div>

        <div class="mt-5 rounded-[1.75rem] border border-[var(--color-line)] bg-[var(--color-panel)] p-4">
            <div class="flex flex-col gap-2 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <p class="text-xs font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase">Calculated lye and water</p>
                    <p class="mt-1 text-sm text-[var(--color-ink-soft)]">This block is derived from the saponified oils, lye type, water mode, and superfat.</p>
                </div>
                <template x-if="oilsMissingSap.length > 0">
                    <div class="rounded-[1.25rem] border border-[var(--color-line-strong)] bg-[var(--color-accent-soft)] px-4 py-3 text-sm text-[var(--color-ink-strong)]">
                        Missing KOH SAP for <span class="font-medium" x-text="oilsMissingSap.map((row) => row.name).join(', ')"></span>.
                    </div>
                </template>
            </div>

            <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                <template x-for="card in lyeSummaryCards" :key="`${lyeType}-${card.id}`">
                    <div class="rounded-[1.35rem] border border-[var(--color-line)] bg-white p-3">
                        <p class="text-xs font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase" x-text="card.label"></p>
                        <p class="mt-2 text-2xl font-semibold text-[var(--color-ink-strong)]" x-text="`${formatLyeSummaryCardValue(card)} ${oilUnit}`"></p>
                    </div>
                </template>
            </div>
        </div>
    </div>
</section>
