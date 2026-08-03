<x-production-bench.page purchasing compact>
    <header class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="sk-eyebrow">{{ __('production_bench.receipt.workflow') }}</p>
            <h1 class="mt-2 text-3xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.receipt.new') }}</h1>
        </div>
        <a href="{{ route('production-bench.purchasing.receipts') }}" wire:navigate class="sk-btn sk-btn-ghost">{{ __('production_bench.common.cancel') }}</a>
    </header>

    <section aria-labelledby="receipt-source-heading" class="space-y-3">
        <h2 id="receipt-source-heading" class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.receipt.choose_source') }}</h2>
        <div class="grid gap-3 sm:grid-cols-2">
            <button type="button" wire:click="chooseSource('purchase_order')" wire:loading.attr="disabled" wire:target="chooseSource" aria-pressed="{{ $source === 'purchase_order' ? 'true' : 'false' }}" @class(['min-h-20 rounded-xl border p-4 text-left transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)]', 'border-[var(--color-accent)] bg-[var(--color-accent-soft)]' => $source === 'purchase_order', 'border-[var(--color-line)] bg-[var(--color-panel)] hover:bg-[var(--color-panel-strong)]' => $source !== 'purchase_order'])>
                <span class="block font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.receipt.receive_order') }}</span>
                <span class="mt-1 block text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.receipt.receive_order_help') }}</span>
            </button>
            <button type="button" wire:click="chooseSource('direct')" wire:loading.attr="disabled" wire:target="chooseSource" aria-pressed="{{ $source === 'direct' ? 'true' : 'false' }}" @class(['min-h-20 rounded-xl border p-4 text-left transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)]', 'border-[var(--color-accent)] bg-[var(--color-accent-soft)]' => $source === 'direct', 'border-[var(--color-line)] bg-[var(--color-panel)] hover:bg-[var(--color-panel-strong)]' => $source !== 'direct'])>
                <span class="block font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.receipt.direct') }}</span>
                <span class="mt-1 block text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.receipt.direct_help') }}</span>
            </button>
        </div>
    </section>

    @if ($source === 'purchase_order' || $source === 'direct')
        <form wire:submit="post" wire:loading.attr="aria-busy" wire:target="post" class="space-y-6">
            <section class="sk-card grid gap-4 p-5 sm:grid-cols-2">
                <h2 class="font-semibold text-[var(--color-ink-strong)] sm:col-span-2">{{ __('production_bench.receipt.header') }}</h2>
                <label class="text-sm">
                    <span class="font-medium">{{ __('production_bench.receipt.received_on') }}</span>
                    <input wire:model="receivedAt" type="date" required aria-invalid="{{ $errors->has('receivedAt') ? 'true' : 'false' }}" @if($errors->has('receivedAt')) aria-describedby="receipt-date-error" @endif class="sk-input mt-1 w-full">
                    @error('receivedAt') <span id="receipt-date-error" class="mt-1 block text-xs text-[var(--color-danger-strong)]">{{ $message }}</span> @enderror
                </label>
                <label class="text-sm">
                    <span class="font-medium">{{ __('production_bench.receipt.delivery_reference') }}</span>
                    <input wire:model="deliveryReference" class="sk-input mt-1 w-full">
                </label>
                <label class="text-sm sm:col-span-2">
                    <span class="font-medium">{{ __('production_bench.common.notes') }}</span>
                    <textarea wire:model="notes" rows="2" class="sk-input mt-1 w-full"></textarea>
                </label>
            </section>

            @if ($source === 'purchase_order')
                <section aria-labelledby="receipt-order-lines-heading" class="space-y-4">
                    <h2 id="receipt-order-lines-heading" class="sr-only">{{ __('production_bench.receipt.receive_order') }}</h2>
                    <label class="block text-sm">
                        <span class="font-medium">{{ __('production_bench.common.search') }}</span>
                        <input wire:model.live.debounce.300ms="orderSearch" type="search" autocomplete="off" class="sk-input mt-1 w-full" aria-label="{{ __('production_bench.common.search') }}: {{ __('production_bench.receipt.purchase_order') }}">
                    </label>
                    <label class="block text-sm">
                        <span class="font-medium">{{ __('production_bench.receipt.purchase_order') }}</span>
                        <select wire:model.live="orderPublicId" required aria-invalid="{{ $errors->has('orderPublicId') ? 'true' : 'false' }}" @if($errors->has('orderPublicId')) aria-describedby="receipt-order-error" @endif class="sk-input mt-1 w-full">
                            <option value="">{{ __('production_bench.receipt.choose_order') }}</option>
                            @foreach ($orders as $order)
                                <option value="{{ $order->public_id }}">{{ $order->reference }} · {{ $order->supplier->name }}</option>
                            @endforeach
                        </select>
                        @error('orderPublicId') <span id="receipt-order-error" class="mt-1 block text-xs text-[var(--color-danger-strong)]">{{ $message }}</span> @enderror
                    </label>

                    @if ($selectedOrder)
                        <p id="receipt-order-currency-help" class="text-xs text-[var(--color-ink-soft)]">{{ __('production_bench.receipt.currency_locked_help') }}</p>
                        <div class="space-y-3" data-receipt-editable-lines>
                            <div class="space-y-2">
                                <div class="hidden bg-[var(--color-panel-muted)] text-xs uppercase tracking-wide text-[var(--color-ink-soft)] lg:grid lg:grid-cols-[2rem_minmax(12rem,1.4fr)_6rem_7rem_6rem_minmax(29rem,2fr)]">
                                    <span class="px-3 py-3"></span><span class="px-3 py-3">{{ __('production_bench.receipt.item') }}</span><span class="px-3 py-3 text-right">{{ __('production_bench.receipt.ordered') }}</span><span class="px-3 py-3 text-right">{{ __('production_bench.receipt.previously_received') }}</span><span class="px-3 py-3 text-right">{{ __('production_bench.receipt.remaining') }}</span><span class="px-3 py-3">{{ __('production_bench.receipt.receipt_values') }}</span>
                                </div>
                                @foreach ($selectedOrder->lines as $line)
                                    @php($posted = (int) $line->receiptLines->filter(fn ($receiptLine) => $receiptLine->goodsReceipt?->status->value === 'posted')->sum('packs_received'))
                                    @php($remaining = $line->ordered_packs - $posted)
                                    <article wire:key="order-receipt-line-{{ $line->id }}" class="grid gap-3 rounded-xl bg-[var(--color-field-muted)] p-4 text-sm lg:grid-cols-[2rem_minmax(12rem,1.4fr)_6rem_7rem_6rem_minmax(29rem,2fr)] lg:items-start lg:gap-0 lg:p-0">
                                        <label class="flex min-h-12 items-center justify-center px-3 py-3"><span class="sr-only">{{ __('production_bench.receipt.select_item', ['item' => $line->listing_name]) }}</span><input wire:model.live="selected.{{ $line->id }}" type="checkbox" @disabled($remaining < 1) class="size-5 rounded border-[var(--color-line-strong)] text-[var(--color-accent)] focus:ring-[var(--color-accent)]"></label>
                                        <div class="px-3 py-1 lg:py-3"><p class="font-medium text-[var(--color-ink-strong)]">{{ $line->ingredient?->localizedDisplayName() ?? $line->packagingItem?->name }}</p><p class="mt-1 text-xs text-[var(--color-ink-soft)]">{{ $line->listing_name }}</p></div>
                                        <div data-receipt-mobile-context class="grid grid-cols-3 gap-3 px-3 lg:contents"><span class="numeric lg:px-3 lg:py-3 lg:text-right"><span class="mb-1 block text-xs text-[var(--color-ink-soft)] lg:hidden">{{ __('production_bench.receipt.ordered') }}</span>{{ $line->ordered_packs }}</span><span class="numeric lg:px-3 lg:py-3 lg:text-right"><span class="mb-1 block text-xs text-[var(--color-ink-soft)] lg:hidden">{{ __('production_bench.receipt.previously_received') }}</span>{{ $posted }}</span><span class="numeric lg:px-3 lg:py-3 lg:text-right"><span class="mb-1 block text-xs text-[var(--color-ink-soft)] lg:hidden">{{ __('production_bench.receipt.remaining') }}</span>{{ $remaining }}</span></div>
                                        <div class="grid gap-3 px-3 py-3 sm:grid-cols-2 lg:grid-cols-6 lg:gap-2">
                                            <label class="text-xs">{{ __('production_bench.receipt.formats') }}<input wire:model="lineInputs.{{ $line->id }}.packs_received" type="number" min="1" max="{{ $remaining }}" aria-invalid="{{ $errors->has("lineInputs.{$line->id}.packs_received") ? 'true' : 'false' }}" @if($errors->has("lineInputs.{$line->id}.packs_received")) aria-describedby="line-{{ $line->id }}-packs-error" @endif class="sk-input mt-1 w-full py-2 numeric">@error("lineInputs.{$line->id}.packs_received")<span id="line-{{ $line->id }}-packs-error" class="mt-1 block text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror</label>
                                            <label class="text-xs sm:col-span-2">{{ __('production_bench.receipt.actual_quantity') }}<span class="mt-1 flex gap-1"><input wire:model="lineInputs.{{ $line->id }}.actual_quantity" inputmode="decimal" aria-invalid="{{ $errors->has("lineInputs.{$line->id}.actual_quantity") ? 'true' : 'false' }}" @if($errors->has("lineInputs.{$line->id}.actual_quantity")) aria-describedby="line-{{ $line->id }}-actual-quantity-error" @endif class="sk-input min-w-0 flex-1 py-2 numeric"><select wire:model="lineInputs.{{ $line->id }}.actual_unit" aria-label="{{ __('production_bench.receipt.actual_quantity') }} ({{ __('production_bench.procurement.unit') }})" aria-invalid="{{ $errors->has("lineInputs.{$line->id}.actual_unit") ? 'true' : 'false' }}" @if($errors->has("lineInputs.{$line->id}.actual_unit")) aria-describedby="line-{{ $line->id }}-actual-unit-error" @endif class="sk-input w-20 py-2">@if($line->unit_kind->value === 'count')<option value="count">{{ __('production_bench.receipt.count') }}</option>@else<option value="g">g</option><option value="kg">kg</option><option value="oz">oz</option><option value="lb">lb</option>@endif</select></span>@error("lineInputs.{$line->id}.actual_quantity")<span id="line-{{ $line->id }}-actual-quantity-error" class="mt-1 block text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror @error("lineInputs.{$line->id}.actual_unit")<span id="line-{{ $line->id }}-actual-unit-error" class="mt-1 block text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror</label>
                                            <label class="text-xs">{{ __('production_bench.receipt.price_basis') }}<select wire:model="lineInputs.{{ $line->id }}.receipt_price_basis" aria-invalid="{{ $errors->has("lineInputs.{$line->id}.receipt_price_basis") ? 'true' : 'false' }}" @if($errors->has("lineInputs.{$line->id}.receipt_price_basis")) aria-describedby="line-{{ $line->id }}-price-basis-error" @endif class="sk-input mt-1 w-full py-2"><option value="total_purchase_format">{{ __('production_bench.receipt.total_format') }}</option><option value="per_unit">{{ __('production_bench.receipt.per_unit') }}</option></select>@error("lineInputs.{$line->id}.receipt_price_basis")<span id="line-{{ $line->id }}-price-basis-error" class="mt-1 block text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror</label>
                                            <label class="text-xs">{{ __('production_bench.receipt.price') }}<span class="mt-1 flex gap-1"><input wire:model="lineInputs.{{ $line->id }}.receipt_price_amount" inputmode="decimal" aria-invalid="{{ $errors->has("lineInputs.{$line->id}.receipt_price_amount") ? 'true' : 'false' }}" @if($errors->has("lineInputs.{$line->id}.receipt_price_amount")) aria-describedby="line-{{ $line->id }}-price-error" @endif class="sk-input min-w-0 flex-1 py-2 numeric"><input wire:model="lineInputs.{{ $line->id }}.currency" maxlength="3" readonly data-receipt-currency-locked aria-label="{{ __('production_bench.common.currency') }}" aria-invalid="{{ $errors->has("lineInputs.{$line->id}.currency") ? 'true' : 'false' }}" aria-describedby="receipt-order-currency-help{{ $errors->has("lineInputs.{$line->id}.currency") ? ' line-'.$line->id.'-currency-error' : '' }}" class="sk-input w-16 py-2 uppercase"></span>@error("lineInputs.{$line->id}.receipt_price_amount")<span id="line-{{ $line->id }}-price-error" class="mt-1 block text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror @error("lineInputs.{$line->id}.currency")<span id="line-{{ $line->id }}-currency-error" class="mt-1 block text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror</label>
                                            <label class="text-xs">{{ __('production_bench.receipt.price_unit') }}<input wire:model="lineInputs.{{ $line->id }}.receipt_price_unit" aria-invalid="{{ $errors->has("lineInputs.{$line->id}.receipt_price_unit") ? 'true' : 'false' }}" @if($errors->has("lineInputs.{$line->id}.receipt_price_unit")) aria-describedby="line-{{ $line->id }}-price-unit-error" @endif class="sk-input mt-1 w-full py-2">@error("lineInputs.{$line->id}.receipt_price_unit")<span id="line-{{ $line->id }}-price-unit-error" class="mt-1 block text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror</label>
                                            <label class="text-xs sm:col-span-2">{{ __('production_bench.receipt.supplier_batch') }}<input wire:model="lineInputs.{{ $line->id }}.supplier_batch_number" aria-invalid="{{ $errors->has("lineInputs.{$line->id}.supplier_batch_number") ? 'true' : 'false' }}" @if($errors->has("lineInputs.{$line->id}.supplier_batch_number")) aria-describedby="line-{{ $line->id }}-batch-error" @endif class="sk-input mt-1 w-full py-2">@error("lineInputs.{$line->id}.supplier_batch_number")<span id="line-{{ $line->id }}-batch-error" class="mt-1 block text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror</label>
                                            <label class="text-xs sm:col-span-2">{{ __('production_bench.receipt.expiry') }}<input wire:model="lineInputs.{{ $line->id }}.expires_at" type="date" aria-invalid="{{ $errors->has("lineInputs.{$line->id}.expires_at") ? 'true' : 'false' }}" @if($errors->has("lineInputs.{$line->id}.expires_at")) aria-describedby="line-{{ $line->id }}-expiry-error" @endif class="sk-input mt-1 w-full py-2">@error("lineInputs.{$line->id}.expires_at")<span id="line-{{ $line->id }}-expiry-error" class="mt-1 block text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror</label>
                                            <label class="text-xs sm:col-span-2">{{ __('production_bench.common.notes') }}<input wire:model="lineInputs.{{ $line->id }}.notes" aria-invalid="{{ $errors->has("lineInputs.{$line->id}.notes") ? 'true' : 'false' }}" @if($errors->has("lineInputs.{$line->id}.notes")) aria-describedby="line-{{ $line->id }}-notes-error" @endif class="sk-input mt-1 w-full py-2">@error("lineInputs.{$line->id}.notes")<span id="line-{{ $line->id }}-notes-error" class="mt-1 block text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror</label>
                                        </div>
                                    </article>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </section>
            @else
                <section aria-labelledby="receipt-direct-lines-heading" class="space-y-4">
                    <h2 id="receipt-direct-lines-heading" class="sr-only">{{ __('production_bench.receipt.direct') }}</h2>
                    <label class="block text-sm"><span class="font-medium">{{ __('production_bench.supplier.singular') }}</span><select wire:model.live="supplierId" required aria-invalid="{{ $errors->has('supplierId') ? 'true' : 'false' }}" @if($errors->has('supplierId')) aria-describedby="receipt-supplier-error" @endif class="sk-input mt-1 w-full"><option value="">{{ __('production_bench.receipt.choose_supplier') }}</option>@foreach($suppliers as $supplier)<option value="{{ $supplier->id }}">{{ $supplier->name }}</option>@endforeach</select>@error('supplierId') <span id="receipt-supplier-error" class="mt-1 block text-xs text-[var(--color-danger-strong)]">{{ $message }}</span> @enderror</label>
                    @if ($supplierId)
                        <label class="block text-sm">
                            <span class="font-medium">{{ __('production_bench.common.search') }}</span>
                            <input wire:model.live.debounce.300ms="listingSearch" type="search" autocomplete="off" class="sk-input mt-1 w-full" aria-label="{{ __('production_bench.common.search') }}: {{ __('production_bench.listing.singular') }}">
                        </label>
                        <p id="receipt-direct-currency-help" class="text-xs text-[var(--color-ink-soft)]">{{ __('production_bench.receipt.currency_locked_help') }}</p>
                        <div class="space-y-2">
                            @forelse ($listings as $listing)
                                <article wire:key="direct-receipt-line-{{ $listing->id }}" class="rounded-xl bg-[var(--color-field-muted)] p-4">
                                    <div class="flex items-start gap-3">
                                        <label class="flex min-h-11 items-center"><span class="sr-only">{{ __('production_bench.receipt.select_item', ['item' => $listing->purchase_format]) }}</span><input wire:model.live="selected.{{ $listing->id }}" type="checkbox" class="size-5 rounded border-[var(--color-line-strong)] text-[var(--color-accent)] focus:ring-[var(--color-accent)]"></label>
                                        <div><p class="font-medium text-[var(--color-ink-strong)]">{{ $listing->ingredient?->localizedDisplayName() ?? $listing->packagingItem?->name }}</p><p class="mt-1 text-xs text-[var(--color-ink-soft)]">{{ $listing->purchase_format }}</p></div>
                                    </div>
                                    <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                        <label class="text-xs">{{ __('production_bench.receipt.formats') }}<input wire:model="lineInputs.{{ $listing->id }}.packs_received" type="number" min="1" aria-invalid="{{ $errors->has("lineInputs.{$listing->id}.packs_received") ? 'true' : 'false' }}" @if($errors->has("lineInputs.{$listing->id}.packs_received")) aria-describedby="line-{{ $listing->id }}-packs-error" @endif class="sk-input mt-1 w-full numeric">@error("lineInputs.{$listing->id}.packs_received")<span id="line-{{ $listing->id }}-packs-error" class="mt-1 block text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror</label>
                                        <label class="text-xs">{{ __('production_bench.receipt.actual_quantity') }}<span class="mt-1 flex gap-1"><input wire:model="lineInputs.{{ $listing->id }}.actual_quantity" inputmode="decimal" aria-invalid="{{ $errors->has("lineInputs.{$listing->id}.actual_quantity") ? 'true' : 'false' }}" @if($errors->has("lineInputs.{$listing->id}.actual_quantity")) aria-describedby="line-{{ $listing->id }}-actual-quantity-error" @endif class="sk-input min-w-0 flex-1 numeric"><select wire:model="lineInputs.{{ $listing->id }}.actual_unit" aria-label="{{ __('production_bench.receipt.actual_quantity') }} ({{ __('production_bench.procurement.unit') }})" aria-invalid="{{ $errors->has("lineInputs.{$listing->id}.actual_unit") ? 'true' : 'false' }}" @if($errors->has("lineInputs.{$listing->id}.actual_unit")) aria-describedby="line-{{ $listing->id }}-actual-unit-error" @endif class="sk-input w-20">@if($listing->unit_kind->value === 'count')<option value="count">{{ __('production_bench.receipt.count') }}</option>@else<option value="g">g</option><option value="kg">kg</option><option value="oz">oz</option><option value="lb">lb</option>@endif</select></span>@error("lineInputs.{$listing->id}.actual_quantity")<span id="line-{{ $listing->id }}-actual-quantity-error" class="mt-1 block text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror @error("lineInputs.{$listing->id}.actual_unit")<span id="line-{{ $listing->id }}-actual-unit-error" class="mt-1 block text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror</label>
                                        <label class="text-xs">{{ __('production_bench.receipt.price_basis') }}<select wire:model="lineInputs.{{ $listing->id }}.receipt_price_basis" aria-invalid="{{ $errors->has("lineInputs.{$listing->id}.receipt_price_basis") ? 'true' : 'false' }}" @if($errors->has("lineInputs.{$listing->id}.receipt_price_basis")) aria-describedby="line-{{ $listing->id }}-price-basis-error" @endif class="sk-input mt-1 w-full"><option value="total_purchase_format">{{ __('production_bench.receipt.total_format') }}</option><option value="per_unit">{{ __('production_bench.receipt.per_unit') }}</option></select>@error("lineInputs.{$listing->id}.receipt_price_basis")<span id="line-{{ $listing->id }}-price-basis-error" class="mt-1 block text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror</label>
                                        <label class="text-xs">{{ __('production_bench.receipt.price') }}<span class="mt-1 flex gap-1"><input wire:model="lineInputs.{{ $listing->id }}.receipt_price_amount" inputmode="decimal" aria-invalid="{{ $errors->has("lineInputs.{$listing->id}.receipt_price_amount") ? 'true' : 'false' }}" @if($errors->has("lineInputs.{$listing->id}.receipt_price_amount")) aria-describedby="line-{{ $listing->id }}-price-error" @endif class="sk-input min-w-0 flex-1 numeric"><input wire:model="lineInputs.{{ $listing->id }}.currency" maxlength="3" readonly data-receipt-currency-locked aria-label="{{ __('production_bench.common.currency') }}" aria-invalid="{{ $errors->has("lineInputs.{$listing->id}.currency") ? 'true' : 'false' }}" aria-describedby="receipt-direct-currency-help{{ $errors->has("lineInputs.{$listing->id}.currency") ? ' line-'.$listing->id.'-currency-error' : '' }}" class="sk-input w-16 uppercase"></span>@error("lineInputs.{$listing->id}.receipt_price_amount")<span id="line-{{ $listing->id }}-price-error" class="mt-1 block text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror @error("lineInputs.{$listing->id}.currency")<span id="line-{{ $listing->id }}-currency-error" class="mt-1 block text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror</label>
                                        <label class="text-xs">{{ __('production_bench.receipt.price_unit') }}<input wire:model="lineInputs.{{ $listing->id }}.receipt_price_unit" aria-invalid="{{ $errors->has("lineInputs.{$listing->id}.receipt_price_unit") ? 'true' : 'false' }}" @if($errors->has("lineInputs.{$listing->id}.receipt_price_unit")) aria-describedby="line-{{ $listing->id }}-price-unit-error" @endif class="sk-input mt-1 w-full">@error("lineInputs.{$listing->id}.receipt_price_unit")<span id="line-{{ $listing->id }}-price-unit-error" class="mt-1 block text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror</label>
                                        <label class="text-xs">{{ __('production_bench.receipt.supplier_batch') }}<input wire:model="lineInputs.{{ $listing->id }}.supplier_batch_number" aria-invalid="{{ $errors->has("lineInputs.{$listing->id}.supplier_batch_number") ? 'true' : 'false' }}" @if($errors->has("lineInputs.{$listing->id}.supplier_batch_number")) aria-describedby="line-{{ $listing->id }}-batch-error" @endif class="sk-input mt-1 w-full">@error("lineInputs.{$listing->id}.supplier_batch_number")<span id="line-{{ $listing->id }}-batch-error" class="mt-1 block text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror</label>
                                        <label class="text-xs">{{ __('production_bench.receipt.expiry') }}<input wire:model="lineInputs.{{ $listing->id }}.expires_at" type="date" aria-invalid="{{ $errors->has("lineInputs.{$listing->id}.expires_at") ? 'true' : 'false' }}" @if($errors->has("lineInputs.{$listing->id}.expires_at")) aria-describedby="line-{{ $listing->id }}-expiry-error" @endif class="sk-input mt-1 w-full">@error("lineInputs.{$listing->id}.expires_at")<span id="line-{{ $listing->id }}-expiry-error" class="mt-1 block text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror</label>
                                        <label class="text-xs">{{ __('production_bench.common.notes') }}<input wire:model="lineInputs.{{ $listing->id }}.notes" aria-invalid="{{ $errors->has("lineInputs.{$listing->id}.notes") ? 'true' : 'false' }}" @if($errors->has("lineInputs.{$listing->id}.notes")) aria-describedby="line-{{ $listing->id }}-notes-error" @endif class="sk-input mt-1 w-full">@error("lineInputs.{$listing->id}.notes")<span id="line-{{ $listing->id }}-notes-error" class="mt-1 block text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror</label>
                                    </div>
                                </article>
                            @empty
                                <p role="status" class="rounded-xl bg-[var(--color-panel-muted)] px-4 py-6 text-center text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.receipt.no_listings') }}</p>
                            @endforelse
                        </div>
                    @endif
                </section>
            @endif

            @if ($errors->any())
                <div role="alert" aria-live="assertive" tabindex="-1" data-receipt-error-summary class="rounded-xl bg-[var(--color-danger-soft)] px-4 py-3 text-sm text-[var(--color-danger-strong)]">
                    <p class="font-medium">{{ __('production_bench.receipt.fix_errors') }}</p>
                    <ul class="mt-1 list-disc space-y-1 pl-5">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
            <div class="pb-24">
                <x-workflow-action-bar data-receipt-action-bar>
                    <a href="{{ route('production-bench.purchasing.receipts') }}" wire:navigate class="sk-btn sk-btn-ghost">{{ __('production_bench.common.cancel') }}</a>
                    <span wire:loading.delay wire:target="post" role="status" aria-live="polite" class="text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.receipt.post') }}…</span>
                    <button type="submit" wire:confirm="{{ __('production_bench.receipt.post_confirm') }}" wire:loading.attr="disabled" wire:target="post" class="sk-btn sk-btn-primary min-h-11">{{ __('production_bench.receipt.post') }}</button>
                </x-workflow-action-bar>
            </div>
        </form>
    @endif
</x-production-bench.page>
