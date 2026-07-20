@php
    $isCosmeticWorkbench = $isCosmeticWorkbench ?? false;
    $ifraSearchOptions = collect($workbench['ifraProductCategories'] ?? [])->map(fn (array $category): array => [
        'id' => $category['id'],
        'label' => filled($category['short_name'] ?? null)
            ? "Cat {$category['code']} — {$category['short_name']}"
            : "Cat {$category['code']}",
        'searchText' => implode(' ', array_filter([$category['code'], $category['short_name'] ?? null])),
    ])->values()->all();
@endphp

<section class="sk-card px-5 py-4" aria-labelledby="formula-setup-heading">
	<div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
		<div class="min-w-0">
			<p id="formula-setup-heading" class="sk-eyebrow">{{ __('workbench.settings.title') }}</p>
			<div x-show="! isFormulaSettingsOpen" x-cloak x-transition.opacity.duration.150ms class="mt-2 flex flex-wrap gap-2">
			<template x-for="card in formulaSetupSummaryCards" :key="`setup-${card.id}`">
				<span
					:class="{
						'bg-[var(--color-chemistry-soft)] text-[var(--color-chemistry-strong)]': card.tone === 'chemistry',
						'bg-[var(--color-info-soft)] text-[var(--color-info-strong)]': card.tone === 'info',
						'bg-[var(--color-danger-soft)] text-[var(--color-danger-strong)]': card.tone === 'danger',
						'bg-[var(--color-field-muted)] text-[var(--color-ink-soft)]': card.tone === 'neutral',
					}"
					class="inline-flex min-h-8 items-center gap-2 rounded-full px-3 py-1 text-xs font-medium"
				>
					<span x-text="card.label"></span>
					<span class="numeric font-semibold text-[var(--color-ink-strong)]" x-text="card.value"></span>
				</span>
			</template>
			</div>
		</div>
		<button
			type="button"
			@click="toggleFormulaSettings()"
			:aria-expanded="isFormulaSettingsOpen.toString()"
			aria-controls="formula-settings-panel"
			class="inline-flex min-h-10 shrink-0 items-center justify-center rounded-lg bg-[var(--color-field-muted)] px-4 py-2 text-sm font-medium text-[var(--color-ink-soft)] transition hover:text-[var(--color-ink-strong)]"
		>
			<span x-text="isFormulaSettingsOpen ? t('settings.hide') : t('settings.edit')"></span>
		</button>
	</div>
	<div id="formula-settings-panel" x-show="isFormulaSettingsOpen" x-cloak class="mt-4">
