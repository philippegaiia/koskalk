<aside class="space-y-4">
    <div class="rounded-[2rem] border border-[var(--color-line)] bg-white p-4">
        <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Ingredient browser</p>
        <h3 class="mt-2 text-lg font-semibold text-[var(--color-ink-strong)]">Filtered by role</h3>
        <input x-model="search" type="search" placeholder="Search name or INCI" class="mt-4 w-full rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] px-4 py-2.5 text-sm text-[var(--color-ink-strong)] outline-none transition focus:border-[var(--color-line-strong)]" />

        <div class="mt-4 flex flex-wrap gap-2">
            <template x-for="option in categoryOptions" :key="option.value">
                <button type="button" @click="activeCategory = option.value" :class="activeCategory === option.value ? 'border-[var(--color-accent)] bg-[var(--color-accent-soft)] text-[var(--color-ink-strong)]' : 'border-[var(--color-line)] bg-[var(--color-panel)] text-[var(--color-ink-soft)]'" class="rounded-full border px-3 py-1.5 text-xs font-medium transition">
                    <span x-text="option.label"></span>
                </button>
            </template>
        </div>
    </div>

    <div class="overflow-hidden rounded-[2rem] border border-[var(--color-line)] bg-white">
        <div class="border-b border-[var(--color-line)] px-5 py-4">
            <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Available ingredients</p>
            <p class="mt-1 text-sm text-[var(--color-ink-soft)]"><span x-text="filteredIngredients.length"></span> match the current filter</p>
        </div>

        <div class="max-h-[44rem] divide-y divide-[var(--color-line)] overflow-y-auto px-3 pr-2">
            <template x-for="ingredient in filteredIngredients" :key="ingredient.id">
                <div class="px-2 py-2.5 transition hover:bg-[var(--color-panel)]">
                    <div class="flex items-start gap-2.5">
                        <div class="size-10 shrink-0 overflow-hidden rounded-2xl bg-[var(--color-panel)]">
                            <template x-if="ingredient.image_url">
                                <img :src="ingredient.image_url" :alt="ingredient.name" class="size-full object-cover" />
                            </template>
                            <template x-if="! ingredient.image_url">
                                <div class="grid size-full place-items-center text-[10px] font-semibold tracking-[0.08em] text-[var(--color-ink-soft)]" x-text="ingredientCategoryCode(ingredient)"></div>
                            </template>
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-start gap-2">
                                <div class="min-w-0 flex-1">
                                    <p class="break-words text-sm font-semibold leading-5 text-[var(--color-ink-strong)]" x-text="ingredient.name"></p>
                                </div>
                                <span class="shrink-0 rounded-full border border-[var(--color-line)] bg-white px-2.5 py-0.5 text-[10px] font-medium text-[var(--color-ink-soft)]" x-text="ingredient.category_label"></span>
                            </div>
                            <p class="mt-0.5 min-w-0 break-words text-xs leading-4 text-[var(--color-ink-soft)]" x-text="ingredient.inci_name || 'INCI not entered yet'"></p>
                            <div class="mt-2 flex items-start justify-between gap-2">
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
                                    <template x-if="ingredientHasInspector(ingredient)">
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
                                                <template x-for="row in ingredientInspectorRows(ingredient)" :key="row.label">
                                                    <div class="flex items-center justify-between gap-3 rounded-xl bg-[var(--color-panel)] px-3 py-2">
                                                        <span x-text="row.label"></span>
                                                        <span class="font-medium text-[var(--color-ink-strong)]" x-text="row.value"></span>
                                                    </div>
                                                </template>
                                            </div>
                                            <template x-if="ingredientFattyAcidRows(ingredient).length > 0">
                                                <div class="mt-3">
                                                    <p class="text-[11px] font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase">Fatty acids</p>
                                                    <div class="mt-2 max-h-40 space-y-1 overflow-y-auto pr-1 text-xs text-[var(--color-ink-soft)]">
                                                        <template x-for="row in ingredientFattyAcidRows(ingredient)" :key="row.key">
                                                            <div class="flex items-center justify-between gap-3 rounded-xl border border-[var(--color-line)] px-3 py-2">
                                                                <span x-text="row.label"></span>
                                                                <span class="font-medium text-[var(--color-ink-strong)]" x-text="`${format(row.value, 1)}%`"></span>
                                                            </div>
                                                        </template>
                                                    </div>
                                                </div>
                                            </template>
                                        </div>
                                    </template>
                                </div>
                                <div class="ml-auto flex flex-wrap justify-end gap-2">
                                    <template x-if="ingredient.can_add_to_saponified_oils">
                                        <button type="button" @click.stop="addIngredient(ingredient, 'saponified_oils')" class="inline-flex items-center gap-1 rounded-full bg-[var(--color-accent-strong)] px-2.5 py-1.5 text-xs font-medium text-white transition hover:bg-[var(--color-accent)]">
                                            <span class="text-sm leading-none">+</span>
                                            <span>Oil</span>
                                        </button>
                                    </template>
                                    <template x-if="ingredient.can_add_to_additives">
                                        <button type="button" @click.stop="addIngredient(ingredient, 'additives')" class="inline-flex items-center gap-1 rounded-full border border-[var(--color-line-strong)] bg-[var(--color-accent-soft)] px-2.5 py-1.5 text-xs font-medium text-[var(--color-ink-strong)] transition hover:bg-white">
                                            <span class="text-sm leading-none">+</span>
                                            <span>Additive</span>
                                        </button>
                                    </template>
                                    <template x-if="ingredient.can_add_to_fragrance">
                                        <button type="button" @click.stop="addIngredient(ingredient, 'fragrance')" class="inline-flex items-center gap-1 rounded-full border border-[var(--color-line)] bg-white px-2.5 py-1.5 text-xs font-medium text-[var(--color-ink-soft)] transition hover:border-[var(--color-line-strong)] hover:text-[var(--color-ink-strong)]">
                                            <span class="text-sm leading-none">+</span>
                                            <span>Aromatic</span>
                                        </button>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </div>

    <div class="rounded-[2rem] border border-[var(--color-line)] bg-white p-5">
        <div>
            <div>
                <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Fatty acid profile</p>
                <p class="mt-1 text-sm text-[var(--color-ink-soft)]">Live blend feedback.</p>
            </div>
        </div>

        <template x-if="hasFattyAcidProfileData">
            <div class="mt-4 space-y-4">
                <div class="rounded-[1.5rem] border border-[var(--color-line)] bg-[var(--color-panel)] p-4">
                    <p class="text-xs font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase">Grouped profile</p>
                    <div class="mt-3 flex h-3 overflow-hidden rounded-full bg-white/80">
                        <template x-for="segment in fattyAcidGroupSegments()" :key="segment.key">
                            <div :style="`width: ${segment.percent}%; background: ${segment.color};`"></div>
                        </template>
                    </div>
                    <div class="mt-3 grid gap-2">
                        <template x-for="segment in fattyAcidGroupSegments()" :key="`${segment.key}-legend`">
                            <div class="flex items-center justify-between gap-3 rounded-2xl border border-[var(--color-line)] bg-white px-3 py-2 text-xs">
                                <div class="flex min-w-0 items-center gap-2">
                                    <span class="inline-block h-2.5 w-2.5 rounded-full" :style="`background: ${segment.color};`"></span>
                                    <span class="rounded-full px-2 py-0.5 font-medium text-white" :style="`background: ${segment.color};`" x-text="segment.shortLabel"></span>
                                    <span class="truncate text-[var(--color-ink-strong)]" x-text="segment.label"></span>
                                </div>
                                <span class="shrink-0 text-[var(--color-ink-soft)]" x-text="`${format(segment.value, 1)}%`"></span>
                            </div>
                        </template>
                    </div>
                </div>

                <div class="grid gap-2">
                    <template x-for="row in fattyAcidProfileRows" :key="row.key">
                        <div class="rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] px-4 py-3 text-sm">
                            <div class="flex items-center justify-between gap-3">
                                <span class="text-[var(--color-ink-soft)]" x-text="row.label"></span>
                                <span class="font-medium text-[var(--color-ink-strong)]" x-text="`${format(row.value, 1)}%`"></span>
                            </div>
                            <div class="mt-3 h-2 overflow-hidden rounded-full bg-white/80">
                                <div class="h-full rounded-full bg-[var(--color-ink-strong)]" :style="fattyAcidRowBarStyle(row.value, 'var(--color-ink-soft)')"></div>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </template>

        <template x-if="!hasFattyAcidProfileData">
            <div class="mt-4 rounded-[1.5rem] border border-dashed border-[var(--color-line)] bg-[var(--color-panel)] px-4 py-6 text-sm text-[var(--color-ink-soft)]">
                Fill the fatty acid profile on the selected carrier oils to see the blended profile here.
            </div>
        </template>
    </div>
</aside>
