@php
    $inlineFormulaOutputType = $inlineFormulaOutputType ?? false;
    $deferFormulaOutputIngredientFields = $deferFormulaOutputIngredientFields ?? false;
@endphp

<div @class(['sk-inset sk-tone-info p-4', 'mb-4' => ! $inlineFormulaOutputType && ! $deferFormulaOutputIngredientFields]) data-formula-output-type aria-labelledby="setting-formula-output-type">
    <p id="setting-formula-output-type" class="sk-eyebrow">{{ __('workbench.settings.production_output') }}</p>

    <div role="radiogroup" aria-labelledby="setting-formula-output-type" class="mt-3 flex flex-wrap gap-2">
        <button
            type="button"
            role="radio"
            :aria-checked="productionOutputType === 'finished_product'"
            @click="productionOutputType = 'finished_product'; outputIngredientId = ''"
            :class="productionOutputType === 'finished_product' ? 'border-[var(--color-active)] bg-[var(--color-active)] text-[var(--color-on-active)] shadow-sm' : 'border-[var(--color-field-outline)] bg-transparent text-[var(--color-ink-strong)] hover:border-[var(--color-line-strong)] hover:bg-[var(--color-field-muted)]'"
            class="rounded-full border px-4 py-2.5 text-xs font-medium transition-colors"
        >
            {{ __('workbench.settings.finished_product') }}
        </button>
        <button
            type="button"
            role="radio"
            :aria-checked="productionOutputType === 'manufactured_ingredient'"
            @click="productionOutputType = 'manufactured_ingredient'"
            :class="productionOutputType === 'manufactured_ingredient' ? 'border-[var(--color-active)] bg-[var(--color-active)] text-[var(--color-on-active)] shadow-sm' : 'border-[var(--color-field-outline)] bg-transparent text-[var(--color-ink-strong)] hover:border-[var(--color-line-strong)] hover:bg-[var(--color-field-muted)]'"
            class="rounded-full border px-4 py-2.5 text-xs font-medium transition-colors"
        >
            {{ __('workbench.settings.manufactured_ingredient') }}
        </button>
    </div>

    @unless ($inlineFormulaOutputType || $deferFormulaOutputIngredientFields)
        @include('livewire.dashboard.partials.recipe-workbench.formula-output-ingredient-fields')
    @endunless
</div>

@if ($inlineFormulaOutputType && ! $deferFormulaOutputIngredientFields)
    @include('livewire.dashboard.partials.recipe-workbench.formula-output-ingredient-fields', ['inlineFormulaOutputType' => true])
@endif
