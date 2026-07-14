@php
    $editor = $this;
    $componentRows = is_array($editor->data['components'] ?? null) ? $editor->data['components'] : [];
    $ingredientOptions = $editor->componentIngredientOptions();
    $componentOptions = collect($ingredientOptions)
        ->map(fn (string $label, int|string $id): array => ['id' => (int) $id, 'label' => $label])
        ->values()
        ->all();
    $total = $editor->componentPercentageTotal();
    $isBalanced = abs($total - 100.0) < 0.01;
@endphp

<section class="overflow-visible sk-card" aria-labelledby="composition-heading">
    <div class="flex flex-col gap-4 border-b border-[var(--color-line)] px-5 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-6">
        <div>
            <h3 id="composition-heading" class="text-lg font-semibold text-[var(--color-ink-strong)]">Blend composition</h3>
            <p class="mt-1 max-w-2xl text-sm text-[var(--color-ink-soft)]">Search the catalogue, then set each component’s share.</p>
        </div>
        <p role="status" aria-live="polite" class="shrink-0 rounded-full bg-[var(--color-field-muted)] px-3 py-1.5 text-sm">
            <span class="text-[var(--color-ink-soft)]">Total </span>
            <span class="numeric font-medium" style="color: {{ $isBalanced ? 'var(--color-success-strong)' : 'var(--color-danger-strong)' }}">{{ number_format($total, 1, '.', '') }}%</span>
        </p>
    </div>

    <div class="space-y-5 px-5 py-5 sm:px-6">
        <div
            class="space-y-3"
            x-data="{ creating: false }"
            x-on:component-created.window="creating = false"
        >
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
                <div class="w-full max-w-2xl" x-on:search-combobox-selected="$wire.addComponent($event.detail.id)">
                    <x-search-combobox
                        id="composition-ingredient-search"
                        label="Search and add a component ingredient"
                        :options="$componentOptions"
                        placeholder="Search ingredient by name or INCI"
                        action-label="Add"
                        empty-message="No matching ingredients"
                        :retain-selection="false"
                        option-added-event="component-created"
                        option-added-id-key="ingredientId"
                        option-added-label-key="ingredientLabel"
                    />
                </div>
                <button
                    type="button"
                    @click="creating = true; $wire.set('quickComponentName', document.getElementById('composition-ingredient-search')?.value ?? ''); $nextTick(() => $refs.quickComponentName.focus())"
                    class="sk-combobox-button shrink-0 rounded-md px-1 py-3 text-sm font-medium text-[var(--color-accent-strong)] underline"
                >
                    Create ingredient
                </button>
            </div>

            <div x-cloak x-show="creating" class="sk-inset max-w-2xl space-y-4 p-4">
                <div>
                    <p class="font-medium text-[var(--color-ink-strong)]">Create a private ingredient</p>
                    <p class="mt-1 text-sm text-[var(--color-ink-soft)]">Add the essential details now. You can complete the ingredient later.</p>
                </div>
                @error('plan')
                    <p role="alert" class="rounded-lg border border-[var(--color-danger-soft)] bg-[var(--color-danger-soft)] px-3 py-2 text-sm text-[var(--color-danger-strong)]">{{ $message }}</p>
                @enderror
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="quick-component-name" class="sk-eyebrow block">Name</label>
                        <input
                            x-ref="quickComponentName"
                            id="quick-component-name"
                            type="text"
                            maxlength="255"
                            wire:model="quickComponentName"
                            class="sk-input mt-1"
                            aria-invalid="{{ $errors->has('quickComponentName') ? 'true' : 'false' }}"
                        />
                        @error('quickComponentName')
                            <p role="alert" class="mt-1 text-xs text-[var(--color-danger-strong)]">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label for="quick-component-category" class="sk-eyebrow block">Category</label>
                        <select
                            id="quick-component-category"
                            wire:model="quickComponentCategory"
                            class="sk-input mt-1"
                            aria-invalid="{{ $errors->has('quickComponentCategory') ? 'true' : 'false' }}"
                        >
                            <option value="">Choose a category</option>
                            @foreach (\App\IngredientCategory::options() as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('quickComponentCategory')
                            <p role="alert" class="mt-1 text-xs text-[var(--color-danger-strong)]">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
                <div class="flex flex-wrap justify-end gap-2">
                    <button
                        type="button"
                        @click="creating = false; $wire.set('quickComponentName', ''); $wire.set('quickComponentCategory', null)"
                        class="sk-btn sk-combobox-button"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        wire:click="createAndAddComponent"
                        wire:loading.attr="disabled"
                        wire:target="createAndAddComponent"
                        class="sk-btn sk-btn-primary"
                    >
                        Create and add
                    </button>
                </div>
            </div>
        </div>

        @error('data.components')
            <p role="alert" class="rounded-lg border border-[var(--color-danger-soft)] bg-[var(--color-danger-soft)] px-3 py-2 text-sm text-[var(--color-danger-strong)]">{{ $message }}</p>
        @enderror

        @if (count($componentRows) === 0)
            <div class="rounded-lg border border-dashed border-[var(--color-line)] bg-[var(--color-field-muted)] px-4 py-5 text-sm text-[var(--color-ink-soft)]">
                <p class="font-medium text-[var(--color-ink-strong)]">No components added yet.</p>
                <p class="mt-1">Use the search above to add the ingredients that make up this blend.</p>
            </div>
        @else
            <div class="overflow-hidden rounded-lg border border-[var(--color-line)]" aria-label="Blend components">
                <div class="hidden text-sm lg:grid lg:grid-cols-[minmax(0,1fr)_9rem_3.5rem] lg:gap-px lg:bg-[var(--color-line)]" aria-hidden="true">
                    <div class="bg-[var(--color-field-muted)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">Component ingredient</div>
                    <div class="bg-[var(--color-field-muted)] px-4 py-3 font-medium text-[var(--color-ink-strong)]">Share</div>
                    <div class="bg-[var(--color-field-muted)]"></div>
                </div>
                <div class="divide-y divide-[var(--color-line)] bg-white">
                    @foreach ($componentRows as $index => $row)
                        @php($componentLabel = $ingredientOptions[(int) ($row['component_ingredient_id'] ?? 0)] ?? 'Unavailable ingredient')
                        @php($shareField = 'data.components.'.$index.'.percentage_in_parent')
                        <div class="grid grid-cols-1 gap-3 bg-white p-3 text-sm lg:grid-cols-[minmax(0,1fr)_9rem_3.5rem] lg:gap-px lg:bg-[var(--color-line)] lg:p-0" wire:key="composition-row-{{ $index }}">
                            <div class="flex items-center bg-white lg:px-4 lg:py-3">
                                <p class="min-w-0 truncate font-medium text-[var(--color-ink-strong)]" title="{{ $componentLabel }}">{{ $componentLabel }}</p>
                            </div>
                            <div class="flex flex-col gap-2 bg-white lg:px-3 lg:py-3">
                                <label for="composition-share-{{ $index }}" class="sk-eyebrow lg:sr-only">Share</label>
                                <div class="relative">
                                    <input id="composition-share-{{ $index }}" type="text" inputmode="decimal" wire:model.live.debounce.300ms="data.components.{{ $index }}.percentage_in_parent" aria-label="Share for {{ $componentLabel }}" aria-invalid="{{ $errors->has($shareField) ? 'true' : 'false' }}" class="numeric w-full rounded-xl border border-[var(--color-line)] bg-[var(--color-field)] px-3 py-2 pr-9 text-right text-sm text-[var(--color-ink-strong)] transition" @error($shareField) style="border-color: var(--color-danger)" @enderror />
                                    <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm text-[var(--color-ink-soft)]">%</span>
                                </div>
                                @error($shareField)
                                    <p role="alert" class="text-xs text-[var(--color-danger-strong)]">{{ $message }}</p>
                                @enderror
                            </div>
                            <div class="flex items-center justify-end bg-white lg:px-4 lg:py-3">
                                <button type="button" wire:click="removeComponentRow({{ $index }})" class="grid size-10 place-items-center rounded-md text-base text-[var(--color-ink-soft)] transition hover:bg-[var(--color-danger-soft)] hover:text-[var(--color-danger-strong)]" aria-label="Remove {{ $componentLabel }} from blend">×</button>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="border-t border-[var(--color-line)] pt-5">
            <label for="composition-source-notes" class="sk-eyebrow block">Composition source</label>
            <textarea id="composition-source-notes" wire:model="data.composition_source_notes" rows="2" class="sk-input mt-1 w-full" placeholder="One source for the whole blend composition, e.g. supplier specification or lab report."></textarea>
        </div>
    </div>
</section>
