<x-production-bench.page active="inventory" :subnavigation="$mode">
    @if (! $isActive && ! $isReadOnly)
        <section class="sk-card p-8 text-center">
            <h1 class="text-3xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.common.inactive') }}</h1>
            <a href="{{ route('production-bench.home') }}" wire:navigate class="mt-4 inline-block text-sm font-medium text-[var(--color-accent)]">{{ __('production_bench.title') }}</a>
        </section>
    @else
        @if ($isReadOnly)
            <p role="status" class="rounded-xl bg-[var(--color-warning-soft)] px-4 py-3 text-sm text-[var(--color-warning-strong)]">{{ __('production_bench.common.read_only') }}</p>
        @endif

        <header>
            <h1 class="text-3xl font-semibold text-[var(--color-ink-strong)]">{{ $mode === 'stock' ? __('production_bench.inventory.stock_title') : ($mode === 'requirements' ? __('production_bench.inventory.requirements_title') : __('production_bench.navigation.inventory')) }}</h1>
            <p class="mt-3 max-w-2xl text-sm leading-6 text-[var(--color-ink-soft)]">{{ $mode === 'stock' ? __('production_bench.inventory.stock_help') : ($mode === 'requirements' ? __('production_bench.inventory.requirements_help') : __('production_bench.inventory.help')) }}</p>
        </header>

        @if ($mode !== 'requirements')
        <section class="sk-card overflow-hidden">
            <div class="border-b border-[var(--color-line)] p-6">
                <h2 class="text-xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.inventory.opening_stock') }}</h2>
            </div>

            <form wire:submit="createOpeningStock" class="grid gap-5 p-6 md:grid-cols-2 xl:grid-cols-4">
                <label class="space-y-2 md:col-span-2 xl:col-span-4">
                    <span class="text-sm font-medium">{{ __('production_bench.inventory.supplier_listing') }}</span>
                    <select wire:model.live="supplierListingId" required @disabled($isReadOnly) class="sk-input w-full">
                        <option value="">{{ __('production_bench.inventory.choose') }}</option>
                        @foreach ($supplierListings as $listing)
                            <option value="{{ $listing->id }}">{{ $listing->supplier->name }} · {{ $listing->ingredient?->localizedDisplayName() ?? $listing->packagingItem?->name }} · {{ $listing->purchase_format }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="space-y-2">
                    <span class="text-sm font-medium">{{ __('production_bench.inventory.quantity') }}</span>
                    <div class="flex gap-2">
                        <input wire:model="quantity" inputmode="decimal" required @disabled($isReadOnly) class="sk-input min-w-0 flex-1 font-mono">
                        <select wire:model="unit" @disabled($isReadOnly) class="sk-input w-24">
                            @if ($unit !== 'count')
                                <option>g</option><option>kg</option><option>oz</option><option>lb</option>
                            @else
                                <option value="count">{{ __('production_bench.inventory.units') }}</option>
                            @endif
                        </select>
                    </div>
                    @error('quantity') <span class="text-xs text-[var(--color-danger-strong)]">{{ $message }}</span> @enderror
                </label>

                <label class="space-y-2">
                    <span class="text-sm font-medium">{{ __('production_bench.inventory.price_per', ['unit' => $unit === 'count' ? __('production_bench.inventory.item') : $unit]) }}</span>
                    <div class="flex gap-2">
                        <input wire:model="pricePerUnit" inputmode="decimal" required @disabled($isReadOnly) class="sk-input min-w-0 flex-1 font-mono">
                        <input wire:model="currency" required maxlength="3" @disabled($isReadOnly) class="sk-input w-20 uppercase">
                    </div>
                </label>

                <label class="space-y-2">
                    <span class="text-sm font-medium">{{ __('production_bench.inventory.supplier_batch') }} <span class="font-normal text-[var(--color-ink-muted)]">{{ __('production_bench.inventory.optional') }}</span></span>
                    <input wire:model="supplierBatchNumber" @disabled($isReadOnly) class="sk-input w-full">
                </label>

                <label class="space-y-2">
                    <span class="text-sm font-medium">{{ __('production_bench.inventory.stocked_on') }}</span>
                    <input wire:model="stockedAt" type="date" @disabled($isReadOnly) class="sk-input w-full">
                </label>

                <label class="space-y-2">
                    <span class="text-sm font-medium">{{ __('production_bench.inventory.expires_on') }}</span>
                    <input wire:model="expiresAt" type="date" @disabled($isReadOnly) class="sk-input w-full">
                </label>

                <label class="space-y-2 md:col-span-2">
                    <span class="text-sm font-medium">{{ __('production_bench.common.notes') }}</span>
                    <input wire:model="notes" @disabled($isReadOnly) class="sk-input w-full">
                </label>

                <div class="md:col-span-2 xl:col-span-4">
                    <button type="submit" @disabled($isReadOnly) class="sk-btn sk-btn-primary">{{ __('production_bench.inventory.add_lot') }}</button>
                </div>
            </form>
        </section>
        @endif

        @if ($mode !== 'stock')
        <section aria-labelledby="production-forecast-heading" class="sk-card overflow-hidden">
            <div class="border-b border-[var(--color-line)] p-6">
                <h2 id="production-forecast-heading" class="text-xl font-semibold text-[var(--color-ink-strong)]">{{ $mode === 'requirements' ? __('production_bench.inventory.requirements_title') : __('production_bench.inventory.production_forecast') }}</h2>
                <p class="mt-1 text-sm text-[var(--color-ink-soft)]">{{ $mode === 'requirements' ? __('production_bench.inventory.requirements_help') : __('production_bench.inventory.production_forecast_help') }}</p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[720px] text-left text-sm">
                    <thead class="bg-[var(--color-panel-muted)] text-xs uppercase tracking-wide text-[var(--color-ink-soft)]">
                        <tr>
                            <th class="px-5 py-3">{{ __('production_bench.inventory.item_lot') }}</th>
                            @if ($mode === 'requirements')
                                <th class="px-4 py-3 text-right">{{ __('production_bench.inventory.required') }}</th>
                                <th class="px-4 py-3 text-right">{{ __('production_bench.inventory.reserved') }}</th>
                            @endif
                            <th class="px-4 py-3 text-right">{{ __('production_bench.inventory.available') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('production_bench.inventory.incoming') }}</th>
                            <th class="px-5 py-3 text-right">{{ __('production_bench.inventory.forecast') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--color-line)]">
                        @forelse ($forecast as $row)
                            @php($subject = $row['subject'])
                            <tr>
                                <td class="px-5 py-4">
                                    <p class="font-medium text-[var(--color-ink-strong)]">{{ $subject instanceof \App\Models\Ingredient ? $subject->localizedDisplayName() : $subject->name }}</p>
                                    <p class="mt-1 text-xs text-[var(--color-ink-soft)]">{{ $row['display_unit'] }}</p>
                                </td>
                                @if ($mode === 'requirements')
                                    <td class="px-4 py-4 text-right font-mono tabular-nums">{{ $row['required'] }}</td>
                                    <td class="px-4 py-4 text-right font-mono tabular-nums">{{ $row['positions']['reserved'] }}</td>
                                @endif
                                <td class="px-4 py-4 text-right font-mono tabular-nums">{{ $row['positions']['available'] }}</td>
                                <td class="px-4 py-4 text-right font-mono tabular-nums">{{ $row['positions']['incoming'] }}</td>
                                <td class="px-5 py-4 text-right font-mono font-semibold tabular-nums">{{ $row['positions']['forecast'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="{{ $mode === 'requirements' ? 6 : 4 }}" class="px-6 py-10 text-center text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.inventory.no_forecast') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
        @endif

        @if ($mode !== 'requirements')
        <section aria-labelledby="inventory-positions-heading" class="overflow-hidden rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)]">
            <div class="flex items-end justify-between gap-4 border-b border-[var(--color-line)] p-6">
                <h2 id="inventory-positions-heading" class="text-xl font-semibold">{{ __('production_bench.inventory.stock_positions') }}</h2>
                <p class="text-xs text-[var(--color-ink-muted)]">{{ __('production_bench.inventory.mass_shown', ['unit' => $displayUnit]) }}</p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[980px] text-left text-sm">
                    <thead class="bg-[var(--color-panel-muted)] text-xs uppercase tracking-wide text-[var(--color-ink-soft)]">
                        <tr>
                            <th class="px-5 py-3">{{ __('production_bench.inventory.item_lot') }}</th><th class="px-4 py-3">{{ __('production_bench.common.status') }}</th><th class="px-4 py-3 text-right">{{ __('production_bench.inventory.physical') }}</th><th class="px-4 py-3 text-right">{{ __('production_bench.inventory.quarantined') }}</th><th class="px-4 py-3 text-right">{{ __('production_bench.inventory.reserved') }}</th><th class="px-4 py-3 text-right">{{ __('production_bench.inventory.available') }}</th><th class="px-4 py-3 text-right">{{ __('production_bench.inventory.incoming') }}</th><th class="px-4 py-3 text-right">{{ __('production_bench.inventory.forecast') }}</th><th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--color-line)]">
                        @forelse ($lots as $row)
                            @php($lot = $row['lot'])
                            <tr id="lot-{{ $lot->public_id }}">
                                <td class="px-5 py-4">
                                    <p class="font-medium text-[var(--color-ink-strong)]">{{ $lot->subjectName() }}</p>
                                    <p class="mt-1 font-mono text-xs text-[var(--color-ink-soft)]">{{ $lot->internal_lot_code }} @if($lot->supplier_batch_number) · {{ $lot->supplier_batch_number }} @endif</p>
                                    @if($lot->goodsReceiptLine?->goodsReceipt)
                                        @php($originReceipt = $lot->goodsReceiptLine->goodsReceipt)
                                        <p class="mt-2 text-xs text-[var(--color-ink-soft)]">
                                            <a href="{{ route('production-bench.purchasing.receipts.show', $originReceipt) }}" wire:navigate class="inline-flex min-h-11 items-center font-medium text-[var(--color-accent-strong)] hover:underline">{{ __('production_bench.inventory.receipt_origin') }}</a>
                                            · {{ $originReceipt->source->value === 'direct' ? __('production_bench.receipt.direct_source') : __('production_bench.receipt.order_source') }}
                                            · {{ $originReceipt->supplier->name }}
                                            · <span class="numeric">{{ $originReceipt->received_at->format('Y-m-d') }}</span>
                                        </p>
                                    @endif
                                </td>
                                <td class="px-4 py-4"><span class="rounded-full px-2.5 py-1 text-xs font-medium {{ $lot->status->value === 'released' ? 'bg-[var(--color-success-soft)] text-[var(--color-success-strong)]' : 'bg-[var(--color-warning-soft)] text-[var(--color-warning-strong)]' }}">{{ $lot->status->value === 'released' ? __('production_bench.inventory.released') : __('production_bench.inventory.quarantined') }}</span></td>
                                @foreach (['physical', 'quarantined', 'reserved', 'available', 'incoming', 'forecast'] as $position)
                                    <td class="px-4 py-4 text-right font-mono tabular-nums">{{ $row['positions'][$position] }}</td>
                                @endforeach
                                <td class="px-5 py-4 text-right">
                                    @if (! $isReadOnly)
                                        <button wire:click="{{ $lot->status->value === 'released' ? 'quarantine' : 'release' }}({{ $lot->id }})" wire:loading.attr="disabled" type="button" class="inline-flex min-h-11 items-center px-2 text-xs font-medium text-[var(--color-accent)]">{{ $lot->status->value === 'released' ? __('production_bench.inventory.quarantine') : __('production_bench.inventory.release') }}</button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="px-6 py-12 text-center text-[var(--color-ink-soft)]">{{ __('production_bench.inventory.no_lots') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
        @endif
    @endif
</x-production-bench.page>
