<section class="sk-card space-y-4 p-5 sm:p-6" aria-labelledby="pack-bulk-heading">
    <div>
        <h2 id="pack-bulk-heading" class="text-xl font-semibold">{{ __('production_bench.packaging.pack_bulk') }} <x-contextual-help.trigger topic="production.package_later" :topics="$contextualHelp['topics']" /></h2>
        <p class="mt-1 text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.packaging.remaining_bulk', ['quantity' => \App\Support\NumberLocale::formatAdaptiveDecimal($remainingBulk, 0, 4, auth()->user()?->number_locale)]) }}</p>
    </div>
    @if ($production->outputLot->status === \App\Enums\StockLotStatus::Quarantined)
        <p class="text-sm">{{ __('production_bench.packaging.release_bulk') }}</p>
    @elseif ($canMutate && $packingSizes->isNotEmpty() && $remainingBulk > 0)
        <form wire:submit="packageBulk" class="space-y-4">
            <fieldset class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4" @disabled(! $editingOwnsLease || $mutationLocked) :disabled="!canWrite || busy || @js($mutationLocked)">
                <label class="space-y-2 text-sm">
                    <span>{{ __('batch_log.fields.product_size') }}</span>
                    <select wire:model.live="packedSizeKey" class="sk-input w-full" required>
                        <option value="">{{ __('batch_log.fields.choose_size') }}</option>
                        @foreach ($packingSizes as $size)
                            <option value="{{ $size->public_id }}">{{ $size->label(auth()->user()?->number_locale) }}{{ $size->archived_at ? ' · '.__('production_bench.packaging.retired_size') : '' }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="space-y-2 text-sm">
                    <span>{{ __('production_bench.packaging.packed_units') }}</span>
                    <input wire:model="packagedUnits" type="number" min="1" step="1" required class="sk-input w-full">
                </label>
                <label class="space-y-2 text-sm">
                    <span>{{ __('production_bench.packaging.bulk_used') }}</span>
                    <input wire:model="packedBulkGrams" type="text" inputmode="decimal" required class="sk-input w-full">
                </label>
                <label class="space-y-2 text-sm">
                    <span>{{ __('production_bench.packaging.lot_code') }}</span>
                    <input wire:model="packedLotCode" type="text" maxlength="{{ \App\Services\Production\PackedLotCodeGenerator::MAX_LENGTH }}" autocomplete="off" spellcheck="false" class="sk-input w-full font-mono" aria-describedby="packed-lot-code-help @error('packedLotCode') packed-lot-code-error @enderror">
                    <span id="packed-lot-code-help" class="block text-xs text-[var(--color-ink-soft)]">{{ __('production_bench.packaging.lot_code_help') }}</span>
                    @error('packedLotCode') <span id="packed-lot-code-error" role="alert" class="block text-xs text-[var(--color-danger-strong)]">{{ $message }}</span> @enderror
                </label>
                @if ($packingComponents->isNotEmpty())
                    <div class="space-y-1 text-sm sm:col-span-2 lg:col-span-4">
                        <p class="font-medium">{{ __('production_bench.filters.packaging') }}</p>
                        <ul class="list-disc pl-5">
                            @foreach ($packingComponents as $component)
                                <li>{{ $component->name }} × {{ \App\Support\NumberLocale::formatAdaptiveDecimal($component->components_per_unit, 0, 3, auth()->user()?->number_locale) }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                <button type="submit" wire:loading.attr="disabled" class="sk-btn sk-btn-primary justify-self-start">{{ __('production_bench.packaging.record_packing') }}</button>
            </fieldset>
            @foreach (['packedSizeKey', 'packagedUnits', 'packedBulkGrams', 'production_editing'] as $field)
                @error($field) <p role="alert" class="text-sm text-[var(--color-danger-strong)]">{{ $message }}</p> @enderror
            @endforeach
        </form>
    @endif
    @error('packing') <p role="alert" class="text-sm text-[var(--color-danger-strong)]">{{ $message }}</p> @enderror
    @if ($production->packagedLots->isNotEmpty())
        <ul class="divide-y divide-[var(--color-line)]">
            @foreach ($production->packagedLots as $lot)
                <li wire:key="packed-lot-{{ $lot->id }}" class="flex flex-wrap items-center justify-between gap-2 py-3 text-sm">
                    <span>{{ $lot->product_size_label_snapshot }}</span>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-mono">{{ $lot->internal_lot_code }}</span>
                        @if ($lot->packing_undone)
                            <span>{{ __('production_bench.packaging.undone') }}</span>
                        @else
                            <span>{{ __('production_bench.packaging.units_remaining', ['count' => (int) $lot->packed_balance]) }}</span>
                            @if ($canMutate && $lot->movements_count === 1)
                                <button type="button" wire:click="undoPacking({{ $lot->id }})" wire:confirm="{{ __('production_bench.packaging.undo_confirm') }}" wire:loading.attr="disabled" :disabled="!canWrite || busy || @js($mutationLocked)" @disabled(! $editingOwnsLease || $mutationLocked) class="sk-btn sk-btn-ghost text-xs">{{ __('production_bench.packaging.undo') }}</button>
                            @endif
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</section>
