<x-production-bench.page active="purchasing" subnavigation="receipts">
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
                            @foreach ($selectedOrder->lines as $line)
                                @php($posted = (int) $line->receiptLines->filter(fn ($receiptLine) => $receiptLine->goodsReceipt?->status->value === 'posted')->sum('packs_received'))
                                @php($remaining = $line->ordered_packs - $posted)
                                <article wire:key="order-receipt-line-{{ $line->id }}" data-receipt-mobile-context data-receipt-line-card="{{ $line->id }}" aria-disabled="{{ $remaining < 1 ? 'true' : 'false' }}" @class(['sk-card-elevation-subtle rounded-xl border border-[var(--color-line)] p-4 text-sm', 'bg-[var(--color-panel)]' => $remaining > 0, 'bg-[var(--color-panel-strong)]' => $remaining < 1])>
                                    <fieldset data-receipt-line-fields="{{ $line->id }}" @disabled($remaining < 1)>
                                        <legend class="sr-only">{{ $line->ingredient?->localizedDisplayName() ?? $line->packagingItem?->name }}</legend>
                                        <div data-receipt-line-grid="{{ $line->id }}">
                                            <div data-receipt-line-summary="{{ $line->id }}" class="grid gap-x-4 gap-y-3 pb-4 sm:grid-cols-2 xl:grid-cols-[minmax(12rem,.9fr)_repeat(4,minmax(0,1fr))] xl:items-end">
                                                <div class="flex min-w-0 items-start gap-3 xl:self-end">
                                                    <label class="flex min-h-11 shrink-0 items-center"><span class="sr-only">{{ __('production_bench.receipt.select_item', ['item' => $line->listing_name]) }}</span><input wire:model.live="selected.{{ $line->id }}" data-receipt-line-checkbox="{{ $line->id }}" type="checkbox" @disabled($remaining < 1) class="size-5 rounded border-[var(--color-line-strong)] accent-[var(--color-accent)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)] disabled:cursor-not-allowed disabled:opacity-40"></label>
                                                    <div class="min-w-0 pt-1">
                                                        <div class="flex flex-wrap items-center gap-2">
                                                            <p class="font-medium text-[var(--color-ink-strong)]">{{ $line->ingredient?->localizedDisplayName() ?? $line->packagingItem?->name }}</p>
                                                            @if($remaining < 1)<span class="rounded-full bg-[var(--color-panel-strong)] px-2 py-0.5 text-xs font-medium text-[var(--color-ink-soft)]">{{ __('production_bench.receipt.fully_received') }}</span>@endif
                                                        </div>
                                                        <p class="mt-1 text-xs text-[var(--color-ink-soft)]">{{ $line->listing_name }}</p>
                                                    </div>
                                                </div>
                                                <dl class="contents">
                                                    <div><dt class="sk-eyebrow">{{ __('production_bench.receipt.ordered') }}</dt><dd class="numeric mt-1 text-base text-[var(--color-ink-strong)]">{{ $line->ordered_packs }}</dd></div>
                                                    <div><dt class="sk-eyebrow">{{ __('production_bench.receipt.previously_received') }}</dt><dd class="numeric mt-1 text-base text-[var(--color-ink-strong)]">{{ $posted }}</dd></div>
                                                    <div><dt class="sk-eyebrow">{{ __('production_bench.receipt.remaining') }}</dt><dd class="numeric mt-1 text-base text-[var(--color-ink-strong)]">{{ $remaining }}</dd></div>
                                                </dl>
                                                <label class="flex min-w-0 flex-col gap-2 text-xs">
                                                    {{ __('production_bench.receipt.formats') }}
                                                    @if($remaining > 0)
                                                        <input wire:model="lineInputs.{{ $line->id }}.packs_received" type="number" min="1" max="{{ $remaining }}" aria-invalid="{{ $errors->has("lineInputs.{$line->id}.packs_received") ? 'true' : 'false' }}" @if($errors->has("lineInputs.{$line->id}.packs_received")) aria-describedby="line-{{ $line->id }}-packs-error" @endif class="sk-input w-full py-2 numeric">
                                                    @else
                                                        <input type="number" value="0" disabled class="sk-input w-full cursor-not-allowed bg-[var(--color-panel-strong)] py-2 numeric text-[var(--color-ink-muted)] opacity-60">
                                                    @endif
                                                    <span class="text-[10px] leading-4 text-[var(--color-ink-muted)]">{{ __('production_bench.receipt.formats_help') }}</span>
                                                    @error("lineInputs.{$line->id}.packs_received")<span id="line-{{ $line->id }}-packs-error" class="mt-1 block text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror
                                                </label>
                                            </div>

                                            <div data-receipt-line-details="{{ $line->id }}" @class(['grid gap-x-5 gap-y-4 border-t border-[var(--color-line)] py-5 sm:grid-cols-2 xl:grid-cols-4', 'opacity-50' => $remaining < 1])>
                                                <label class="flex min-w-0 flex-col gap-2 text-xs">{{ __('production_bench.receipt.actual_quantity') }}<span class="flex gap-1"><input wire:model="lineInputs.{{ $line->id }}.actual_quantity" inputmode="decimal" aria-invalid="{{ $errors->has("lineInputs.{$line->id}.actual_quantity") ? 'true' : 'false' }}" @if($errors->has("lineInputs.{$line->id}.actual_quantity")) aria-describedby="line-{{ $line->id }}-actual-quantity-error" @endif class="sk-input min-w-0 flex-1 py-2 numeric"><select wire:model="lineInputs.{{ $line->id }}.actual_unit" data-receipt-fixed-unit="{{ $line->id }}" aria-label="{{ __('production_bench.receipt.actual_quantity') }} ({{ __('production_bench.procurement.unit') }})" aria-disabled="true" disabled class="sk-input w-20 shrink-0 cursor-not-allowed bg-[var(--color-panel-strong)] py-2 text-[var(--color-ink-muted)] opacity-70">@if($line->unit_kind->value === 'count')<option value="count">{{ __('production_bench.receipt.count') }}</option>@else<option value="g">g</option><option value="kg">kg</option><option value="oz">oz</option><option value="lb">lb</option>@endif</select></span><span class="text-[10px] leading-4 text-[var(--color-ink-muted)]">{{ __('production_bench.receipt.actual_quantity_help') }}</span>@error("lineInputs.{$line->id}.actual_quantity")<span id="line-{{ $line->id }}-actual-quantity-error" class="mt-1 block text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror @error("lineInputs.{$line->id}.actual_unit")<span id="line-{{ $line->id }}-actual-unit-error" class="mt-1 block text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror</label>
                                                <label class="flex min-w-0 flex-col gap-2 text-xs">{{ __('production_bench.receipt.price_basis') }}<select wire:model.live="lineInputs.{{ $line->id }}.receipt_price_basis" aria-invalid="{{ $errors->has("lineInputs.{$line->id}.receipt_price_basis") ? 'true' : 'false' }}" @if($errors->has("lineInputs.{$line->id}.receipt_price_basis")) aria-describedby="line-{{ $line->id }}-price-basis-error" @endif class="sk-input w-full py-2"><option value="total_purchase_format">{{ __('production_bench.receipt.total_format') }}</option><option value="per_unit">{{ __('production_bench.receipt.per_unit') }}</option></select>@error("lineInputs.{$line->id}.receipt_price_basis")<span id="line-{{ $line->id }}-price-basis-error" class="mt-1 block text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror</label>
                                                <label class="flex min-w-0 flex-col gap-2 text-xs">{{ __('production_bench.receipt.price') }}<span class="flex gap-1"><input wire:model="lineInputs.{{ $line->id }}.receipt_price_amount" inputmode="decimal" aria-invalid="{{ $errors->has("lineInputs.{$line->id}.receipt_price_amount") ? 'true' : 'false' }}" @if($errors->has("lineInputs.{$line->id}.receipt_price_amount")) aria-describedby="line-{{ $line->id }}-price-error" @endif class="sk-input min-w-0 flex-1 py-2 numeric"><input wire:model="lineInputs.{{ $line->id }}.currency" maxlength="3" readonly data-receipt-currency-locked aria-label="{{ __('production_bench.common.currency') }}" aria-invalid="{{ $errors->has("lineInputs.{$line->id}.currency") ? 'true' : 'false' }}" aria-describedby="receipt-order-currency-help{{ $errors->has("lineInputs.{$line->id}.currency") ? ' line-'.$line->id.'-currency-error' : '' }}" class="sk-input w-16 shrink-0 py-2 uppercase"></span><span class="text-[10px] leading-4 text-[var(--color-ink-muted)]">{{ __('production_bench.receipt.inventory_cost_after_posting', ['currency' => $workspaceCurrency]) }}</span>@error("lineInputs.{$line->id}.receipt_price_amount")<span id="line-{{ $line->id }}-price-error" class="mt-1 block text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror @error("lineInputs.{$line->id}.currency")<span id="line-{{ $line->id }}-currency-error" class="mt-1 block text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror</label>
                                                <label class="flex min-w-0 flex-col gap-2 text-xs">{{ __('production_bench.receipt.price_unit') }}<input wire:model="lineInputs.{{ $line->id }}.receipt_price_unit" data-receipt-price-unit-locked="{{ $line->id }}" aria-invalid="{{ $errors->has("lineInputs.{$line->id}.receipt_price_unit") ? 'true' : 'false' }}" @if($errors->has("lineInputs.{$line->id}.receipt_price_unit")) aria-describedby="line-{{ $line->id }}-price-unit-error" @endif class="sk-input w-full cursor-not-allowed bg-[var(--color-panel-strong)] py-2 text-[var(--color-ink-muted)]" readonly><span class="text-[10px] leading-4 text-[var(--color-ink-muted)]">{{ __('production_bench.receipt.unit_locked_help') }}</span>@error("lineInputs.{$line->id}.receipt_price_unit")<span id="line-{{ $line->id }}-price-unit-error" class="mt-1 block text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror</label>
                                                @if (strtoupper((string) $line->currency) !== strtoupper((string) $workspaceCurrency))
                                                    <label class="flex min-w-0 flex-col gap-2 text-xs">{{ __('production_bench.receipt.manual_exchange_rate') }}<input wire:model="lineInputs.{{ $line->id }}.manual_exchange_rate" inputmode="decimal" data-receipt-manual-rate="{{ $line->id }}" aria-invalid="{{ $errors->has("lineInputs.{$line->id}.manual_exchange_rate") ? 'true' : 'false' }}" class="sk-input w-full py-2 numeric"><span class="text-[10px] leading-4 text-[var(--color-ink-muted)]">{{ __('production_bench.receipt.manual_exchange_rate_help') }}</span>@error("lineInputs.{$line->id}.manual_exchange_rate")<span class="mt-1 block text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror</label>
                                                @endif
                                            </div>

                                            <div data-receipt-line-metadata="{{ $line->id }}" @class(['grid gap-x-5 gap-y-4 border-t border-[var(--color-line)] pt-5 sm:grid-cols-2 xl:grid-cols-4', 'opacity-50' => $remaining < 1])>
                                                <label class="flex min-w-0 flex-col gap-2 text-xs">{{ __('production_bench.receipt.supplier_batch') }}<input wire:model="lineInputs.{{ $line->id }}.supplier_batch_number" aria-invalid="{{ $errors->has("lineInputs.{$line->id}.supplier_batch_number") ? 'true' : 'false' }}" @if($errors->has("lineInputs.{$line->id}.supplier_batch_number")) aria-describedby="line-{{ $line->id }}-batch-error" @endif class="sk-input w-full py-2">@error("lineInputs.{$line->id}.supplier_batch_number")<span id="line-{{ $line->id }}-batch-error" class="mt-1 block text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror</label>
                                                <label class="flex min-w-0 flex-col gap-2 text-xs">{{ __('production_bench.receipt.expiry') }}<input wire:model="lineInputs.{{ $line->id }}.expires_at" type="date" aria-invalid="{{ $errors->has("lineInputs.{$line->id}.expires_at") ? 'true' : 'false' }}" @if($errors->has("lineInputs.{$line->id}.expires_at")) aria-describedby="line-{{ $line->id }}-expiry-error" @endif class="sk-input w-full py-2">@error("lineInputs.{$line->id}.expires_at")<span id="line-{{ $line->id }}-expiry-error" class="mt-1 block text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror</label>
                                                <label class="flex min-w-0 flex-col gap-2 text-xs xl:col-span-2">{{ __('production_bench.common.notes') }}<input wire:model="lineInputs.{{ $line->id }}.notes" aria-invalid="{{ $errors->has("lineInputs.{$line->id}.notes") ? 'true' : 'false' }}" @if($errors->has("lineInputs.{$line->id}.notes")) aria-describedby="line-{{ $line->id }}-notes-error" @endif class="sk-input w-full py-2">@error("lineInputs.{$line->id}.notes")<span id="line-{{ $line->id }}-notes-error" class="mt-1 block text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror</label>
                                            </div>
                                        </div>
                                    </fieldset>
                                </article>
                            @endforeach
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
                                        <label class="flex min-h-11 items-center"><span class="sr-only">{{ __('production_bench.receipt.select_item', ['item' => $listing->purchase_format]) }}</span><input wire:model.live="selected.{{ $listing->id }}" type="checkbox" class="size-5 rounded border-[var(--color-line-strong)] accent-[var(--color-accent)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)]"></label>
                                        <div><p class="font-medium text-[var(--color-ink-strong)]">{{ $listing->ingredient?->localizedDisplayName() ?? $listing->packagingItem?->name }}</p><p class="mt-1 text-xs text-[var(--color-ink-soft)]">{{ $listing->purchase_format }}</p></div>
                                    </div>
                                    <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                        <label class="flex min-w-0 flex-col gap-2 text-xs">{{ __('production_bench.receipt.formats') }}<input wire:model="lineInputs.{{ $listing->id }}.packs_received" type="number" min="1" aria-invalid="{{ $errors->has("lineInputs.{$listing->id}.packs_received") ? 'true' : 'false' }}" @if($errors->has("lineInputs.{$listing->id}.packs_received")) aria-describedby="line-{{ $listing->id }}-packs-error" @endif class="sk-input w-full py-2 numeric"><span class="text-[10px] leading-4 text-[var(--color-ink-muted)]">{{ __('production_bench.receipt.formats_help') }}</span>@error("lineInputs.{$listing->id}.packs_received")<span id="line-{{ $listing->id }}-packs-error" class="mt-1 block text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror</label>
                                        <label class="flex min-w-0 flex-col gap-2 text-xs">{{ __('production_bench.receipt.actual_quantity') }}<span class="flex gap-1"><input wire:model="lineInputs.{{ $listing->id }}.actual_quantity" inputmode="decimal" aria-invalid="{{ $errors->has("lineInputs.{$listing->id}.actual_quantity") ? 'true' : 'false' }}" @if($errors->has("lineInputs.{$listing->id}.actual_quantity")) aria-describedby="line-{{ $listing->id }}-actual-quantity-error" @endif class="sk-input min-w-0 flex-1 numeric"><select wire:model="lineInputs.{{ $listing->id }}.actual_unit" data-receipt-fixed-unit="{{ $listing->id }}" aria-label="{{ __('production_bench.receipt.actual_quantity') }} ({{ __('production_bench.procurement.unit') }})" aria-disabled="true" disabled class="sk-input w-20 cursor-not-allowed bg-[var(--color-panel-strong)] py-2 text-[var(--color-ink-muted)] opacity-70">@if($listing->unit_kind->value === 'count')<option value="count">{{ __('production_bench.receipt.count') }}</option>@else<option value="g">g</option><option value="kg">kg</option><option value="oz">oz</option><option value="lb">lb</option>@endif</select></span><span class="text-[10px] leading-4 text-[var(--color-ink-muted)]">{{ __('production_bench.receipt.actual_quantity_help') }}</span>@error("lineInputs.{$listing->id}.actual_quantity")<span id="line-{{ $listing->id }}-actual-quantity-error" class="mt-1 block text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror @error("lineInputs.{$listing->id}.actual_unit")<span id="line-{{ $listing->id }}-actual-unit-error" class="mt-1 block text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror</label>
                                        <label class="text-xs">{{ __('production_bench.receipt.price_basis') }}<select wire:model.live="lineInputs.{{ $listing->id }}.receipt_price_basis" aria-invalid="{{ $errors->has("lineInputs.{$listing->id}.receipt_price_basis") ? 'true' : 'false' }}" @if($errors->has("lineInputs.{$listing->id}.receipt_price_basis")) aria-describedby="line-{{ $listing->id }}-price-basis-error" @endif class="sk-input mt-1 w-full"><option value="total_purchase_format">{{ __('production_bench.receipt.total_format') }}</option><option value="per_unit">{{ __('production_bench.receipt.per_unit') }}</option></select>@error("lineInputs.{$listing->id}.receipt_price_basis")<span id="line-{{ $listing->id }}-price-basis-error" class="mt-1 block text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror</label>
                                        <label class="text-xs">{{ __('production_bench.receipt.price') }}<span class="mt-1 flex gap-1"><input wire:model="lineInputs.{{ $listing->id }}.receipt_price_amount" inputmode="decimal" aria-invalid="{{ $errors->has("lineInputs.{$listing->id}.receipt_price_amount") ? 'true' : 'false' }}" @if($errors->has("lineInputs.{$listing->id}.receipt_price_amount")) aria-describedby="line-{{ $listing->id }}-price-error" @endif class="sk-input min-w-0 flex-1 numeric"><input wire:model="lineInputs.{{ $listing->id }}.currency" maxlength="3" readonly data-receipt-currency-locked aria-label="{{ __('production_bench.common.currency') }}" aria-invalid="{{ $errors->has("lineInputs.{$listing->id}.currency") ? 'true' : 'false' }}" aria-describedby="receipt-direct-currency-help{{ $errors->has("lineInputs.{$listing->id}.currency") ? ' line-'.$listing->id.'-currency-error' : '' }}" class="sk-input w-16 uppercase"></span>@error("lineInputs.{$listing->id}.receipt_price_amount")<span id="line-{{ $listing->id }}-price-error" class="mt-1 block text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror @error("lineInputs.{$listing->id}.currency")<span id="line-{{ $listing->id }}-currency-error" class="mt-1 block text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror</label>
                                        <label class="flex min-w-0 flex-col gap-2 text-xs">{{ __('production_bench.receipt.price_unit') }}<input wire:model="lineInputs.{{ $listing->id }}.receipt_price_unit" data-receipt-price-unit-locked="{{ $listing->id }}" aria-invalid="{{ $errors->has("lineInputs.{$listing->id}.receipt_price_unit") ? 'true' : 'false' }}" @if($errors->has("lineInputs.{$listing->id}.receipt_price_unit")) aria-describedby="line-{{ $listing->id }}-price-unit-error" @endif class="sk-input w-full cursor-not-allowed bg-[var(--color-panel-strong)] py-2 text-[var(--color-ink-muted)]" readonly><span class="text-[10px] leading-4 text-[var(--color-ink-muted)]">{{ __('production_bench.receipt.unit_locked_help') }}</span>@error("lineInputs.{$listing->id}.receipt_price_unit")<span id="line-{{ $listing->id }}-price-unit-error" class="mt-1 block text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror</label>
                                        @if (strtoupper((string) $listing->currency) !== strtoupper((string) $workspaceCurrency))
                                            <label class="flex min-w-0 flex-col gap-2 text-xs">{{ __('production_bench.receipt.manual_exchange_rate') }}<input wire:model="lineInputs.{{ $listing->id }}.manual_exchange_rate" inputmode="decimal" data-receipt-manual-rate="{{ $listing->id }}" aria-invalid="{{ $errors->has("lineInputs.{$listing->id}.manual_exchange_rate") ? 'true' : 'false' }}" class="sk-input w-full py-2 numeric"><span class="text-[10px] leading-4 text-[var(--color-ink-muted)]">{{ __('production_bench.receipt.manual_exchange_rate_help') }}</span>@error("lineInputs.{$listing->id}.manual_exchange_rate")<span class="mt-1 block text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror</label>
                                        @endif
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
