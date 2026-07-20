@php($isCosmeticWorkbench = $isCosmeticWorkbench ?? false)

<aside class="space-y-4">
 <div class="overflow-hidden sk-card sk-tone-catalog">
 <div class="sk-section-header border-b border-[var(--color-line)] px-4 py-4">
 <h3 class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('workbench.ingredients.title') }}</h3>
 </div>

 <div class="space-y-3 border-b border-[var(--color-line)] px-4 py-4">
 <input x-model="search" type="search" placeholder="{{ __('workbench.ingredients.search_placeholder') }}" aria-label="{{ __('workbench.ingredients.search_label') }}" class="sk-ingredient-filter-control w-full px-4 py-3 text-sm text-[var(--color-ink-strong)] placeholder:text-[var(--color-ink-soft)]" />

 <select x-model="activeCategory" aria-label="Filter by category" class="sk-ingredient-filter-control w-full px-4 py-3 text-sm font-medium text-[var(--color-ink-strong)]">
 <template x-for="option in categoryOptions" :key="option.value">
 <option :value="option.value" :selected="option.value === activeCategory" x-text="`${option.label} (${categoryIngredientCount(option.value)})`"></option>
 </template>
 </select>
 </div>

 <div class="border-b border-[var(--color-line)] px-5 py-3">
 <p class="text-sm text-[var(--color-ink-soft)]" x-text="filteredIngredients.length === 1 ? t('ingredients.count_singular') : t('ingredients.count_plural', { count: filteredIngredients.length })"></p>
 </div>

 <div class="max-h-[18rem] divide-y divide-[var(--color-line)] overflow-y-auto md:max-h-[22rem] lg:max-h-[24rem] xl:max-h-[600px]" role="region" aria-label="Ingredient list">
 <template x-for="ingredient in filteredIngredients" :key="ingredient.id">
 <div class="group px-3 py-1.5 transition hover:bg-[var(--color-panel)] focus-within:bg-[var(--color-panel)]">
 <div class="flex items-center gap-3">
 <div class="size-10 shrink-0 overflow-hidden rounded-lg bg-[var(--color-panel)]">
 <template x-if="ingredient.image_url">
 <img :src="ingredient.image_url" :alt="ingredient.name" loading="lazy" decoding="async" class="size-full object-cover" />
 </template>
 <template x-if="! ingredient.image_url">
 <div class="grid size-full place-items-center text-[10px] font-semibold tracking-[0.08em] text-[var(--color-ink-soft)]" x-text="ingredientCategoryCode(ingredient)"></div>
 </template>
 </div>
 <div class="min-w-0 flex-1">
 <p class="flex items-center gap-1.5 text-[13px] font-semibold leading-[1.125rem] text-[var(--color-ink-strong)]" :title="ingredient.name">
 <span class="line-clamp-2" x-text="ingredient.name"></span>
 <span x-show="ingredient.is_user_owned" class="inline-block size-1.5 shrink-0 rounded-full bg-[var(--color-ink-soft)] opacity-60" role="img" aria-label="User-created or user-modified ingredient" title="User-created or user-modified ingredient"></span>
 </p>
 </div>

 <div class="flex shrink-0 items-center gap-2">
 <div x-data="{
 open: false,
 panelStyle: '',
 reposition() {
 this.$nextTick(() => {
 const panelWidth = 256;
 const gutter = 16;
 const rect = this.$refs.trigger.getBoundingClientRect();
 const panelHeight = this.$refs.ingredientInspectorPanel?.offsetHeight ?? 0;
 const left = Math.max(gutter, Math.min(window.innerWidth - panelWidth - gutter, rect.right - panelWidth));
 const belowTop = rect.bottom + 8;
 const aboveTop = rect.top - panelHeight - 8;
 const top = belowTop + panelHeight > window.innerHeight - gutter
 ? Math.max(gutter, aboveTop)
 : belowTop;

 this.panelStyle = `position: fixed; top: ${top}px; left: ${left}px; width: ${panelWidth}px;`;
 });
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
	 class="grid size-9 place-items-center rounded-full border border-[var(--color-line)] bg-[var(--color-field)] text-[10px] font-semibold text-[var(--color-ink-soft)] transition hover:border-[var(--color-line-strong)] hover:text-[var(--color-ink-strong)]" aria-label="Show ingredient details" aria-haspopup="dialog" :aria-expanded="open.toString()">
 i
 </button>
 </template>
 <template x-teleport="body">
 <div x-show="open"
 x-transition.opacity
 @mouseenter="open = true"
 @mouseleave="open = false"
	 @click.outside="open = false"
	 @keydown.escape.window="open = false"
	 @scroll.window="if (open) { reposition(); }"
 @resize.window="if (open) { reposition(); }"
 :style="panelStyle"
 x-ref="ingredientInspectorPanel"
 role="dialog"
 aria-label="Ingredient details"
 class="z-[80] max-h-[calc(100dvh-2rem)] overflow-y-auto rounded-[1.25rem] border border-[var(--color-line)] bg-[var(--color-field)] p-3">
 <p class="sk-eyebrow">{{ __('workbench.common.ingredient') }}</p>
 <div class="mt-2.5 rounded-xl bg-[var(--color-panel)] px-3 py-2">
 <p class="text-sm font-semibold leading-snug text-[var(--color-ink-strong)]" x-text="ingredient.name"></p>
 <p class="mt-1 text-xs leading-4 text-[var(--color-ink-soft)]" x-text="ingredient.inci_name || 'INCI not entered yet'"></p>
 </div>
 <p class="mt-3 sk-eyebrow">{{ __('workbench.ingredients.properties') }}</p>
 <div class="mt-2.5 space-y-1.5 text-xs text-[var(--color-ink-soft)]">
 <template x-for="row in ingredientInspectorRows(ingredient)" :key="row.label">
 <div class="flex items-center justify-between gap-3 rounded-xl bg-[var(--color-panel)] px-3 py-2">
 <span x-text="row.label"></span>
 <span class="numeric font-medium text-[var(--color-ink-strong)]" x-text="row.value"></span>
 </div>
 </template>
 </div>
 <template x-if="ingredientFattyAcidRows(ingredient).length > 0">
 <div class="mt-3">
 <p class="sk-eyebrow">{{ __('workbench.common.fatty_acids') }}</p>
 <div class="mt-2 max-h-40 space-y-1 overflow-y-auto pr-1 text-xs text-[var(--color-ink-soft)]">
 <template x-for="row in ingredientFattyAcidRows(ingredient)" :key="row.key">
 <div class="flex items-center justify-between gap-3 rounded-xl border border-[var(--color-line)] px-3 py-2">
 <span x-text="row.label"></span>
 <span class="numeric font-medium text-[var(--color-ink-strong)]" x-text="`${format(row.value, 1)}%`"></span>
 </div>
 </template>
 </div>
 </div>
 </template>
 </div>
 </template>
 </div>
 <div class="flex justify-end">
 @if ($isCosmeticWorkbench)
 <template x-if="phaseOrder.length <= 1">
 <button type="button" @click.stop="addIngredient(ingredient, cosmeticDefaultPhaseKey())" class="grid size-9 place-items-center rounded-full bg-[var(--color-accent)] text-lg font-semibold leading-none text-[var(--color-on-accent)] opacity-100 transition hover:bg-[var(--color-accent-hover)] sm:opacity-0 sm:group-hover:opacity-100 sm:group-focus-within:opacity-100" aria-label="Add ingredient">
 <span>+</span>
 </button>
 </template>
 <template x-if="phaseOrder.length > 1">
 <div x-data="{
 open: false,
 panelStyle: '',
 reposition() {
 const panelWidth = 192;
 const panelHeight = Math.min(256, Math.max(48, (this.phaseOrder?.length ?? 1) * 40 + 8));
 const gutter = 16;
 const rect = this.$refs.trigger.getBoundingClientRect();
 const left = Math.max(gutter, Math.min(window.innerWidth - panelWidth - gutter, rect.right - panelWidth));
 const belowTop = rect.bottom + 8;
 const aboveTop = rect.top - panelHeight - 8;
 const top = belowTop + panelHeight > window.innerHeight - gutter
 ? Math.max(gutter, aboveTop)
 : belowTop;

 this.panelStyle = `position: fixed; top: ${top}px; left: ${left}px; width: ${panelWidth}px;`;
 },
 }" class="relative">
 <button type="button" x-ref="trigger" @click.stop="open = !open; if (open) { $nextTick(() => reposition()); }" class="grid size-9 place-items-center rounded-full bg-[var(--color-accent)] text-lg font-semibold leading-none text-[var(--color-on-accent)] opacity-100 transition hover:bg-[var(--color-accent-hover)] sm:opacity-0 sm:group-hover:opacity-100 sm:group-focus-within:opacity-100" aria-label="Choose phase for ingredient" aria-haspopup="menu" :aria-expanded="open.toString()">
 <span>+</span>
 </button>
 <template x-teleport="body">
 <div x-show="open"
 x-transition.opacity
 x-cloak
 @click.outside="open = false"
 @keydown.escape.window="open = false"
 @scroll.window="if (open) { reposition(); }"
 @resize.window="if (open) { reposition(); }"
 :style="panelStyle"
 class="z-[90] max-h-[min(16rem,calc(100vh-2rem))] overflow-y-auto rounded-lg border border-[var(--color-line)] bg-[var(--color-field)] p-1 shadow-lg">
 <template x-for="phase in phaseOrder" :key="`${ingredient.id}-${phase.key}-add-option`">
 <button type="button" @click.stop="addIngredient(ingredient, phase.key); open = false" class="flex w-full items-center justify-between gap-3 rounded-md px-3 py-2 text-left text-xs font-medium text-[var(--color-ink-strong)] transition hover:bg-[var(--color-accent-soft)]">
 <span class="truncate" x-text="`Add to ${phase.name || humanizeKey(phase.key)}`"></span>
 <span class="numeric text-[var(--color-ink-soft)]" x-text="`${format(cosmeticPhasePercentageTotal(phase.key), 1)}%`"></span>
 </button>
 </template>
 </div>
 </template>
 </div>
 </template>
 @else
 <button type="button" @click.stop="addIngredient(ingredient)" class="grid size-9 place-items-center rounded-full bg-[var(--color-accent)] text-lg font-semibold leading-none text-[var(--color-on-accent)] opacity-100 transition hover:bg-[var(--color-accent-hover)] sm:opacity-0 sm:group-hover:opacity-100 sm:group-focus-within:opacity-100" aria-label="Add ingredient">
 <span>+</span>
 </button>
 @endif
 </div>
 </div>
 </div>
 </div>
 </template>
 </div>
 <template x-if="ingredients.some(ingredient => ingredient.is_user_owned)">
 <div class="border-t border-[var(--color-line)] px-5 py-2.5 text-[0.625rem] leading-4 text-[var(--color-ink-soft)]">
 <span class="mr-1 inline-block size-1.5 rounded-full bg-[var(--color-ink-soft)] opacity-60"></span>
 {{ __('workbench.ingredients.ownership_note') }}
 </div>
 </template>
 </div>

</aside>
