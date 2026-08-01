<div class="mx-auto max-w-7xl space-y-8">
    <x-production-bench.navigation />

    @if (! $isActive && ! $isReadOnly)
        <section class="sk-card p-8 text-center">
            <h1 class="text-3xl font-semibold text-[var(--color-ink-strong)]">Inactive</h1>
            <a href="{{ route('production-bench.home') }}" wire:navigate class="mt-4 inline-block text-sm font-medium text-[var(--color-accent)]">Production Bench</a>
        </section>
    @else
        @if ($isReadOnly)
            <p role="status" class="rounded-xl bg-[var(--color-warning-soft)] px-4 py-3 text-sm text-[var(--color-warning-strong)]">Read-only. Resume to edit.</p>
        @endif

        <header>
            <h1 class="text-3xl font-semibold text-[var(--color-ink-strong)]">Inventory</h1>
            <p class="mt-3 max-w-2xl text-sm leading-6 text-[var(--color-ink-soft)]">Physical includes quarantined stock. Available excludes quarantined and reserved stock. Negative balances are allowed.</p>
        </header>

        <section class="sk-card overflow-hidden">
            <div class="border-b border-[var(--color-line)] p-6">
                <h2 class="text-xl font-semibold text-[var(--color-ink-strong)]">Opening stock</h2>
                @if ($savedLotCode)
                    <p role="status" class="mt-3 text-sm font-medium text-[var(--color-success-strong)]">Lot <span class="font-mono">{{ $savedLotCode }}</span> created.</p>
                @endif
            </div>

            <form wire:submit="createOpeningStock" class="grid gap-5 p-6 md:grid-cols-2 xl:grid-cols-4">
                <label class="space-y-2">
                    <span class="text-sm font-medium">Stock type</span>
                    <select wire:model.live="subjectType" @disabled($isReadOnly) class="sk-input w-full">
                        <option value="ingredient">Ingredient</option>
                        <option value="packaging">Packaging</option>
                    </select>
                </label>

                <label class="space-y-2 md:col-span-1 xl:col-span-2">
                    <span class="text-sm font-medium">{{ $subjectType === 'ingredient' ? 'Ingredient' : 'Packaging item' }}</span>
                    <select wire:model="subjectId" required @disabled($isReadOnly) class="sk-input w-full">
                        <option value="">Choose…</option>
                        @foreach ($subjectType === 'ingredient' ? $ingredients : $packagingItems as $subject)
                            <option value="{{ $subject->id }}">{{ $subjectType === 'ingredient' ? $subject->localizedDisplayName() : $subject->name }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="space-y-2">
                    <span class="text-sm font-medium">Quantity</span>
                    <div class="flex gap-2">
                        <input wire:model="quantity" inputmode="decimal" required @disabled($isReadOnly) class="sk-input min-w-0 flex-1 font-mono">
                        <select wire:model="unit" @disabled($isReadOnly || $subjectType === 'packaging') class="sk-input w-24">
                            @if ($subjectType === 'ingredient')
                                <option>g</option><option>kg</option><option>oz</option><option>lb</option>
                            @else
                                <option value="count">units</option>
                            @endif
                        </select>
                    </div>
                    @error('quantity') <span class="text-xs text-[var(--color-danger-strong)]">{{ $message }}</span> @enderror
                </label>

                <label class="space-y-2">
                    <span class="text-sm font-medium">Status</span>
                    <select wire:model="status" @disabled($isReadOnly) class="sk-input w-full">
                        <option value="released">Released · available</option>
                        <option value="quarantined">Quarantined · unavailable</option>
                    </select>
                </label>

                <label class="space-y-2">
                    <span class="text-sm font-medium">Supplier batch <span class="font-normal text-[var(--color-ink-muted)]">optional</span></span>
                    <input wire:model="supplierBatchNumber" @disabled($isReadOnly) class="sk-input w-full">
                </label>

                <label class="space-y-2">
                    <span class="text-sm font-medium">Stocked on</span>
                    <input wire:model="stockedAt" type="date" @disabled($isReadOnly) class="sk-input w-full">
                </label>

                <label class="space-y-2">
                    <span class="text-sm font-medium">Expires on</span>
                    <input wire:model="expiresAt" type="date" @disabled($isReadOnly) class="sk-input w-full">
                </label>

                <label class="flex items-start gap-3 md:col-span-2">
                    <input wire:model="provenanceComplete" type="checkbox" @disabled($isReadOnly) class="mt-1">
                    <span><span class="block text-sm font-medium">Provenance complete</span><span class="text-xs text-[var(--color-ink-soft)]">Supplier and batch are known.</span></span>
                </label>

                <label class="space-y-2 md:col-span-2">
                    <span class="text-sm font-medium">Notes</span>
                    <input wire:model="notes" @disabled($isReadOnly) class="sk-input w-full">
                </label>

                <div class="md:col-span-2 xl:col-span-4">
                    <button type="submit" @disabled($isReadOnly) class="rounded-full bg-[var(--color-accent)] px-5 py-2.5 text-sm font-medium text-white disabled:opacity-40">Add lot</button>
                </div>
            </form>
        </section>

        <section class="overflow-hidden rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)]">
            <div class="flex items-end justify-between gap-4 border-b border-[var(--color-line)] p-6">
                <h2 class="text-xl font-semibold">Stock positions</h2>
                <p class="text-xs text-[var(--color-ink-muted)]">Mass shown in {{ $displayUnit }}</p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[980px] text-left text-sm">
                    <thead class="bg-[var(--color-panel-muted)] text-xs uppercase tracking-wide text-[var(--color-ink-soft)]">
                        <tr>
                            <th class="px-5 py-3">Item / lot</th><th class="px-4 py-3">Status</th><th class="px-4 py-3 text-right">Physical</th><th class="px-4 py-3 text-right">Quarantined</th><th class="px-4 py-3 text-right">Reserved</th><th class="px-4 py-3 text-right">Available</th><th class="px-4 py-3 text-right">Incoming</th><th class="px-4 py-3 text-right">Forecast</th><th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--color-line)]">
                        @forelse ($lots as $row)
                            @php($lot = $row['lot'])
                            <tr>
                                <td class="px-5 py-4"><p class="font-medium text-[var(--color-ink-strong)]">{{ $lot->subjectName() }}</p><p class="mt-1 font-mono text-xs text-[var(--color-ink-soft)]">{{ $lot->internal_lot_code }} @if($lot->supplier_batch_number) · {{ $lot->supplier_batch_number }} @endif</p></td>
                                <td class="px-4 py-4"><span class="rounded-full px-2.5 py-1 text-xs font-medium {{ $lot->status->value === 'released' ? 'bg-[var(--color-success-soft)] text-[var(--color-success-strong)]' : 'bg-[var(--color-warning-soft)] text-[var(--color-warning-strong)]' }}">{{ ucfirst($lot->status->value) }}</span></td>
                                @foreach (['physical', 'quarantined', 'reserved', 'available', 'incoming', 'forecast'] as $position)
                                    <td class="px-4 py-4 text-right font-mono tabular-nums">{{ $row['positions'][$position] }}</td>
                                @endforeach
                                <td class="px-5 py-4 text-right">
                                    @if (! $isReadOnly)
                                        <button wire:click="{{ $lot->status->value === 'released' ? 'quarantine' : 'release' }}({{ $lot->id }})" type="button" class="text-xs font-medium text-[var(--color-accent)]">{{ $lot->status->value === 'released' ? 'Quarantine' : 'Release' }}</button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="px-6 py-12 text-center text-[var(--color-ink-soft)]">No lots.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</div>
