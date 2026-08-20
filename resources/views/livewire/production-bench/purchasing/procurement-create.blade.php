<x-production-bench.page active="purchasing" :subnavigation="$isQuotation ? 'quotations' : 'orders'">
    <header><h1 class="text-3xl font-semibold text-[var(--color-ink-strong)]">{{ $isQuotation ? __('production_bench.procurement.new_quotation') : __('production_bench.procurement.new_order') }}</h1></header>

    <form wire:submit="save" class="space-y-4 pb-24">
        @unless ($isQuotation)
            <section class="sk-card space-y-4 p-5">
                <div>
                    <h2 class="font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.procurement.from_quotation') }}</h2>
                    <p class="mt-1 text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.procurement.from_quotation_help') }}</p>
                </div>
                <div class="grid gap-3 sm:grid-cols-[1fr_auto] sm:items-end">
                    <label class="text-sm">
                        {{ __('production_bench.procurement.quotation_request') }}
                        <select wire:model="quotationRequestPublicId" class="sk-input mt-1 w-full">
                            <option value="">{{ __('production_bench.procurement.choose_quotation') }}</option>
                            @foreach ($quotationRequests as $quotationRequest)
                                @php($needsPrices = $quotationRequest->lines->contains(fn ($line) => $line->pack_price === null))
                                <option value="{{ $quotationRequest->public_id }}">{{ $quotationRequest->quotation_reference }} · {{ $quotationRequest->supplier->name }} · {{ $quotationRequest->currency }}{{ $needsPrices ? ' · '.__('production_bench.procurement.prices_needed') : '' }}</option>
                            @endforeach
                        </select>
                    </label>
                    <button type="button" wire:click="useQuotationRequest" class="sk-btn sk-btn-outline" wire:loading.attr="disabled" wire:target="useQuotationRequest">{{ __('production_bench.procurement.use_quotation') }}</button>
                </div>
                @error('quotationRequestPublicId')<p class="text-sm text-[var(--color-danger-strong)]">{{ $message }}</p>@enderror
            </section>

            <div class="flex items-center gap-3 text-xs font-medium uppercase tracking-wide text-[var(--color-ink-soft)]" aria-hidden="true"><span class="h-px flex-1 bg-[var(--color-line)]"></span><span>{{ __('production_bench.procurement.or_direct_order') }}</span><span class="h-px flex-1 bg-[var(--color-line)]"></span></div>
        @endunless

        <section class="sk-card space-y-4 p-5">
            <label class="block text-sm font-medium text-[var(--color-ink-strong)]" for="supplier">{{ __('production_bench.supplier.singular') }}</label>
            <select id="supplier" wire:model.live="supplierId" class="sk-input w-full" required>
                <option value="">{{ __('production_bench.procurement.choose_supplier') }}</option>
                @foreach ($suppliers as $supplier)<option value="{{ $supplier->id }}">{{ $supplier->code }} · {{ $supplier->name }}</option>@endforeach
            </select>
            @error('supplierId')<p class="text-sm text-[var(--color-danger-strong)]">{{ $message }}</p>@enderror
        </section>

        @if ($supplierId)
            <section class="overflow-hidden rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)]">
                <div class="border-b border-[var(--color-line)] px-5 py-4"><h2 class="font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.procurement.purchase_formats') }}</h2></div>
                <div class="divide-y divide-[var(--color-line)]">
                    @forelse ($listings as $listing)
                        <div class="grid gap-3 px-5 py-4 sm:grid-cols-[1fr_9rem] sm:items-center" wire:key="listing-{{ $listing->id }}">
                            <div><p class="font-medium text-[var(--color-ink-strong)]">{{ $listing->ingredient?->localizedDisplayName() ?? $listing->packagingItem?->name }}</p><p class="text-sm text-[var(--color-ink-soft)]">{{ $listing->purchase_format }} · {{ $listing->currency }}@if($listing->supplier_sku) · {{ $listing->supplier_sku }}@endif</p></div>
                            <label class="text-sm">{{ __('production_bench.procurement.quantity') }}<input type="number" min="0" step="1" wire:model="packs.{{ $listing->id }}" class="sk-input mt-1 w-full" inputmode="numeric"></label>
                        </div>
                    @empty
                        <p class="px-5 py-8 text-center text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.listing.none') }}</p>
                    @endforelse
                </div>
            </section>
        @endif
        @error('packs')<p class="text-sm text-[var(--color-danger-strong)]">{{ $message }}</p>@enderror

        <section class="sk-card grid gap-4 p-5 sm:grid-cols-2">
            <label class="text-sm">{{ __('production_bench.procurement.expected_date') }}<input type="date" wire:model="expectedAt" class="sk-input mt-1 w-full"></label>
            <label class="text-sm sm:col-span-2">{{ __('production_bench.common.notes') }}<textarea wire:model="notes" rows="3" class="sk-input mt-1 w-full"></textarea></label>
        </section>

        <x-workflow-action-bar data-production-bench-procurement-save-bar>
            <a href="{{ $isQuotation ? route('production-bench.purchasing.quotations') : route('production-bench.purchasing.orders') }}" wire:navigate class="sk-btn sk-btn-ghost">{{ __('production_bench.common.cancel') }}</a>
            <button type="submit" class="sk-btn sk-btn-primary" wire:loading.attr="disabled">{{ __('production_bench.procurement.create_draft') }}</button>
        </x-workflow-action-bar>
    </form>
</x-production-bench.page>
