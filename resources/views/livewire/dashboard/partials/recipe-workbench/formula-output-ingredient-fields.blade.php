@php($inlineFormulaOutputType = $inlineFormulaOutputType ?? false)

<div
    x-show="productionOutputType === 'manufactured_ingredient'"
    x-cloak
    data-formula-output-ingredient-fields
    @class([
        'mt-4 grid gap-4 lg:grid-cols-2' => ! $inlineFormulaOutputType,
        'sk-inset grid gap-4 p-4 lg:col-span-2 lg:grid-cols-2' => $inlineFormulaOutputType,
    ])
>
    <div>
        <label for="formula-output-ingredient" class="sk-eyebrow">{{ __('workbench.settings.choose_manufactured_ingredient') }}</label>
        <select id="formula-output-ingredient" x-model="outputIngredientId" class="mt-3 w-full rounded-lg bg-[var(--color-field)] px-3 py-2.5 text-sm text-[var(--color-ink-strong)] transition">
            <option value="">{{ __('workbench.common.choose_later') }}</option>
            <template x-for="ingredient in manufacturedIngredients" :key="ingredient.id">
                <option :value="String(ingredient.id)" x-text="ingredient.name"></option>
            </template>
        </select>
    </div>

    <div>
        <label for="new-formula-output-ingredient-name" class="sk-eyebrow">{{ __('workbench.settings.new_manufactured_ingredient_name') }}</label>
        <div class="mt-3 flex gap-2">
            <input id="new-formula-output-ingredient-name" x-model="manufacturedIngredientName" @keydown.enter.prevent="createManufacturedIngredient()" type="text" maxlength="255" class="min-w-0 flex-1 rounded-lg bg-[var(--color-field)] px-4 py-2.5 text-sm text-[var(--color-ink-strong)] transition" />
            <button type="button" @click="createManufacturedIngredient()" :disabled="manufacturedIngredientStatus === 'saving'" class="sk-btn shrink-0 bg-[var(--color-active)] text-[var(--color-on-active)] hover:opacity-90 disabled:cursor-wait disabled:opacity-60">
                {{ __('workbench.settings.create_manufactured_ingredient') }}
            </button>
        </div>
        <p
            x-show="manufacturedIngredientMessage"
            x-cloak
            role="status"
            aria-live="polite"
            class="mt-2 text-xs leading-5"
            :class="manufacturedIngredientStatus === 'error' ? 'text-[var(--color-danger-strong)]' : 'text-[var(--color-ink-soft)]'"
            x-text="manufacturedIngredientMessage"
        ></p>
    </div>
</div>
