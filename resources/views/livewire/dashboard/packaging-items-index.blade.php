<div class="mx-auto w-full max-w-7xl space-y-6">
    @if ($statusMessage)
        <div role="status" class="rounded-xl border border-[var(--color-success-soft)] bg-[var(--color-success-soft)] px-5 py-3 text-sm text-[var(--color-success-strong)]">
            {{ $statusMessage }}
        </div>
    @endif

    <section class="sk-card p-5 sm:p-6" aria-label="Packaging catalog heading">
        <div class="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
            <div class="min-w-0">
                <p class="sk-eyebrow">Packaging items</p>
                <h3 class="mt-2 max-w-4xl text-xl font-semibold text-[var(--color-ink-strong)] sm:text-2xl">Manage packaging used in recipe costing.</h3>
                <p class="mt-2 max-w-3xl text-sm leading-7 text-[var(--color-ink-soft)]">
                    Add boxes, jars, labels, inserts, and other reusable packaging with a unit price. Saved items can be selected in recipe packaging and costing instead of retyped.
                </p>
            </div>

            <a href="{{ route('dashboard') }}" wire:navigate class="inline-flex min-h-11 items-center justify-center whitespace-nowrap rounded-full border border-[var(--color-line)] px-5 py-2.5 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel)]">
                Back to dashboard
            </a>
        </div>
    </section>

    @if (! $currentUser)
        <section class="sk-card p-8 text-center" aria-label="Sign in required">
            <h4 class="text-lg font-semibold text-[var(--color-ink-strong)]">Sign in to manage packaging items</h4>
            <p class="mt-3 text-sm leading-7 text-[var(--color-ink-soft)]">Open the dashboard from your signed-in app or admin session to create and reuse packaging items.</p>
        </section>
    @else
        <section class="overflow-hidden sk-card p-0" aria-label="Packaging catalog table">
            <div class="flex flex-col gap-4 border-b border-[var(--color-line)] bg-[var(--color-field-muted)] px-5 py-4">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <p class="text-sm font-medium text-[var(--color-ink-strong)]">Packaging catalog</p>
                        <p class="mt-1 text-xs text-[var(--color-ink-soft)]">Saved boxes, jars, labels, and inserts available to recipe costing.</p>
                    </div>

                    <a href="{{ route('packaging-items.create') }}" wire:navigate class="sk-btn sk-btn-primary justify-center">Add packaging item</a>
                </div>

                <div class="flex flex-col gap-3 sm:flex-row sm:items-center" aria-label="Packaging catalog filters">
                    <label class="sk-field min-w-64">
                        <span class="shrink-0 text-[var(--color-ink-soft)]">Search</span>
                        <input wire:model.live.debounce.250ms="search" type="text" placeholder="Name or notes" class="sk-field-control" aria-label="Search packaging items" />
                    </label>
                </div>
            </div>

            @if ($items->isEmpty())
                <div class="p-8 text-center">
                    <h4 class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ $search !== '' ? 'No packaging items match' : 'No packaging items yet' }}</h4>
                    <p class="mt-3 text-sm leading-7 text-[var(--color-ink-soft)]">Create reusable boxes, labels, jars, and inserts once, then pull them into recipe costing when needed.</p>
                    <div class="mt-5">
                        <a href="{{ route('packaging-items.create') }}" wire:navigate class="sk-btn sk-btn-primary">Add packaging item</a>
                    </div>
                </div>
            @else
                <div class="sk-table-wrapper">
                    <table class="sk-table">
                        <thead>
                            <tr>
                                <th scope="col">Picture</th>
                                <th scope="col"><button type="button" wire:click="sortBy('name')" class="sk-table-sort-button">Name</button></th>
                                <th scope="col"><button type="button" wire:click="sortBy('unit_cost')" class="sk-table-sort-button">{{ $unitPriceLabel }}</button></th>
                                <th scope="col">Notes</th>
                                <th scope="col" class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($items as $item)
                                @php
                                    $errorKey = 'unit_cost_'.$item->id;
                                    $imageUrl = $item->featuredImageUrl();
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
                                    <td>
                                        <input type="text" inputmode="decimal" value="{{ $this->formattedUnitCost($item->unit_cost) }}" wire:change="updateUnitCost({{ $item->id }}, $event.target.value)" class="sk-input numeric w-32" aria-label="{{ $unitPriceLabel }} for {{ $item->name }}" />
                                        @error($errorKey)
                                            <p role="alert" class="mt-1 text-xs text-[var(--color-danger-strong)]">{{ $message }}</p>
                                        @enderror
                                    </td>
                                    <td class="text-[var(--color-ink-soft)]">{{ $item->notes ?? '-' }}</td>
                                    <td class="text-right">
                                        <div class="inline-flex items-center gap-1">
                                            <a href="{{ route('packaging-items.edit', $item->id) }}" wire:navigate class="grid size-9 place-items-center rounded-lg text-[var(--color-ink-soft)] hover:bg-[var(--color-panel-strong)]" aria-label="Edit {{ $item->name }}" title="Edit">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125" />
                                                </svg>
                                            </a>
                                            <button type="button" wire:click="confirmDelete({{ $item->id }})" class="grid size-9 place-items-center rounded-lg text-[var(--color-danger-strong)] hover:bg-[var(--color-danger-soft)]" aria-label="Delete {{ $item->name }}" title="Delete">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                                </svg>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="flex flex-col gap-3 border-t border-[var(--color-line)] px-5 py-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-center gap-2 text-xs text-[var(--color-ink-soft)]">
                        <span>Per page</span>
                        <select wire:model.live="perPage" class="sk-select-control w-20" aria-label="Items per page">
                            <option value="25">25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                    </div>
                    {{ $items->links() }}
                </div>
            @endif
        </section>

        @if ($pendingDeleteItem)
            <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" x-data @click.self="$wire.cancelDelete()" role="dialog" aria-modal="true" aria-labelledby="packaging-delete-heading">
                <div class="sk-card w-full max-w-md p-6" @click.stop>
                    @php($usedFormulaCount = $pendingDeleteImpact['formula_count'] ?? 0)

                    @if ($usedFormulaCount > 0)
                        <h3 id="packaging-delete-heading" class="text-lg font-semibold text-[var(--color-ink-strong)]">Manage "{{ $pendingDeleteItem->name }}"</h3>
                        <p class="mt-2 text-sm leading-6 text-[var(--color-ink-soft)]">
                            Used in {{ $usedFormulaCount }} {{ \Illuminate\Support\Str::plural('formula', $usedFormulaCount) }}. Removing it will delete it from every saved formula version, including backups and archived formulas, and from formula costing.
                        </p>
                        @error('packaging_item')
                            <p role="alert" class="mt-4 rounded-lg bg-[var(--color-danger-soft)] px-3 py-2 text-sm text-[var(--color-danger-strong)]">{{ $message }}</p>
                        @enderror
                        <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row">
                            <button type="button" wire:click="cancelDelete()" wire:loading.attr="disabled" wire:target="removeEverywhereAndDelete" class="sk-btn sk-btn-outline">Cancel</button>
                            <button type="button" wire:click="removeEverywhereAndDelete" wire:loading.attr="disabled" wire:target="removeEverywhereAndDelete" class="sk-btn flex-1 bg-[var(--color-danger-strong)] text-white hover:bg-[var(--color-danger)]">Remove everywhere and delete</button>
                        </div>
                    @else
                        <h3 id="packaging-delete-heading" class="text-lg font-semibold text-[var(--color-ink-strong)]">Delete "{{ $pendingDeleteItem->name }}"?</h3>
                        <p class="mt-2 text-sm text-[var(--color-ink-soft)]">This removes the packaging item from your private catalog.</p>
                        @error('packaging_item')
                            <p role="alert" class="mt-4 rounded-lg bg-[var(--color-danger-soft)] px-3 py-2 text-sm text-[var(--color-danger-strong)]">{{ $message }}</p>
                        @enderror
                        <div class="mt-5 flex gap-2">
                            <button type="button" wire:click="cancelDelete()" class="sk-btn sk-btn-outline">Cancel</button>
                            <button type="button" wire:click="deletePackagingItem({{ $pendingDeleteItem->id }})" class="sk-btn flex-1 bg-[var(--color-danger-strong)] text-white hover:bg-[var(--color-danger)]">Delete</button>
                        </div>
                    @endif
                </div>
            </div>
        @endif
    @endif
</div>