@if ($isCosmeticWorkbench)
	 <div>
	 <div class="grid gap-4 lg:grid-cols-2 xl:grid-cols-5">
	 <div class="sk-inset p-4">
	 <p id="setting-product-type" class="sk-eyebrow">{{ __('workbench.common.product_category') }}</p>
	 <template x-if="productTypes.length">
	 <select aria-labelledby="setting-product-type" x-model="productTypeId" class="mt-3 w-full rounded-lg bg-[var(--color-field)] px-3 py-2.5 text-sm text-[var(--color-ink-strong)] transition">
	 <option value="">{{ __('workbench.common.choose_later') }}</option>
	 <template x-for="productType in productTypes" :key="productType.id">
	 <option :value="String(productType.id)" x-text="productType.name"></option>
	 </template>
	 </select>
	 </template>
	 <template x-if="! productTypes.length">
	 <p class="mt-3 text-xs leading-5 text-[var(--color-ink-soft)]">{{ __('workbench.common.cosmetic_categories_unavailable') }}</p>
	 </template>
	 </div>
	 <div class="sk-inset p-4">
	 <p id="setting-batch-weight" class="sk-eyebrow">{{ __('workbench.common.total_batch') }}</p>
	 <div role="radiogroup" aria-label="Weight unit" class="mt-3 flex gap-2">
	 <button type="button" role="radio" :aria-checked="oilUnit === 'g'" @click="oilUnit = 'g'" :class="oilUnit === 'g' ? 'bg-[var(--color-active)] text-[var(--color-on-active)] shadow-sm' : 'bg-[var(--color-control)] text-[var(--color-ink-soft)] hover:bg-[var(--color-panel)]'" class="rounded-full px-4 py-2.5 text-xs font-medium transition">g</button>
	 <button type="button" role="radio" :aria-checked="oilUnit === 'oz'" @click="oilUnit = 'oz'" :class="oilUnit === 'oz' ? 'bg-[var(--color-active)] text-[var(--color-on-active)] shadow-sm' : 'bg-[var(--color-control)] text-[var(--color-ink-soft)] hover:bg-[var(--color-panel)]'" class="rounded-full px-4 py-2.5 text-xs font-medium transition">oz</button>
	 <button type="button" role="radio" :aria-checked="oilUnit === 'lb'" @click="oilUnit = 'lb'" :class="oilUnit === 'lb' ? 'bg-[var(--color-active)] text-[var(--color-on-active)] shadow-sm' : 'bg-[var(--color-control)] text-[var(--color-ink-soft)] hover:bg-[var(--color-panel)]'" class="rounded-full px-4 py-2.5 text-xs font-medium transition">lb</button>
	 </div>
	 <input aria-labelledby="setting-batch-weight" x-model="oilWeight" @blur="normalizeDecimalBlur($event)" type="text" inputmode="decimal" class="numeric mt-3 w-full rounded-lg bg-[var(--color-field)] px-4 py-3 text-sm text-[var(--color-ink-strong)] transition" />
	 </div>
	 <div class="sk-inset p-4">
	 <p id="setting-entry-mode" class="sk-eyebrow">{{ __('workbench.settings.entry_mode') }}</p>
	 <div role="radiogroup" aria-label="Entry mode" class="mt-3 flex flex-wrap gap-2">
	 <button type="button" role="radio" :aria-checked="editMode === 'percentage'" @click="editMode = 'percentage'" :class="editMode === 'percentage' ? 'bg-[var(--color-active)] text-[var(--color-on-active)] shadow-sm' : 'bg-[var(--color-control)] text-[var(--color-ink-soft)] hover:bg-[var(--color-panel)]'" class="rounded-full px-4 py-2.5 text-xs font-medium transition">{{ __('workbench.common.formula_percent') }}</button>
	 <button type="button" role="radio" :aria-checked="editMode === 'weight'" @click="editMode = 'weight'" :class="editMode === 'weight' ? 'bg-[var(--color-active)] text-[var(--color-on-active)] shadow-sm' : 'bg-[var(--color-control)] text-[var(--color-ink-soft)] hover:bg-[var(--color-panel)]'" class="rounded-full px-4 py-2.5 text-xs font-medium transition">{{ __('workbench.common.weight') }}</button>
	 </div>
	 </div>
	 <div class="sk-inset sk-tone-info p-4">
	 <p id="setting-exposure" class="sk-eyebrow">{{ __('workbench.settings.product_use') }}</p>
	 <div role="radiogroup" aria-label="Exposure type" class="mt-3 flex flex-wrap gap-2">
	 <button type="button" role="radio" :aria-checked="exposureMode === 'rinse_off'" @click="exposureMode = 'rinse_off'" :class="exposureMode === 'rinse_off' ? 'bg-[var(--color-active)] text-[var(--color-on-active)] shadow-sm' : 'bg-[var(--color-control)] text-[var(--color-ink-soft)] hover:bg-[var(--color-panel)]'" class="rounded-full px-4 py-2.5 text-xs font-medium transition">{{ __('workbench.common.rinse_off') }}</button>
	 <button type="button" role="radio" :aria-checked="exposureMode === 'leave_on'" @click="exposureMode = 'leave_on'" :class="exposureMode === 'leave_on' ? 'bg-[var(--color-active)] text-[var(--color-on-active)] shadow-sm' : 'bg-[var(--color-control)] text-[var(--color-ink-soft)] hover:bg-[var(--color-panel)]'" class="rounded-full px-4 py-2.5 text-xs font-medium transition">{{ __('workbench.common.leave_on') }}</button>
	 </div>
	 </div>
	 <div class="sk-inset sk-tone-info p-4">
	 <button type="button" @click="isComplianceSettingsOpen = ! isComplianceSettingsOpen" :aria-expanded="isComplianceSettingsOpen.toString()" class="flex w-full items-start justify-between gap-4 text-left">
	 <span>
	 <span class="sk-eyebrow">{{ __('workbench.common.label_compliance') }}</span>
	 <span class="mt-2 block text-xs leading-5 text-[var(--color-ink-soft)]" x-text="regulatoryRegimeCoverageLabel"></span>
	 </span>
	 <span class="rounded-full bg-white px-3 py-1 text-xs font-medium text-[var(--color-ink-soft)]" x-text="isComplianceSettingsOpen ? 'Hide' : 'Show'"></span>
	 </button>
	 <div x-show="isComplianceSettingsOpen" x-cloak class="mt-4 space-y-4">
	 <div>
	 <p id="setting-regime" class="sk-eyebrow">{{ __('workbench.settings.regulatory_framework') }}</p>
	 <select aria-labelledby="setting-regime" x-model="regulatoryRegime" class="mt-3 w-full rounded-lg bg-[var(--color-field)] px-3 py-2.5 text-sm text-[var(--color-ink-strong)] transition">
	 <template x-for="regime in regulatoryRegimes" :key="regime.code">
	 <option :value="regime.code" x-text="regime.version_label ? `${regime.name} - ${regime.version_label}` : regime.name"></option>
	 </template>
	 </select>
	 </div>
	 <div>
	 <p id="setting-ifra" class="sk-eyebrow">{{ __('workbench.settings.ifra_category') }}</p>
	 <template x-if="$data.ifraProductCategories?.length">
	 <x-search-combobox id="cosmetic-ifra-context-search" :label="__('workbench.settings.ifra_category')" :options="$ifraSearchOptions" :placeholder="__('workbench.settings.no_ifra_category')" class="mt-3" x-effect="syncSelection(selectedIfraProductCategoryId)" x-on:search-combobox-selected="selectedIfraProductCategoryId = String($event.detail.id)" x-on:search-combobox-cleared="selectedIfraProductCategoryId = ''" />
	 </template>
	 <template x-if="! $data.ifraProductCategories?.length">
	 <p class="mt-3 text-xs text-[var(--color-ink-soft)]">{{ __('workbench.settings.ifra_unavailable') }}</p>
	 </template>
	 <template x-if="selectedIfraProductCategory">
	 <span class="mt-2 inline-block rounded-full border border-[var(--color-active)] bg-[var(--color-active-soft)] px-2.5 py-1 text-xs font-medium text-[var(--color-ink-strong)]" x-text="`Cat ${selectedIfraProductCategory.code}`"></span>
	 </template>
	 </div>
	 </div>
	 </div>
	 </div>
	 </div>
