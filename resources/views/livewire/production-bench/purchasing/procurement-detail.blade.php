<x-production-bench.page purchasing compact>
    @if ($isReadOnly)
        <p role="status" class="rounded-xl bg-[var(--color-warning-soft)] px-4 py-3 text-sm text-[var(--color-warning-strong)]">{{ __('production_bench.common.read_only') }}</p>
    @endif

    <header class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="sk-eyebrow">{{ $order->supplier->code }} · {{ $order->supplier->name }}</p>
            <h1 class="mt-2 text-3xl font-semibold text-[var(--color-ink-strong)]">{{ $isQuotation ? __('production_bench.procurement.quotation_request') : __('production_bench.procurement.purchase_order') }}</h1>
            <p class="mt-1 text-sm text-[var(--color-ink-soft)]">{{ $isQuotation ? ($order->quotation_reference ?? __('production_bench.procurement.draft')) : $order->reference }}</p>
            @if ($isQuotation)
                <p class="mt-2 max-w-2xl text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.procurement.quotation_detail_help') }}</p>
            @endif
        </div>
        <span class="rounded-full bg-[var(--color-field-muted)] px-3 py-1 text-xs font-medium text-[var(--color-ink-soft)]">{{ $order->status->value }}</span>
    </header>

    <section>
        <div class="overflow-x-auto">
            <div class="min-w-[700px] space-y-2 px-1 py-2 text-left text-sm">
            <div class="grid grid-cols-[minmax(0,1.15fr)_minmax(10rem,1fr)_7rem_minmax(12.5rem,1.15fr)] bg-[var(--color-panel-muted)] text-xs uppercase tracking-wide text-[var(--color-ink-soft)]">
                <div class="px-4 py-2.5">{{ __('production_bench.procurement.item') }}</div>
                <div class="px-4 py-2.5">{{ __('production_bench.procurement.purchase_format') }}</div>
                <div class="px-4 py-2.5 text-right">{{ __('production_bench.procurement.quantity') }}</div>
                <div class="px-4 py-2.5 text-right">{{ __('production_bench.procurement.price') }}</div>
            </div>

            @foreach ($order->lines as $line)
                <article wire:key="line-{{ $line->id }}" data-production-bench-procurement-line class="sk-card-elevation-subtle isolate overflow-hidden rounded-xl bg-[var(--color-field-muted)]">
                    <div class="grid grid-cols-[minmax(0,1.15fr)_minmax(10rem,1fr)_7rem_minmax(12.5rem,1.15fr)]">
                        <div class="px-4 py-3"><p class="font-medium text-[var(--color-ink-strong)]">{{ $line->ingredient?->localizedDisplayName() ?? $line->packagingItem?->name }}</p></div>
                        <div class="px-4 py-3">{{ $line->listing_name }}</div>
                        <div class="numeric px-4 py-3 text-right">{{ $line->ordered_packs }}</div>
                        <div class="numeric whitespace-nowrap px-4 py-3 text-right">{{ $line->pack_price === null ? '—' : \App\Support\NumberLocale::formatAdaptiveDecimal($line->pack_price, 2, 4).' '.$line->currency }}</div>
                    </div>
                    @if ($canPrice)
                        <div wire:key="price-{{ $line->id }}" class="bg-[var(--color-panel-muted)] px-4 py-3">
                            <form wire:submit="recordPrice({{ $line->id }})" class="grid gap-2 sm:grid-cols-[1fr_1fr_1fr_auto] sm:items-end" data-production-bench-price-form>
                                <label class="text-xs">{{ __('production_bench.procurement.price_basis') }}<select wire:model.live="priceInputs.{{ $line->id }}.basis" class="sk-input mt-1 w-full py-2"><option value="per_unit">{{ __('production_bench.procurement.per_unit') }}</option><option value="total_purchase_format">{{ __('production_bench.procurement.total_format') }}</option></select></label>
                                <label class="text-xs">
                                    {{ __('production_bench.procurement.price') }}
                                    <span class="mt-1 flex overflow-hidden rounded-lg border border-[var(--color-line)] bg-[var(--color-field)] transition focus-within:border-[var(--color-accent)] focus-within:ring-1 focus-within:ring-[var(--color-accent)]" data-production-bench-price-field>
                                        <input
                                            wire:model="priceInputs.{{ $line->id }}.amount"
                                            type="text"
                                            inputmode="decimal"
                                            pattern="[0-9]+([.,][0-9]+)?"
                                            x-on:input="$event.target.value = $event.target.value.replace(/[^0-9.,]/g, '')"
                                            data-production-bench-price-amount
                                            aria-invalid="{{ $errors->has("priceInputs.{$line->id}.amount") ? 'true' : 'false' }}"
                                            class="sk-input min-w-0 flex-1 rounded-none border-0 bg-transparent py-2 focus:outline-none focus:ring-0"
                                        >
                                        <span class="inline-flex items-center bg-transparent px-3 text-xs font-medium text-[var(--color-ink-soft)]" data-production-bench-price-currency>{{ $line->currency }}</span>
                                    </span>
                                    @error("priceInputs.{$line->id}.amount")
                                        <span class="mt-1 block text-xs text-[var(--color-danger-strong)]">{{ $message }}</span>
                                    @enderror
                                </label>
                                <label class="text-xs">{{ __('production_bench.procurement.unit') }}<input wire:model="priceInputs.{{ $line->id }}.unit" class="sk-input mt-1 w-full py-2"></label>
                                <button type="submit" class="sk-btn sk-btn-primary py-2">{{ __('production_bench.procurement.save_price') }}</button>
                            </form>
                        </div>
                    @endif
                </article>
                @endforeach
            </div>
        </div>
    </section>

    @if (! $isQuotation && $needsPrices)
        <p role="status" class="rounded-xl bg-[var(--color-warning-soft)] px-4 py-3 text-sm text-[var(--color-warning-strong)]">{{ __('production_bench.procurement.confirm_prices_before_issue') }}</p>
    @endif

    @if ($canIssueOrder)
        <section class="sk-card grid gap-4 p-5 sm:grid-cols-3">
            <h2 class="font-semibold text-[var(--color-ink-strong)] sm:col-span-3">{{ __('production_bench.procurement.delivery_and_totals') }}</h2>
            <label class="text-sm">{{ __('production_bench.common.name') }}<input wire:model="deliveryAddress.name" class="sk-input mt-1 w-full"></label>
            <label class="text-sm">{{ __('production_bench.supplier.city') }}<input wire:model="deliveryAddress.city" class="sk-input mt-1 w-full"></label>
            <label class="text-sm">{{ __('production_bench.supplier.country_code') }}<input wire:model="deliveryAddress.country_code" maxlength="2" class="sk-input mt-1 w-full"></label>
            <label class="text-sm">{{ __('production_bench.procurement.shipping') }}<input wire:model="shippingAmount" class="sk-input mt-1 w-full" inputmode="decimal"></label>
            <label class="text-sm">{{ __('production_bench.procurement.discount') }}<input wire:model="discountAmount" class="sk-input mt-1 w-full" inputmode="decimal"></label>
            <label class="text-sm">{{ __('production_bench.procurement.tax') }}<input wire:model="taxAmount" class="sk-input mt-1 w-full" inputmode="decimal"></label>
        </section>
    @endif

    @if ($emailText)
        <section class="sk-card space-y-3 p-5" x-data="{ copied: false }">
            <div class="flex items-center justify-between gap-3"><h2 class="font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.procurement.email_text') }}</h2><button type="button" class="sk-btn sk-btn-outline" @click="navigator.clipboard.writeText($refs.email.value); copied = true; setTimeout(() => copied = false, 1500)"><span x-text="copied ? '{{ __('production_bench.procurement.copied') }}' : '{{ __('production_bench.procurement.copy') }}'">{{ __('production_bench.procurement.copy') }}</span></button></div>
            <textarea x-ref="email" readonly rows="10" class="sk-input w-full font-mono text-xs">{{ $emailText }}</textarea>
        </section>
    @endif

    @unless($isReadOnly)
        <div class="pb-24">
            <x-workflow-action-bar data-production-bench-procurement-action-bar>
                @if ($canReceive)
                    <a href="{{ route('production-bench.purchasing.receipts.create', ['source' => 'purchase_order', 'order' => $order->public_id]) }}" wire:navigate class="sk-btn sk-btn-outline">{{ __('production_bench.receipt.receive_delivery') }}</a>
                @endif
                @if ($emailText)
                    <a href="{{ route('production-bench.purchasing.documents.print', $order) }}" target="_blank" class="sk-btn sk-btn-outline">{{ __('production_bench.procurement.print_pdf') }}</a>
                @endif
                @if ($canIssueQuotation)<button type="button" wire:click="issueQuotation" class="sk-btn sk-btn-primary">{{ __('production_bench.procurement.issue_quotation') }}</button>@endif
                @if ($canConvert)<button type="button" wire:click="convertToPurchaseOrder" @if ($needsPrices) wire:confirm="{{ __('production_bench.procurement.convert_missing_prices') }}" data-production-bench-convert-warning @endif class="sk-btn sk-btn-primary">{{ __('production_bench.procurement.convert') }}</button>@endif
                @if ($canIssueOrder)<button type="button" wire:click="issuePurchaseOrder" class="sk-btn sk-btn-primary">{{ __('production_bench.procurement.issue_order') }}</button>@endif
            </x-workflow-action-bar>
        </div>
    @endunless
</x-production-bench.page>
