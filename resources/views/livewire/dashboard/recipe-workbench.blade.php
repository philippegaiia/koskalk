<div x-data="recipeWorkbench(@js($workbench))" x-init="init()" class="mx-auto max-w-[90rem] space-y-6">
    <section class="rounded-[2rem] border border-[var(--color-line)] bg-white p-5">
        <div class="min-w-0">
            <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Formula name</p>
            <input x-model="formulaName" type="text" placeholder="Untitled soap formula" class="mt-2 w-full rounded-[1.25rem] border border-[var(--color-line)] bg-[var(--color-panel)] px-4 py-3 text-2xl font-semibold tracking-[-0.04em] text-[var(--color-ink-strong)] outline-none transition focus:border-[var(--color-line-strong)]" />
        </div>

        <div class="mt-4 flex flex-wrap gap-2">
            <span class="rounded-full border border-[var(--color-line)] px-3 py-2 text-xs font-medium text-[var(--color-ink-soft)]" x-text="hasSavedRecipe ? 'Connected to saved draft' : 'New local draft'"></span>
            <span class="rounded-full border border-[var(--color-line)] bg-[var(--color-panel)] px-3 py-2 text-xs font-medium text-[var(--color-ink-soft)]" x-text="manufacturingModeLabel"></span>
            <span class="rounded-full border border-[var(--color-line)] bg-[var(--color-panel)] px-3 py-2 text-xs font-medium text-[var(--color-ink-soft)]" x-text="exposureModeLabel"></span>
            <span class="rounded-full border border-[var(--color-line)] bg-[var(--color-panel)] px-3 py-2 text-xs font-medium text-[var(--color-ink-soft)]" x-text="`Regime: ${regulatoryRegime.toUpperCase()}`"></span>
            <span class="rounded-full border border-[var(--color-line)] bg-[var(--color-panel)] px-3 py-2 text-xs font-medium text-[var(--color-ink-soft)]">{{ $workbench['productFamily']['name'] ?? 'Soap' }}</span>
        </div>

        <div class="mt-4 rounded-[1.75rem] border border-[var(--color-line)] bg-[var(--color-panel)] p-4">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Save status</p>
                    <h3 class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]" x-text="hasSavedRecipe ? 'Working draft connected' : 'Ready to save'"></h3>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button type="button" @click="saveDraft()" :disabled="!oilPercentageIsBalanced || isSaving" :class="!oilPercentageIsBalanced || isSaving ? 'cursor-not-allowed bg-[var(--color-line)] text-[var(--color-ink-soft)]' : 'bg-[var(--color-accent-strong)] text-white hover:bg-[var(--color-accent)]'" class="rounded-full px-4 py-2.5 text-sm font-medium transition">
                        <span x-text="isSaving ? 'Saving…' : 'Save draft'"></span>
                    </button>
                    <button type="button" @click="saveAsNewVersion()" :disabled="!oilPercentageIsBalanced || isSaving" :class="!oilPercentageIsBalanced || isSaving ? 'cursor-not-allowed border-[var(--color-line)] text-[var(--color-ink-soft)]' : 'border-[var(--color-line-strong)] bg-white text-[var(--color-ink-strong)] hover:bg-[var(--color-accent-soft)]'" class="rounded-full border px-4 py-2.5 text-sm font-medium transition">
                        Save as new version
                    </button>
                    <button type="button" x-show="hasSavedRecipe" x-cloak @click="duplicateFormula()" :disabled="!oilPercentageIsBalanced || isSaving" :class="!oilPercentageIsBalanced || isSaving ? 'cursor-not-allowed border-[var(--color-line)] text-[var(--color-ink-soft)]' : 'border-[var(--color-line)] bg-white text-[var(--color-ink-soft)] hover:bg-[var(--color-accent-soft)]'" class="rounded-full border px-4 py-2.5 text-sm font-medium transition">
                        Duplicate
                    </button>
                </div>
            </div>
            <div class="mt-3 space-y-2 text-sm text-[var(--color-ink-soft)]">
                <p x-text="hasSavedRecipe ? 'You are editing the current working draft. Use Save as new version to create a numbered snapshot you can reopen later.' : 'Save the first draft once the oils reach 100% to turn this into a persistent formula.'"></p>
                <div :class="saveStatus === 'error' ? 'border-[var(--color-danger-soft)] bg-[var(--color-danger-soft)] text-[var(--color-danger-strong)]' : 'border-[var(--color-line)] bg-white text-[var(--color-ink-soft)]'" class="rounded-[1.5rem] border px-4 py-3">
                    <p class="font-medium" x-text="saveMessage || 'Draft and version actions stay disabled until the saponified oils reach exactly 100%.'"></p>
                </div>
                <template x-if="needsCatalogReview">
                    <div class="rounded-[1.5rem] border border-[var(--color-warning-soft)] bg-[var(--color-warning-soft)] px-4 py-3 text-sm text-[var(--color-warning-strong)]">
                        <p class="font-medium" x-text="catalogReview?.message"></p>
                    </div>
                </template>
            </div>
        </div>
    </section>

    <section class="rounded-[2rem] border border-[var(--color-line)] bg-white">
        <details class="group" x-data="{ open: false }" @toggle="open = $event.target.open">
            <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-5 py-4">
                <div>
                    <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Recipe content</p>
                    <h3 class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">Description, instructions, and finished-product image</h3>
                    <p class="mt-1 text-sm text-[var(--color-ink-soft)]">Optional publishing content for later sharing and finished formula pages.</p>
                </div>
                <span class="rounded-full border border-[var(--color-line)] bg-[var(--color-panel)] px-3 py-1.5 text-xs font-medium text-[var(--color-ink-soft)]" x-text="open ? 'Hide' : 'Open'"></span>
            </summary>

            <div class="border-t border-[var(--color-line)] px-5 py-5">
                @if ($workbench['recipe'])
                    <form wire:submit="saveRecipeContent" class="space-y-4">
                        @if ($recipeContentMessage)
                            <div class="{{ $recipeContentStatus === 'success' ? 'border-[var(--color-success-soft)] bg-[var(--color-success-soft)] text-[var(--color-success-strong)]' : 'border-[var(--color-danger-soft)] bg-[var(--color-danger-soft)] text-[var(--color-danger-strong)]' }} rounded-[1.5rem] border px-4 py-3 text-sm">
                                {{ $recipeContentMessage }}
                            </div>
                        @endif

                        {{ $this->form }}

                        <div class="flex justify-end">
                            <button type="submit" class="rounded-full bg-[var(--color-accent-strong)] px-4 py-2.5 text-sm font-medium text-white transition hover:bg-[var(--color-accent)]">
                                Save recipe content
                            </button>
                        </div>
                    </form>
                @else
                    <div class="rounded-[1.5rem] border border-[var(--color-line)] bg-[var(--color-panel)] px-4 py-4 text-sm text-[var(--color-ink-soft)]">
                        Save the first draft once, then add the finished-product image, a richer description, instructions, and process photos here.
                    </div>
                @endif
            </div>
        </details>
    </section>

    <section class="rounded-[2rem] border border-[var(--color-line)] bg-white">
        <div class="border-b border-[var(--color-line)] px-5 py-4">
            <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Formula settings</p>
            <div class="mt-3 rounded-[1.5rem] border border-[var(--color-line)] bg-[var(--color-panel)] p-4">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div class="space-y-2">
                        <p class="text-xs font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase">Formula context</p>
                        <p class="text-sm text-[var(--color-ink-soft)]">Rinse-off versus leave-on is saved on the formula version because it affects compliance interpretation and future INCI behavior.</p>
                        <div class="flex flex-wrap gap-2 text-xs text-[var(--color-ink-soft)]">
                            <span class="rounded-full border border-[var(--color-line)] bg-white px-3 py-1.5 font-medium" x-text="manufacturingModeLabel"></span>
                            <span class="rounded-full border border-[var(--color-line)] bg-white px-3 py-1.5 font-medium" x-text="`Regime: ${regulatoryRegime.toUpperCase()}`"></span>
                        </div>
                    </div>
                    <div class="min-w-0 rounded-[1.25rem] border border-[var(--color-line)] bg-white p-3">
                        <p class="text-xs font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase">Exposure mode</p>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <button type="button" @click="exposureMode = 'rinse_off'" :class="exposureMode === 'rinse_off' ? 'bg-[var(--color-ink-strong)] text-white' : 'bg-[var(--color-panel)] text-[var(--color-ink-soft)]'" class="rounded-full px-3 py-2 text-xs font-medium transition">Rinse-off</button>
                            <button type="button" @click="exposureMode = 'leave_on'" :class="exposureMode === 'leave_on' ? 'bg-[var(--color-ink-strong)] text-white' : 'bg-[var(--color-panel)] text-[var(--color-ink-soft)]'" class="rounded-full px-3 py-2 text-xs font-medium transition">Leave-on</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-4 grid gap-4 xl:grid-cols-5">
                <div class="rounded-[1.5rem] border border-[var(--color-line)] bg-[var(--color-panel)] p-4">
                    <p class="text-xs font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase">Lye type</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <button type="button" @click="lyeType = 'naoh'" :class="lyeType === 'naoh' ? 'bg-[var(--color-accent-strong)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-3 py-2 text-xs font-medium transition">NaOH</button>
                        <button type="button" @click="lyeType = 'koh'" :class="lyeType === 'koh' ? 'bg-[var(--color-accent-strong)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-3 py-2 text-xs font-medium transition">KOH</button>
                        <button type="button" @click="lyeType = 'dual'" :class="lyeType === 'dual' ? 'bg-[var(--color-accent-strong)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-3 py-2 text-xs font-medium transition">Dual Lye</button>
                    </div>
                    <template x-if="lyeType === 'dual'">
                        <div class="mt-3 rounded-2xl border border-[var(--color-line)] bg-white p-3">
                            <div class="flex items-center justify-between gap-3">
                                <span class="text-xs font-medium text-[var(--color-ink-soft)]">NaOH <span x-text="`${format(dualNaohPercentage, 1)}%`"></span></span>
                                <span class="text-xs font-medium text-[var(--color-ink-soft)]">KOH <span x-text="`${format(dualKohPercentage, 1)}%`"></span></span>
                            </div>
                            <input x-model.number="dualKohPercentage" type="range" min="0" max="100" step="1" class="mt-3 w-full accent-[var(--color-accent-strong)]" />
                        </div>
                    </template>
                    <template x-if="lyeType === 'koh' || lyeType === 'dual'">
                        <div class="mt-3 flex flex-wrap gap-2">
                            <button type="button" @click="kohPurity = 100" :class="kohPurity === 100 ? 'bg-[var(--color-accent-strong)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-3 py-2 text-xs font-medium transition">KOH 100%</button>
                            <button type="button" @click="kohPurity = 90" :class="kohPurity === 90 ? 'bg-[var(--color-accent-strong)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-3 py-2 text-xs font-medium transition">KOH 90%</button>
                        </div>
                    </template>
                </div>
                <div class="rounded-[1.5rem] border border-[var(--color-line)] bg-[var(--color-panel)] p-4">
                    <p class="text-xs font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase">Weight of oils</p>
                    <div class="mt-3 flex gap-2">
                        <button type="button" @click="oilUnit = 'g'" :class="oilUnit === 'g' ? 'bg-[var(--color-accent-strong)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-3 py-2 text-xs font-medium transition">g</button>
                        <button type="button" @click="oilUnit = 'oz'" :class="oilUnit === 'oz' ? 'bg-[var(--color-accent-strong)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-3 py-2 text-xs font-medium transition">oz</button>
                        <button type="button" @click="oilUnit = 'lb'" :class="oilUnit === 'lb' ? 'bg-[var(--color-accent-strong)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-3 py-2 text-xs font-medium transition">lb</button>
                    </div>
                    <input x-model.number="oilWeight" type="number" min="0" step="1" class="mt-3 w-full rounded-2xl border border-[var(--color-line)] bg-white px-4 py-3 text-sm text-[var(--color-ink-strong)] outline-none" />
                </div>
                <div class="rounded-[1.5rem] border border-[var(--color-line)] bg-[var(--color-panel)] p-4">
                    <p class="text-xs font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase">Water mode</p>
                    <div class="mt-3 grid gap-2">
                        <button type="button" @click="waterMode = 'percent_of_oils'" :class="waterMode === 'percent_of_oils' ? 'bg-[var(--color-accent-strong)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-2xl px-3 py-2 text-left text-xs font-medium transition">Water as % of oils</button>
                        <button type="button" @click="waterMode = 'lye_ratio'" :class="waterMode === 'lye_ratio' ? 'bg-[var(--color-accent-strong)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-2xl px-3 py-2 text-left text-xs font-medium transition">Water : lye ratio</button>
                        <button type="button" @click="waterMode = 'lye_concentration'" :class="waterMode === 'lye_concentration' ? 'bg-[var(--color-accent-strong)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-2xl px-3 py-2 text-left text-xs font-medium transition">Lye concentration</button>
                    </div>
                    <input x-model.number="waterValue" type="number" min="0" step="0.1" class="mt-3 w-full rounded-2xl border border-[var(--color-line)] bg-white px-4 py-3 text-sm text-[var(--color-ink-strong)] outline-none" />
                </div>
                <div class="rounded-[1.5rem] border border-[var(--color-line)] bg-[var(--color-panel)] p-4">
                    <p class="text-xs font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase">Superfat</p>
                    <div class="mt-3 flex items-center justify-between gap-3 text-sm">
                        <span :class="superfat < 0 ? 'text-red-600' : 'text-[var(--color-ink-soft)]'" class="font-medium">Current</span>
                        <span :class="superfat < 0 ? 'text-red-600' : 'text-[var(--color-ink-strong)]'" class="font-semibold" x-text="`${format(superfat, 1)}%`"></span>
                    </div>
                    <input x-model.number="superfat" type="range" min="-20" max="20" step="0.5" :class="superfat < 0 ? 'accent-red-600' : 'accent-[var(--color-accent-strong)]'" class="mt-3 w-full" />
                    <input x-model.number="superfat" type="number" min="-20" max="20" step="0.5" :class="superfat < 0 ? 'border-red-200 text-red-600' : 'border-[var(--color-line)] text-[var(--color-ink-strong)]'" class="mt-3 w-full rounded-2xl border bg-white px-4 py-3 text-sm outline-none" />
                </div>
                <div class="rounded-[1.5rem] border border-[var(--color-line)] bg-[var(--color-panel)] p-4">
                    <p class="text-xs font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase">Entry mode</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <button type="button" @click="editMode = 'percentage'" :class="editMode === 'percentage' ? 'bg-[var(--color-accent-strong)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-3 py-2 text-xs font-medium transition">% of oils</button>
                        <button type="button" @click="editMode = 'weight'" :class="editMode === 'weight' ? 'bg-[var(--color-accent-strong)] text-white' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-3 py-2 text-xs font-medium transition">Weight</button>
                    </div>
                    <div class="mt-4">
                        <p class="text-xs font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase">IFRA context</p>
                        <template x-if="$data.ifraProductCategories?.length">
                            <div class="mt-3 flex flex-wrap gap-2">
                                <template x-for="category in $data.ifraProductCategories" :key="category.id">
                                    <button type="button" @click="selectedIfraProductCategoryId = category.id" :class="selectedIfraProductCategoryId === category.id ? 'border-[var(--color-accent)] bg-[var(--color-accent-soft)] text-[var(--color-ink-strong)]' : 'border-transparent bg-white/70 text-[var(--color-ink-soft)]'" class="rounded-full border px-3 py-1.5 text-xs font-medium transition">
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
        </div>
    </section>

    <section class="grid gap-4 xl:grid-cols-[22rem_minmax(0,1fr)]">
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

            <div class="rounded-[2rem] border border-[var(--color-line)] bg-white">
                <div class="border-b border-[var(--color-line)] px-5 py-4">
                    <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Available ingredients</p>
                    <p class="mt-1 text-sm text-[var(--color-ink-soft)]"><span x-text="filteredIngredients.length"></span> match the current filter</p>
                </div>

                <div class="max-h-[44rem] divide-y divide-[var(--color-line)] overflow-y-auto px-3">
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
                                        <div x-data="{ open: false }" class="relative shrink-0" x-cloak>
                                            <template x-if="ingredientHasInspector(ingredient)">
                                                <button type="button"
                                                    @mouseenter="open = true"
                                                    @mouseleave="open = false"
                                                    @focus="open = true"
                                                    @blur="open = false"
                                                    @click.prevent="open = !open"
                                                    class="grid size-6 place-items-center rounded-full border border-[var(--color-line)] bg-white text-[11px] font-semibold text-[var(--color-ink-soft)] transition hover:border-[var(--color-line-strong)] hover:text-[var(--color-ink-strong)]">
                                                    i
                                                </button>
                                            </template>
                                            <div x-show="open"
                                                x-transition.opacity
                                                @mouseenter="open = true"
                                                @mouseleave="open = false"
                                                @click.outside="open = false"
                                                class="absolute left-0 top-8 z-20 w-64 rounded-[1.25rem] border border-[var(--color-line)] bg-white p-3 shadow-lg">
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
        </aside>

        <div class="space-y-4">
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

                            <div :class="oilPercentageIsBalanced ? 'bg-[var(--color-panel)] text-[var(--color-ink-strong)]' : 'bg-red-50 text-red-700'" class="px-4 py-3 font-medium">Oil total</div>
                            <div :class="oilPercentageIsBalanced ? 'bg-[var(--color-panel)] text-[var(--color-ink-strong)]' : 'bg-red-50 text-red-700'" class="px-4 py-3 font-medium" x-text="`${format(totalOilPercentage(), 1)}%`"></div>
                            <div :class="oilPercentageIsBalanced ? 'bg-[var(--color-panel)] text-[var(--color-ink-strong)]' : 'bg-red-50 text-red-700'" class="px-4 py-3 font-medium" x-text="`${format(oilWeightTotal(), 1)} ${oilUnit}`"></div>
                            <div :class="oilPercentageIsBalanced ? 'bg-[var(--color-panel)]' : 'bg-red-50'" class="px-4 py-3"></div>
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
                                <div class="rounded-[1.35rem] border border-[var(--color-line)] bg-white p-4">
                                    <p class="text-xs font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase" x-text="card.label"></p>
                                    <p class="mt-2 text-2xl font-semibold text-[var(--color-ink-strong)]" x-text="`${format(card.value, 2)} ${oilUnit}`"></p>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </section>

            <section class="space-y-4">
                <div class="overflow-hidden rounded-[2rem] border border-[var(--color-line)] bg-white">
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
                                                    <input x-model.number="row.percentage" @input="row.percentage = nonNegativeNumber($event.target.value)" type="number" min="0" step="0.1" class="w-full rounded-xl border border-[var(--color-line)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline-none" />
                                                </template>
                                                <template x-if="editMode !== 'percentage'">
                                                    <span class="inline-flex min-h-10 items-center text-sm text-[var(--color-ink-soft)]" x-text="`${format(row.percentage, 2)}%`"></span>
                                                </template>
                                            </div>
                                            <div class="bg-white px-3 py-3 text-sm text-[var(--color-ink-soft)]">
                                                <template x-if="editMode === 'weight'">
                                                    <input :value="format(rowWeight(row), 1)" @input="updatePercentageFromWeight(row, $event.target.value)" type="number" min="0" step="0.1" class="w-full rounded-xl border border-[var(--color-line)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline-none" />
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
                        </template>

                        <template x-if="fragranceRows.length > 0">
                            <div class="overflow-hidden rounded-[1.75rem] border border-[var(--color-line)]">
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
                                                    <input x-model.number="row.percentage" @input="row.percentage = nonNegativeNumber($event.target.value)" type="number" min="0" step="0.1" class="w-full rounded-xl border border-[var(--color-line)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline-none" />
                                                </template>
                                                <template x-if="editMode !== 'percentage'">
                                                    <span class="inline-flex min-h-10 items-center text-sm text-[var(--color-ink-soft)]" x-text="`${format(row.percentage, 2)}%`"></span>
                                                </template>
                                            </div>
                                            <div class="bg-white px-3 py-3 text-sm text-[var(--color-ink-soft)]">
                                                <template x-if="editMode === 'weight'">
                                                    <input :value="format(rowWeight(row), 1)" @input="updatePercentageFromWeight(row, $event.target.value)" type="number" min="0" step="0.1" class="w-full rounded-xl border border-[var(--color-line)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline-none" />
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
                        </template>

                        <template x-if="!hasPostReactionRows">
                            <div class="rounded-[1.75rem] border border-dashed border-[var(--color-line)] bg-[var(--color-panel)] px-5 py-8 text-center">
                                <p class="text-sm font-medium text-[var(--color-ink-strong)]">No post-reaction ingredients yet.</p>
                                <p class="mt-2 text-sm text-[var(--color-ink-soft)]">Add additives, essential oils, or aromatic extracts from the browser and only the matching category block will appear here.</p>
                            </div>
                        </template>
                    </div>
                </div>

                <div class="grid gap-4 xl:grid-cols-4">
                    <template x-for="card in totalSummaryCards" :key="card.id">
                        <div class="rounded-[2rem] border border-[var(--color-line)] bg-white p-5">
                            <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase" x-text="card.label"></p>
                            <p class="mt-3 text-2xl font-semibold text-[var(--color-ink-strong)]" x-text="card.value"></p>
                        </div>
                    </template>
                </div>

                <template x-if="Math.abs(totalOilPercentage() - 100) > 0.01">
                    <div class="rounded-[1.5rem] border border-[var(--color-line-strong)] bg-[var(--color-accent-soft)] px-4 py-3 text-sm text-[var(--color-ink-strong)]">
                        The saponified oils should total 100% on the oil basis before the formula is considered balanced.
                    </div>
                </template>

                <template x-if="hasComparisonBaseline">
                    <details class="rounded-[2rem] border border-[var(--color-line)] bg-white p-5">
                        <summary class="flex cursor-pointer list-none items-center justify-between gap-4">
                            <div>
                                <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Saved versions</p>
                                <p class="mt-1 text-sm text-[var(--color-ink-soft)]">Comparison is optional. Open it only when you want to check drift against a saved snapshot.</p>
                            </div>
                            <span class="rounded-full border border-[var(--color-line)] bg-[var(--color-panel)] px-3 py-1.5 text-xs font-medium text-[var(--color-ink-soft)]">Compare versions</span>
                        </summary>

                        <div class="mt-4">
                            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                                <div class="text-sm text-[var(--color-ink-soft)]">
                                    Baseline now loaded:
                                    <span class="font-medium text-[var(--color-ink-strong)]" x-text="baselineFormulaName"></span>
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <template x-if="versionOptions.length > 0">
                                        <select x-model="selectedComparisonVersionId" class="rounded-full border border-[var(--color-line)] bg-white px-4 py-2 text-xs font-medium text-[var(--color-ink-strong)] outline-none">
                                            <template x-for="option in versionOptions" :key="option.id">
                                                <option :value="option.id" x-text="option.label"></option>
                                            </template>
                                        </select>
                                    </template>
                                    <button type="button" @click="loadComparisonVersion()" class="rounded-full border border-[var(--color-line-strong)] bg-[var(--color-accent-soft)] px-4 py-2 text-xs font-medium text-[var(--color-ink-strong)] transition hover:bg-white">Compare</button>
                                    <button type="button" @click="openSelectedVersion()" class="rounded-full border border-[var(--color-line)] bg-white px-4 py-2 text-xs font-medium text-[var(--color-ink-strong)] transition hover:bg-[var(--color-accent-soft)]">Open version</button>
                                </div>
                            </div>

                            <template x-if="comparisonMessage">
                                 <div class="mt-3 rounded-[1.25rem] border border-[var(--color-warning-soft)] bg-[var(--color-warning-soft)] px-4 py-3 text-sm text-[var(--color-warning-strong)]" x-text="comparisonMessage"></div>
                            </template>

                            <template x-if="comparisonSummaryItems().length > 0">
                                <div class="mt-4 flex flex-wrap gap-2">
                                    <template x-for="item in comparisonSummaryItems()" :key="item">
                                        <span class="rounded-full border border-[var(--color-line)] bg-[var(--color-panel)] px-3 py-1 text-xs text-[var(--color-ink-soft)]" x-text="item"></span>
                                    </template>
                                </div>
                            </template>

                            <div class="mt-4 grid gap-3 xl:grid-cols-2">
                                <template x-for="row in currentComparisonRows()" :key="row.label">
                                    <div class="rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] px-4 py-3 text-sm">
                                        <div class="flex items-start justify-between gap-4">
                                            <div>
                                                <div class="font-medium text-[var(--color-ink-strong)]" x-text="row.label"></div>
                                                <div class="mt-1 text-xs text-[var(--color-ink-soft)]">
                                                    Saved: <span x-text="format(row.baseline, 1)"></span> · Current: <span x-text="format(row.current, 1)"></span>
                                                </div>
                                            </div>
                                            <span :class="row.pillClass" class="rounded-full border px-3 py-1 text-xs font-medium" x-text="`${row.directionLabel} (${row.delta >= 0 ? '+' : ''}${format(row.delta, 1)})`"></span>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </details>
                </template>

                <div class="grid gap-4 xl:grid-cols-[minmax(0,1.15fr)_minmax(0,0.85fr)]">
                    <div class="rounded-[2rem] border border-[var(--color-line)] bg-white p-5">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Koskalk qualities</p>
                                <p class="mt-1 text-sm text-[var(--color-ink-soft)]">Compact interpretation first, deeper chemistry second.</p>
                            </div>
                            <span class="rounded-full border border-[var(--color-line)] px-3 py-1 text-xs font-medium text-[var(--color-ink-soft)]" x-text="isPreviewingCalculation ? 'Updating…' : latherProfileSummary()"></span>
                        </div>

                        <template x-if="hasQualityMetricsData">
                            <div class="mt-4 grid gap-2">
                                <template x-for="row in defaultQualityRows()" :key="row.key">
                                    <div class="rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] px-4 py-3 text-sm">
                                        <div class="flex items-center justify-between gap-4">
                                            <span class="text-[var(--color-ink-soft)]" x-text="row.label"></span>
                                            <div class="text-right">
                                                <div class="font-medium text-[var(--color-ink-strong)]" x-text="format(row.value, 1)"></div>
                                                <div class="text-xs text-[var(--color-ink-soft)]" x-text="row.level"></div>
                                            </div>
                                        </div>
                                        <div class="relative mt-3 h-2 overflow-hidden rounded-full bg-white/80">
                                            <template x-if="targetZoneStyle(row.key)">
                                                <div class="absolute inset-y-0 rounded-full bg-[var(--color-success-soft)]" :style="targetZoneStyle(row.key)"></div>
                                            </template>
                                            <div class="relative h-full rounded-full" :style="qualityBarStyle(row.value)"></div>
                                        </div>
                                        <template x-if="row.explanation">
                                            <p class="mt-2 text-xs leading-5 text-[var(--color-ink-soft)]" x-text="row.explanation"></p>
                                        </template>
                                    </div>
                                </template>
                            </div>
                        </template>

                        <template x-if="!hasQualityMetricsData">
                            <div class="mt-4 rounded-[1.5rem] border border-dashed border-[var(--color-line)] bg-[var(--color-panel)] px-4 py-6 text-sm text-[var(--color-ink-soft)]">
                                Add saponifiable oils with SAP data to see backend-calculated Koskalk qualities here.
                            </div>
                        </template>

                        <template x-if="qualityFlags().length > 0">
                            <div class="mt-4 flex flex-wrap gap-2">
                                <template x-for="flag in qualityFlags()" :key="flag.label">
                                    <div class="rounded-2xl border border-[var(--color-line-strong)] bg-[var(--color-accent-soft)] px-3 py-2">
                                        <div class="text-xs font-medium text-[var(--color-ink-strong)]" x-text="flag.label"></div>
                                        <div class="mt-1 text-xs leading-5 text-[var(--color-ink-soft)]" x-text="flag.explanation"></div>
                                    </div>
                                </template>
                            </div>
                        </template>

                        <template x-if="hasQualityMetricsData">
                            <details class="mt-4 rounded-[1.5rem] border border-[var(--color-line)] bg-[var(--color-panel)] px-4 py-3">
                                <summary class="cursor-pointer text-sm font-medium text-[var(--color-ink-strong)]">Advanced metrics</summary>
                                <div class="mt-3 grid gap-2">
                                    <template x-for="row in advancedQualityRows()" :key="row.key">
                                        <div class="rounded-2xl border border-[var(--color-line)] bg-white px-4 py-3 text-sm">
                                            <div class="flex items-center justify-between">
                                                <span class="text-[var(--color-ink-soft)]" x-text="row.label"></span>
                                                <div class="text-right">
                                                    <div class="font-medium text-[var(--color-ink-strong)]" x-text="format(row.value, 1)"></div>
                                                    <template x-if="row.level">
                                                        <div class="text-xs text-[var(--color-ink-soft)]" x-text="row.level"></div>
                                                    </template>
                                                </div>
                                            </div>
                                            <template x-if="row.level">
                                                <div class="relative mt-3 h-2 overflow-hidden rounded-full bg-[var(--color-panel)]">
                                                    <template x-if="targetZoneStyle(row.key)">
                                                        <div class="absolute inset-y-0 rounded-full bg-[var(--color-success-soft)]" :style="targetZoneStyle(row.key)"></div>
                                                    </template>
                                                    <div class="relative h-full rounded-full" :style="qualityBarStyle(row.value)"></div>
                                                </div>
                                            </template>
                                            <template x-if="row.explanation">
                                                <p class="mt-2 text-xs leading-5 text-[var(--color-ink-soft)]" x-text="row.explanation"></p>
                                            </template>
                                        </div>
                                    </template>
                                </div>
                            </details>
                        </template>
                    </div>

                    <div class="rounded-[2rem] border border-[var(--color-line)] bg-white p-5">
                        <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Fatty acid profile</p>
                        <template x-if="hasFattyAcidProfileData">
                            <div class="mt-4 space-y-4">
                                <div class="rounded-[1.5rem] border border-[var(--color-line)] bg-[var(--color-panel)] p-4">
                                    <p class="text-xs font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase">Grouped profile</p>
                                    <div class="mt-3 flex h-3 overflow-hidden rounded-full bg-white/80">
                                        <template x-for="segment in fattyAcidGroupSegments()" :key="segment.key">
                                            <div :style="`width: ${segment.percent}%; background: ${segment.color};`"></div>
                                        </template>
                                    </div>
                                    <div class="mt-3 flex flex-wrap gap-2">
                                        <template x-for="segment in fattyAcidGroupSegments()" :key="`${segment.key}-legend`">
                                            <div class="flex items-center gap-2 rounded-full border border-[var(--color-line)] bg-white px-3 py-1 text-xs">
                                                <span class="inline-block h-2.5 w-2.5 rounded-full" :style="`background: ${segment.color};`"></span>
                                                <span class="rounded-full px-2 py-0.5 font-medium text-white" :style="`background: ${segment.color};`" x-text="segment.shortLabel"></span>
                                                <span class="text-[var(--color-ink-strong)]" x-text="segment.label"></span>
                                                <span class="text-[var(--color-ink-soft)]" x-text="`${format(segment.value, 1)}%`"></span>
                                            </div>
                                        </template>
                                    </div>
                                </div>

                                <div class="grid gap-2">
                                    <template x-for="row in fattyAcidProfileRows" :key="row.key">
                                        <div class="rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] px-4 py-3 text-sm">
                                            <div class="flex items-center justify-between">
                                                <span class="text-[var(--color-ink-soft)]" x-text="row.label"></span>
                                                <span class="font-medium text-[var(--color-ink-strong)]" x-text="`${format(row.value, 1)}%`"></span>
                                            </div>
                                            <div class="mt-3 h-2 overflow-hidden rounded-full bg-white/80">
                                                <div class="h-full rounded-full bg-[var(--color-ink-strong)]" :style="qualityBarStyle(row.value, 'var(--color-ink-soft)')"></div>
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
                </div>
    </section>

    <x-filament-actions::modals />
</div>
