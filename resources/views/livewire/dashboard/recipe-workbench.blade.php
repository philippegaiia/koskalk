<div x-data="recipeWorkbench(@js($workbench))" x-init="init()" class="space-y-6">
    <section class="grid gap-4 xl:grid-cols-[minmax(0,1.3fr)_21rem]">
        <div class="rounded-[2rem] border border-[var(--color-line)] bg-white p-5">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div class="max-w-2xl">
                    <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Soap workbench</p>
                    <input x-model="formulaName" type="text" class="mt-2 w-full border-0 bg-transparent p-0 text-3xl font-semibold tracking-[-0.04em] text-[var(--color-ink-strong)] focus:outline-none" />
                    <p class="mt-3 text-sm leading-6 text-[var(--color-ink-soft)]">
                        The reaction core is edited on oil-basis percentages. Later additions stay visible as separate phases and also expose their derived total-formula percentages.
                    </p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <span class="rounded-full border border-[var(--color-line)] px-3 py-2 text-xs font-medium text-[var(--color-ink-soft)]">Unsaved local draft</span>
                    <span class="rounded-full border border-[var(--color-line)] bg-[var(--color-panel)] px-3 py-2 text-xs font-medium text-[var(--color-ink-soft)]">Soap: oil basis + total view</span>
                </div>
            </div>

            <div class="mt-6 grid gap-4 lg:grid-cols-5">
                <div class="rounded-[1.5rem] border border-[var(--color-line)] bg-[var(--color-panel)] p-4">
                    <p class="text-xs font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase">Working basis</p>
                    <p class="mt-3 text-sm font-medium text-[var(--color-ink-strong)]">Initial oils</p>
                </div>
                <div class="rounded-[1.5rem] border border-[var(--color-line)] bg-[var(--color-panel)] p-4">
                    <p class="text-xs font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase">Entry mode</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <button type="button" @click="editMode = 'percentage'" :class="editMode === 'percentage' ? 'bg-[var(--color-ink-strong)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-3 py-2 text-xs font-medium transition">% of oils</button>
                        <button type="button" @click="editMode = 'weight'" :class="editMode === 'weight' ? 'bg-[var(--color-ink-strong)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-3 py-2 text-xs font-medium transition">Weight</button>
                    </div>
                </div>
                <div class="rounded-[1.5rem] border border-[var(--color-line)] bg-[var(--color-panel)] p-4">
                    <p class="text-xs font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase">Derived summary</p>
                    <p class="mt-3 text-sm font-medium text-[var(--color-ink-strong)]">% of total formula</p>
                </div>
                <div class="rounded-[1.5rem] border border-[var(--color-line)] bg-[var(--color-panel)] p-4">
                    <p class="text-xs font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase">Family</p>
                    <p class="mt-3 text-sm font-medium text-[var(--color-ink-strong)]">{{ $workbench['productFamily']['name'] ?? 'Soap' }}</p>
                </div>
                <div class="rounded-[1.5rem] border border-[var(--color-line)] bg-[var(--color-panel)] p-4">
                    <p class="text-xs font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase">IFRA context</p>
                    <template x-if="$data.ifraProductCategories?.length">
                        <div class="mt-3 flex flex-wrap gap-2">
                            <template x-for="category in $data.ifraProductCategories" :key="category.id">
                                <button type="button" @click="selectedIfraProductCategoryId = category.id" :class="selectedIfraProductCategoryId === category.id ? 'border-[var(--color-line-strong)] bg-white text-[var(--color-ink-strong)]' : 'border-transparent bg-white/70 text-[var(--color-ink-soft)]'" class="rounded-full border px-3 py-1.5 text-xs font-medium transition">
                                    <span x-text="`Cat ${category.code}`"></span>
                                </button>
                            </template>
                        </div>
                    </template>
                    <template x-if="! $data.ifraProductCategories?.length">
                        <p class="mt-3 text-sm text-[var(--color-ink-soft)]">IFRA categories can be selected once the compliance catalog is populated.</p>
                    </template>
                </div>
            </div>
        </div>

        <div class="rounded-[2rem] border border-[var(--color-line-strong)] bg-[var(--color-panel-strong)] p-5">
            <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Draft status</p>
            <h3 class="mt-3 text-2xl font-semibold text-[var(--color-ink-strong)]">Local-first editing</h3>
            <div class="mt-4 space-y-3 text-sm text-[var(--color-ink-soft)]">
                <p>The ingredient browser, reaction-core math, and phase totals are all loaded here before persistence is connected.</p>
                <p>That lets us validate the recipe structure and the soap-specific percentage rules without blocking on auth and save flows.</p>
            </div>
        </div>
    </section>

    <section class="grid gap-4 xl:grid-cols-[22rem_minmax(0,1fr)]">
        <aside class="space-y-4">
            <div class="rounded-[2rem] border border-[var(--color-line)] bg-white p-5">
                <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Ingredient browser</p>
                <h3 class="mt-2 text-lg font-semibold text-[var(--color-ink-strong)]">Filtered by role</h3>
                <input x-model="search" type="search" placeholder="Search name or INCI" class="mt-4 w-full rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] px-4 py-3 text-sm text-[var(--color-ink-strong)] outline-none transition focus:border-[var(--color-line-strong)]" />

                <div class="mt-4 flex flex-wrap gap-2">
                    <template x-for="option in categoryOptions" :key="option.value">
                        <button type="button" @click="activeCategory = option.value" :class="activeCategory === option.value ? 'border-[var(--color-line-strong)] bg-[var(--color-ink-strong)] text-white' : 'border-[var(--color-line)] bg-[var(--color-panel)] text-[var(--color-ink-soft)]'" class="rounded-full border px-3 py-2 text-xs font-medium transition">
                            <span x-text="option.label"></span>
                        </button>
                    </template>
                </div>
            </div>

            <div class="rounded-[2rem] border border-[var(--color-line)] bg-white">
                <div class="border-b border-[var(--color-line)] px-5 py-4">
                    <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Available ingredients</p>
                    <p class="mt-1 text-sm text-[var(--color-ink-soft)]"><span x-text="filteredIngredients.length"></span> match the current filter</p>
                </div>

                <div class="max-h-[38rem] space-y-3 overflow-y-auto p-4">
                    <template x-for="ingredient in filteredIngredients" :key="ingredient.id">
                        <button type="button" @click="addIngredient(ingredient)" class="w-full rounded-[1.25rem] border border-[var(--color-line)] bg-[var(--color-panel)] px-4 py-3 text-left transition hover:border-[var(--color-line-strong)] hover:bg-white">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="font-medium text-[var(--color-ink-strong)]" x-text="ingredient.name"></p>
                                    <p class="mt-1 text-xs leading-5 text-[var(--color-ink-soft)]" x-text="ingredient.inci_name"></p>
                                </div>
                                <span class="rounded-full border border-[var(--color-line)] px-2.5 py-1 text-[11px] font-medium text-[var(--color-ink-soft)]" x-text="ingredient.category_label"></span>
                            </div>
                            <div class="mt-3 flex flex-wrap gap-2 text-[11px] text-[var(--color-ink-soft)]">
                                <template x-if="ingredient.koh_sap_value">
                                    <span class="rounded-full border border-[var(--color-line)] px-2 py-1" x-text="`KOH SAP ${format(ingredient.koh_sap_value, 3)}`"></span>
                                </template>
                                <template x-if="ingredient.needs_compliance">
                                    <span class="rounded-full border border-[var(--color-line)] px-2 py-1">Allergens + IFRA</span>
                                </template>
                            </div>
                        </button>
                    </template>
                </div>
            </div>
        </aside>

        <div class="space-y-4">
            <section class="overflow-hidden rounded-[2rem] border border-[var(--color-line-strong)] bg-white">
                <div class="border-b border-[var(--color-line)] px-5 py-4">
                    <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Reaction core</p>
                    <h3 class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">Saponified oils + lye water</h3>
                </div>

                <div class="grid gap-4 p-5 xl:grid-cols-[minmax(0,1.25fr)_20rem]">
                    <div class="overflow-hidden rounded-[1.75rem] border border-[var(--color-line)]">
                        <div class="grid grid-cols-[minmax(0,1.8fr)_7rem_7rem_2.5rem] gap-px bg-[var(--color-line)] text-sm">
                            <div class="bg-[var(--color-panel)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">Oil</div>
                            <div class="bg-[var(--color-panel)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">% oils</div>
                            <div class="bg-[var(--color-panel)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">Weight</div>
                            <div class="bg-[var(--color-panel)] px-4 py-3"></div>

                            <template x-for="row in oilRows" :key="row.id">
                                <div class="contents">
                                    <div class="bg-white px-4 py-3">
                                        <p class="font-medium text-[var(--color-ink-strong)]" x-text="row.name"></p>
                                        <p class="mt-1 text-xs text-[var(--color-ink-soft)]" x-text="row.inci_name"></p>
                                    </div>
                                    <div class="bg-white px-3 py-3">
                                        <template x-if="editMode === 'percentage'">
                                            <input x-model.number="row.percentage" type="number" step="0.1" class="w-full rounded-xl border border-[var(--color-line)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline-none" />
                                        </template>
                                        <template x-if="editMode !== 'percentage'">
                                            <span class="inline-flex min-h-10 items-center text-sm text-[var(--color-ink-soft)]" x-text="`${format(row.percentage, 2)}%`"></span>
                                        </template>
                                    </div>
                                    <div class="bg-white px-3 py-3 text-sm text-[var(--color-ink-soft)]">
                                        <template x-if="editMode === 'weight'">
                                            <input :value="format(rowWeight(row), 1)" @input="updatePercentageFromWeight(row, $event.target.value)" type="number" step="0.1" class="w-full rounded-xl border border-[var(--color-line)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline-none" />
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

                            <div class="bg-[var(--color-panel)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">Oil total</div>
                            <div class="bg-[var(--color-panel)] px-4 py-3 font-medium text-[var(--color-ink-strong)]" x-text="`${format(totalOilPercentage(), 1)}%`"></div>
                            <div class="bg-[var(--color-panel)] px-4 py-3 font-medium text-[var(--color-ink-strong)]" x-text="`${format(oilWeightTotal(), 1)} ${oilUnit}`"></div>
                            <div class="bg-[var(--color-panel)] px-4 py-3"></div>
                        </div>
                    </div>

                    <div class="space-y-4">
                        <div class="rounded-[1.75rem] border border-[var(--color-line)] bg-[var(--color-panel)] p-4">
                            <p class="text-xs font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase">Lye type</p>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <button type="button" @click="lyeType = 'naoh'" :class="lyeType === 'naoh' ? 'bg-[var(--color-ink-strong)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-3 py-2 text-xs font-medium transition">NaOH</button>
                                <button type="button" @click="lyeType = 'koh'" :class="lyeType === 'koh' ? 'bg-[var(--color-ink-strong)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-3 py-2 text-xs font-medium transition">KOH</button>
                            </div>
                            <template x-if="lyeType === 'koh'">
                                <div class="mt-3 flex flex-wrap gap-2">
                                    <button type="button" @click="kohPurity = 100" :class="kohPurity === 100 ? 'bg-[var(--color-ink-strong)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-3 py-2 text-xs font-medium transition">KOH 100%</button>
                                    <button type="button" @click="kohPurity = 90" :class="kohPurity === 90 ? 'bg-[var(--color-ink-strong)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-3 py-2 text-xs font-medium transition">KOH 90%</button>
                                </div>
                            </template>
                        </div>

                        <div class="rounded-[1.75rem] border border-[var(--color-line)] bg-[var(--color-panel)] p-4">
                            <p class="text-xs font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase">Weight of oils</p>
                            <div class="mt-3 flex gap-2">
                                <button type="button" @click="oilUnit = 'g'" :class="oilUnit === 'g' ? 'bg-[var(--color-ink-strong)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-3 py-2 text-xs font-medium transition">g</button>
                                <button type="button" @click="oilUnit = 'oz'" :class="oilUnit === 'oz' ? 'bg-[var(--color-ink-strong)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-3 py-2 text-xs font-medium transition">oz</button>
                                <button type="button" @click="oilUnit = 'lb'" :class="oilUnit === 'lb' ? 'bg-[var(--color-ink-strong)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-3 py-2 text-xs font-medium transition">lb</button>
                            </div>
                            <input x-model.number="oilWeight" type="number" min="0" step="1" class="mt-3 w-full rounded-2xl border border-[var(--color-line)] bg-white px-4 py-3 text-sm text-[var(--color-ink-strong)] outline-none" />
                        </div>

                        <div class="rounded-[1.75rem] border border-[var(--color-line)] bg-[var(--color-panel)] p-4">
                            <p class="text-xs font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase">Water mode</p>
                            <div class="mt-3 grid gap-2">
                                <button type="button" @click="waterMode = 'percent_of_oils'" :class="waterMode === 'percent_of_oils' ? 'bg-[var(--color-ink-strong)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-2xl px-3 py-2 text-left text-xs font-medium transition">Water as % of oils</button>
                                <button type="button" @click="waterMode = 'lye_ratio'" :class="waterMode === 'lye_ratio' ? 'bg-[var(--color-ink-strong)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-2xl px-3 py-2 text-left text-xs font-medium transition">Water : lye ratio</button>
                                <button type="button" @click="waterMode = 'lye_concentration'" :class="waterMode === 'lye_concentration' ? 'bg-[var(--color-ink-strong)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-2xl px-3 py-2 text-left text-xs font-medium transition">Lye concentration</button>
                            </div>
                            <input x-model.number="waterValue" type="number" min="0" step="0.1" class="mt-3 w-full rounded-2xl border border-[var(--color-line)] bg-white px-4 py-3 text-sm text-[var(--color-ink-strong)] outline-none" />
                        </div>

                        <div class="rounded-[1.75rem] border border-[var(--color-line)] bg-[var(--color-panel)] p-4">
                            <p class="text-xs font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase">Superfat</p>
                            <input x-model.number="superfat" type="number" min="0" max="20" step="0.5" class="mt-3 w-full rounded-2xl border border-[var(--color-line)] bg-white px-4 py-3 text-sm text-[var(--color-ink-strong)] outline-none" />
                        </div>
                    </div>
                </div>
            </section>

            <section class="grid gap-4 xl:grid-cols-[minmax(0,1.05fr)_22rem]">
                <div class="space-y-4">
                    <div class="overflow-hidden rounded-[2rem] border border-[var(--color-line)] bg-white">
                        <div class="border-b border-[var(--color-line)] px-5 py-4">
                            <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Post-reaction phases</p>
                            <h3 class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">Additives and aromatics</h3>
                        </div>

                        <div class="space-y-5 p-5">
                            <div class="rounded-[1.75rem] border border-[var(--color-line)]">
                                <div class="border-b border-[var(--color-line)] px-4 py-3">
                                    <p class="font-medium text-[var(--color-ink-strong)]">Additives</p>
                                    <p class="mt-1 text-xs text-[var(--color-ink-soft)]">Colorants, preservatives, and other post-reaction functional materials.</p>
                                </div>
                                <div class="grid grid-cols-[minmax(0,1.8fr)_6rem_6rem_6rem_2.5rem] gap-px bg-[var(--color-line)] text-sm">
                                    <div class="bg-[var(--color-panel)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">Ingredient</div>
                                    <div class="bg-[var(--color-panel)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">% oils</div>
                                    <div class="bg-[var(--color-panel)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">Weight</div>
                                    <div class="bg-[var(--color-panel)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">% total</div>
                                    <div class="bg-[var(--color-panel)] px-4 py-3"></div>

                                    <template x-for="row in additiveRows" :key="row.id">
                                        <div class="contents">
                                            <div class="bg-white px-4 py-3">
                                                <p class="font-medium text-[var(--color-ink-strong)]" x-text="row.name"></p>
                                                <p class="mt-1 text-xs text-[var(--color-ink-soft)]" x-text="row.inci_name"></p>
                                            </div>
                                            <div class="bg-white px-3 py-3">
                                                <template x-if="editMode === 'percentage'">
                                                    <input x-model.number="row.percentage" type="number" step="0.1" class="w-full rounded-xl border border-[var(--color-line)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline-none" />
                                                </template>
                                                <template x-if="editMode !== 'percentage'">
                                                    <span class="inline-flex min-h-10 items-center text-sm text-[var(--color-ink-soft)]" x-text="`${format(row.percentage, 2)}%`"></span>
                                                </template>
                                            </div>
                                            <div class="bg-white px-3 py-3 text-sm text-[var(--color-ink-soft)]">
                                                <template x-if="editMode === 'weight'">
                                                    <input :value="format(rowWeight(row), 1)" @input="updatePercentageFromWeight(row, $event.target.value)" type="number" step="0.1" class="w-full rounded-xl border border-[var(--color-line)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline-none" />
                                                </template>
                                                <template x-if="editMode !== 'weight'">
                                                    <span class="inline-flex min-h-10 items-center" x-text="`${format(rowWeight(row), 1)} ${oilUnit}`"></span>
                                                </template>
                                            </div>
                                            <div class="bg-white px-4 py-3 text-sm text-[var(--color-ink-soft)]" x-text="`${format(totalFormulaPercentage(row), 2)}%`"></div>
                                            <div class="grid place-items-center bg-white px-2 py-3">
                                                <button type="button" @click="removeIngredient('additives', row.id)" class="grid size-8 place-items-center rounded-full border border-[var(--color-line)] text-[var(--color-ink-soft)] transition hover:border-[var(--color-line-strong)] hover:text-[var(--color-ink-strong)]">×</button>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>

                            <div class="rounded-[1.75rem] border border-[var(--color-line)]">
                                <div class="border-b border-[var(--color-line)] px-4 py-3">
                                    <p class="font-medium text-[var(--color-ink-strong)]">Fragrance and aromatics</p>
                                    <p class="mt-1 text-xs text-[var(--color-ink-soft)]">Essential oils and aromatic extracts with their own compliance context.</p>
                                </div>
                                <div class="grid grid-cols-[minmax(0,1.8fr)_6rem_6rem_6rem_2.5rem] gap-px bg-[var(--color-line)] text-sm">
                                    <div class="bg-[var(--color-panel)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">Ingredient</div>
                                    <div class="bg-[var(--color-panel)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">% oils</div>
                                    <div class="bg-[var(--color-panel)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">Weight</div>
                                    <div class="bg-[var(--color-panel)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">% total</div>
                                    <div class="bg-[var(--color-panel)] px-4 py-3"></div>

                                    <template x-for="row in fragranceRows" :key="row.id">
                                        <div class="contents">
                                            <div class="bg-white px-4 py-3">
                                                <p class="font-medium text-[var(--color-ink-strong)]" x-text="row.name"></p>
                                                <p class="mt-1 text-xs text-[var(--color-ink-soft)]" x-text="row.inci_name"></p>
                                            </div>
                                            <div class="bg-white px-3 py-3">
                                                <template x-if="editMode === 'percentage'">
                                                    <input x-model.number="row.percentage" type="number" step="0.1" class="w-full rounded-xl border border-[var(--color-line)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline-none" />
                                                </template>
                                                <template x-if="editMode !== 'percentage'">
                                                    <span class="inline-flex min-h-10 items-center text-sm text-[var(--color-ink-soft)]" x-text="`${format(row.percentage, 2)}%`"></span>
                                                </template>
                                            </div>
                                            <div class="bg-white px-3 py-3 text-sm text-[var(--color-ink-soft)]">
                                                <template x-if="editMode === 'weight'">
                                                    <input :value="format(rowWeight(row), 1)" @input="updatePercentageFromWeight(row, $event.target.value)" type="number" step="0.1" class="w-full rounded-xl border border-[var(--color-line)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline-none" />
                                                </template>
                                                <template x-if="editMode !== 'weight'">
                                                    <span class="inline-flex min-h-10 items-center" x-text="`${format(rowWeight(row), 1)} ${oilUnit}`"></span>
                                                </template>
                                            </div>
                                            <div class="bg-white px-4 py-3 text-sm text-[var(--color-ink-soft)]" x-text="`${format(totalFormulaPercentage(row), 2)}%`"></div>
                                            <div class="grid place-items-center bg-white px-2 py-3">
                                                <button type="button" @click="removeIngredient('fragrance', row.id)" class="grid size-8 place-items-center rounded-full border border-[var(--color-line)] text-[var(--color-ink-soft)] transition hover:border-[var(--color-line-strong)] hover:text-[var(--color-ink-strong)]">×</button>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="space-y-4">
                    <div class="rounded-[2rem] border border-[var(--color-line)] bg-white p-5">
                        <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Lye block</p>
                        <template x-if="oilsMissingSap.length > 0">
                            <div class="mt-4 rounded-[1.5rem] border border-[var(--color-line-strong)] bg-[var(--color-accent-soft)] px-4 py-3 text-sm text-[var(--color-ink-strong)]">
                                Lye and glycerine need KOH SAP data. Missing SAP for:
                                <span class="font-medium" x-text="oilsMissingSap.map((row) => row.name).join(', ')"></span>.
                                Add the SAP profile in admin on the ingredient version or SAP profile resource.
                            </div>
                        </template>
                        <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-1">
                            <div class="rounded-[1.5rem] border border-[var(--color-line)] bg-[var(--color-panel)] p-4">
                                <p class="text-xs font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase">Adjusted NaOH</p>
                                <p class="mt-2 text-2xl font-semibold text-[var(--color-ink-strong)]" x-text="`${format(lyeBreakdown().naoh_adjusted, 2)} ${oilUnit}`"></p>
                            </div>
                            <div class="rounded-[1.5rem] border border-[var(--color-line)] bg-[var(--color-panel)] p-4">
                                <p class="text-xs font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase">Adjusted KOH</p>
                                <p class="mt-2 text-2xl font-semibold text-[var(--color-ink-strong)]" x-text="`${format(lyeBreakdown().koh_adjusted, 2)} ${oilUnit}`"></p>
                            </div>
                            <div class="rounded-[1.5rem] border border-[var(--color-line)] bg-[var(--color-panel)] p-4">
                                <p class="text-xs font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase" x-text="lyeType === 'koh' && kohPurity === 90 ? 'KOH To Weigh (90%)' : 'KOH Reference'"></p>
                                <p class="mt-2 text-2xl font-semibold text-[var(--color-ink-strong)]" x-text="`${format(lyeType === 'koh' ? lyeBreakdown().koh_to_weigh : lyeBreakdown().koh_adjusted, 2)} ${oilUnit}`"></p>
                            </div>
                            <div class="rounded-[1.5rem] border border-[var(--color-line)] bg-[var(--color-panel)] p-4">
                                <p class="text-xs font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase">Water</p>
                                <p class="mt-2 text-2xl font-semibold text-[var(--color-ink-strong)]" x-text="`${format(lyeBreakdown().water_weight, 2)} ${oilUnit}`"></p>
                            </div>
                            <div class="rounded-[1.5rem] border border-[var(--color-line)] bg-[var(--color-panel)] p-4">
                                <p class="text-xs font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase">Produced glycerine</p>
                                <p class="mt-2 text-2xl font-semibold text-[var(--color-ink-strong)]" x-text="`${format(lyeBreakdown().glycerine_weight, 2)} ${oilUnit}`"></p>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-[2rem] border border-[var(--color-line)] bg-white p-5">
                        <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Soap qualities</p>
                        <div class="mt-4 grid gap-2">
                            <template x-for="[label, key] in [['Hardness', 'hardness'], ['Cleansing', 'cleansing'], ['Conditioning', 'conditioning'], ['Bubbly', 'bubbly'], ['Creamy', 'creamy'], ['Iodine', 'iodine'], ['INS', 'ins']]" :key="key">
                                <div class="flex items-center justify-between rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] px-4 py-3 text-sm">
                                    <span class="text-[var(--color-ink-soft)]" x-text="label"></span>
                                    <span class="font-medium text-[var(--color-ink-strong)]" x-text="format(qualityMetrics()[key], 1)"></span>
                                </div>
                            </template>
                        </div>
                    </div>

                    <div class="rounded-[2rem] border border-[var(--color-line)] bg-white p-5">
                        <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Totals</p>
                        <div class="mt-4 space-y-3 text-sm">
                            <div class="flex items-center justify-between rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] px-4 py-3">
                                <span class="text-[var(--color-ink-soft)]">Oils basis total</span>
                                <span class="font-medium text-[var(--color-ink-strong)]" x-text="`${format(totalOilPercentage(), 1)}%`"></span>
                            </div>
                            <div class="flex items-center justify-between rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] px-4 py-3">
                                <span class="text-[var(--color-ink-soft)]">Post-reaction additions</span>
                                <span class="font-medium text-[var(--color-ink-strong)]" x-text="`${format(totalAdditionPercentage(), 1)}% of oils`"></span>
                            </div>
                            <div class="flex items-center justify-between rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] px-4 py-3">
                                <span class="text-[var(--color-ink-soft)]">Final batch estimate</span>
                                <span class="font-medium text-[var(--color-ink-strong)]" x-text="`${format(finalBatchWeight(), 1)} ${oilUnit}`"></span>
                            </div>
                        </div>

                        <template x-if="Math.abs(totalOilPercentage() - 100) > 0.01">
                            <div class="mt-4 rounded-[1.5rem] border border-[var(--color-line-strong)] bg-[var(--color-accent-soft)] px-4 py-3 text-sm text-[var(--color-ink-strong)]">
                                The saponified oils should total 100% on the oil basis before the formula is considered balanced.
                            </div>
                        </template>
                    </div>
                </div>
            </section>
        </div>
    </section>
</div>
