<x-production-bench.page active="inventory" subnavigation="materials">
    @if (! $isActive && ! $isReadOnly)
        <section class="sk-card p-8 text-center">
            <h1 class="text-3xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.common.inactive') }}</h1>
            <a href="{{ route('production-bench.home') }}" wire:navigate class="mt-4 inline-block text-sm font-medium text-[var(--color-accent)]">{{ __('production_bench.title') }}</a>
        </section>
    @else
        @if ($isReadOnly)
            <p role="status" class="rounded-xl bg-[var(--color-warning-soft)] px-4 py-3 text-sm text-[var(--color-warning-strong)]">{{ __('production_bench.common.read_only') }}</p>
        @endif

        <header class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <a href="{{ route('production-bench.inventory') }}" wire:navigate class="text-sm font-medium text-[var(--color-accent-strong)] hover:underline">← {{ __('production_bench.inventory.stock_by_material') }}</a>
                <h1 class="mt-3 text-3xl font-semibold text-[var(--color-ink-strong)]">{{ $materialName }}</h1>
                @if ($materialCode)<p class="mt-1 font-mono text-sm text-[var(--color-ink-soft)]">{{ $materialCode }}</p>@endif
            </div>
            <a href="{{ $lotRegisterUrl }}" wire:navigate class="sk-btn sk-btn-outline shrink-0">{{ __('production_bench.inventory.view_all_lots') }}</a>
        </header>

        <section class="sk-card overflow-hidden" aria-labelledby="current-position-heading">
            <div class="border-b border-[var(--color-line)] px-5 py-4">
                <h2 id="current-position-heading" class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.inventory.current_position') }}</h2>
                <p class="mt-1 text-xs text-[var(--color-ink-soft)]">{{ __('production_bench.inventory.current_position_help', ['unit' => $displayUnit]) }}</p>
            </div>
            <dl class="grid grid-cols-2 divide-x divide-y divide-[var(--color-line)] sm:grid-cols-4 lg:grid-cols-7 lg:divide-y-0">
                @foreach (['physical', 'available', 'reserved', 'quarantined', 'incoming', 'required', 'forecast'] as $key)
                    <div class="px-5 py-4">
                        <dt class="text-xs font-medium text-[var(--color-ink-soft)]">{{ __('production_bench.inventory.'.$key) }}</dt>
                        <dd class="numeric mt-1 text-lg font-semibold {{ $key === 'forecast' && str_starts_with($position[$key], '-') ? 'text-[var(--color-danger-strong)]' : 'text-[var(--color-ink-strong)]' }}">{{ $position[$key] }}</dd>
                    </div>
                @endforeach
            </dl>
        </section>

        <section class="sk-card p-5" aria-labelledby="buffer-heading">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <h2 id="buffer-heading" class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.inventory.buffer_stock') }}</h2>
                    <p class="mt-1 max-w-2xl text-sm leading-6 text-[var(--color-ink-soft)]">{{ __('production_bench.inventory.buffer_stock_help') }}</p>
                    @if ($buffer !== null)
                        <p class="mt-2 text-sm {{ $bufferBelow ? 'text-[var(--color-warning-strong)]' : 'text-[var(--color-ink-soft)]' }}">
                            {{ $bufferBelow ? __('production_bench.inventory.below_buffer_detail') : __('production_bench.inventory.above_buffer_detail') }}
                            <span class="numeric font-semibold">{{ $buffer }} {{ $displayUnit }}</span>
                        </p>
                    @endif
                </div>
                <form wire:submit="saveBuffer" class="flex w-full max-w-md items-end gap-2 lg:w-auto">
                    <label class="sk-field min-w-0 flex-1">
                        <span>{{ __('production_bench.inventory.buffer_stock') }} ({{ $displayUnit }})</span>
                        <input wire:model="bufferQuantity" type="text" inputmode="decimal" placeholder="{{ __('production_bench.inventory.buffer_none') }}" class="sk-field-control numeric" @disabled($isReadOnly) />
                    </label>
                    <button type="submit" class="sk-btn sk-btn-primary shrink-0" @disabled($isReadOnly)>{{ __('production_bench.inventory.save_buffer') }}</button>
                </form>
            </div>
        </section>

        <section class="sk-card overflow-hidden" aria-labelledby="open-lots-heading">
            <div class="flex flex-col gap-3 border-b border-[var(--color-line)] px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 id="open-lots-heading" class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.inventory.open_lots') }}</h2>
                    <p class="mt-1 text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.inventory.open_lots_help') }}</p>
                </div>
                <a href="{{ $lotRegisterUrl }}" wire:navigate class="text-sm font-medium text-[var(--color-accent-strong)] hover:underline">{{ __('production_bench.inventory.view_all_lots') }} →</a>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[900px] text-left text-sm">
                    <thead class="bg-[var(--color-panel-muted)] text-xs uppercase tracking-wide text-[var(--color-ink-soft)]">
                        <tr>
                            <th class="px-5 py-3">{{ __('production_bench.inventory.item_lot') }}</th>
                            <th class="px-4 py-3">{{ __('production_bench.inventory.lot_supplier') }}</th>
                            <th class="px-4 py-3">{{ __('production_bench.common.status') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('production_bench.inventory.physical') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('production_bench.inventory.reserved') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('production_bench.inventory.available') }}</th>
                            <th class="px-5 py-3">{{ __('production_bench.inventory.stocked_on') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--color-line)]">
                        @forelse ($openLots as $row)
                            @php($lot = $row['lot'])
                            @php($supplier = $lot->goodsReceiptLine?->goodsReceipt?->supplier ?? $lot->supplierListing?->supplier)
                            <tr wire:key="material-open-lot-{{ $lot->id }}">
                                <td class="px-5 py-3">
                                    <p class="font-mono text-sm text-[var(--color-ink-strong)]">{{ $lot->internal_lot_code }}</p>
                                    @if ($lot->supplier_batch_number)<p class="mt-0.5 text-xs text-[var(--color-ink-soft)]">{{ __('production_bench.inventory.supplier_batch') }}: {{ $lot->supplier_batch_number }}</p>@endif
                                    @if ($lot->expires_at)<p class="mt-0.5 text-xs text-[var(--color-ink-soft)]">{{ __('production_bench.inventory.expires_on') }}: {{ $lot->expires_at->format('Y-m-d') }}</p>@endif
                                </td>
                                <td class="px-4 py-3 text-[var(--color-ink-soft)]">{{ $supplier?->name ?? __('production_bench.inventory.supplier_unknown') }}</td>
                                <td class="px-4 py-3"><span class="rounded-full px-2.5 py-1 text-xs font-medium {{ $lot->status->value === 'released' ? 'bg-[var(--color-success-soft)] text-[var(--color-success-strong)]' : 'bg-[var(--color-warning-soft)] text-[var(--color-warning-strong)]' }}">{{ $lot->status->value === 'released' ? __('production_bench.inventory.released') : __('production_bench.inventory.quarantined') }}</span></td>
                                <td class="numeric px-4 py-3 text-right">{{ $row['positions']['physical'] }}</td>
                                <td class="numeric px-4 py-3 text-right">{{ $row['positions']['reserved'] }}</td>
                                <td class="numeric px-4 py-3 text-right">{{ $row['positions']['available'] }}</td>
                                <td class="numeric px-5 py-3 text-[var(--color-ink-soft)]">{{ $lot->stocked_at->format('Y-m-d') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-6 py-8 text-center text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.inventory.no_open_lots') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="sk-card overflow-hidden" aria-labelledby="activity-heading">
            <div class="border-b border-[var(--color-line)] px-5 py-4">
                <h2 id="activity-heading" class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.inventory.period_activity') }}</h2>
                <p class="mt-1 text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.inventory.period_activity_help') }}</p>
            </div>
            <div class="flex flex-col gap-3 border-b border-[var(--color-line)] p-4 sm:flex-row sm:items-end">
                <label class="sk-field sm:max-w-xs">
                    <span>{{ __('production_bench.inventory.period') }}</span>
                    <select wire:model.live="periodPreset" class="sk-select-control">
                        <option value="30">{{ __('production_bench.inventory.last_30_days') }}</option>
                        <option value="365">{{ __('production_bench.inventory.last_365_days') }}</option>
                        <option value="custom">{{ __('production_bench.inventory.custom_period') }}</option>
                    </select>
                </label>
                @if ($periodPreset === 'custom')
                    <label class="sk-field">
                        <span>{{ __('production_bench.inventory.from') }}</span>
                        <input wire:model.live="customFrom" type="date" class="sk-field-control" aria-invalid="{{ $errors->has('customFrom') ? 'true' : 'false' }}" />
                        @error('customFrom')<span class="text-xs text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror
                    </label>
                    <label class="sk-field">
                        <span>{{ __('production_bench.inventory.to') }}</span>
                        <input wire:model.live="customTo" type="date" class="sk-field-control" aria-invalid="{{ $errors->has('customTo') ? 'true' : 'false' }}" />
                        @error('customTo')<span class="text-xs text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror
                    </label>
                @endif
                <p class="text-xs text-[var(--color-ink-soft)]">{{ $periodLabel }}</p>
            </div>
            <dl class="grid grid-cols-2 divide-x divide-y divide-[var(--color-line)] sm:grid-cols-4 lg:grid-cols-8 lg:divide-y-0">
                @foreach (['opening_physical', 'received', 'production_consumed', 'other_inbound', 'other_outbound', 'adjustments', 'net_change', 'closing_physical'] as $key)
                    <div class="px-5 py-4">
                        <dt class="text-xs font-medium text-[var(--color-ink-soft)]">{{ __('production_bench.inventory.'.$key) }}</dt>
                        <dd class="numeric mt-1 text-base font-semibold text-[var(--color-ink-strong)]">{{ $activity[$key] }}</dd>
                    </div>
                @endforeach
            </dl>
            <div class="border-t border-[var(--color-line)] bg-[var(--color-panel-muted)] px-5 py-4 text-sm">
                <p class="font-medium text-[var(--color-ink-strong)]">{{ __('production_bench.inventory.reconciliation') }}</p>
                <p class="numeric mt-2 text-[var(--color-ink-soft)]">{{ $activity['opening_physical'] }} + {{ $activity['received'] }} + {{ $activity['other_inbound'] }} − {{ $activity['production_consumed'] }} − {{ $activity['other_outbound'] }} + {{ $activity['adjustments'] }} = {{ $activity['closing_physical'] }}</p>
                <p class="mt-2 text-xs {{ $activity['reconciliation_ok'] ? 'text-[var(--color-success-strong)]' : 'text-[var(--color-danger-strong)]' }}">{{ __('production_bench.inventory.reconciliation_delta', ['delta' => $activity['reconciliation_delta']]) }}</p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[760px] text-left text-sm">
                    <thead class="bg-[var(--color-panel-muted)] text-xs uppercase tracking-wide text-[var(--color-ink-soft)]">
                        <tr>
                            <th class="px-5 py-3">{{ __('production_bench.inventory.date') }}</th>
                            <th class="px-4 py-3">{{ __('production_bench.inventory.activity_group') }}</th>
                            <th class="px-4 py-3">{{ __('production_bench.inventory.activity_type') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('production_bench.inventory.quantity_delta') }}</th>
                            <th class="px-5 py-3">{{ __('production_bench.inventory.source') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--color-line)]">
                        @forelse ($activity['movements'] as $entry)
                            @php($movement = $entry['movement'])
                            @php($sourceUrl = $this->sourceUrl($movement))
                            @php($sourceLabel = $this->sourceLabel($movement))
                            <tr wire:key="material-activity-{{ $movement->id }}">
                                <td class="numeric px-5 py-3 text-[var(--color-ink-soft)]">{{ $movement->occurred_at?->format('Y-m-d H:i') }}</td>
                                <td class="px-4 py-3 text-[var(--color-ink-soft)]">{{ $this->groupLabel($entry['group']) }}</td>
                                <td class="px-4 py-3 text-[var(--color-ink-soft)]">{{ $this->movementTypeLabel($movement->type) }}</td>
                                <td class="numeric px-4 py-3 text-right {{ str_starts_with($entry['quantity_delta'], '-') ? 'text-[var(--color-danger-strong)]' : 'text-[var(--color-ink-strong)]' }}">{{ $entry['quantity_delta'] }}</td>
                                <td class="px-5 py-3">@if ($sourceUrl)<a href="{{ $sourceUrl }}" wire:navigate class="font-medium text-[var(--color-accent-strong)] hover:underline">{{ $sourceLabel }}</a>@else<span class="text-[var(--color-ink-soft)]">{{ __('production_bench.inventory.source_not_available') }}</span>@endif</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-6 py-8 text-center text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.inventory.no_material_activity') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</x-production-bench.page>
