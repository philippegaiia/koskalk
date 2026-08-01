<x-production-bench.page purchasing compact>
    <header>
        <p class="sk-eyebrow">{{ $supplier->code }}</p>
        <h1 class="mt-2 text-3xl font-semibold text-[var(--color-ink-strong)]">Edit supplier</h1>
    </header>

    <form wire:submit="save" class="space-y-4">
        {{ $this->form }}

        @error('data.production_bench') <p class="text-sm text-[var(--color-danger-strong)]">{{ $message }}</p> @enderror
        <div class="flex flex-wrap items-center gap-4">
            <button type="submit" class="sk-btn sk-btn-primary" wire:loading.attr="disabled" wire:target="save">Save supplier</button>
            <a href="{{ route('production-bench.purchasing.supplier', $supplier) }}" wire:navigate class="text-sm font-medium text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)]">Cancel</a>
        </div>
    </form>
</x-production-bench.page>
