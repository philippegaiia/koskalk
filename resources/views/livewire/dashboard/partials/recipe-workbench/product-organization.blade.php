<div class="space-y-4" x-data="{ organizationSaveFailedMessage: @js(__('organization.save_failed')) }">
    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <label for="product-brand" class="block text-sm font-medium">{{ __('organization.brand') }}</label>
            <select id="product-brand" :value="brandId ?? ''" @change="changeOrganizationBrand($event.target.value)" class="mt-2 w-full rounded-lg border border-[var(--color-line)] bg-[var(--color-field)] px-3 py-2.5 text-sm">
                <option value="">{{ __('organization.no_brand') }}</option>
                <template x-for="brand in organizationBrands" :key="brand.id"><option :value="brand.id" :selected="String(brand.id) === String(brandId)" x-text="brand.name"></option></template>
            </select>
            <button type="button" @click="startOrganizationGroup('brand')" class="mt-2 text-sm font-medium text-[var(--color-accent-strong)]">{{ __('organization.create_brand') }}</button>
        </div>
        <div>
            <label for="product-collection" class="block text-sm font-medium">{{ __('organization.collection') }}</label>
            <select id="product-collection" :value="productCollectionId ?? ''" @change="changeProductCollection($event.target.value)" class="mt-2 w-full rounded-lg border border-[var(--color-line)] bg-[var(--color-field)] px-3 py-2.5 text-sm">
                <option value="">{{ __('organization.no_collection') }}</option>
                <template x-for="collection in availableProductCollections" :key="collection.id"><option :value="collection.id" :selected="String(collection.id) === String(productCollectionId)" x-text="productCollectionLabel(collection)"></option></template>
            </select>
            <button type="button" @click="startOrganizationGroup('collection')" :disabled="!brandId" class="mt-2 text-sm font-medium text-[var(--color-accent-strong)] disabled:opacity-50">{{ __('organization.create_collection') }}</button>
        </div>
    </div>
    <div x-cloak x-show="organizationGroupType" class="mt-4 flex flex-wrap items-end gap-2">
        <label class="min-w-0 flex-1 text-sm"><span x-text="organizationGroupType === 'brand' ? @js(__('organization.brand')) : @js(__('organization.collection'))"></span><input x-model="organizationGroupName" maxlength="255" type="text" @keydown.enter.prevent="createOrganizationGroup()" class="mt-2 w-full rounded-lg border border-[var(--color-line)] bg-[var(--color-field)] px-3 py-2 text-sm" /></label>
        <button type="button" @click="createOrganizationGroup()" :disabled="organizationStatus === 'saving'" class="sk-btn sk-btn-outline">{{ __('organization.create') }}</button>
        <button type="button" @click="organizationGroupType = null" :disabled="organizationStatus === 'saving'" class="sk-btn sk-btn-ghost">{{ __('workbench.packaging.modal.cancel') }}</button>
    </div>
    <p x-cloak x-show="organizationMessage" role="status" class="mt-3 text-sm" :class="organizationStatus === 'error' ? 'text-[var(--color-danger-strong)]' : 'text-[var(--color-ink-soft)]'" x-text="organizationMessage"></p>
</div>
