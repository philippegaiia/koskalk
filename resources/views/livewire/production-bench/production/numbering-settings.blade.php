<x-production-bench.page active="production-setup" subnavigation="numbering">
    <header>
        <p class="sk-eyebrow">{{ __('production_bench.navigation.settings') }}</p>
        <h1 class="mt-2 text-3xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.settings.numbering') }}</h1>
        <p class="mt-2 max-w-2xl text-sm text-[var(--color-ink-soft)]">{{ __('lot_numbering.page_help') }}</p>
    </header>

    @if (! $isEditable)
        <p role="status" class="rounded-xl bg-[var(--color-warning-soft)] px-4 py-3 text-sm text-[var(--color-warning-strong)]">
            {{ $accessMessage }}
        </p>
    @endif

    <form wire:submit="save" class="space-y-6">
        <section class="sk-card space-y-5 p-5" aria-labelledby="permanent-number-heading">
            <div>
                <h2 id="permanent-number-heading" class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('lot_numbering.production_title') }}</h2>
                <p class="mt-1 text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.settings.numbering_future_help') }}</p>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <label class="text-sm">
                    <span class="font-medium">{{ __('production_bench.settings.number_prefix') }}</span>
                    <input wire:model.live="permanentPrefix" class="sk-input mt-1 w-full" autocomplete="off" @readonly(! $isEditable) @error('permanentPrefix') aria-describedby="permanent-prefix-error" @enderror>
                    @error('permanentPrefix')<span id="permanent-prefix-error" class="mt-1 block text-xs text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror
                </label>

                <label class="text-sm">
                    <span class="font-medium">{{ __('production_bench.settings.next_number') }}</span>
                    <input wire:model.live="nextPermanentSerial" type="text" inputmode="numeric" class="sk-input mt-1 w-full" autocomplete="off" @readonly(! $isEditable) @error('nextPermanentSerial') aria-describedby="next-number-error" @enderror>
                    @error('nextPermanentSerial')<span id="next-number-error" class="mt-1 block text-xs text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror
                </label>

                <label class="text-sm">
                    <span class="font-medium">{{ __('lot_numbering.padding') }}</span>
                    <input wire:model.live="permanentPadding" type="text" inputmode="numeric" class="sk-input mt-1 w-full" autocomplete="off" @readonly(! $isEditable) aria-describedby="number-digits-help @error('permanentPadding') number-digits-error @enderror">
                    <span id="number-digits-help" class="mt-1 block text-xs text-[var(--color-ink-soft)]">{{ __('lot_numbering.padding_help') }}</span>
                    @error('permanentPadding')<span id="number-digits-error" class="mt-1 block text-xs text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror
                </label>

                <label class="text-sm">
                    <span class="font-medium">{{ __('production_bench.settings.number_suffix') }}</span>
                    <input wire:model.live="permanentSuffix" class="sk-input mt-1 w-full" autocomplete="off" @readonly(! $isEditable) @error('permanentSuffix') aria-describedby="number-suffix-error" @enderror>
                    @error('permanentSuffix')<span id="number-suffix-error" class="mt-1 block text-xs text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror
                </label>
            </div>

            <div class="rounded-xl bg-[var(--color-panel-muted)] p-4">
                <span class="block text-sm font-medium text-[var(--color-ink-strong)]">{{ __('lot_numbering.preview') }}</span>
                <output aria-live="polite" class="mt-1 block font-mono text-lg text-[var(--color-ink-strong)]">{{ $example }}</output>
            </div>
        </section>

        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 text-sm" aria-label="{{ __('lot_numbering.planning_reference') }}">
            <span class="font-medium">{{ __('lot_numbering.planning_reference') }}</span>
            <span class="font-mono">T{{ str_pad($nextPlanningSerial, 5, '0', STR_PAD_LEFT) }}</span>
            <p class="w-full text-xs text-[var(--color-ink-soft)]">{{ __('production_bench.settings.temporary_counter_help') }}</p>
        </div>

        <div class="flex flex-wrap items-center justify-end gap-3">
            @if ($this->only(['permanentPrefix', 'nextPermanentSerial', 'permanentPadding', 'permanentSuffix']) != $savedSettings)<span class="text-sm text-[var(--color-ink-soft)]">{{ __('lot_numbering.unsaved') }}</span>@endif
            <button type="submit" class="sk-btn sk-btn-primary" wire:loading.attr="disabled" wire:target="save" @disabled(! $isEditable)>
                <span wire:loading.remove wire:target="save">{{ __('lot_numbering.save_production') }}</span>
                <span wire:loading wire:target="save">{{ __('lot_numbering.saving') }}</span>
            </button>
        </div>
    </form>

    <livewire:production-bench.production.ingredient-lot-number-settings />
</x-production-bench.page>
