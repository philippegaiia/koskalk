<div class="mx-auto w-full max-w-app space-y-6">
    <script type="application/json" data-contextual-help-scope>{!! \Illuminate\Support\Js::encode($contextualHelp) !!}</script>
    <section class="sk-card p-5 sm:p-6" aria-label="{{ __('packaging.page.aria_label') }}">
        <div class="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
            <div class="min-w-0 flex-1">
                <p class="sk-eyebrow">{{ __('packaging.page.title') }}</p>
                <div data-contextual-help-heading class="flex flex-wrap items-center gap-x-3 gap-y-1">
                    <h3 class="mt-2 max-w-4xl text-xl font-semibold text-[var(--color-ink-strong)] sm:text-2xl">{{ __('packaging.page.heading') }}</h3>
                    <x-contextual-help.index-button :help="$contextualHelp" tab="materials" />
                </div>
                <p class="mt-2 max-w-3xl text-sm leading-7 text-[var(--color-ink-soft)]">
                    {{ __('packaging.page.intro') }}
                </p>
            </div>

            <a href="{{ route('dashboard') }}" wire:navigate class="sk-btn sk-btn-outline">
                {{ __('packaging.actions.back_to_dashboard') }}
            </a>
        </div>
    </section>

    @if (! $currentUser)
        <section class="sk-card p-8 text-center" aria-label="{{ __('packaging.auth.aria_label') }}">
            <h4 class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('packaging.auth.heading') }}</h4>
            <p class="mt-3 text-sm leading-7 text-[var(--color-ink-soft)]">{{ __('packaging.auth.description') }}</p>
        </section>
    @else
        <section class="overflow-hidden sk-card p-0" aria-label="{{ __('packaging.catalog.table_label') }}">
            <div class="flex flex-col gap-4 border-b border-[var(--color-line)] bg-[var(--color-field-muted)] px-5 py-4">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <p class="text-sm font-medium text-[var(--color-ink-strong)]">{{ __('packaging.catalog.heading') }}</p>
                        <p class="mt-1 text-xs text-[var(--color-ink-soft)]">{{ __('packaging.catalog.description') }}</p>
                    </div>

                    <a href="{{ route('packaging-items.create') }}" wire:navigate class="sk-btn sk-btn-primary justify-center">{{ __('packaging.actions.add') }}</a>
                </div>

                <div class="flex flex-col gap-3 sm:flex-row sm:items-center" aria-label="{{ __('packaging.catalog.filters_label') }}">
                    <label class="sk-field min-w-64">
                        <span class="shrink-0 text-[var(--color-ink-soft)]">{{ __('packaging.search.label') }}</span>
                        <input autocomplete="off" wire:model.live.debounce.250ms="search" type="text" placeholder="{{ __('packaging.search.placeholder') }}" class="sk-field-control" aria-label="{{ __('packaging.search.aria_label') }}" />
                    </label>
                </div>
            </div>

            @if ($items->isEmpty())
                <div class="p-8 text-center">
                    <h4 class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ $search !== '' ? __('packaging.empty.no_matches') : __('packaging.empty.no_items') }}</h4>
                    <p class="mt-3 text-sm leading-7 text-[var(--color-ink-soft)]">{{ __('packaging.empty.description') }}</p>
                    <div class="mt-5">
                        <a href="{{ route('packaging-items.create') }}" wire:navigate class="sk-btn sk-btn-primary">{{ __('packaging.actions.add') }}</a>
                    </div>
                </div>
            @else
                <x-sticky-table-scroll>
                    <table class="sk-table">
                        <thead wire:ignore.self data-sticky-table-header class="relative z-20 whitespace-nowrap shadow-[0_1px_0_0_var(--color-line)]">
                            <tr>
                                <th scope="col"><span class="sr-only">{{ __('packaging.table.image') }}</span></th>
                                <th scope="col"><button type="button" wire:click="sortBy('name')" class="sk-table-sort-button">{{ __('packaging.table.name') }}</button></th>
                                <th scope="col"><button type="button" wire:click="sortBy('material_code')" class="sk-table-sort-button">{{ __('packaging.table.material_code') }}</button></th>
                                <th scope="col"><button type="button" wire:click="sortBy('unit_cost')" class="sk-table-sort-button">{{ $unitPriceLabel }}</button></th>
                                <th scope="col">{{ __('packaging.table.notes') }}</th>
                                <th scope="col" class="text-right">{{ __('packaging.table.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($items as $item)
                                @php
                                    $errorKey = 'unit_cost_'.$item->id;
                                    $imageUrl = $item->iconImageUrl();
                                @endphp
                                <tr wire:key="packaging-item-{{ $item->id }}">
                                    <td>
                                        @if ($imageUrl)
                                            <img src="{{ $imageUrl }}" alt="{{ $item->name }}" class="size-13 rounded-lg object-cover" />
                                        @else
                                            <span class="grid size-13 place-items-center rounded-lg bg-[var(--color-panel-strong)] text-[var(--color-ink-soft)]" aria-hidden="true">
                                                <span class="text-xs font-semibold">PKG</span>
                                            </span>
                                        @endif
                                    </td>
                                    <td class="font-semibold text-[var(--color-ink-strong)]">{{ $item->name }}</td>
                                    <td class="font-mono text-sm text-[var(--color-ink-soft)]">{{ $item->material_code ?? '—' }}</td>
                                    <td>
                                        <input type="text" inputmode="decimal" value="{{ $this->formattedUnitCost($item->unit_cost) }}" wire:change="updateUnitCost({{ $item->id }}, $event.target.value)" class="sk-input numeric w-32" aria-label="{{ __('packaging.accessibility.unit_price', ['item' => $item->name]) }}" />
                                        @error($errorKey)
                                            <p role="alert" class="mt-1 text-xs text-[var(--color-danger-strong)]">{{ $message }}</p>
                                        @enderror
                                    </td>
                                    <td class="text-[var(--color-ink-soft)]">{{ $item->notes ?? '-' }}</td>
                                    <td class="text-right">
                                        <div class="inline-flex items-center gap-1">
                                            <x-table-row-action icon="pencil" label="{{ __('packaging.actions.edit') }}" href="{{ route('packaging-items.edit', $item) }}" wire:navigate aria-label="{{ __('packaging.accessibility.edit', ['item' => $item->name]) }}" />
                                            <x-table-row-action icon="trash" label="{{ __('packaging.actions.delete') }}" wire:click="confirmDelete({{ $item->id }})" aria-label="{{ __('packaging.accessibility.delete', ['item' => $item->name]) }}" />
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-sticky-table-scroll>

                <x-table-pagination :paginator="$items" :per-page-label="__('packaging.table.per_page')" />
            @endif
        </section>

        @if ($pendingDeleteItem)
            <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" x-data @click.self="$wire.cancelDelete()" role="dialog" aria-modal="true" aria-labelledby="packaging-delete-heading">
                <div class="sk-card w-full max-w-md p-6" @click.stop>
                    @php($usedFormulaCount = $pendingDeleteImpact['formula_count'] ?? 0)

                    @if ($usedFormulaCount > 0)
                        <h3 id="packaging-delete-heading" class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('packaging.removal.used.heading', ['item' => $pendingDeleteItem->name]) }}</h3>
                        <p class="mt-2 text-sm leading-6 text-[var(--color-ink-soft)]">
                            {{ trans_choice('packaging.removal.used.description', $usedFormulaCount, ['count' => $usedFormulaCount]) }}
                        </p>
                        @error('packaging_item')
                            <p role="alert" class="mt-4 rounded-lg bg-[var(--color-danger-soft)] px-3 py-2 text-sm text-[var(--color-danger-strong)]">{{ $message }}</p>
                        @enderror
                        <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row">
                            <button type="button" wire:click="cancelDelete()" wire:loading.attr="disabled" wire:target="removeEverywhereAndDelete" class="sk-btn sk-btn-outline">{{ __('packaging.actions.cancel') }}</button>
                            <button type="button" wire:click="removeEverywhereAndDelete" wire:loading.attr="disabled" wire:target="removeEverywhereAndDelete" class="sk-btn flex-1 bg-[var(--color-danger-strong)] text-white hover:bg-[var(--color-danger)]">{{ __('packaging.actions.remove_everywhere') }}</button>
                        </div>
                    @else
                        <h3 id="packaging-delete-heading" class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('packaging.removal.unused.heading', ['item' => $pendingDeleteItem->name]) }}</h3>
                        <p class="mt-2 text-sm text-[var(--color-ink-soft)]">{{ __('packaging.removal.unused.description') }}</p>
                        @error('packaging_item')
                            <p role="alert" class="mt-4 rounded-lg bg-[var(--color-danger-soft)] px-3 py-2 text-sm text-[var(--color-danger-strong)]">{{ $message }}</p>
                        @enderror
                        <div class="mt-5 flex gap-2">
                            <button type="button" wire:click="cancelDelete()" class="sk-btn sk-btn-outline">{{ __('packaging.actions.cancel') }}</button>
                            <button type="button" wire:click="deletePackagingItem({{ $pendingDeleteItem->id }})" class="sk-btn flex-1 bg-[var(--color-danger-strong)] text-white hover:bg-[var(--color-danger)]">{{ __('packaging.actions.delete') }}</button>
                        </div>
                    @endif
                </div>
            </div>
        @endif
    @endif
</div>
