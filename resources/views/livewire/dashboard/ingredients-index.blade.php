<div class="mx-auto w-full max-w-7xl space-y-6">
    <section class="sk-card p-5 sm:p-6">
        <div class="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
            <div class="min-w-0">
                <p class="sk-eyebrow">Ingredients</p>
                <h3 class="mt-2 max-w-4xl text-xl font-semibold text-[var(--color-ink-strong)] sm:text-2xl">Use platform ingredients or maintain your own.</h3>
                <p class="mt-2 max-w-3xl text-sm leading-7 text-[var(--color-ink-soft)]">
                    Platform ingredients are shared reference records. Add your own price/kg for costing, duplicate one when you need an editable copy, or create a private ingredient from scratch.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-3 lg:justify-end">
                @include('livewire.dashboard.partials.duplicate-ingredient-modal')

                <a href="{{ route('dashboard') }}" wire:navigate class="inline-flex min-h-11 items-center justify-center whitespace-nowrap rounded-full border border-[var(--color-line)] px-5 py-2.5 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel)]">
                    Back to dashboard
                </a>
            </div>
        </div>
    </section>

    @if (! $currentUser)
        <section class="sk-card p-8 text-center">
            <h4 class="text-lg font-semibold text-[var(--color-ink-strong)]">Sign in to manage ingredients</h4>
            <p class="mt-3 text-sm leading-7 text-[var(--color-ink-soft)]">Open the dashboard from your signed-in app or admin session to create and maintain private ingredients.</p>
        </section>
    @else
        <section class="overflow-hidden sk-card p-0">
            <div class="flex flex-col gap-4 border-b border-[var(--color-line)] bg-[var(--color-field-muted)] px-5 py-4">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <p class="text-sm font-medium text-[var(--color-ink-strong)]">Ingredient catalog</p>
                        <p class="mt-1 text-xs text-[var(--color-ink-soft)]">Price platform ingredients for costing, or create private ingredients that only you can edit.</p>
                    </div>

                    <a href="{{ route('ingredients.create') }}" wire:navigate class="sk-btn sk-btn-primary justify-center">Add ingredient</a>
                </div>

                <div class="flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between" aria-label="Ingredient catalog filters">
                    <div class="flex flex-col gap-2">
                        <div class="flex flex-wrap gap-2" role="radiogroup" aria-label="Ingredient catalog filter">
                            @foreach ($this->ownershipFilterOptions() as $filterValue => $filterLabel)
                                <button
                                    type="button"
                                    role="radio"
                                    aria-checked="{{ $ownershipFilter === $filterValue ? 'true' : 'false' }}"
                                    wire:click="setOwnershipFilter('{{ $filterValue }}')"
                                    class="{{ $ownershipFilter === $filterValue ? 'border-[var(--color-accent)] bg-[var(--color-accent-soft)] text-[var(--color-accent-strong)]' : 'border-[var(--color-line)] bg-white text-[var(--color-ink-soft)] hover:bg-[var(--color-field-muted)] hover:text-[var(--color-ink-strong)]' }} rounded-full border px-4 py-2 text-sm font-medium transition"
                                >
                                    {{ $filterValue === 'mine' ? $filterLabel.' ('.$privateIngredientUsage['used'].')' : $filterLabel }}
                                </button>
                            @endforeach
                        </div>

                        <p class="text-xs text-[var(--color-ink-soft)]">
                            @if ($privateIngredientUsage['limit'] === null)
                                {{ $privateIngredientUsage['used'] }} private {{ \Illuminate\Support\Str::plural('ingredient', $privateIngredientUsage['used']) }}
                            @else
                                {{ $privateIngredientUsage['used'] }} of {{ $privateIngredientUsage['limit'] }} private {{ \Illuminate\Support\Str::plural('ingredient', $privateIngredientUsage['limit']) }}
                            @endif
                        </p>
                    </div>

                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                        <label class="sk-field min-w-64">
                            <span class="shrink-0 text-[var(--color-ink-soft)]">Search</span>
                            <input wire:model.live.debounce.250ms="search" type="text" placeholder="Name, INCI, or category" class="sk-field-control" aria-label="Search ingredients" />
                        </label>
                    </div>
                </div>
            </div>

            @if ($ingredients->isEmpty())
                <div class="px-5 py-10 text-center">
                    <h4 class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ $search !== '' ? 'No ingredients match' : 'No ingredients yet' }}</h4>
                    <p class="mt-2 text-sm text-[var(--color-ink-soft)]">Create your first private ingredient or set a price on a platform ingredient to see it here.</p>
                    <div class="mt-5">
                        <a href="{{ route('ingredients.create') }}" wire:navigate class="sk-btn sk-btn-primary">Add ingredient</a>
                    </div>
                </div>
            @else
                <div class="sk-table-wrapper">
                    <table class="sk-table">
                        <thead>
                            <tr>
                                <th scope="col">Picture</th>
                                <th scope="col">
                                    <button type="button" wire:click="sortBy('display_name')" class="inline-flex items-center gap-1 font-semibold">
                                        Name
                                        <span class="text-xs text-[var(--color-ink-soft)]">{{ $sortField === 'display_name' ? ($sortDirection === 'asc' ? 'Asc' : 'Desc') : '' }}</span>
                                    </button>
                                </th>
                                <th scope="col">INCI</th>
                                <th scope="col">
                                    <button type="button" wire:click="sortBy('category')" class="inline-flex items-center gap-1 font-semibold">
                                        Category
                                        <span class="text-xs text-[var(--color-ink-soft)]">{{ $sortField === 'category' ? ($sortDirection === 'asc' ? 'Asc' : 'Desc') : '' }}</span>
                                    </button>
                                </th>
                                <th scope="col">Source</th>
                                <th scope="col">{{ $priceLabel }}</th>
                                <th scope="col" class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($ingredients as $ingredient)
                                @php
                                    $imageUrl = $this->catalogImageUrl($ingredient);
                                    $displayName = $ingredient->localizedDisplayName();
                                    $isMine = $ingredient->owner_type === \App\OwnerType::User && $ingredient->owner_id === $currentUser->id;
                                    $cannotDelete = $isMine && ((int) $ingredient->costing_items_count > 0 || (int) $ingredient->recipe_items_count > 0);
                                    $formulaUsage = $formulaUsageByIngredient[$ingredient->id] ?? [];
                                    $formulaUsageCount = count($formulaUsage);
                                    $hasFormulaUsage = $isMine && $formulaUsageCount > 0;
                                    $usageDisclosureId = 'ingredient-usage-'.$ingredient->id;
                                @endphp
                                <tr wire:key="ingredient-{{ $ingredient->id }}" @if ($cannotDelete) data-cannot-delete="{{ $ingredient->id }}" @endif>
                                    <td>
                                        @if ($imageUrl)
                                            <img src="{{ $imageUrl }}" alt="" class="size-13 rounded-lg object-cover" />
                                        @else
                                            <div class="grid size-13 place-items-center rounded-lg bg-[var(--color-panel-strong)] text-xs font-semibold text-[var(--color-ink-soft)]">
                                                {{ mb_substr((string) $displayName, 0, 1) }}
                                            </div>
                                        @endif
                                    </td>
                                    <td>
                                        <div class="min-w-48">
                                            <p class="flex items-center gap-1.5 font-semibold text-[var(--color-ink-strong)]">
                                                <span>{{ $displayName }}</span>
                                                <x-ingredient-source-marker :is-user-owned="$isMine" />
                                            </p>
                                            @if ($ingredient->supplier_name)
                                                <p class="mt-1 text-xs text-[var(--color-ink-soft)]">{{ $ingredient->supplier_name }}</p>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="max-w-72 whitespace-normal text-[var(--color-ink-soft)]">{{ $ingredient->inci_name ?: '—' }}</td>
                                    <td>
                                        <span class="inline-flex rounded-full bg-[var(--color-panel-strong)] px-2.5 py-1 text-xs font-medium text-[var(--color-ink-soft)]">
                                            {{ $ingredient->category?->getLabel() ?? 'Uncategorized' }}
                                        </span>
                                    </td>
                                    <td>
                                        <span class="{{ $isMine ? 'bg-[var(--color-warning-soft)] text-[var(--color-warning-strong)]' : 'bg-[var(--color-panel-strong)] text-[var(--color-ink-soft)]' }} inline-flex rounded-full px-2.5 py-1 text-xs font-medium">
                                            {{ $isMine ? 'Mine' : 'Platform' }}
                                        </span>
                                    </td>
                                    <td>
                                        <div class="min-w-36">
                                            <input
                                                value="{{ $this->formattedPrice($ingredient->user_price_per_kg) }}"
                                                wire:change="updateIngredientPrice({{ $ingredient->id }}, $event.target.value)"
                                                type="text"
                                                inputmode="decimal"
                                                class="sk-input numeric"
                                                aria-label="Price per kg for {{ $displayName }}"
                                            />
                                            @error('price_'.$ingredient->id)
                                                <p class="mt-1 text-xs text-[var(--color-danger-strong)]">{{ $message }}</p>
                                            @enderror
                                        </div>
                                    </td>
                                    <td>
                                        <div class="flex items-center justify-end gap-1">
                                            @if ($isMine)
                                                <a href="{{ route('ingredients.edit', $ingredient) }}" wire:navigate class="grid size-9 place-items-center rounded-lg text-[var(--color-ink-soft)] hover:bg-[var(--color-panel-strong)]" aria-label="Edit {{ $displayName }}" title="Edit">
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125" />
                                                    </svg>
                                                </a>
                                                @if ($hasFormulaUsage)
                                                    <div class="flex flex-col items-end">
                                                        <button
                                                            type="button"
                                                            wire:click="toggleUsage({{ $ingredient->id }})"
                                                            aria-expanded="{{ $expandedUsageIngredientId === $ingredient->id ? 'true' : 'false' }}"
                                                            aria-controls="{{ $usageDisclosureId }}"
                                                            class="inline-flex min-h-9 items-center justify-center rounded-lg px-2.5 text-xs font-medium text-[var(--color-danger-strong)] hover:bg-[var(--color-danger-soft)] focus-visible:outline-2 focus-visible:outline-offset-2"
                                                        >
                                                            Used in {{ $formulaUsageCount }} {{ \Illuminate\Support\Str::plural('formula', $formulaUsageCount) }}
                                                        </button>

                                                        <div id="{{ $usageDisclosureId }}" @if ($expandedUsageIngredientId !== $ingredient->id) hidden @endif class="mt-2 w-72 rounded-xl border border-[var(--color-line)] bg-white p-3 text-left shadow-sm">
                                                            <ul class="space-y-2">
                                                                @foreach ($formulaUsage as $usage)
                                                                    <li>
                                                                        <a href="{{ $usage['url'] }}" wire:navigate class="text-sm font-medium text-[var(--color-accent-strong)] underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2">
                                                                            {{ $usage['name'] }}
                                                                        </a>
                                                                        @if ($usage['version_count'] > 1)
                                                                            <p class="text-xs text-[var(--color-ink-soft)]">{{ $usage['version_count'] }} saved versions</p>
                                                                        @endif
                                                                    </li>
                                                                @endforeach
                                                            </ul>
                                                            <p class="mt-3 border-t border-[var(--color-line)] pt-3 text-xs leading-5 text-[var(--color-ink-soft)]">Deletion is protected while recoverable formula records use it.</p>
                                                        </div>
                                                    </div>
                                                @else
                                                    <button
                                                        type="button"
                                                        wire:click="confirmDelete({{ $ingredient->id }})"
                                                        @disabled($cannotDelete)
                                                        class="grid size-9 place-items-center rounded-lg text-[var(--color-danger-strong)] hover:bg-[var(--color-danger-soft)] disabled:cursor-not-allowed disabled:opacity-40"
                                                        aria-label="Delete {{ $displayName }}"
                                                        title="{{ $cannotDelete ? 'This ingredient is already used.' : 'Delete' }}"
                                                    >
                                                        <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                                        </svg>
                                                    </button>
                                                @endif
                                            @else
                                                <span class="text-xs text-[var(--color-ink-soft)]">Reference</span>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="border-t border-[var(--color-line)] px-5 py-2.5">
                    <x-ingredient-source-legend :show="$ingredients->contains(fn ($ingredient): bool => $ingredient->owner_type === \App\OwnerType::User && $ingredient->owner_id === $currentUser->id)" />
                </div>

                <div class="flex flex-col gap-3 border-t border-[var(--color-line)] px-5 py-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-center gap-2 text-xs text-[var(--color-ink-soft)]">
                        <span>Per page</span>
                        <select wire:model.live="perPage" class="sk-select-control w-20" aria-label="Ingredients per page">
                            <option value="25">25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                    </div>
                    {{ $ingredients->links() }}
                </div>
            @endif
        </section>

        @if ($pendingDeleteIngredient)
            <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" x-data @click.self="$wire.cancelDelete()" role="dialog" aria-modal="true" aria-labelledby="ingredient-delete-heading">
                <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
                    <h3 id="ingredient-delete-heading" class="text-lg font-semibold text-[var(--color-ink-strong)]">Delete "{{ $pendingDeleteIngredient->localizedDisplayName() }}"?</h3>
                    <p class="mt-2 text-sm text-[var(--color-ink-soft)]">This removes the ingredient from your private catalog.</p>

                    <div class="mt-6 flex justify-end gap-3">
                        <button type="button" wire:click="cancelDelete" class="rounded-full border border-[var(--color-line)] px-4 py-2 text-sm font-medium text-[var(--color-ink-soft)]">Cancel</button>
                        <button type="button" wire:click="deleteIngredient({{ $pendingDeleteIngredient->id }})" class="rounded-full bg-[var(--color-danger-strong)] px-4 py-2 text-sm font-medium text-white">Delete</button>
                    </div>
                </div>
            </div>
        @endif
    @endif
</div>