@else
	 <div>
	 <div class="grid gap-4 xl:grid-cols-5">
	 <div class="sk-inset sk-tone-chemistry p-4">
	 <p id="setting-lye-type" class="sk-eyebrow">{{ __('workbench.common.lye_type') }}</p>
	 <div role="radiogroup" aria-label="Lye type" class="mt-3 flex flex-wrap gap-2">
	 <button type="button" role="radio" :aria-checked="lyeType === 'naoh'" @click="lyeType = 'naoh'" :class="lyeType === 'naoh' ? 'bg-[var(--color-active)] text-[var(--color-on-active)] shadow-sm' : 'bg-[var(--color-control)] text-[var(--color-ink-soft)] hover:bg-[var(--color-panel)]'" class="rounded-full px-4 py-2.5 text-xs font-medium transition">NaOH</button>
	 <button type="button" role="radio" :aria-checked="lyeType === 'koh'" @click="lyeType = 'koh'" :class="lyeType === 'koh' ? 'bg-[var(--color-active)] text-[var(--color-on-active)] shadow-sm' : 'bg-[var(--color-control)] text-[var(--color-ink-soft)] hover:bg-[var(--color-panel)]'" class="rounded-full px-4 py-2.5 text-xs font-medium transition">KOH</button>
	 <button type="button" role="radio" :aria-checked="lyeType === 'dual'" @click="lyeType = 'dual'" :class="lyeType === 'dual' ? 'bg-[var(--color-active)] text-[var(--color-on-active)] shadow-sm' : 'bg-[var(--color-control)] text-[var(--color-ink-soft)] hover:bg-[var(--color-panel)]'" class="rounded-full px-4 py-2.5 text-xs font-medium transition">{{ __('workbench.common.dual_lye') }}</button>
	 </div>
	 <template x-if="lyeType === 'dual'">
	 <div class="mt-3 sk-inset p-3">
	 <div class="flex items-center justify-between gap-3">
	 <span class="text-xs font-medium text-[var(--color-ink-soft)]">NaOH <span class="numeric" x-text="`${format(dualNaohPercentage, 1)}%`"></span></span>
	 <span class="text-xs font-medium text-[var(--color-ink-soft)]">KOH <span class="numeric" x-text="`${format(dualKohPercentage, 1)}%`"></span></span>
	 </div>
	 <input aria-label="NaOH to KOH ratio" x-model.number="dualKohPercentage" type="range" min="0" max="100" step="1" class="mt-3 w-full accent-[var(--color-active)]" />
	 </div>
	 </template>
	 <template x-if="lyeType === 'koh' || lyeType === 'dual'">
	 <div role="radiogroup" aria-label="KOH purity" class="mt-3 flex flex-wrap gap-2">
	 <button type="button" role="radio" :aria-checked="kohPurity === 100" @click="kohPurity = 100" :class="kohPurity === 100 ? 'bg-[var(--color-active)] text-[var(--color-on-active)] shadow-sm' : 'bg-[var(--color-control)] text-[var(--color-ink-soft)] hover:bg-[var(--color-panel)]'" class="rounded-full px-4 py-2.5 text-xs font-medium transition">KOH 100%</button>
	 <button type="button" role="radio" :aria-checked="kohPurity === 90" @click="kohPurity = 90" :class="kohPurity === 90 ? 'bg-[var(--color-active)] text-[var(--color-on-active)] shadow-sm' : 'bg-[var(--color-control)] text-[var(--color-ink-soft)] hover:bg-[var(--color-panel)]'" class="rounded-full px-4 py-2.5 text-xs font-medium transition">KOH 90%</button>
	 </div>
	 </template>
	 </div>
	 <div class="sk-inset p-4">
	 <p id="setting-base-weight" class="sk-eyebrow">{{ __('workbench.settings.total_oil_weight') }}</p>
	 <div role="radiogroup" aria-label="Weight unit" class="mt-3 flex gap-2">
	 <button type="button" role="radio" :aria-checked="oilUnit === 'g'" @click="oilUnit = 'g'" :class="oilUnit === 'g' ? 'bg-[var(--color-active)] text-[var(--color-on-active)] shadow-sm' : 'bg-[var(--color-control)] text-[var(--color-ink-soft)] hover:bg-[var(--color-panel)]'" class="rounded-full px-4 py-2.5 text-xs font-medium transition">g</button>
	 <button type="button" role="radio" :aria-checked="oilUnit === 'oz'" @click="oilUnit = 'oz'" :class="oilUnit === 'oz' ? 'bg-[var(--color-active)] text-[var(--color-on-active)] shadow-sm' : 'bg-[var(--color-control)] text-[var(--color-ink-soft)] hover:bg-[var(--color-panel)]'" class="rounded-full px-4 py-2.5 text-xs font-medium transition">oz</button>
	 <button type="button" role="radio" :aria-checked="oilUnit === 'lb'" @click="oilUnit = 'lb'" :class="oilUnit === 'lb' ? 'bg-[var(--color-active)] text-[var(--color-on-active)] shadow-sm' : 'bg-[var(--color-control)] text-[var(--color-ink-soft)] hover:bg-[var(--color-panel)]'" class="rounded-full px-4 py-2.5 text-xs font-medium transition">lb</button>
	 </div>
	 <input aria-labelledby="setting-base-weight" x-model="oilWeight" @blur="normalizeDecimalBlur($event)" type="text" inputmode="decimal" class="numeric mt-3 w-full rounded-lg bg-[var(--color-field)] px-4 py-3 text-sm text-[var(--color-ink-strong)] transition" />
	 <div class="mt-4 border-t border-[var(--color-line)] pt-4">
	 <p id="setting-entry-mode-soap" class="sk-eyebrow">{{ __('workbench.settings.entry_mode') }}</p>
	 <div role="radiogroup" aria-label="Entry mode" class="mt-3 flex flex-wrap gap-2">
	 <button type="button" role="radio" :aria-checked="editMode === 'percentage'" @click="editMode = 'percentage'" :class="editMode === 'percentage' ? 'bg-[var(--color-active)] text-[var(--color-on-active)] shadow-sm' : 'bg-[var(--color-control)] text-[var(--color-ink-soft)] hover:bg-[var(--color-panel)]'" class="rounded-full px-4 py-2.5 text-xs font-medium transition">% of oils</button>
	 <button type="button" role="radio" :aria-checked="editMode === 'weight'" @click="editMode = 'weight'" :class="editMode === 'weight' ? 'bg-[var(--color-active)] text-[var(--color-on-active)] shadow-sm' : 'bg-[var(--color-control)] text-[var(--color-ink-soft)] hover:bg-[var(--color-panel)]'" class="rounded-full px-4 py-2.5 text-xs font-medium transition">{{ __('workbench.common.weight') }}</button>
	 </div>
	 </div>
	 </div>
	 <div class="sk-inset sk-tone-chemistry p-4">
	 <p id="setting-water-mode" class="sk-eyebrow">{{ __('workbench.settings.water_mode') }}</p>
	 <div role="radiogroup" aria-label="Water calculation mode" class="mt-3 grid gap-2">
	 <button type="button" role="radio" :aria-checked="waterMode === 'percent_of_oils'" @click="waterMode = 'percent_of_oils'" :class="waterMode === 'percent_of_oils' ? 'bg-[var(--color-active)] text-[var(--color-on-active)] shadow-sm' : 'bg-[var(--color-control)] text-[var(--color-ink-soft)] hover:bg-[var(--color-panel)]'" class="rounded-[1rem] px-4 py-2.5 text-left text-xs font-medium transition">{{ __('workbench.common.water_percent') }}</button>
	 <button type="button" role="radio" :aria-checked="waterMode === 'lye_ratio'" @click="waterMode = 'lye_ratio'" :class="waterMode === 'lye_ratio' ? 'bg-[var(--color-active)] text-[var(--color-on-active)] shadow-sm' : 'bg-[var(--color-control)] text-[var(--color-ink-soft)] hover:bg-[var(--color-panel)]'" class="rounded-[1rem] px-4 py-2.5 text-left text-xs font-medium transition">{{ __('workbench.common.water_ratio') }}</button>
	 <button type="button" role="radio" :aria-checked="waterMode === 'lye_concentration'" @click="waterMode = 'lye_concentration'" :class="waterMode === 'lye_concentration' ? 'bg-[var(--color-active)] text-[var(--color-on-active)] shadow-sm' : 'bg-[var(--color-control)] text-[var(--color-ink-soft)] hover:bg-[var(--color-panel)]'" class="rounded-[1rem] px-4 py-2.5 text-left text-xs font-medium transition">{{ __('workbench.common.lye_concentration') }}</button>
	 </div>
	 <input aria-labelledby="setting-water-mode" x-model="waterValue" @blur="normalizeDecimalBlur($event)" type="text" inputmode="decimal" class="numeric mt-3 w-full rounded-lg bg-[var(--color-field)] px-4 py-3 text-sm text-[var(--color-ink-strong)] transition" />
	 </div>
	 <div class="sk-inset sk-tone-chemistry p-4">
	 <p id="setting-superfat" class="sk-eyebrow">{{ __('workbench.common.superfat') }}</p>
	 <div class="mt-3 flex items-center justify-between gap-3 text-sm">
	 <span aria-hidden="true"></span>
	 <span :class="superfat < 0 ? 'text-[var(--color-danger-strong)]' : 'text-[var(--color-ink-strong)]'" class="numeric font-semibold" x-text="`${format(superfat, 1)}%`"></span>
	 </div>
	 <input aria-labelledby="setting-superfat" x-model.number="superfat" @change="confirmNegativeSuperfat($event)" type="range" min="-20" max="20" step="0.5" :class="superfat < 0 ? 'accent-[var(--color-danger)]' : 'accent-[var(--color-active)]'" class="mt-3 w-full" />
	 <input aria-labelledby="setting-superfat" x-model="superfat" @blur="normalizeDecimalBlur($event, true)" @change="confirmNegativeSuperfat($event)" type="text" inputmode="decimal" :class="number(superfat) < 0 ? 'border-[var(--color-danger-soft)] text-[var(--color-danger-strong)]' : 'border-[var(--color-line)] text-[var(--color-ink-strong)]'" class="sk-superfat-control numeric mt-3 w-full rounded-lg border bg-[var(--color-field)] px-4 py-3 text-sm transition" />
	 </div>
	 <div class="sk-inset sk-tone-info p-4">
	 <p id="setting-exposure-soap" class="sk-eyebrow">{{ __('workbench.settings.product_use') }}</p>
	 <div role="radiogroup" aria-label="Exposure type" class="mt-3 flex flex-wrap gap-2">
	 <button type="button" role="radio" :aria-checked="exposureMode === 'rinse_off'" @click="exposureMode = 'rinse_off'" :class="exposureMode === 'rinse_off' ? 'bg-[var(--color-active)] text-[var(--color-on-active)] shadow-sm' : 'bg-[var(--color-control)] text-[var(--color-ink-soft)] hover:bg-[var(--color-panel)]'" class="rounded-full px-4 py-2.5 text-xs font-medium transition">{{ __('workbench.common.rinse_off') }}</button>
	 <button type="button" role="radio" :aria-checked="exposureMode === 'leave_on'" @click="exposureMode = 'leave_on'" :class="exposureMode === 'leave_on' ? 'bg-[var(--color-active)] text-[var(--color-on-active)] shadow-sm' : 'bg-[var(--color-control)] text-[var(--color-ink-soft)] hover:bg-[var(--color-panel)]'" class="rounded-full px-4 py-2.5 text-xs font-medium transition">{{ __('workbench.common.leave_on') }}</button>
	 </div>
	 <div class="mt-4">
	 <button type="button" @click="isComplianceSettingsOpen = ! isComplianceSettingsOpen" :aria-expanded="isComplianceSettingsOpen.toString()" class="flex w-full items-start justify-between gap-3 rounded-lg bg-white px-3 py-2.5 text-left transition hover:bg-[var(--color-panel)]">
	 <span>
	 <span class="sk-eyebrow">{{ __('workbench.common.label_compliance') }}</span>
	 <span class="mt-2 block text-xs leading-5 text-[var(--color-ink-soft)]" x-text="regulatoryRegimeCoverageLabel"></span>
	 </span>
	 <span class="rounded-full bg-[var(--color-field-muted)] px-3 py-1 text-xs font-medium text-[var(--color-ink-soft)]" x-text="isComplianceSettingsOpen ? 'Hide' : 'Show'"></span>
	 </button>
	 <div x-show="isComplianceSettingsOpen" x-cloak class="mt-4 space-y-4">
	 <div>
	 <p id="setting-regime-soap" class="sk-eyebrow">{{ __('workbench.settings.regulatory_framework') }}</p>
	 <select aria-labelledby="setting-regime-soap" x-model="regulatoryRegime" class="mt-3 w-full rounded-lg bg-[var(--color-field)] px-3 py-2.5 text-sm text-[var(--color-ink-strong)] transition">
	 <template x-for="regime in regulatoryRegimes" :key="regime.code">
	 <option :value="regime.code" x-text="regime.version_label ? `${regime.name} - ${regime.version_label}` : regime.name"></option>
	 </template>
	 </select>
	 </div>
	 <div>
	 <p id="setting-ifra-soap" class="sk-eyebrow">{{ __('workbench.settings.ifra_category') }}</p>
	 <template x-if="$data.ifraProductCategories?.length">
	 <x-search-combobox id="soap-ifra-context-search" :label="__('workbench.settings.ifra_category')" :options="$ifraSearchOptions" :placeholder="__('workbench.settings.no_ifra_category')" class="mt-3" x-effect="syncSelection(selectedIfraProductCategoryId)" x-on:search-combobox-selected="selectedIfraProductCategoryId = String($event.detail.id)" x-on:search-combobox-cleared="selectedIfraProductCategoryId = ''" />
	 </template>
	 <template x-if="! $data.ifraProductCategories?.length">
	 <p class="mt-3 text-xs text-[var(--color-ink-soft)]">{{ __('workbench.settings.ifra_unavailable') }}</p>
	 </template>
	 <template x-if="selectedIfraProductCategory">
	 <span class="mt-2 inline-block rounded-full border border-[var(--color-active)] bg-[var(--color-active-soft)] px-2.5 py-1 text-xs font-medium text-[var(--color-ink-strong)]" x-text="`Cat ${selectedIfraProductCategory.code}`"></span>
	 </template>
	 </div>
	 </div>
	 </div>
	 </div>
	 </div>
	 </div>
	@endif
</div>
	</section>
