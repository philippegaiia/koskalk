<x-production-bench.page productionSetup>
    <header class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="sk-eyebrow">{{ __('production_bench.navigation.production') }}</p>
            <h1 class="mt-2 text-3xl font-semibold text-[var(--color-ink-strong)]">{{ $editing ? __('production_bench.settings.edit_batch_size') : __('production_bench.settings.new_batch_size') }}</h1>
            <p class="mt-2 max-w-2xl text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.settings.presets_help') }}</p>
        </div>
        <a href="{{ route('production-bench.production.settings.presets') }}" wire:navigate class="sk-btn sk-btn-ghost">{{ __('production_bench.common.cancel') }}</a>
    </header>

    @if ($isReadOnly)
        <p role="status" class="rounded-xl bg-[var(--color-warning-soft)] px-4 py-3 text-sm text-[var(--color-warning-strong)]">{{ __('production_bench.common.read_only') }}</p>
    @endif

    <form wire:submit="save" class="space-y-6">
        <section class="sk-card space-y-5 p-5">
            <div class="grid gap-4 md:grid-cols-3">
                <label class="text-sm md:col-span-3">
                    <span class="font-medium">{{ __('production_bench.settings.preset_name') }}</span>
                    <input wire:model="name" class="sk-input mt-1 w-full" placeholder="SOAP 100G" @disabled(! $isBenchActive || $isReadOnly)>
                    @error('name')<span class="mt-1 block text-xs text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror
                </label>
                <label class="text-sm md:col-span-2">
                    <span class="font-medium">{{ __('production_bench.settings.batch_size') }}</span>
                    <span class="mt-1 flex gap-2">
                        <input wire:model="basisInputValue" type="text" inputmode="decimal" class="sk-input min-w-0 flex-1" placeholder="12" @disabled(! $isBenchActive || $isReadOnly)>
                        <select wire:model="basisInputUnit" class="sk-input w-24" @disabled(! $isBenchActive || $isReadOnly)>@foreach ($massUnits as $unit)<option value="{{ $unit->value }}">{{ $unit->value }}</option>@endforeach</select>
                    </span>
                    <span class="mt-1 block text-xs text-[var(--color-ink-soft)]">{{ __('production_bench.settings.batch_size_help') }}</span>
                    @error('basisInputValue')<span class="mt-1 block text-xs text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror
                </label>
                <label class="text-sm">
                    <span class="font-medium">{{ __('production_bench.settings.expected_units') }}</span>
                    <input wire:model="expectedUnits" type="number" min="1" step="1" inputmode="numeric" class="sk-input mt-1 w-full" placeholder="100" @disabled(! $isBenchActive || $isReadOnly)>
                    @error('expectedUnits')<span class="mt-1 block text-xs text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror
                </label>
            </div>
            <label class="flex items-start gap-3 text-sm">
                <input wire:model="isActive" type="checkbox" class="mt-0.5 size-4 rounded border-[var(--color-line-strong)]" style="accent-color: var(--color-accent);" @disabled(! $isBenchActive || $isReadOnly)>
                <span><span class="font-medium text-[var(--color-ink-strong)]">{{ $isActive ? __('production_bench.common.active') : __('production_bench.common.inactive') }}</span><span class="mt-1 block text-[var(--color-ink-soft)]">{{ __('production_bench.settings.batch_size_active_help') }}</span></span>
            </label>
        </section>

        <section class="sk-card space-y-4 p-5" aria-labelledby="applicable-products-heading">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h2 id="applicable-products-heading" class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.settings.applicable_products') }}</h2>
                    <p class="mt-1 text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.settings.applicable_products_help') }}</p>
                </div>
                <label class="w-full text-sm sm:max-w-xs">
                    <span class="font-medium">{{ __('production_bench.common.search') }}</span>
                    <input wire:model.live.debounce.300ms="productSearch" type="search" class="sk-input mt-1 w-full" placeholder="{{ __('production_bench.settings.search_products') }}">
                </label>
            </div>
            <p class="text-xs text-[var(--color-ink-soft)]">{{ trans_choice('production_bench.settings.products_selected', count($selectedRecipeIds), ['count' => count($selectedRecipeIds)]) }}</p>
            <div class="max-h-[28rem] overflow-y-auto rounded-xl border border-[var(--color-line)]">
                <table class="w-full min-w-[36rem] text-left text-sm">
                    <thead class="sticky top-0 z-10 bg-[var(--color-panel-muted)] text-xs uppercase tracking-wide text-[var(--color-ink-soft)]"><tr><th class="px-4 py-3">{{ __('production_bench.settings.product') }}</th><th class="px-4 py-3 text-center">{{ __('production_bench.settings.applicable') }}</th><th class="px-4 py-3 text-center">{{ __('production_bench.settings.default') }}</th></tr></thead>
                    <tbody class="divide-y divide-[var(--color-line)]">
                        @forelse ($recipes as $recipe)
                            @php($isSelected = in_array((string) $recipe->id, array_map('strval', $selectedRecipeIds), true))
                            <tr wire:key="batch-size-product-{{ $recipe->id }}">
                                <td class="px-4 py-3 font-medium text-[var(--color-ink-strong)]">{{ $recipe->name }}</td>
                                <td class="px-4 py-3 text-center"><input wire:model.live="selectedRecipeIds" type="checkbox" value="{{ $recipe->id }}" class="size-4 rounded border-[var(--color-line-strong)]" style="accent-color: var(--color-accent);" @disabled(! $isBenchActive || $isReadOnly) aria-label="{{ __('production_bench.settings.applicable_product', ['product' => $recipe->name]) }}"></td>
                                <td class="px-4 py-3 text-center"><input wire:model.live="defaultRecipeIds" type="checkbox" value="{{ $recipe->id }}" class="size-4 rounded border-[var(--color-line-strong)]" style="accent-color: var(--color-accent);" @disabled(! $isBenchActive || $isReadOnly) aria-label="{{ __('production_bench.settings.default_product', ['product' => $recipe->name]) }}"></td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="px-4 py-8 text-center text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.settings.no_matching_products') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <x-table-pagination :paginator="$recipes" />
            @error('selectedRecipeIds')<span class="block text-xs text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror
        </section>

        <div class="flex flex-wrap justify-end gap-2">
            <a href="{{ route('production-bench.production.settings.presets') }}" wire:navigate class="sk-btn sk-btn-ghost">{{ __('production_bench.common.cancel') }}</a>
            <button type="submit" class="sk-btn sk-btn-primary" @disabled(! $isBenchActive || $isReadOnly)>{{ $editing ? __('production_bench.common.save_changes') : __('production_bench.settings.add_preset') }}</button>
        </div>
    </form>
</x-production-bench.page>
