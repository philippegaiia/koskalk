<div class="mx-auto max-w-7xl space-y-8">
    <x-production-bench.navigation />

    @if (! $isActive && ! $isReadOnly)
        <section class="sk-card p-8 text-center">
            <h1 class="font-serif text-3xl text-[var(--color-ink-strong)]">Activate the bench to start purchasing.</h1>
            <a href="{{ route('production-bench.home') }}" wire:navigate class="mt-4 inline-block text-sm font-medium text-[var(--color-accent)]">Go to Production Bench home</a>
        </section>
    @else
        @if ($isReadOnly)
            <p class="rounded-xl bg-[var(--color-warning-soft)] px-4 py-3 text-sm text-[var(--color-warning-strong)]">Read-only: suppliers, orders, receipts and lot history remain visible.</p>
        @endif

        <header>
            <p class="sk-eyebrow">Purchasing</p>
            <h1 class="mt-2 font-serif text-4xl text-[var(--color-ink-strong)]">Order packs. Receive what actually arrived.</h1>
            <p class="mt-3 max-w-3xl text-sm leading-6 text-[var(--color-ink-soft)]">A supplier listing records the commercial pack—5 kg pail, 20 kg drum, 500 caps. Orders preserve that price; each delivery creates its own internal lot.</p>
        </header>

        <div class="grid gap-6 xl:grid-cols-2">
            <section class="sk-card p-6">
                <p class="sk-eyebrow">Suppliers</p>
                <h2 class="mt-2 text-xl font-semibold">Who you buy from</h2>
                <form wire:submit="createSupplier" class="mt-5 grid gap-3 sm:grid-cols-[1fr_1fr_auto]">
                    <input wire:model="supplierName" required @disabled($isReadOnly) placeholder="Supplier name" class="sk-input">
                    <input wire:model="supplierEmail" type="email" @disabled($isReadOnly) placeholder="Email · optional" class="sk-input">
                    <button type="submit" @disabled($isReadOnly) class="rounded-full bg-[var(--color-accent)] px-4 py-2 text-sm font-medium text-white disabled:opacity-40">Add supplier</button>
                </form>
                <div class="mt-5 divide-y divide-[var(--color-line)] border-y border-[var(--color-line)]">
                    @forelse ($suppliers as $supplier)
                        <div class="flex items-center justify-between gap-4 py-3"><span class="font-medium">{{ $supplier->name }}</span><span class="text-xs text-[var(--color-ink-soft)]">{{ $supplier->listings->count() }} listings</span></div>
                    @empty
                        <p class="py-5 text-sm text-[var(--color-ink-soft)]">Add your first supplier to create pack listings.</p>
                    @endforelse
                </div>
            </section>

            <section class="sk-card p-6">
                <p class="sk-eyebrow">Listings</p>
                <h2 class="mt-2 text-xl font-semibold">A supplier pack linked to your item</h2>
                <form wire:submit="createListing" class="mt-5 grid gap-3 sm:grid-cols-2">
                    <select wire:model="listingSupplierId" required @disabled($isReadOnly) class="sk-input"><option value="">Supplier…</option>@foreach($suppliers as $supplier)<option value="{{ $supplier->id }}">{{ $supplier->name }}</option>@endforeach</select>
                    <select wire:model.live="listingSubjectType" @disabled($isReadOnly) class="sk-input"><option value="ingredient">Ingredient</option><option value="packaging">Packaging</option></select>
                    <select wire:model="listingSubjectId" required @disabled($isReadOnly) class="sk-input sm:col-span-2"><option value="">Linked Soapkraft item…</option>@foreach($listingSubjectType === 'ingredient' ? $ingredients : $packagingItems as $subject)<option value="{{ $subject->id }}">{{ $listingSubjectType === 'ingredient' ? $subject->localizedDisplayName() : $subject->name }}</option>@endforeach</select>
                    <input wire:model="listingDescription" required @disabled($isReadOnly) placeholder="Pack description · e.g. 5 kg pail" class="sk-input">
                    <input wire:model="listingSku" @disabled($isReadOnly) placeholder="Supplier SKU · optional" class="sk-input">
                    <div class="flex gap-2"><input wire:model="listingQuantity" required @disabled($isReadOnly) placeholder="Net quantity" inputmode="decimal" class="sk-input min-w-0 flex-1 font-mono"><select wire:model="listingUnit" @disabled($isReadOnly || $listingSubjectType === 'packaging') class="sk-input w-24">@if($listingSubjectType === 'ingredient')<option>g</option><option>kg</option><option>oz</option><option>lb</option>@else<option value="count">units</option>@endif</select></div>
                    <div class="flex items-center gap-2"><input wire:model="listingPackPrice" required @disabled($isReadOnly) placeholder="Pack price" inputmode="decimal" class="sk-input min-w-0 flex-1 font-mono"><span class="text-sm text-[var(--color-ink-soft)]">{{ $workspace->default_currency }}</span></div>
                    <button type="submit" @disabled($isReadOnly) class="rounded-full bg-[var(--color-accent)] px-4 py-2 text-sm font-medium text-white disabled:opacity-40 sm:col-span-2 sm:justify-self-start">Save listing</button>
                </form>
            </section>
        </div>

        <section class="overflow-hidden rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)]">
            <div class="border-b border-[var(--color-line)] p-6"><p class="sk-eyebrow">Listing library</p><h2 class="mt-2 text-xl font-semibold">Commercial packs</h2></div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[760px] text-left text-sm">
                    <thead class="bg-[var(--color-panel-muted)] text-xs uppercase tracking-wide text-[var(--color-ink-soft)]"><tr><th class="px-5 py-3">Soapkraft item</th><th class="px-4 py-3">Supplier</th><th class="px-4 py-3">Pack</th><th class="px-4 py-3 text-right">Net content</th><th class="px-5 py-3 text-right">Pack price</th></tr></thead>
                    <tbody class="divide-y divide-[var(--color-line)]">
                        @forelse($listings as $listing)
                            <tr><td class="px-5 py-4 font-medium">{{ $listing->ingredient?->localizedDisplayName() ?? $listing->packagingItem?->name }}</td><td class="px-4 py-4">{{ $listing->supplier->name }}</td><td class="px-4 py-4">{{ $listing->purchase_format }} @if($listing->supplier_sku)<span class="font-mono text-xs text-[var(--color-ink-soft)]">· {{ $listing->supplier_sku }}</span>@endif</td><td class="px-4 py-4 text-right font-mono">{{ number_format((float)$listing->net_quantity, 2) }} {{ $listing->net_unit }}</td><td class="px-5 py-4 text-right font-mono">{{ number_format((float)$listing->total_price, 2) }} {{ $listing->currency }}</td></tr>
                        @empty
                            <tr><td colspan="5" class="px-6 py-10 text-center text-[var(--color-ink-soft)]">No listings yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <div class="grid gap-6 xl:grid-cols-2">
            <section class="sk-card p-6">
                <p class="sk-eyebrow">Purchase orders</p>
                <h2 class="mt-2 text-xl font-semibold">Plan whole supplier packs</h2>
                <form wire:submit="createOrder" class="mt-5 grid gap-3 sm:grid-cols-[1fr_7rem_auto]">
                    <select wire:model="orderListingId" required @disabled($isReadOnly) class="sk-input"><option value="">Choose a listing…</option>@foreach($listings as $listing)<option value="{{ $listing->id }}">{{ $listing->supplier->name }} · {{ $listing->purchase_format }} · {{ $listing->ingredient?->localizedDisplayName() ?? $listing->packagingItem?->name }}</option>@endforeach</select>
                    <input wire:model="orderPacks" type="number" min="1" required @disabled($isReadOnly) class="sk-input font-mono">
                    <button type="submit" @disabled($isReadOnly) class="rounded-full bg-[var(--color-accent)] px-4 py-2 text-sm font-medium text-white disabled:opacity-40">Create draft</button>
                </form>
                <div class="mt-6 space-y-3">
                    @forelse($orders as $order)
                        <article class="rounded-xl border border-[var(--color-line)] p-4">
                            <div class="flex items-start justify-between gap-4"><div><p class="font-mono text-sm font-semibold">{{ $order->reference }}</p><p class="mt-1 text-sm text-[var(--color-ink-soft)]">{{ $order->supplier->name }}</p></div><span class="rounded-full bg-[var(--color-field-muted)] px-2.5 py-1 text-xs font-medium">{{ str_replace('_', ' ', ucfirst($order->status->value)) }}</span></div>
                            @foreach($order->lines as $line)<p class="mt-3 text-sm">{{ $line->ordered_packs }} × {{ $line->listing_name }} <span class="font-mono text-[var(--color-ink-soft)]">· {{ number_format((float)$line->expected_cost, 2) }} {{ $line->currency }}</span></p>@endforeach
                            @if(! $isReadOnly)
                                <div class="mt-4 flex gap-4">
                                    @if($order->status === \App\PurchaseOrderStatus::Draft)<button wire:click="placeOrder({{ $order->id }})" type="button" class="text-sm font-medium text-[var(--color-accent)]">Place order →</button>@endif
                                    @if(in_array($order->status, [\App\PurchaseOrderStatus::Draft, \App\PurchaseOrderStatus::Ordered], true))<button wire:click="cancelOrder({{ $order->id }})" type="button" class="text-sm text-[var(--color-ink-soft)]">Cancel</button>@endif
                                </div>
                                @foreach($order->receipts->where('status', \App\GoodsReceiptStatus::Posted) as $receipt)
                                    <button wire:click="reverseReceipt({{ $receipt->id }})" type="button" class="mt-3 text-xs text-[var(--color-danger-strong)]">Reverse receipt {{ $receipt->delivery_reference ?: '#'.$receipt->id }}</button>
                                @endforeach
                            @endif
                        </article>
                    @empty
                        <p class="py-4 text-sm text-[var(--color-ink-soft)]">No orders yet.</p>
                    @endforelse
                </div>
            </section>

            <section class="sk-card p-6">
                <p class="sk-eyebrow">Receive delivery</p>
                <h2 class="mt-2 text-xl font-semibold">Record actual quantity</h2>
                <p class="mt-2 text-sm leading-6 text-[var(--color-ink-soft)]">Partial deliveries are welcome. Each receipt becomes a distinct Soapkraft lot.</p>
                <form wire:submit="receiveDelivery" class="mt-5 grid gap-3 sm:grid-cols-2">
                    <select wire:model="receiptOrderLineId" required @disabled($isReadOnly) class="sk-input sm:col-span-2"><option value="">Outstanding order line…</option>@foreach($receivableLines as $line)<option value="{{ $line->id }}">{{ $line->purchaseOrder->reference }} · {{ $line->listing_name }} · {{ $line->ordered_packs - $line->receiptLines->sum('packs_received') }} packs remaining</option>@endforeach</select>
                    <input wire:model="receiptPacks" type="number" min="1" required @disabled($isReadOnly) placeholder="Packs received" class="sk-input font-mono">
                    <input wire:model="receiptDeliveryReference" @disabled($isReadOnly) placeholder="Delivery reference · optional" class="sk-input">
                    <div class="flex gap-2"><input wire:model="receiptQuantity" required @disabled($isReadOnly) placeholder="Actual net quantity" inputmode="decimal" class="sk-input min-w-0 flex-1 font-mono"><select wire:model="receiptUnit" @disabled($isReadOnly) class="sk-input w-24"><option>g</option><option>kg</option><option>oz</option><option>lb</option><option value="count">units</option></select></div>
                    <input wire:model="receiptSupplierBatch" @disabled($isReadOnly) placeholder="Supplier batch · optional" class="sk-input">
                    <select wire:model="receiptStatus" @disabled($isReadOnly) class="sk-input sm:col-span-2"><option value="quarantined">Quarantine until checked</option><option value="released">Release immediately</option></select>
                    <button type="submit" @disabled($isReadOnly) class="rounded-full bg-[var(--color-accent)] px-4 py-2 text-sm font-medium text-white disabled:opacity-40 sm:col-span-2 sm:justify-self-start">Post receipt & create lot</button>
                </form>
            </section>
        </div>

        <section class="sk-card p-6">
            <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(20rem,0.8fr)]">
                <div>
                    <p class="sk-eyebrow">Lot documents</p>
                    <h2 class="mt-2 text-xl font-semibold">Keep the paperwork where it matters</h2>
                    <p class="mt-2 max-w-xl text-sm leading-6 text-[var(--color-ink-soft)]">Attach a receipt, invoice, delivery note, CoA, SDS or supplier certificate to the received internal lot. Files remain private.</p>
                    <div class="mt-5 divide-y divide-[var(--color-line)] border-y border-[var(--color-line)]">
                        @forelse($receiptLots as $lot)
                            <div class="py-3">
                                <div class="flex items-center justify-between gap-4"><p class="font-medium">{{ $lot->subjectName() }} <span class="font-mono text-xs text-[var(--color-ink-soft)]">· {{ $lot->internal_lot_code }}</span></p><span class="text-xs text-[var(--color-ink-soft)]">{{ $lot->documents->count() }} files</span></div>
                                @if($lot->supplier_batch_number)<p class="mt-1 text-xs text-[var(--color-ink-soft)]">Supplier batch {{ $lot->supplier_batch_number }}</p>@endif
                            </div>
                        @empty
                            <p class="py-5 text-sm text-[var(--color-ink-soft)]">Received lots will appear here.</p>
                        @endforelse
                    </div>
                </div>
                <form wire:submit="uploadDocument" class="rounded-xl bg-[var(--color-panel-muted)] p-5">
                    <label class="block space-y-2"><span class="text-sm font-medium">Received lot</span><select wire:model="documentLotId" required @disabled($isReadOnly) class="sk-input w-full"><option value="">Choose lot…</option>@foreach($receiptLots as $lot)<option value="{{ $lot->id }}">{{ $lot->internal_lot_code }} · {{ $lot->subjectName() }}</option>@endforeach</select></label>
                    <label class="mt-3 block space-y-2"><span class="text-sm font-medium">Document type</span><select wire:model="documentType" @disabled($isReadOnly) class="sk-input w-full"><option value="certificate_of_analysis">Certificate of analysis</option><option value="receipt">Receipt</option><option value="invoice">Invoice</option><option value="delivery_note">Delivery note</option><option value="safety_data_sheet">Safety data sheet</option><option value="specification">Specification</option><option value="certificate">Certificate</option><option value="photo">Photo</option><option value="other">Other</option></select></label>
                    <label class="mt-3 block space-y-2"><span class="text-sm font-medium">PDF or image</span><input wire:model="documentUpload" type="file" accept=".pdf,image/*" required @disabled($isReadOnly) class="block w-full text-sm"></label>
                    @error('documentUpload')<p class="mt-1 text-xs text-[var(--color-danger-strong)]">{{ $message }}</p>@enderror
                    <label class="mt-3 block space-y-2"><span class="text-sm font-medium">Note <span class="font-normal text-[var(--color-ink-muted)]">optional</span></span><input wire:model="documentNote" @disabled($isReadOnly) class="sk-input w-full"></label>
                    <button type="submit" wire:loading.attr="disabled" @disabled($isReadOnly) class="mt-4 rounded-full bg-[var(--color-accent)] px-4 py-2 text-sm font-medium text-white disabled:opacity-40">Attach private file</button>
                </form>
            </div>
        </section>
    @endif
</div>
