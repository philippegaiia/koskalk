<div class="mx-auto w-full max-w-app space-y-6">
    <script type="application/json" data-contextual-help-scope>{!! \Illuminate\Support\Js::encode($contextualHelp) !!}</script>
    <x-contextual-help.index-button :help="$contextualHelp" tab="materials" />

    @if (! $currentUser)
        <section class="sk-card p-8 text-center" aria-label="{{ __('packaging.auth.aria_label') }}">
            <h4 class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('packaging.auth.heading') }}</h4>
            <p class="mt-3 text-sm leading-7 text-[var(--color-ink-soft)]">{{ __('packaging.auth.description') }}</p>
        </section>
    @else
        <section class="overflow-hidden sk-card p-0" aria-labelledby="packaging-catalog-heading">
            <div class="flex flex-col gap-2.5 border-b border-[var(--color-line)] bg-[var(--color-field-muted)] px-5 py-3">
                <h3 id="packaging-catalog-heading" data-contextual-help-heading class="sr-only">{{ __('packaging.catalog.heading') }}</h3>

                <div class="flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
                <p class="max-w-xl text-xs text-[var(--color-ink-soft)]">{{ __('packaging.page.intro') }}</p>

                <div class="flex flex-wrap items-center gap-2 xl:justify-end">
                <div
                    x-data="listOutputDownload()"
                    data-export-url="{{ route('packaging-items.export') }}"
                    data-export-parameters="{{ \Illuminate\Support\Js::encode(['workspace' => $destinationWorkspacePublicId, 'sortField' => $sortField, 'sortDirection' => $sortDirection]) }}"
                    data-export-livewire-filters='["sortField","sortDirection"]'
                    data-export-search-parameter="search"
                    data-export-server-search="{{ $search }}"
                    data-export-has-results="{{ $items->total() > 0 ? 'true' : 'false' }}"
                    data-export-filename-fallback="{{ __('packaging.exports.filename') }}.{format}"
                    data-export-error-access="{{ __('packaging.exports.ui.errors.access') }}"
                    data-export-error-forbidden="{{ __('packaging.exports.ui.errors.forbidden') }}"
                    data-export-error-workspace-changed="{{ __('packaging.exports.validation.workspace_changed') }}"
                    data-export-error-invalid="{{ __('packaging.exports.ui.errors.invalid') }}"
                    data-export-error-throttled="{{ __('ingredients.exports.ui.errors.throttled') }}"
                    data-export-error-failed="{{ __('packaging.exports.ui.errors.failed') }}"
                    class="flex flex-wrap items-center gap-2"
                    aria-label="{{ __('packaging.catalog.filters_label') }}"
                    @keydown.escape.window="if (outputMenuOpen) { outputMenuOpen = false; $refs.exportMenuTrigger.focus(); }"
                >
                    <label class="sk-field min-h-11 w-64 py-2">
                        <input data-list-output-search @input="visibleSearch = $event.target.value" autocomplete="off" wire:model.live.debounce.250ms="search" type="text" placeholder="{{ __('packaging.search.placeholder') }}" class="sk-field-control" aria-label="{{ __('packaging.search.aria_label') }}" />
                    </label>

                    @if ($destinationWorkspacePublicId)
                        <div class="flex flex-wrap items-center gap-2">
                            <div class="relative" @click.outside="outputMenuOpen = false">
                                <button
                                    x-ref="exportMenuTrigger"
                                    type="button"
                                    aria-haspopup="menu"
                                    aria-controls="packaging-export-menu"
                                    :aria-expanded="outputMenuOpen.toString()"
                                    :aria-disabled="(outputPending || currentResultsAreEmpty()).toString()"
                                    :disabled="currentResultsAreEmpty()"
                                    wire:loading.attr="disabled"
                                    wire:target="sortBy"
                                    @click="if (toggleMenu()) $nextTick(() => $refs.xlsxExport.focus())"
                                    class="sk-btn sk-btn-outline min-h-11 gap-2 whitespace-nowrap"
                                >
                                    <x-action-icon name="download" />{{ __('packaging.exports.ui.label') }}<x-action-icon name="chevron-down" />
                                </button>
                                <div id="packaging-export-menu" x-show="outputMenuOpen" x-cloak role="menu" aria-label="{{ __('packaging.exports.ui.menu_label') }}" @keydown.escape.stop.prevent="outputMenuOpen = false; $refs.exportMenuTrigger.focus()" class="absolute left-0 top-full z-30 mt-2 min-w-52 rounded-xl border border-[var(--color-line)] bg-[var(--color-panel)] p-1 shadow-lg">
                                    <button x-ref="xlsxExport" type="button" role="menuitem" :disabled="outputPending" wire:loading.attr="disabled" wire:target="sortBy" @click="outputMenuOpen = false; $nextTick(() => $refs.exportMenuTrigger.focus()); exportList('xlsx')" @keydown.arrow-down.prevent="$refs.csvExport.focus()" @keydown.arrow-up.prevent="$refs.csvExport.focus()" class="block min-h-11 w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-[var(--color-ink-strong)] hover:bg-[var(--color-panel-strong)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-[var(--color-accent)]">{{ __('ingredients.exports.ui.xlsx') }}</button>
                                    <button x-ref="csvExport" type="button" role="menuitem" :disabled="outputPending" wire:loading.attr="disabled" wire:target="sortBy" @click="outputMenuOpen = false; $nextTick(() => $refs.exportMenuTrigger.focus()); exportList('csv')" @keydown.arrow-down.prevent="$refs.xlsxExport.focus()" @keydown.arrow-up.prevent="$refs.xlsxExport.focus()" class="block min-h-11 w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-[var(--color-ink-strong)] hover:bg-[var(--color-panel-strong)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-[var(--color-accent)]">{{ __('ingredients.exports.ui.csv') }}</button>
                                </div>
                            </div>
                            <p x-show="currentResultsAreEmpty()" x-cloak class="max-w-64 text-xs text-[var(--color-ink-soft)]">{{ __('packaging.exports.validation.empty') }}</p>
                            <p x-show="outputPending" x-cloak role="status" aria-live="polite" class="text-xs text-[var(--color-ink-soft)]">{{ __('packaging.exports.ui.pending') }}</p>
                            <p x-show="outputError" x-cloak role="alert" class="text-xs text-[var(--color-danger-strong)]" x-text="outputError"></p>
                        </div>
                    @endif
                </div>

                @if ($canCreateItems)
                    <a href="{{ route('packaging-items.create') }}" wire:navigate class="sk-btn sk-btn-primary min-h-11 justify-center">{{ __('packaging.actions.add_short') }}</a>
                @endif
                </div>
                </div>
            </div>

            @if ($items->isEmpty())
                <div class="p-8 text-center">
                    <h4 class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ $search !== '' ? __('packaging.empty.no_matches') : __('packaging.empty.no_items') }}</h4>
                    <p class="mt-3 text-sm leading-7 text-[var(--color-ink-soft)]">{{ __('packaging.empty.description') }}</p>
                    @if ($canCreateItems)
                        <div class="mt-5">
                            <a href="{{ route('packaging-items.create') }}" wire:navigate class="sk-btn sk-btn-primary">{{ __('packaging.actions.add') }}</a>
                        </div>
                    @endif
                </div>
            @else
                <x-sticky-table-scroll>
                    <table class="sk-table [&_tbody_td]:py-1">
                        <colgroup>
                            <col class="w-16" />
                            <col />
                            <col />
                            <col />
                            <col />
                            <col />
                        </colgroup>
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
                                            <img src="{{ $imageUrl }}" alt="{{ $item->name }}" class="size-8 rounded-lg object-cover" />
                                        @else
                                            <span class="grid size-8 place-items-center rounded-lg bg-[var(--color-panel-strong)] text-[var(--color-ink-soft)]" aria-hidden="true">
                                                <span class="text-xs font-semibold">PKG</span>
                                            </span>
                                        @endif
                                    </td>
                                    <td class="font-semibold text-[var(--color-ink-strong)]">{{ $item->name }}</td>
                                    <td class="font-mono text-sm text-[var(--color-ink-soft)]">{{ $item->material_code ?? '—' }}</td>
                                    <td>
                                        @if ($canUpdateItems)
                                            <input type="text" inputmode="decimal" value="{{ $this->formattedUnitCost($item->unit_cost) }}" wire:change="updateUnitCost({{ $item->id }}, $event.target.value)" class="sk-input numeric w-32 py-1.5" aria-label="{{ __('packaging.accessibility.unit_price', ['item' => $item->name]) }}" />
                                        @else
                                            <span class="numeric text-sm text-[var(--color-ink-soft)]">{{ $this->formattedUnitCost($item->unit_cost) }}</span>
                                        @endif
                                        @error($errorKey)
                                            <p role="alert" class="mt-1 text-xs text-[var(--color-danger-strong)]">{{ $message }}</p>
                                        @enderror
                                    </td>
                                    <td class="text-[var(--color-ink-soft)]">{{ $item->notes ?? '-' }}</td>
                                    <td class="text-right">
                                        <div class="inline-flex items-center gap-1">
                                            @if ($canUpdateItems)
                                                <x-table-row-action icon="pencil" label="{{ __('packaging.actions.edit') }}" href="{{ route('packaging-items.edit', $item) }}" wire:navigate aria-label="{{ __('packaging.accessibility.edit', ['item' => $item->name]) }}" />
                                            @endif
                                            @if ($canDeleteItems)
                                                <x-table-row-action icon="trash" label="{{ __('packaging.actions.delete') }}" wire:click="confirmDelete({{ $item->id }})" aria-label="{{ __('packaging.accessibility.delete', ['item' => $item->name]) }}" />
                                            @endif
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
            @if ($canDeleteItems)
            <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" x-data @click.self="$wire.cancelDelete()" role="dialog" aria-modal="true" aria-labelledby="packaging-delete-heading">
                <div class="sk-card w-full max-w-md p-6" @click.stop>
                    @php($usedFormulaCount = $pendingDeleteImpact['formula_count'] ?? 0)

                    @if ($pendingDeleteBlock !== null)
                        <h3 id="packaging-delete-heading" class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('packaging.removal.unused.heading', ['item' => $pendingDeleteItem->name]) }}</h3>
                        <p role="alert" class="mt-2 text-sm leading-6 text-[var(--color-ink-soft)]">{{ $pendingDeleteBlock }}</p>
                        <div class="mt-5 flex justify-end">
                            <button type="button" wire:click="cancelDelete()" class="sk-btn sk-btn-outline">{{ __('packaging.actions.cancel') }}</button>
                        </div>
                    @elseif ($usedFormulaCount > 0)
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
    @endif
</div>
