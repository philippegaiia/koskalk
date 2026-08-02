<x-production-bench.page purchasing compact>
    <header>
        <p class="sk-eyebrow">{{ $supplier->code }}</p>
        <h1 class="mt-2 text-3xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.supplier.edit') }}</h1>
    </header>

    <form wire:submit="save" class="space-y-4 pb-24">
        {{ $this->form }}

        @error('data.production_bench') <p class="text-sm text-[var(--color-danger-strong)]">{{ $message }}</p> @enderror

        <x-workflow-action-bar data-production-bench-save-bar>
            <x-slot:leading>
                <button type="button" wire:click="delete" wire:confirm="{{ __('production_bench.supplier.delete_confirm') }}" class="sk-btn sk-btn-danger" wire:loading.attr="disabled" wire:target="delete">
                    {{ __('production_bench.supplier.delete') }}
                </button>
            </x-slot:leading>

            <a href="{{ route('production-bench.purchasing.supplier', $supplier) }}" wire:navigate class="sk-btn sk-btn-ghost">
                {{ __('production_bench.common.cancel') }}
            </a>
            <button type="submit" class="sk-btn sk-btn-primary" wire:loading.attr="disabled" wire:target="save">
                {{ __('production_bench.supplier.save') }}
            </button>
        </x-workflow-action-bar>
    </form>
</x-production-bench.page>
