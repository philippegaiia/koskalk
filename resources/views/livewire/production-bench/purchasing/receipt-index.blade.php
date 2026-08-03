<x-production-bench.page purchasing>
    @if ($isReadOnly)
        <p role="status" class="rounded-xl bg-[var(--color-warning-soft)] px-4 py-3 text-sm text-[var(--color-warning-strong)]">{{ __('production_bench.common.read_only') }}</p>
    @endif

    <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="text-3xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.receipt.plural') }}</h1>
            <p class="mt-2 max-w-2xl text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.receipt.index_help') }}</p>
        </div>
        @if ($isBenchActive)
            <div class="flex flex-wrap gap-2" data-receipt-create-action>
                <a href="{{ route('production-bench.purchasing.receipts.create', ['source' => 'purchase_order']) }}" wire:navigate class="sk-btn sk-btn-primary">{{ __('production_bench.receipt.receive_order') }}</a>
                <a href="{{ route('production-bench.purchasing.receipts.create', ['source' => 'direct']) }}" wire:navigate class="sk-btn sk-btn-outline">{{ __('production_bench.receipt.direct') }}</a>
            </div>
        @endif
    </header>

    @if ($receipts->isEmpty())
        <section class="rounded-2xl border border-dashed border-[var(--color-line-strong)] bg-[var(--color-panel)] px-6 py-14 text-center">
            <h2 class="text-xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.receipt.empty_title') }}</h2>
            <p class="mx-auto mt-2 max-w-xl text-sm leading-6 text-[var(--color-ink-soft)]">{{ __('production_bench.receipt.empty_help') }}</p>
            @if ($isBenchActive)
                <div class="mt-6 flex flex-wrap justify-center gap-2" data-receipt-create-action>
                    <a href="{{ route('production-bench.purchasing.receipts.create', ['source' => 'purchase_order']) }}" wire:navigate class="sk-btn sk-btn-primary">{{ __('production_bench.receipt.receive_order') }}</a>
                    <a href="{{ route('production-bench.purchasing.receipts.create', ['source' => 'direct']) }}" wire:navigate class="sk-btn sk-btn-outline">{{ __('production_bench.receipt.direct') }}</a>
                </div>
            @endif
        </section>
    @else
        <section aria-labelledby="receipt-list-heading" class="overflow-hidden rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)]">
            <h2 id="receipt-list-heading" class="sr-only">{{ __('production_bench.receipt.plural') }}</h2>
            <div class="overflow-x-auto" data-receipt-responsive-table>
                <table class="w-full min-w-[900px] text-left text-sm">
                    <thead class="bg-[var(--color-panel-muted)] text-xs uppercase tracking-wide text-[var(--color-ink-soft)]">
                        <tr>
                            <th class="px-5 py-3">{{ __('production_bench.receipt.received_on') }}</th>
                            <th class="px-4 py-3">{{ __('production_bench.receipt.reference') }}</th>
                            <th class="px-4 py-3">{{ __('production_bench.receipt.source') }}</th>
                            <th class="px-4 py-3">{{ __('production_bench.supplier.singular') }}</th>
                            <th class="px-4 py-3">{{ __('production_bench.procurement.purchase_order') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('production_bench.receipt.lines') }}</th>
                            <th class="px-4 py-3">{{ __('production_bench.common.status') }}</th>
                            <th class="px-5 py-3 text-right">{{ __('production_bench.common.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--color-line)]">
                        @foreach ($receipts as $receipt)
                            <tr wire:key="receipt-{{ $receipt->id }}" class="transition hover:bg-[var(--color-panel-strong)]">
                                <td class="numeric whitespace-nowrap px-5 py-4">{{ $receipt->received_at->format('Y-m-d') }}</td>
                                <td class="numeric px-4 py-4 font-medium text-[var(--color-ink-strong)]">{{ $receipt->delivery_reference ?: __('production_bench.receipt.no_reference') }}</td>
                                <td class="px-4 py-4">{{ $receipt->source->value === 'direct' ? __('production_bench.receipt.direct_source') : __('production_bench.receipt.order_source') }}</td>
                                <td class="px-4 py-4">{{ $receipt->supplier->name }}</td>
                                <td class="numeric px-4 py-4">
                                    @if ($receipt->purchaseOrder)
                                        <a href="{{ route('production-bench.purchasing.procurement.show', $receipt->purchaseOrder) }}" wire:navigate class="inline-flex min-h-11 items-center font-medium text-[var(--color-accent-strong)] hover:underline">{{ $receipt->purchaseOrder->reference }}</a>
                                    @else
                                        <span class="text-[var(--color-ink-soft)]">{{ __('production_bench.receipt.not_applicable') }}</span>
                                    @endif
                                </td>
                                <td class="numeric px-4 py-4 text-right">{{ $receipt->lines_count }}</td>
                                <td class="px-4 py-4">{{ $receipt->status->value === 'reversed' ? __('production_bench.receipt.status_reversed') : __('production_bench.receipt.status_posted') }}</td>
                                <td class="px-5 py-4 text-right"><a href="{{ route('production-bench.purchasing.receipts.show', $receipt) }}" wire:navigate class="inline-flex min-h-11 items-center font-medium text-[var(--color-accent-strong)] hover:underline">{{ __('production_bench.receipt.open') }}</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
        <div data-receipt-pagination>
            {{ $receipts->links() }}
        </div>
    @endif
</x-production-bench.page>
