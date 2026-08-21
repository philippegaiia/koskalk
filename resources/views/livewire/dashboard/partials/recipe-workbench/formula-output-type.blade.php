@php($inlineFormulaOutputType = $inlineFormulaOutputType ?? false)

<div @class(['sk-inset sk-tone-info p-4', 'mb-4' => ! $inlineFormulaOutputType]) data-formula-output-type aria-labelledby="setting-formula-output-type">
    <p id="setting-formula-output-type" class="sk-eyebrow">{{ __('workbench.settings.production_output') }}</p>

    <div role="radiogroup" aria-labelledby="setting-formula-output-type" class="mt-3 flex flex-wrap gap-2">
        <button
            type="button"
            role="radio"
            :aria-checked="productionOutputType === 'finished_product'"
            @click="productionOutputType = 'finished_product'; outputIngredientId = ''"
            :class="productionOutputType === 'finished_product' ? 'bg-[var(--color-active)] text-[var(--color-on-active)] shadow-sm' : 'bg-[var(--color-control)] text-[var(--color-ink-soft)] hover:bg-[var(--color-panel)]'"
            class="rounded-full px-4 py-2.5 text-xs font-medium transition"
        >
            {{ __('workbench.settings.finished_product') }}
        </button>
        <button
            type="button"
            role="radio"
            :aria-checked="productionOutputType === 'manufactured_ingredient'"
            @click="productionOutputType = 'manufactured_ingredient'"
            :class="productionOutputType === 'manufactured_ingredient' ? 'bg-[var(--color-active)] text-[var(--color-on-active)] shadow-sm' : 'bg-[var(--color-control)] text-[var(--color-ink-soft)] hover:bg-[var(--color-panel)]'"
            class="rounded-full px-4 py-2.5 text-xs font-medium transition"
        >
            {{ __('workbench.settings.manufactured_ingredient') }}
        </button>
    </div>

    @unless ($inlineFormulaOutputType)
        @include('livewire.dashboard.partials.recipe-workbench.formula-output-ingredient-fields')
    @endunless
</div>

@if ($inlineFormulaOutputType)
    @include('livewire.dashboard.partials.recipe-workbench.formula-output-ingredient-fields', ['inlineFormulaOutputType' => true])
@endif
