<x-production-bench.page purchasing compact>
    <header>
        <h1 class="text-3xl font-semibold text-[var(--color-ink-strong)]">New supplier</h1>
    </header>

    <form wire:submit="save" class="space-y-4 pb-24">
        {{ $this->form }}

        @error('data.production_bench') <p class="text-sm text-[var(--color-danger-strong)]">{{ $message }}</p> @enderror

        <div
            data-production-bench-save-bar
            class="pointer-events-none fixed bottom-0 left-0 right-0 z-10 px-4 pb-[max(1rem,env(safe-area-inset-bottom))] lg:left-[var(--app-sidebar-width,0rem)]"
        >
            <div class="mx-auto flex max-w-5xl items-center justify-end gap-4">
                <a href="{{ route('production-bench.purchasing.suppliers') }}" wire:navigate class="pointer-events-auto text-sm font-medium text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)]">Cancel</a>
                <button type="submit" class="pointer-events-auto sk-btn sk-btn-primary shadow-[0_8px_20px_rgba(60,50,30,0.16)]" wire:loading.attr="disabled" wire:target="save">Save supplier</button>
            </div>
        </div>
    </form>
</x-production-bench.page>
