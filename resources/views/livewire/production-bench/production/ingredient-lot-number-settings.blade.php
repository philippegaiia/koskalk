<section class="sk-card space-y-5 p-5" aria-labelledby="ingredient-number-heading">
    <div>
        <h2 id="ingredient-number-heading" class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('lot_numbering.title') }}</h2>
        <p class="mt-1 max-w-2xl text-sm text-[var(--color-ink-soft)]">{{ __('lot_numbering.help') }}</p>
    </div>
    <form wire:submit="save" class="space-y-5">
        {{ $this->form }}
        @if (($data['reset_period'] ?? null) !== ($savedData['reset_period'] ?? null))
            <div role="status" class="rounded-xl bg-[var(--color-panel-muted)] p-4">
                <p class="text-sm font-medium text-[var(--color-ink-strong)]">{{ __('lot_numbering.counter_change', ['before' => __('lot_numbering.'.($savedData['reset_period'] ?? 'never')), 'after' => __('lot_numbering.'.($data['reset_period'] ?? 'never')), 'number' => $data['next_number'] ?? '—']) }}</p>
                <p class="mt-2 text-xs text-[var(--color-ink-soft)]">{{ __('lot_numbering.counter_change_help') }}</p>
            </div>
        @endif
        <div class="rounded-xl bg-[var(--color-panel-muted)] p-4">
            <span class="block text-sm font-medium text-[var(--color-ink-strong)]">{{ __('lot_numbering.preview') }}</span>
            <output aria-live="polite" class="mt-1 block break-all font-mono text-lg text-[var(--color-ink-strong)]">{{ $example ?? __('lot_numbering.preview_invalid') }}</output>
            <p class="mt-2 text-xs text-[var(--color-ink-soft)]">{{ __('lot_numbering.preview_context', ['date' => now()->toDateString()]) }}</p>
        </div>
        <div class="flex flex-wrap items-center justify-end gap-3">
            @if ($data != $savedData)<span class="text-sm text-[var(--color-ink-soft)]">{{ __('lot_numbering.unsaved') }}</span>@endif
            <button type="submit" class="sk-btn sk-btn-primary" wire:loading.attr="disabled" wire:target="save" @disabled(! $this->isEditable)>
                <span wire:loading.remove wire:target="save">{{ __('lot_numbering.save') }}</span>
                <span wire:loading wire:target="save">{{ __('lot_numbering.saving') }}</span>
            </button>
        </div>
    </form>
    <x-filament-actions::modals />
</section>
