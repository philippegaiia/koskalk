<section class="space-y-4">
    <div id="post-reaction-phases" class="overflow-hidden rounded-[2rem] border border-[var(--color-line)] bg-white transition-shadow duration-300">
        <div class="border-b border-[var(--color-line)] px-5 py-4">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Post-reaction phases</p>
                    <h3 class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">Additives and aromatics</h3>
                </div>
                <span class="rounded-full border border-[var(--color-line)] bg-[var(--color-panel)] px-3 py-1.5 text-xs font-medium text-[var(--color-ink-soft)]" x-text="`${format(totalAdditionPercentage(), 1)}% of oils`"></span>
            </div>
        </div>

        <div class="space-y-5 p-5">
            <template x-if="additiveRows.length > 0">
            <div class="overflow-hidden rounded-[1.75rem] border border-[var(--color-line)]">
                <div class="border-b border-[var(--color-line)] px-4 py-3">
                    <p class="font-medium text-[var(--color-ink-strong)]">Additives</p>
                    <p class="mt-1 text-xs text-[var(--color-ink-soft)]">Colorants, preservatives, and other post-reaction functional materials. Drag to reorder the additives already in this phase.</p>
                </div>
                <div class="grid grid-cols-[2.75rem_minmax(0,1.8fr)_6rem_6rem_2.5rem] gap-px bg-[var(--color-line)] text-sm">
                    <div class="bg-[var(--color-panel)] px-3 py-3"></div>
                    <div class="bg-[var(--color-panel)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">Ingredient</div>
                    <div class="bg-[var(--color-panel)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">% oils</div>
                    <div class="bg-[var(--color-panel)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">Weight</div>
                    <div class="bg-[var(--color-panel)] px-4 py-3"></div>
                </div>

                <div class="divide-y divide-[var(--color-line)] bg-white">
                    <template x-for="row in additiveRows" :key="row.id">
                        <div @dragover="allowPhaseDrop('additives', $event, row.id)"
                            @drop="dropDraggedRow('additives', $event, row.id)"
                            :class="{
                                'bg-[var(--color-accent-soft)]': isDropTarget('additives', row.id),
                                'opacity-60': isDraggedRow('additives', row.id),
                            }"
                            class="grid grid-cols-[2.75rem_minmax(0,1.8fr)_6rem_6rem_2.5rem] gap-px bg-[var(--color-line)] transition">
                            <div class="grid place-items-center bg-white px-2 py-3">
                                <button type="button"
                                    draggable="true"
                                    @dragstart="beginRowDrag('additives', row.id, $event)"
                                    @dragend="endRowDrag()"
                                    class="grid size-8 cursor-grab place-items-center rounded-full border border-[var(--color-line)] text-[var(--color-ink-soft)] transition hover:border-[var(--color-line-strong)] hover:text-[var(--color-ink-strong)] active:cursor-grabbing"
                                    aria-label="Drag to reorder or move this additive">
                                    <span class="text-base leading-none">⋮⋮</span>
                                </button>
                            </div>
                            <div class="bg-white px-4 py-3">
                                <p class="font-medium text-[var(--color-ink-strong)]" x-text="row.name"></p>
                                <p class="mt-1 text-xs text-[var(--color-ink-soft)]" x-text="row.inci_name"></p>
                            </div>
                            <div class="bg-white px-3 py-3">
                                <template x-if="editMode === 'percentage'">
                                    <input x-model="row.percentage" @keydown="handleDecimalKeydown($event)" @blur="normalizeDecimalBlur($event); row.percentage = nonNegativeNumber($event.target.value)" type="text" inputmode="decimal" class="w-full rounded-xl border border-[var(--color-line)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline-none" />
                                </template>
                                <template x-if="editMode !== 'percentage'">
                                    <span class="inline-flex min-h-10 items-center text-sm text-[var(--color-ink-soft)]" x-text="`${format(row.percentage, 2)}%`"></span>
                                </template>
                            </div>
                            <div class="bg-white px-3 py-3 text-sm text-[var(--color-ink-soft)]">
                                <template x-if="editMode === 'weight'">
                                    <input :value="format(rowWeight(row), 1)" @input="updatePercentageFromWeight(row, $event.target.value)" @keydown="handleDecimalKeydown($event)" @blur="normalizeDecimalBlur($event)" type="text" inputmode="decimal" class="w-full rounded-xl border border-[var(--color-line)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline-none" />
                                </template>
                                <template x-if="editMode !== 'weight'">
                                    <span class="inline-flex min-h-10 items-center" x-text="`${format(rowWeight(row), 1)} ${oilUnit}`"></span>
                                </template>
                            </div>
                            <div class="flex items-center justify-center bg-white px-2 py-3">
                                <button type="button" @click="removeIngredient('additives', row.id)" class="grid size-8 place-items-center rounded-full border border-[var(--color-line)] text-[var(--color-ink-soft)] transition hover:border-[var(--color-line-strong)] hover:text-[var(--color-ink-strong)]">×</button>
                            </div>
                        </div>
                    </template>

                    <div @dragover="allowPhaseDrop('additives', $event)"
                        @drop="dropDraggedRow('additives', $event)"
                        :class="isDropTarget('additives') ? 'bg-[var(--color-accent-soft)] text-[var(--color-ink-strong)]' : 'bg-[var(--color-panel)] text-[var(--color-ink-soft)]'"
                        class="grid grid-cols-[2.75rem_minmax(0,1.8fr)_6rem_6rem_2.5rem] gap-px text-xs font-medium transition">
                        <div class="px-3 py-2"></div>
                        <div class="px-4 py-2">Drag here to place an ingredient at the end of additives.</div>
                        <div class="px-3 py-2"></div>
                        <div class="px-3 py-2"></div>
                        <div class="px-2 py-2"></div>
                    </div>
                </div>
            </div>
            </template>

            <template x-if="fragranceRows.length > 0">
                <div class="overflow-hidden rounded-[1.75rem] border border-[var(--color-line)]">
                    <div class="border-b border-[var(--color-line)] px-4 py-3">
                        <p class="font-medium text-[var(--color-ink-strong)]">Fragrance and aromatics</p>
                        <p class="mt-1 text-xs text-[var(--color-ink-soft)]">Essential oils and aromatic extracts with their own compliance context. Drag to reorder inside this aromatic phase.</p>
                    </div>
                    <div class="grid grid-cols-[2.75rem_minmax(0,1.8fr)_6rem_6rem_2.5rem] gap-px bg-[var(--color-line)] text-sm">
                        <div class="bg-[var(--color-panel)] px-3 py-3"></div>
                        <div class="bg-[var(--color-panel)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">Ingredient</div>
                        <div class="bg-[var(--color-panel)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">% oils</div>
                        <div class="bg-[var(--color-panel)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">Weight</div>
                        <div class="bg-[var(--color-panel)] px-4 py-3"></div>
                    </div>

                    <div class="divide-y divide-[var(--color-line)] bg-white">
                        <template x-for="row in fragranceRows" :key="row.id">
                            <div @dragover="allowPhaseDrop('fragrance', $event, row.id)"
                                @drop="dropDraggedRow('fragrance', $event, row.id)"
                                :class="{
                                    'bg-[var(--color-accent-soft)]': isDropTarget('fragrance', row.id),
                                    'opacity-60': isDraggedRow('fragrance', row.id),
                                }"
                                class="grid grid-cols-[2.75rem_minmax(0,1.8fr)_6rem_6rem_2.5rem] gap-px bg-[var(--color-line)] transition">
                                <div class="grid place-items-center bg-white px-2 py-3">
                                    <button type="button"
                                        draggable="true"
                                        @dragstart="beginRowDrag('fragrance', row.id, $event)"
                                        @dragend="endRowDrag()"
                                        class="grid size-8 cursor-grab place-items-center rounded-full border border-[var(--color-line)] text-[var(--color-ink-soft)] transition hover:border-[var(--color-line-strong)] hover:text-[var(--color-ink-strong)] active:cursor-grabbing"
                                        aria-label="Drag to reorder this aromatic ingredient">
                                        <span class="text-base leading-none">⋮⋮</span>
                                    </button>
                                </div>
                                <div class="bg-white px-4 py-3">
                                    <p class="font-medium text-[var(--color-ink-strong)]" x-text="row.name"></p>
                                    <p class="mt-1 text-xs text-[var(--color-ink-soft)]" x-text="row.inci_name"></p>
                                </div>
                                <div class="bg-white px-3 py-3">
                                    <template x-if="editMode === 'percentage'">
                                        <input x-model="row.percentage" @keydown="handleDecimalKeydown($event)" @blur="normalizeDecimalBlur($event); row.percentage = nonNegativeNumber($event.target.value)" type="text" inputmode="decimal" class="w-full rounded-xl border border-[var(--color-line)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline-none" />
                                    </template>
                                    <template x-if="editMode !== 'percentage'">
                                        <span class="inline-flex min-h-10 items-center text-sm text-[var(--color-ink-soft)]" x-text="`${format(row.percentage, 2)}%`"></span>
                                    </template>
                                </div>
                                <div class="bg-white px-3 py-3 text-sm text-[var(--color-ink-soft)]">
                                    <template x-if="editMode === 'weight'">
                                        <input :value="format(rowWeight(row), 1)" @input="updatePercentageFromWeight(row, $event.target.value)" @keydown="handleDecimalKeydown($event)" @blur="normalizeDecimalBlur($event)" type="text" inputmode="decimal" class="w-full rounded-xl border border-[var(--color-line)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline-none" />
                                    </template>
                                    <template x-if="editMode !== 'weight'">
                                        <span class="inline-flex min-h-10 items-center" x-text="`${format(rowWeight(row), 1)} ${oilUnit}`"></span>
                                    </template>
                                </div>
                                <div class="grid place-items-center bg-white px-2 py-3">
                                    <button type="button" @click="removeIngredient('fragrance', row.id)" class="grid size-8 place-items-center rounded-full border border-[var(--color-line)] text-[var(--color-ink-soft)] transition hover:border-[var(--color-line-strong)] hover:text-[var(--color-ink-strong)]">×</button>
                                </div>
                            </div>
                        </template>

                        <div @dragover="allowPhaseDrop('fragrance', $event)"
                            @drop="dropDraggedRow('fragrance', $event)"
                            :class="isDropTarget('fragrance') ? 'bg-[var(--color-accent-soft)] text-[var(--color-ink-strong)]' : 'bg-[var(--color-panel)] text-[var(--color-ink-soft)]'"
                            class="grid grid-cols-[2.75rem_minmax(0,1.8fr)_6rem_6rem_2.5rem] gap-px text-xs font-medium transition">
                            <div class="px-3 py-2"></div>
                            <div class="px-4 py-2">Drag here to place an aromatic ingredient at the end of the phase.</div>
                            <div class="px-3 py-2"></div>
                            <div class="px-3 py-2"></div>
                            <div class="px-2 py-2"></div>
                        </div>
                    </div>
                </div>
            </template>

        </div>
    </div>

    <div class="rounded-[2rem] border border-[var(--color-line)] bg-white p-5">
        <div class="flex flex-col gap-2 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Batch totals</p>
                <p class="mt-1 text-sm text-[var(--color-ink-soft)]">A quick read of the current formula outputs without repeating the oil basis already shown above.</p>
            </div>
        </div>

        <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <template x-for="card in totalSummaryCards" :key="card.id">
                <div class="flex h-full flex-col justify-between rounded-[1.5rem] border border-[var(--color-line)] bg-[var(--color-panel)] p-4">
                    <div>
                        <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase" x-text="card.label"></p>
                    </div>
                    <p class="pt-6 text-2xl font-semibold text-[var(--color-ink-strong)]" x-text="card.value"></p>
                </div>
            </template>
        </div>
    </div>

    <template x-if="Math.abs(totalOilPercentage() - 100) > 0.01">
        <div class="rounded-[1.5rem] border border-[var(--color-line-strong)] bg-[var(--color-accent-soft)] px-4 py-3 text-sm text-[var(--color-ink-strong)]">
            The saponified oils should total 100% on the oil basis before the formula is considered balanced.
        </div>
    </template>
</section>
