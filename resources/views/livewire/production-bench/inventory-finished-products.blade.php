<x-production-bench.page active="inventory" subnavigation="finished-products">
    @if (! $isActive && ! $isReadOnly)
        <section class="sk-card p-8 text-center">
            <h1 class="text-3xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.common.inactive') }}</h1>
            <a href="{{ route('production-bench.home') }}" wire:navigate class="mt-4 inline-block rounded-full px-4 py-2 text-sm font-medium text-[var(--color-accent-strong)]">{{ __('production_bench.title') }}</a>
        </section>
    @else
        @if ($isReadOnly)
            <p role="status" class="rounded-xl bg-[var(--color-warning-soft)] px-4 py-3 text-sm text-[var(--color-warning-strong)]">{{ __('production_bench.common.read_only') }}</p>
        @endif

        <script type="application/json" data-contextual-help-scope>{!! \Illuminate\Support\Js::encode($contextualHelp) !!}</script>
        <header class="flex flex-wrap items-center justify-between gap-3">
            <div data-contextual-help-heading class="flex flex-wrap items-center gap-x-3 gap-y-1">
                <h1 id="finished-products-heading" class="text-3xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.inventory.finished_products') }}</h1>
                <x-contextual-help.index-button :help="$contextualHelp" tab="inventory" />
            </div>
            <a href="{{ route('production-bench.production.index', ['date_basis' => 'manufactured']) }}" wire:navigate class="sk-btn sk-btn-secondary">{{ __('production_bench.production.production_history') }}</a>
        </header>

        <section aria-label="{{ __('production_bench.common.search') }}" class="sk-card grid gap-4 p-5 sm:grid-cols-2 lg:grid-cols-4">
            <label class="space-y-2 sm:col-span-2">
                <span class="text-sm font-medium">{{ __('production_bench.common.search') }}</span>
                <input type="search" autocomplete="off" wire:model.live.debounce.300ms="search" class="sk-input w-full" placeholder="{{ __('production_bench.inventory.finished_products_search') }}">
            </label>
            @include('livewire.production-bench.production.organization-filters')
        </section>

        @if ($finishedStock->total())
            @include('livewire.production-bench.inventory-finished-stock-table', ['showStockHeading' => false])
        @else
            <p class="sk-card px-5 py-8 text-sm text-[var(--color-ink-soft)]">
                {{ __($search !== '' || $brandFilter !== '' || $collectionFilter !== '' ? 'production_bench.inventory.finished_products_no_matches' : 'production_bench.inventory.finished_products_empty') }}
            </p>
        @endif
    @endif
    <x-filament-actions::modals />
</x-production-bench.page>
