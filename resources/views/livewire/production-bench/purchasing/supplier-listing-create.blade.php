<x-production-bench.page purchasing compact>
    <header>
        <div>
            @if ($lockedSupplier)
                <p class="sk-eyebrow">{{ $lockedSupplier->code }} · {{ $lockedSupplier->name }}</p>
            @endif
            <h1 @class(['mt-2' => $lockedSupplier, 'text-3xl font-semibold text-[var(--color-ink-strong)]'])>{{ $editingListingPublicId ? 'Edit' : 'New' }} supplier listing</h1>
        </div>
    </header>

    <form wire:submit="save" class="space-y-4 pb-24">
        @if (! $editingListingPublicId)
            <div class="flex flex-wrap gap-3">
                <a href="{{ route('ingredients.create', array_filter(['return_to' => 'supplier_listing', 'supplier' => $lockedSupplier?->public_id])) }}" wire:navigate class="sk-btn sk-btn-secondary">Create ingredient</a>
                <a href="{{ route('packaging-items.create', array_filter(['return_to' => 'supplier_listing', 'supplier' => $lockedSupplier?->public_id])) }}" wire:navigate class="sk-btn sk-btn-secondary">Create packaging item</a>
            </div>
        @endif

        {{ $this->form }}

        @error('data.production_bench') <p class="text-sm text-[var(--color-danger-strong)]">{{ $message }}</p> @enderror

        <div
            data-production-bench-save-bar
            class="pointer-events-none fixed bottom-0 left-0 right-0 z-10 px-4 pb-[max(1rem,env(safe-area-inset-bottom))] lg:left-[var(--app-sidebar-width,0rem)]"
        >
            <div class="mx-auto flex max-w-5xl items-center justify-end gap-4">
                <a href="{{ $lockedSupplier ? route('production-bench.purchasing.supplier', $lockedSupplier) : route('production-bench.purchasing.listings') }}" wire:navigate class="pointer-events-auto text-sm font-medium text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)]">Cancel</a>
                <button type="submit" class="pointer-events-auto sk-btn sk-btn-primary shadow-[0_8px_20px_rgba(60,50,30,0.16)]" wire:loading.attr="disabled" wire:target="save">{{ $editingListingPublicId ? 'Save changes' : 'Save supplier listing' }}</button>
            </div>
        </div>
    </form>
</x-production-bench.page>
