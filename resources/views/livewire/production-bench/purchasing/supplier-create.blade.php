<x-production-bench.page active="purchasing" subnavigation="suppliers">
    <header>
        <h1 class="text-3xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.supplier.new') }}</h1>
    </header>

    <form wire:submit="save" class="space-y-4 pb-24">
        {{ $this->form }}

        @error('data.production_bench') <p class="text-sm text-[var(--color-danger-strong)]">{{ $message }}</p> @enderror

        <x-workflow-action-bar data-production-bench-save-bar>
            <a href="{{ route('production-bench.purchasing.suppliers') }}" wire:navigate class="sk-btn sk-btn-ghost">
                {{ __('production_bench.common.cancel') }}
            </a>
            <button type="submit" class="sk-btn sk-btn-primary" wire:loading.attr="disabled" wire:target="save">
                {{ __('production_bench.supplier.save') }}
            </button>
        </x-workflow-action-bar>
    </form>
</x-production-bench.page>
