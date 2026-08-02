<x-production-bench.page purchasing compact>
    <header>
        <div>
            @if ($lockedSupplier)
                <p class="sk-eyebrow">{{ $lockedSupplier->code }} · {{ $lockedSupplier->name }}</p>
            @endif
            <h1 @class(['mt-2' => $lockedSupplier, 'text-3xl font-semibold text-[var(--color-ink-strong)]'])>{{ $editingListingPublicId ? __('production_bench.listing.edit') : __('production_bench.listing.new') }}</h1>
        </div>
    </header>

    <form wire:submit="save" class="space-y-4 pb-24">
        {{ $this->form }}

        @error('data.production_bench') <p class="text-sm text-[var(--color-danger-strong)]">{{ $message }}</p> @enderror

        <x-workflow-action-bar data-production-bench-save-bar>
            @if ($editingListingPublicId)
                <x-slot:leading>
                    <button type="button" wire:click="delete" wire:confirm="{{ __('production_bench.listing.delete_confirm') }}" class="sk-btn sk-btn-danger" wire:loading.attr="disabled" wire:target="delete">
                        {{ __('production_bench.listing.delete') }}
                    </button>
                </x-slot:leading>
            @endif

            <a href="{{ $lockedSupplier ? route('production-bench.purchasing.supplier', $lockedSupplier) : route('production-bench.purchasing.listings') }}" wire:navigate class="sk-btn sk-btn-ghost">
                {{ __('production_bench.common.cancel') }}
            </a>
            <button type="submit" class="sk-btn sk-btn-primary" wire:loading.attr="disabled" wire:target="save">
                {{ $editingListingPublicId ? __('production_bench.listing.save_changes') : __('production_bench.listing.save') }}
            </button>
        </x-workflow-action-bar>
    </form>
</x-production-bench.page>
