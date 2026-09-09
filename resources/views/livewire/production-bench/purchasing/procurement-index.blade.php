<x-production-bench.page active="purchasing" :subnavigation="$isQuotation ? 'quotations' : 'orders'">
    @if ($isReadOnly)
        <p role="status" class="rounded-xl bg-[var(--color-warning-soft)] px-4 py-3 text-sm text-[var(--color-warning-strong)]">{{ __('production_bench.common.read_only') }}</p>
    @endif

    <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <h1 class="text-3xl font-semibold text-[var(--color-ink-strong)]">{{ $isQuotation ? __('production_bench.procurement.quotation_requests') : __('production_bench.procurement.purchase_orders') }}</h1>
        @if ($isBenchActive)
            <a href="{{ $isQuotation ? route('production-bench.purchasing.quotations.create') : route('production-bench.purchasing.orders.create') }}" wire:navigate class="sk-btn sk-btn-primary">{{ $isQuotation ? __('production_bench.procurement.new_quotation') : __('production_bench.procurement.new_order') }}</a>
        @endif
    </header>

    <section class="overflow-hidden sk-card">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[720px] text-left text-sm">
                <thead class="bg-[var(--color-panel-muted)] text-xs uppercase tracking-wide text-[var(--color-ink-soft)]"><tr><th class="px-5 py-3">{{ __('production_bench.procurement.reference') }}</th><th class="px-4 py-3">{{ __('production_bench.supplier.singular') }}</th><th class="px-4 py-3">{{ __('production_bench.common.status') }}</th><th class="px-4 py-3">{{ __('production_bench.common.currency') }}</th><th class="px-5 py-3 text-right">{{ __('production_bench.common.actions') }}</th></tr></thead>
                <tbody class="divide-y divide-[var(--color-line)]">
                    @forelse ($orders as $order)
                        @php
                            $orderStatus = match ($order->status->value) {
                                'partially_received' => 'incomplete',
                                'received' => 'complete',
                                default => $order->status->value,
                            };
                        @endphp
                        <tr wire:key="procurement-{{ $order->id }}" class="transition hover:bg-[var(--color-panel-strong)]">
                            <td class="numeric px-5 py-4 font-medium text-[var(--color-ink-strong)]">{{ $isQuotation ? ($order->quotation_reference ?? __('production_bench.procurement.draft')) : $order->reference }}</td>
                            <td class="px-4 py-4">{{ $order->supplier->name }}</td>
                            <td class="px-4 py-4">
                                <span
                                    data-purchase-order-status="{{ $orderStatus }}"
                                    @class([
                                        'inline-flex rounded-full px-2.5 py-1 text-xs font-medium',
                                        'bg-[var(--color-success-soft)] text-[var(--color-success-strong)]' => $orderStatus === 'complete',
                                        'bg-[var(--color-warning-soft)] text-[var(--color-warning-strong)]' => $orderStatus === 'incomplete',
                                        'bg-[var(--color-accent-soft)] text-[var(--color-accent-strong)]' => $orderStatus === 'ordered',
                                        'bg-[var(--color-danger-soft)] text-[var(--color-danger-strong)]' => $orderStatus === 'cancelled',
                                        'bg-[var(--color-field-muted)] text-[var(--color-ink-soft)]' => $orderStatus === 'draft',
                                    ])
                                >{{ __("production_bench.procurement.status_{$orderStatus}") }}</span>
                            </td>
                            <td class="px-4 py-4">{{ $order->currency }}</td>
                            <td class="px-5 py-4 text-right"><a href="{{ route('production-bench.purchasing.procurement.show', $order) }}" wire:navigate class="font-medium text-[var(--color-accent-strong)] hover:underline">{{ __('production_bench.procurement.open') }}</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-6 py-12 text-center text-[var(--color-ink-soft)]">{{ $isQuotation ? __('production_bench.procurement.no_quotations') : __('production_bench.procurement.no_orders') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</x-production-bench.page>
