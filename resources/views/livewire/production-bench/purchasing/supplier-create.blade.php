<div class="mx-auto w-full max-w-5xl space-y-6">
    <x-production-bench.navigation />
    <x-production-bench.purchasing-navigation />

    <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <h1 class="text-3xl font-semibold text-[var(--color-ink-strong)]">New supplier</h1>
        <a href="{{ route('production-bench.purchasing.suppliers') }}" wire:navigate class="text-sm font-medium text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)]">Cancel</a>
    </header>

    <form wire:submit="save" class="space-y-4">
        {{ $this->form }}

        @error('data.production_bench') <p class="text-sm text-[var(--color-danger-strong)]">{{ $message }}</p> @enderror
        <div class="flex flex-wrap items-center gap-4">
            <button type="submit" class="sk-btn sk-btn-primary" wire:loading.attr="disabled" wire:target="save">Save supplier</button>
            <a href="{{ route('production-bench.purchasing.suppliers') }}" wire:navigate class="text-sm font-medium text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)]">Cancel</a>
        </div>
    </form>
</div>
