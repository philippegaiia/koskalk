<x-production-bench.page active="purchasing" subnavigation="suppliers">
    <script type="application/json" data-contextual-help-scope>{!! \Illuminate\Support\Js::encode($contextualHelp) !!}</script>
    <header class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div data-contextual-help-heading class="flex flex-wrap items-center gap-x-3 gap-y-1">
            <h1 class="flex-1 text-3xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.supplier.new') }}</h1>
            <x-contextual-help.index-button :help="$contextualHelp" tab="purchasing" />
        </div>

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
