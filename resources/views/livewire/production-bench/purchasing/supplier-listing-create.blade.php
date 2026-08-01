<div class="mx-auto max-w-5xl space-y-8">
    <x-production-bench.navigation />
    <x-production-bench.purchasing-navigation />

    <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="sk-eyebrow">Supplier listings</p>
            <h1 class="mt-2 text-3xl font-semibold text-[var(--color-ink-strong)]">New supplier listing</h1>
        </div>
        <a href="{{ $lockedSupplier ? route('production-bench.purchasing.supplier', $lockedSupplier) : route('production-bench.purchasing.listings') }}" wire:navigate class="text-sm font-medium text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)]">Cancel</a>
    </header>

    <form wire:submit="save" class="space-y-6">
        <section class="sk-card p-5">
            <h2 class="text-lg font-semibold text-[var(--color-ink-strong)]">Supplier</h2>
            <div class="mt-4">
                @if ($lockedSupplier)
                    <div class="rounded-xl border border-[var(--color-line)] bg-[var(--color-field-muted)] px-4 py-3">
                        <p class="text-xs font-medium uppercase tracking-wide text-[var(--color-ink-soft)]">Supplier selected</p>
                        <p class="mt-1 font-medium text-[var(--color-ink-strong)]"><span class="numeric">{{ $lockedSupplier->code }}</span> · {{ $lockedSupplier->name }}</p>
                    </div>
                @else
                    <x-search-combobox
                        id="supplier-listing-supplier-search"
                        label="Supplier"
                        :options="$supplierOptions"
                        :selected-id="$supplierId"
                        placeholder="Search suppliers"
                        :allow-empty="false"
                        x-on:search-combobox-selected="$wire.set('supplierId', Number($event.detail.id))"
                        x-on:search-combobox-cleared="$wire.set('supplierId', null)"
                    />
                @endif
                @error('supplierId') <p class="mt-2 text-xs text-[var(--color-danger-strong)]">{{ $message }}</p> @enderror
            </div>
        </section>

        <section class="sk-card p-5">
            <h2 class="text-lg font-semibold text-[var(--color-ink-strong)]">Catalog item</h2>
            <fieldset class="mt-4">
                <legend class="sr-only">Catalog item type</legend>
                <div class="grid gap-2 sm:grid-cols-2">
                    <label class="cursor-pointer rounded-xl border border-[var(--color-line)] px-4 py-3 has-checked:border-[var(--color-accent)] has-checked:bg-[var(--color-accent-soft)]"><input wire:model.live="materialType" type="radio" value="ingredient" class="mr-2">Ingredient</label>
                    <label class="cursor-pointer rounded-xl border border-[var(--color-line)] px-4 py-3 has-checked:border-[var(--color-accent)] has-checked:bg-[var(--color-accent-soft)]"><input wire:model.live="materialType" type="radio" value="packaging" class="mr-2">Packaging item</label>
                </div>
            </fieldset>
            <div class="mt-4">
                @if ($materialType === 'packaging')
                    <x-search-combobox
                        id="supplier-listing-packaging-search"
                        label="Packaging item"
                        :options="$packagingOptions"
                        :selected-id="$packagingItemId"
                        placeholder="Search packaging items"
                        :allow-empty="false"
                        x-on:search-combobox-selected="$wire.set('packagingItemId', Number($event.detail.id))"
                        x-on:search-combobox-cleared="$wire.set('packagingItemId', null)"
                    />
                    @error('packagingItemId') <p class="mt-2 text-xs text-[var(--color-danger-strong)]">{{ $message }}</p> @enderror
                @else
                    <x-search-combobox
                        id="supplier-listing-ingredient-search"
                        label="Ingredient"
                        :options="$ingredientOptions"
                        :selected-id="$ingredientId"
                        placeholder="Search ingredients"
                        :allow-empty="false"
                        x-on:search-combobox-selected="$wire.set('ingredientId', Number($event.detail.id))"
                        x-on:search-combobox-cleared="$wire.set('ingredientId', null)"
                    />
                    <p class="mt-2 text-xs text-[var(--color-ink-soft)]">Select an existing Soapkraft ingredient.</p>
                    @error('ingredientId') <p class="mt-2 text-xs text-[var(--color-danger-strong)]">{{ $message }}</p> @enderror
                @endif
            </div>
        </section>

        <section class="sk-card p-5">
            <h2 class="text-lg font-semibold text-[var(--color-ink-strong)]">Purchase format</h2>
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <label class="space-y-2"><span class="text-sm font-medium">Supplier SKU</span><input wire:model="supplierSku" class="sk-input w-full">@error('supplierSku') <span class="block text-xs text-[var(--color-danger-strong)]">{{ $message }}</span> @enderror</label>
                <label class="space-y-2"><span class="text-sm font-medium">Supplier item name</span><input wire:model="supplierName" class="sk-input w-full">@error('supplierName') <span class="block text-xs text-[var(--color-danger-strong)]">{{ $message }}</span> @enderror</label>
                <label class="space-y-2 md:col-span-2"><span class="text-sm font-medium">Purchase format</span><input wire:model="purchaseFormat" required placeholder="e.g. 200 kg drum" class="sk-input w-full">@error('purchaseFormat') <span class="block text-xs text-[var(--color-danger-strong)]">{{ $message }}</span> @enderror</label>
                <label class="space-y-2"><span class="text-sm font-medium">Net quantity</span><input wire:model.live.debounce.250ms="netQuantity" required inputmode="decimal" class="sk-input w-full">@error('netQuantity') <span class="block text-xs text-[var(--color-danger-strong)]">{{ $message }}</span> @enderror</label>
                <label class="space-y-2"><span class="text-sm font-medium">Unit of measure</span>@if ($materialType === 'packaging')<input value="count" disabled class="sk-input w-full">@else<select wire:model.live="netUnit" required class="sk-input w-full"><option value="g">g</option><option value="kg">kg</option><option value="oz">oz</option><option value="lb">lb</option></select>@endif @error('netUnit') <span class="block text-xs text-[var(--color-danger-strong)]">{{ $message }}</span> @enderror</label>
            </div>
        </section>

        <section class="sk-card p-5">
            <h2 class="text-lg font-semibold text-[var(--color-ink-strong)]">Pricing</h2>
            <fieldset class="mt-4">
                <legend class="text-sm font-medium">Pricing basis</legend>
                <div class="mt-2 grid gap-2 sm:grid-cols-2">
                    <label class="cursor-pointer rounded-xl border border-[var(--color-line)] px-4 py-3 has-checked:border-[var(--color-accent)] has-checked:bg-[var(--color-accent-soft)]"><input wire:model.live="priceBasis" type="radio" value="per_unit" class="mr-2">Price per unit of measure</label>
                    <label class="cursor-pointer rounded-xl border border-[var(--color-line)] px-4 py-3 has-checked:border-[var(--color-accent)] has-checked:bg-[var(--color-accent-soft)]"><input wire:model.live="priceBasis" type="radio" value="total_purchase_format" class="mr-2">Total purchase-format price</label>
                </div>
                @error('priceBasis') <p class="mt-2 text-xs text-[var(--color-danger-strong)]">{{ $message }}</p> @enderror
            </fieldset>
            <div class="mt-4 grid gap-4 md:grid-cols-3">
                <label class="space-y-2"><span class="text-sm font-medium">Price</span><input wire:model.live.debounce.250ms="priceAmount" required inputmode="decimal" class="sk-input w-full">@error('priceAmount') <span class="block text-xs text-[var(--color-danger-strong)]">{{ $message }}</span> @enderror</label>
                @if ($priceBasis === 'per_unit')
                    <label class="space-y-2"><span class="text-sm font-medium">Price unit</span>@if ($materialType === 'packaging')<input value="count" disabled class="sk-input w-full">@else<select wire:model.live="priceUnit" required class="sk-input w-full"><option value="g">g</option><option value="kg">kg</option><option value="oz">oz</option><option value="lb">lb</option></select>@endif @error('priceUnit') <span class="block text-xs text-[var(--color-danger-strong)]">{{ $message }}</span> @enderror</label>
                @endif
                <div class="space-y-2"><span class="text-sm font-medium">Currency</span><x-search-combobox id="supplier-listing-currency-search" label="Currency" :options="$currencyOptions" :selected-id="$currency" :allow-empty="false" placeholder="Search currencies" x-on:search-combobox-selected="$wire.set('currency', String($event.detail.id))" />@error('currency') <span class="block text-xs text-[var(--color-danger-strong)]">{{ $message }}</span> @enderror</div>
            </div>
            <p class="mt-3 text-xs text-[var(--color-ink-soft)]">Enter either the price per unit of measure or the total price for one purchase format.</p>
            @if ($pricePreview)
                <div role="status" class="mt-4 grid gap-3 rounded-xl bg-[var(--color-field-muted)] p-4 sm:grid-cols-2">
                    <div><p class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)]">Unit price</p><p class="numeric mt-1 font-medium">{{ $pricePreview['unit'] }}</p></div>
                    <div><p class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)]">Purchase-format total</p><p class="numeric mt-1 font-medium">{{ $pricePreview['total'] }}</p></div>
                </div>
            @endif
        </section>

        <section class="sk-card p-5">
            <h2 class="text-lg font-semibold text-[var(--color-ink-strong)]">Ordering</h2>
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <label class="space-y-2"><span class="text-sm font-medium">Minimum order</span><input wire:model="minimumPacks" type="number" min="1" step="1" required class="sk-input w-full"><span class="block text-xs text-[var(--color-ink-soft)]">Number of purchase formats.</span>@error('minimumPacks') <span class="block text-xs text-[var(--color-danger-strong)]">{{ $message }}</span> @enderror</label>
                <label class="flex items-center gap-3 self-end rounded-xl border border-[var(--color-line)] px-4 py-3"><input wire:model="isActive" type="checkbox"><span class="text-sm font-medium">Active</span></label>
                <label class="space-y-2 md:col-span-2"><span class="text-sm font-medium">Notes</span><textarea wire:model="notes" rows="4" class="sk-input w-full"></textarea>@error('notes') <span class="block text-xs text-[var(--color-danger-strong)]">{{ $message }}</span> @enderror</label>
            </div>
        </section>

        @error('production_bench') <p class="text-sm text-[var(--color-danger-strong)]">{{ $message }}</p> @enderror
        <div class="flex flex-wrap items-center gap-4">
            <button type="submit" class="rounded-full bg-[var(--color-accent)] px-5 py-2.5 text-sm font-medium text-white transition hover:bg-[var(--color-accent-strong)] disabled:opacity-40" wire:loading.attr="disabled" wire:target="save">Save supplier listing</button>
            <a href="{{ $lockedSupplier ? route('production-bench.purchasing.supplier', $lockedSupplier) : route('production-bench.purchasing.listings') }}" wire:navigate class="text-sm font-medium text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)]">Cancel</a>
        </div>
    </form>
</div>
