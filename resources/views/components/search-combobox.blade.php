@props([
    'id',
    'label',
    'options' => [],
    'placeholder' => 'Search',
    'actionLabel' => 'Select',
    'emptyMessage' => 'No matching items',
    'selectedId' => null,
    'selectedLabel' => null,
    'retainSelection' => true,
    'allowEmpty' => true,
    'disabled' => false,
    'optionAddedEvent' => null,
    'optionAddedIdKey' => 'id',
    'optionAddedLabelKey' => 'label',
])

<div
    {{ $attributes->merge(['class' => 'relative w-full']) }}
    data-search-combobox="{{ $id }}"
    x-data="searchCombobox({
        options: @js($options),
        id: @js($id),
        selectedId: @js($selectedId),
        selectedLabel: @js($selectedLabel),
        retainSelection: @js($retainSelection),
        allowEmpty: @js($allowEmpty),
    })"
    @if ($optionAddedEvent) x-on:{{ $optionAddedEvent }}.window="registerOption($event.detail, @js($optionAddedIdKey), @js($optionAddedLabelKey))" @endif
    @click.outside="closeOptions()"
>
    <label for="{{ $id }}" class="sr-only">{{ $label }}</label>
    <div class="sk-combobox-control flex items-center rounded-lg border border-[var(--color-line)] bg-[var(--color-field)] transition">
        <input
            id="{{ $id }}"
            x-model="query"
            @focus="open = true"
            @input="handleInput()"
            @keydown.enter.prevent="selectActiveOption()"
            @keydown.escape.prevent="closeOptions()"
            @keydown.arrow-down.prevent="open = true; moveActive(1)"
            @keydown.arrow-up.prevent="open = true; moveActive(-1)"
            type="text"
            inputmode="search"
            role="combobox"
            aria-autocomplete="list"
            aria-label="{{ $label }}"
            aria-controls="{{ $id }}-options"
            :aria-activedescendant="open && activeIndex >= 0 && filteredOptions[activeIndex] ? '{{ $id }}-option-' + filteredOptions[activeIndex].id : null"
            :aria-expanded="open.toString()"
            placeholder="{{ $placeholder }}"
            class="sk-field-control min-w-0 rounded-lg px-4 py-3 text-sm placeholder:text-[var(--color-ink-soft)]"
            @disabled($disabled)
        />
        <button x-cloak x-show="query !== ''" type="button" @click="clear(); $nextTick(() => document.getElementById(@js($id))?.focus())" class="sk-combobox-button grid size-10 shrink-0 place-items-center rounded-md text-[var(--color-ink-soft)] transition hover:bg-[var(--color-field-muted)]" aria-label="Clear {{ \Illuminate\Support\Str::lower($label) }}" @disabled($disabled)>
            <span aria-hidden="true" class="text-lg leading-none">×</span>
        </button>
        <button type="button" @click="open = ! open; activeIndex = -1" class="sk-combobox-button grid size-10 shrink-0 place-items-center rounded-md text-[var(--color-ink-soft)] transition hover:bg-[var(--color-field-muted)]" aria-label="Toggle {{ \Illuminate\Support\Str::lower($label) }} options" @disabled($disabled)>
            <span aria-hidden="true" class="text-xs">⌄</span>
        </button>
    </div>

    <div
        x-cloak
        x-show="open"
        id="{{ $id }}-options"
        role="listbox"
        class="absolute left-0 right-0 top-[calc(100%+0.35rem)] z-30 max-h-72 overflow-y-auto rounded-lg border border-[var(--color-line)] bg-[var(--color-panel)] p-1 shadow-[0_10px_30px_color-mix(in_oklch,var(--color-ink-strong)_14%,transparent)]"
    >
        <template x-if="filteredOptions.length === 0">
            <p class="px-3 py-2.5 text-sm text-[var(--color-ink-soft)]">{{ $emptyMessage }}</p>
        </template>
        <template x-for="(option, index) in filteredOptions" :key="option.id">
            <button
                type="button"
                role="option"
                :id="'{{ $id }}-option-' + option.id"
                :aria-selected="sameId(selectedId, option.id).toString()"
                @mousemove="activeIndex = index"
                @click="selectOption(option)"
                class="flex w-full items-center justify-between gap-3 rounded-md px-3 py-2.5 text-left text-sm transition hover:bg-[var(--color-field-muted)] focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-[var(--color-accent)]"
                :class="{ 'bg-[var(--color-field-muted)]': activeIndex === index || sameId(selectedId, option.id) }"
            >
                <span class="min-w-0">
                    <span class="block truncate font-medium text-[var(--color-ink-strong)]" x-text="option.label"></span>
                    <span x-show="option.description" class="mt-0.5 block truncate text-xs text-[var(--color-ink-soft)]" x-text="option.description"></span>
                </span>
                <span class="shrink-0 text-xs font-medium text-[var(--color-accent-strong)]" x-text="sameId(selectedId, option.id) ? 'Selected' : @js($actionLabel)"></span>
            </button>
        </template>
    </div>
</div>
