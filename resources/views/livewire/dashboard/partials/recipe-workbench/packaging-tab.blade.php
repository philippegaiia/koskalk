@php
    $packagingCatalogOptions = collect($workbench['packagingCatalog'] ?? [])->map(fn (array $item): array => [
        'id' => $item['id'],
        'label' => $item['material_code'] ? $item['name'].' · '.$item['material_code'] : $item['name'],
        'description' => $item['notes'] ?? null,
        'searchText' => implode(' ', array_filter([$item['name'], $item['material_code'] ?? null, $item['notes'] ?? null])),
    ])->values()->all();
    $declaredContentUnits = ['g', 'kg', 'ml', 'l', 'oz', 'lb', 'fl_oz'];
@endphp

<div x-show="activeWorkbenchTab === 'packaging'" role="tabpanel" aria-labelledby="tab-packaging" id="panel-packaging" class="space-y-6 pb-24">
 <section class="overflow-hidden sk-card" aria-labelledby="product-sizes-heading">
 <div class="border-b border-[var(--color-line)] px-5 py-4">
 <p class="sk-eyebrow">{{ __('workbench.packaging.sizes.title') }}</p>
 <h3 id="product-sizes-heading" class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('workbench.packaging.sizes.heading') }} <x-contextual-help.trigger topic="shared.packaging" :topics="$contextualHelp['topics'] ?? []" /></h3>
 <p class="mt-2 max-w-3xl text-sm text-[var(--color-ink-soft)]">{{ __('workbench.packaging.sizes.help') }}</p>
 </div>

 <template x-if="productSizes.length === 0">
 <div class="px-5 py-8 text-sm text-[var(--color-ink-soft)]">
 <p class="font-medium text-[var(--color-ink-strong)]">{{ __('workbench.packaging.sizes.empty') }}</p>
 <p class="mt-2 max-w-3xl">{{ __('workbench.packaging.sizes.empty_help') }}</p>
 <button type="button" @click="addProductSize()" :disabled="!canWriteRecipe" class="sk-btn sk-btn-primary mt-4">{{ __('workbench.packaging.sizes.add') }}</button>
 </div>
 </template>

 <template x-if="productSizes.length > 0">
 <div class="flex flex-wrap items-center gap-2 px-5 py-4">
 <div class="flex flex-wrap gap-2" role="radiogroup" aria-label="{{ __('workbench.packaging.sizes.group_label') }}" data-product-size-picker>
 <template x-for="(size, index) in productSizes" :key="size.key">
 <button
     type="button"
     role="radio"
     :aria-checked="selectedProductSizeKey === size.key"
     @click="selectProductSize(size.key)"
     :class="selectedProductSizeKey === size.key ? 'bg-[var(--color-active)] text-[var(--color-on-active)] shadow-sm' : 'bg-[var(--color-control)] text-[var(--color-ink-soft)] hover:bg-[var(--color-panel)]'"
     class="inline-flex items-center gap-2 rounded-lg px-4 py-2.5 text-sm font-medium transition"
 >
 <span x-text="productSizeLabel(size, index)"></span>
 <span class="numeric text-xs opacity-75" aria-hidden="true" x-text="packagingRowCountForSize(size.key)"></span>
 <span class="sr-only" x-text="t('packaging.sizes.packaging_count', { count: packagingRowCountForSize(size.key) })"></span>
 </button>
 </template>
 </div>
 <button type="button" @click="addProductSize()" :disabled="!canWriteRecipe || !canAddProductSize" class="sk-btn sk-btn-outline">{{ __('workbench.packaging.sizes.add') }}</button>
 <p x-show="!canAddProductSize" role="status" class="text-xs text-[var(--color-ink-soft)]" x-text="t('packaging.sizes.limit_reached', { max: maxProductSizes })"></p>
 </div>
 </template>
 </section>

 <template x-if="selectedProductSize">
 <section class="overflow-hidden sk-card" aria-labelledby="selected-size-heading">
 <div class="border-b border-[var(--color-line)] px-5 py-4">
 <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
 <div>
 <p class="sk-eyebrow">{{ __('workbench.packaging.sizes.details') }}</p>
 <h3 id="selected-size-heading" class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]" x-text="productSizeLabel(selectedProductSize)"></h3>
 </div>
 <button
     type="button"
     @click="removeProductSize(selectedProductSize.key)"
     :disabled="!canWriteRecipe"
     :aria-label="t('packaging.sizes.remove_label', { size: productSizeLabel(selectedProductSize) })"
     class="sk-btn sk-btn-outline text-[var(--color-danger-strong)]"
 >{{ __('workbench.packaging.sizes.remove') }}</button>
 </div>

 <div class="mt-4 grid gap-3 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.6fr)]">
 <label class="sk-inset p-4">
 <span class="sk-eyebrow">{{ __('workbench.packaging.sizes.name') }}</span>
 <input
     :value="selectedProductSize.name"
     @input="updateProductSizeName(selectedProductSize, $event.target.value)"
     :disabled="!canWriteRecipe"
     type="text"
     maxlength="120"
     aria-label="{{ __('workbench.packaging.sizes.name') }}"
     placeholder="{{ __('workbench.packaging.sizes.name_placeholder') }}"
     class="mt-3 w-full rounded-lg border border-[var(--color-line)] bg-[var(--color-field)] px-3 py-2.5 text-sm text-[var(--color-ink-strong)] transition"
 />
 </label>

 <div class="sk-inset p-4">
 <span id="declared-content-label" class="sk-eyebrow">{{ __('workbench.packaging.sizes.declared_content') }}</span>
 <div class="mt-3 flex flex-col gap-3 sm:flex-row sm:items-center">
 <input
     :value="declaredContentFieldText(selectedProductSize)"
     @change="updateProductSizeContent(selectedProductSize, $event.target.value)"
     @blur="$event.target.value = declaredContentFieldText(selectedProductSize)"
     :disabled="!canWriteRecipe"
     type="text"
     inputmode="decimal"
     aria-labelledby="declared-content-label"
     :aria-invalid="productSizeContentError(selectedProductSize) !== ''"
     aria-describedby="declared-content-error"
     class="numeric w-full rounded-lg border border-[var(--color-line)] bg-[var(--color-field)] px-3 py-2.5 text-sm text-[var(--color-ink-strong)] transition sm:w-32"
 />
 <div class="flex flex-wrap gap-2" role="radiogroup" aria-label="{{ __('workbench.packaging.sizes.unit_group') }}">
 @foreach ($declaredContentUnits as $unit)
 <button
     type="button"
     role="radio"
     :aria-checked="selectedProductSize.declared_content_unit === '{{ $unit }}'"
     @click="updateProductSizeUnit(selectedProductSize, '{{ $unit }}')"
     :disabled="!canWriteRecipe"
     :class="selectedProductSize.declared_content_unit === '{{ $unit }}' ? 'bg-[var(--color-active)] text-[var(--color-on-active)] shadow-sm' : 'bg-[var(--color-control)] text-[var(--color-ink-soft)] hover:bg-[var(--color-panel)]'"
     class="rounded-lg px-3 py-2.5 text-xs font-medium transition"
 >{{ __('workbench.packaging.sizes.units.'.$unit) }}</button>
 @endforeach
 </div>
 </div>
 <p id="declared-content-error" x-show="productSizeContentError(selectedProductSize) !== ''" role="alert" class="mt-2 text-xs font-medium text-[var(--color-danger-strong)]" x-text="productSizeContentError(selectedProductSize)"></p>
 <p class="mt-2 text-xs text-[var(--color-ink-soft)]">{{ __('workbench.packaging.sizes.declared_content_help') }}</p>
 </div>
 </div>
 </div>

 <div class="border-b border-[var(--color-line)] px-5 py-4">
 <div class="flex flex-col gap-4">
 <div>
 <h4 class="text-base font-semibold text-[var(--color-ink-strong)]">{{ __('workbench.packaging.sizes.packaging_heading') }}</h4>
 <p class="mt-1 max-w-3xl text-sm text-[var(--color-ink-soft)]">{{ __('workbench.packaging.plan.help') }}</p>
 </div>

 <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
 <template x-if="packagingCatalog.length > 0">
 <x-search-combobox
 id="packaging-catalog-search"
 :label="__('workbench.packaging.plan.search_label')"
 :options="$packagingCatalogOptions"
 :placeholder="__('workbench.packaging.plan.search_placeholder')"
 :action-label="__('workbench.packaging.plan.add')"
 :empty-message="__('workbench.packaging.plan.no_matches')"
 :retain-selection="false"
 :floating-options="true"
 class="w-full sm:w-72 sm:min-w-72"
 x-effect="replaceOptions(packagingCatalog.map((item) => ({ id: item.id, label: item.material_code ? item.name + ' · ' + item.material_code : item.name, description: item.notes || '', searchText: [item.name, item.material_code, item.notes].filter(Boolean).join(' ') })))"
 x-on:search-combobox-selected="selectPackagingCatalogItem(packagingCatalog.find((item) => String(item.id) === String($event.detail.id)))"
 />
 </template>
 <button type="button" @click="openPackagingCatalogModal()" class="sk-btn sk-btn-primary">
 {{ __('workbench.packaging.plan.create') }}
 </button>
 </div>
 </div>
 </div>

 <p x-cloak x-show="packagingCatalogMessage && !packagingCatalogModalOpen" role="status" aria-live="polite" class="border-b border-[var(--color-line)] px-5 py-3 text-sm text-[var(--color-ink-strong)]" x-text="packagingCatalogMessage"></p>

 <template x-if="selectedSizePackagingRows.length === 0">
 <div class="px-5 py-8 text-sm text-[var(--color-ink-soft)]">
 <p class="font-medium text-[var(--color-ink-strong)]">{{ __('workbench.packaging.plan.empty') }}</p>
 <p class="mt-2">{{ __('workbench.packaging.plan.empty_help') }}</p>
 </div>
 </template>

 <template x-if="selectedSizePackagingRows.length > 0">
	 <div class="overflow-hidden touch-pan-x">
	 <div>
	 <div class="hidden text-sm lg:grid lg:grid-cols-[minmax(0,1.9fr)_9rem_minmax(0,1.3fr)_7rem] lg:gap-px lg:bg-[var(--color-line)]">
 <div class="bg-[var(--color-field-muted)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">{{ __('workbench.packaging.plan.item') }}</div>
 <div class="bg-[var(--color-field-muted)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">{{ __('workbench.packaging.plan.quantity_per_unit') }}</div>
 <div class="bg-[var(--color-field-muted)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">{{ __('workbench.packaging.plan.notes') }}</div>
 <div class="bg-[var(--color-field-muted)] px-4 py-3"></div>
 </div>

 <div class="divide-y divide-[var(--color-line)] bg-white">
 <template x-for="row in selectedSizePackagingRows" :key="row.id">
	 <div class="grid grid-cols-1 gap-3 bg-white p-3 text-sm lg:grid-cols-[minmax(0,1.9fr)_9rem_minmax(0,1.3fr)_7rem] lg:gap-px lg:bg-[var(--color-line)] lg:p-0">
	 <div class="flex items-center bg-white lg:px-4 lg:py-3">
 <div>
 <p class="font-medium text-[var(--color-ink-strong)]" x-text="row.name"></p>
 <p x-show="row.material_code" x-text="row.material_code" class="mt-1 font-mono text-xs font-medium text-[var(--color-ink-soft)]"></p>
 </div>
 </div>
	 <div class="flex flex-col gap-2 bg-white lg:flex-row lg:items-center lg:px-3 lg:py-3">
	 <span class="sk-eyebrow lg:hidden">{{ __('workbench.packaging.plan.quantity_per_unit') }}</span>
 <input :value="row.components_per_unit" @blur="normalizeDecimalBlur($event); updatePackagingPlanComponents(row, $event.target.value)" type="text" inputmode="decimal" :aria-label="t('packaging.accessibility.quantity_for', { item: row.name })" class="numeric w-full rounded-xl border border-[var(--color-line)] bg-[var(--color-field)] px-3 py-2 text-sm text-[var(--color-ink-strong)] transition" />
 </div>
	 <div class="flex flex-col gap-2 bg-white lg:flex-row lg:items-center lg:px-3 lg:py-3">
	 <span class="sk-eyebrow lg:hidden">{{ __('workbench.packaging.plan.notes') }}</span>
 <input x-model="row.notes" type="text" :aria-label="t('packaging.accessibility.notes_for', { item: row.name })" class="w-full rounded-xl border border-[var(--color-line)] bg-[var(--color-field)] px-3 py-2 text-sm text-[var(--color-ink-strong)] transition" />
 </div>
	 <div class="flex items-center justify-end bg-white lg:px-4 lg:py-3">
	 <button type="button" @click="removePackagingPlanRow(row.id)" class="grid size-10 place-items-center rounded-md text-base text-[var(--color-ink-soft)] transition hover:bg-[var(--color-danger-soft)] hover:text-[var(--color-danger-strong)]" aria-label="{{ __('workbench.packaging.accessibility.remove_item') }}">×</button>
 </div>
 </div>
 </template>
 </div>
 </div>
 </div>
 </template>
 </section>
 </template>

    <x-workflow-action-bar max-width="max-w-app" data-packaging-plan-save-bar>
 <button type="button" @click="publish()" :disabled="isFormulaLocked || !canSaveRecipe || isSaving" class="sk-btn sk-btn-primary">
 <span x-text="isFormulaLocked ? t('header.locked') : (isSaving ? t('header.saving') : t('header.save'))"></span>
 </button>
 </x-workflow-action-bar>
</div>
