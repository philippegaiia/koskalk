<x-production-bench.page purchasing compact>
    @if ($isReadOnly)
        <p role="status" class="rounded-xl bg-[var(--color-warning-soft)] px-4 py-3 text-sm text-[var(--color-warning-strong)]">{{ __('production_bench.common.read_only') }}</p>
    @endif

    <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="sk-eyebrow">{{ $receipt->source->value === 'direct' ? __('production_bench.receipt.direct_source') : __('production_bench.receipt.order_source') }}</p>
            <h1 class="mt-2 text-3xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.receipt.singular') }}</h1>
            <p class="numeric mt-1 text-sm text-[var(--color-ink-soft)]">{{ $receipt->delivery_reference ?: __('production_bench.receipt.no_reference') }}</p>
        </div>
        <span @class(['rounded-full px-3 py-1 text-xs font-medium', 'bg-[var(--color-danger-soft)] text-[var(--color-danger-strong)]' => $receipt->status->value === 'reversed', 'bg-[var(--color-success-soft)] text-[var(--color-success-strong)]' => $receipt->status->value === 'posted'])>{{ $receipt->status->value === 'reversed' ? __('production_bench.receipt.status_reversed') : __('production_bench.receipt.status_posted') }}</span>
    </header>

    <dl class="grid gap-x-6 gap-y-4 border-y border-[var(--color-line)] py-5 sm:grid-cols-2 lg:grid-cols-4">
        <div><dt class="sk-eyebrow">{{ __('production_bench.receipt.received_on') }}</dt><dd class="numeric mt-1 text-sm text-[var(--color-ink-strong)]">{{ $receipt->received_at->format('Y-m-d') }}</dd></div>
        <div><dt class="sk-eyebrow">{{ __('production_bench.supplier.singular') }}</dt><dd class="mt-1 text-sm"><a href="{{ route('production-bench.purchasing.supplier', $receipt->supplier) }}" wire:navigate class="font-medium text-[var(--color-accent-strong)] hover:underline">{{ $receipt->supplier->name }}</a></dd></div>
        <div><dt class="sk-eyebrow">{{ __('production_bench.procurement.purchase_order') }}</dt><dd class="numeric mt-1 text-sm">@if($receipt->purchaseOrder)<a href="{{ route('production-bench.purchasing.procurement.show', $receipt->purchaseOrder) }}" wire:navigate class="font-medium text-[var(--color-accent-strong)] hover:underline">{{ $receipt->purchaseOrder->reference }}</a>@else<span class="text-[var(--color-ink-soft)]">{{ __('production_bench.receipt.not_applicable') }}</span>@endif</dd></div>
        <div><dt class="sk-eyebrow">{{ __('production_bench.receipt.lines') }}</dt><dd class="numeric mt-1 text-sm text-[var(--color-ink-strong)]">{{ $receipt->lines->count() }}</dd></div>
        @if ($receipt->notes)<div class="sm:col-span-2 lg:col-span-4"><dt class="sk-eyebrow">{{ __('production_bench.common.notes') }}</dt><dd class="mt-1 whitespace-pre-line text-sm text-[var(--color-ink-strong)]">{{ $receipt->notes }}</dd></div>@endif
    </dl>

    <section aria-labelledby="receipt-lines-heading" class="space-y-3">
        <h2 id="receipt-lines-heading" class="text-xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.receipt.received_items') }}</h2>
        <div class="overflow-x-auto" data-receipt-responsive-table>
            <div class="min-w-[920px] space-y-2">
                @foreach ($receipt->lines as $line)
                    <article wire:key="receipt-detail-line-{{ $line->id }}" class="rounded-xl bg-[var(--color-field-muted)] p-4">
                        <div class="grid gap-4 lg:grid-cols-[minmax(13rem,1.2fr)_repeat(4,minmax(7rem,1fr))]">
                            <div>
                                <p class="font-medium text-[var(--color-ink-strong)]">{{ $line->stockLot->subjectName() }}</p>
                                <p class="mt-1 text-xs text-[var(--color-ink-soft)]">{{ $line->supplierListing->purchase_format }}</p>
                                <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs">
                                    <a href="{{ route('production-bench.purchasing.supplier', $receipt->supplier).'#listing-'.$line->supplierListing->public_id }}" wire:navigate class="font-medium text-[var(--color-accent-strong)] hover:underline">{{ __('production_bench.receipt.listing_link') }}</a>
                                    <a href="{{ route('production-bench.inventory', ['lot' => $line->stockLot->public_id]).'#lot-'.$line->stockLot->public_id }}" wire:navigate class="font-medium text-[var(--color-accent-strong)] hover:underline">{{ __('production_bench.receipt.inventory_link') }}</a>
                                </div>
                            </div>
                            @if ($line->purchaseOrderLine)
                                <div><p class="sk-eyebrow">{{ __('production_bench.receipt.ordered') }}</p><p class="numeric mt-1 text-sm">{{ $line->purchaseOrderLine->ordered_packs }}</p></div>
                            @endif
                            <div><p class="sk-eyebrow">{{ __('production_bench.receipt.formats') }}</p><p class="numeric mt-1 text-sm">{{ $line->packs_received }}</p></div>
                            <div><p class="sk-eyebrow">{{ __('production_bench.receipt.actual_quantity') }}</p><p class="numeric mt-1 text-sm">{{ \App\Support\NumberLocale::formatAdaptiveDecimal($line->original_quantity, 0, 4) }} {{ $line->original_unit }}</p></div>
                            <div><p class="sk-eyebrow">{{ __('production_bench.receipt.price') }}</p><p class="numeric mt-1 text-sm">{{ \App\Support\NumberLocale::formatAdaptiveDecimal($line->receipt_price_amount, 2, 4) }} {{ $line->currency }}@if($line->receipt_price_unit) / {{ $line->receipt_price_unit }}@endif</p><p class="mt-1 text-xs text-[var(--color-ink-soft)]">{{ $line->receipt_price_basis->value === 'per_unit' ? __('production_bench.receipt.per_unit') : __('production_bench.receipt.total_format') }}</p></div>
                            <div><p class="sk-eyebrow">{{ __('production_bench.receipt.historical_cost') }}</p><p class="numeric mt-1 text-sm">{{ \App\Support\NumberLocale::formatAdaptiveDecimal($line->historical_total_cost, 2, 4) }} {{ $line->currency }}</p><p class="numeric mt-1 text-xs text-[var(--color-ink-soft)]">{{ \App\Support\NumberLocale::formatAdaptiveDecimal($line->stockLot->historical_unit_cost, 2, 6) }} / {{ $line->stockLot->unit_kind->value === 'mass' ? 'g' : __('production_bench.receipt.count') }}</p></div>
                        </div>
                        <dl class="mt-4 grid gap-3 border-t border-[var(--color-line)] pt-3 sm:grid-cols-4">
                            <div><dt class="sk-eyebrow">{{ __('production_bench.receipt.internal_lot') }}</dt><dd class="numeric mt-1 text-xs">{{ $line->stockLot->internal_lot_code }}</dd></div>
                            <div><dt class="sk-eyebrow">{{ __('production_bench.receipt.supplier_batch') }}</dt><dd class="mt-1 text-xs">{{ $line->supplier_batch_number ?: __('production_bench.receipt.not_provided') }}</dd></div>
                            <div><dt class="sk-eyebrow">{{ __('production_bench.receipt.expiry') }}</dt><dd class="numeric mt-1 text-xs">{{ $line->expires_at?->format('Y-m-d') ?? __('production_bench.receipt.not_provided') }}</dd></div>
                            @if($line->notes)<div><dt class="sk-eyebrow">{{ __('production_bench.common.notes') }}</dt><dd class="mt-1 text-xs">{{ $line->notes }}</dd></div>@endif
                        </dl>
                    </article>
                @endforeach
            </div>
        </div>
    </section>

    @if ($receipt->documents->isNotEmpty() || $receipt->lines->contains(fn ($line) => $line->stockLot->documents->isNotEmpty()))
        <section class="space-y-3">
            <h2 class="text-xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.receipt.documents') }}</h2>
            <ul class="divide-y divide-[var(--color-line)] rounded-xl bg-[var(--color-panel)] px-4">
                @foreach($receipt->documents as $document)<li class="flex items-center justify-between gap-3 py-3 text-sm"><span>{{ $document->mediaAsset->original_filename }}</span><span class="text-xs text-[var(--color-ink-soft)]">{{ $document->type->value }}</span></li>@endforeach
                @foreach($receipt->lines as $line)@foreach($line->stockLot->documents as $document)<li class="flex items-center justify-between gap-3 py-3 text-sm"><span>{{ $document->mediaAsset->original_filename }}</span><span class="text-xs text-[var(--color-ink-soft)]">{{ $line->stockLot->internal_lot_code }} · {{ $document->type->value }}</span></li>@endforeach @endforeach
            </ul>
        </section>
    @endif

    @if ($receipt->status->value === 'reversed')
        <section role="status" class="rounded-xl bg-[var(--color-danger-soft)] p-4 text-sm text-[var(--color-danger-strong)]">
            <p class="font-semibold">{{ __('production_bench.receipt.reversal_reason') }}</p>
            <p class="mt-1">{{ $receipt->reversal_reason }}</p>
        </section>
    @endif

    @if ($canReverse)
        <div class="pb-24">
            <x-workflow-action-bar data-receipt-reversal-action-bar>
                <label class="min-w-0 flex-1 text-sm"><span class="sr-only">{{ __('production_bench.receipt.reversal_reason') }}</span><input wire:model="reversalReason" required placeholder="{{ __('production_bench.receipt.reversal_reason_placeholder') }}" aria-invalid="{{ $errors->has('reversalReason') ? 'true' : 'false' }}" @if($errors->has('reversalReason')) aria-describedby="reversal-reason-error" @endif class="sk-input w-full">@error('reversalReason')<span id="reversal-reason-error" class="mt-1 block text-xs text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror</label>
                <button type="button" wire:click="reverse" wire:confirm="{{ __('production_bench.receipt.reverse_confirm') }}" class="sk-btn sk-btn-danger">{{ __('production_bench.receipt.reverse') }}</button>
            </x-workflow-action-bar>
        </div>
    @endif
</x-production-bench.page>
