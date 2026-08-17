<section class="sk-card p-5" data-production-output-settings aria-labelledby="setting-production-output">
    <div class="flex flex-col gap-1">
        <p id="setting-production-output" class="sk-eyebrow">{{ __('workbench.settings.production_output') }}</p>
        <p class="text-sm leading-6 text-[var(--color-ink-soft)]">{{ __('workbench.settings.production_output_help') }}</p>
    </div>

    <div class="mt-5 grid gap-4 lg:grid-cols-2">
        <div class="sk-inset sk-tone-info p-4">
            <div role="radiogroup" aria-labelledby="setting-production-output" class="flex flex-wrap gap-2">
                <button type="button" role="radio" :aria-checked="productionOutputType === 'finished_product'" @click="productionOutputType = 'finished_product'; outputIngredientId = ''" :class="productionOutputType === 'finished_product' ? 'bg-[var(--color-active)] text-[var(--color-on-active)] shadow-sm' : 'bg-[var(--color-control)] text-[var(--color-ink-soft)] hover:bg-[var(--color-panel)]'" class="rounded-full px-4 py-2.5 text-xs font-medium transition">{{ __('workbench.settings.finished_product') }}</button>
                <button type="button" role="radio" :aria-checked="productionOutputType === 'manufactured_ingredient'" @click="productionOutputType = 'manufactured_ingredient'" :class="productionOutputType === 'manufactured_ingredient' ? 'bg-[var(--color-active)] text-[var(--color-on-active)] shadow-sm' : 'bg-[var(--color-control)] text-[var(--color-ink-soft)] hover:bg-[var(--color-panel)]'" class="rounded-full px-4 py-2.5 text-xs font-medium transition">{{ __('workbench.settings.manufactured_ingredient') }}</button>
            </div>
            <p class="mt-3 text-xs leading-5 text-[var(--color-ink-soft)]" x-show="productionOutputType === 'finished_product'">{{ __('workbench.settings.finished_product_help') }}</p>
            <p class="mt-3 text-xs leading-5 text-[var(--color-ink-soft)]" x-show="productionOutputType === 'manufactured_ingredient'" x-cloak>{{ __('workbench.settings.manufactured_ingredient_help') }}</p>
        </div>

        <div class="sk-inset p-4">
            <label for="production-output-ready-delay" class="sk-eyebrow">{{ __('workbench.settings.ready_delay_override') }}</label>
            <input id="production-output-ready-delay" x-model="readyDelayDays" type="number" min="0" step="1" placeholder="{{ __('workbench.settings.ready_delay_override_placeholder') }}" class="mt-3 w-full rounded-lg bg-[var(--color-field)] px-4 py-3 text-sm text-[var(--color-ink-strong)] transition" />
            <p class="mt-2 text-xs leading-5 text-[var(--color-ink-soft)]">{{ __('workbench.settings.ready_delay_override_help') }}</p>
        </div>
    </div>

    <div x-show="productionOutputType === 'finished_product'" class="mt-4 grid gap-4 lg:grid-cols-2">
        <div class="sk-inset p-4">
            <label for="product-reference" class="sk-eyebrow">{{ __('workbench.settings.product_reference') }}</label>
            <input id="product-reference" x-model="productReference" type="text" maxlength="100" autocomplete="off" placeholder="{{ __('workbench.settings.product_reference_placeholder') }}" class="mt-3 w-full rounded-lg bg-[var(--color-field)] px-4 py-3 text-sm text-[var(--color-ink-strong)] transition" />
            <p class="mt-2 text-xs leading-5 text-[var(--color-ink-soft)]">{{ __('workbench.settings.product_reference_help') }}</p>
        </div>

        <div class="sk-inset p-4">
            <p class="sk-eyebrow">{{ __('workbench.settings.nominal_content') }}</p>
            <div class="mt-3 grid grid-cols-[minmax(0,1fr)_8rem] gap-2">
                <label for="nominal-content-value" class="sr-only">{{ __('workbench.settings.nominal_content_value') }}</label>
                <input id="nominal-content-value" x-model="nominalContentValue" type="text" inputmode="decimal" placeholder="100" class="numeric w-full rounded-lg bg-[var(--color-field)] px-4 py-3 text-sm text-[var(--color-ink-strong)] transition" />
                <label for="nominal-content-unit" class="sr-only">{{ __('workbench.settings.nominal_content_unit') }}</label>
                <select id="nominal-content-unit" x-model="nominalContentUnit" class="w-full rounded-lg bg-[var(--color-field)] px-3 py-3 text-sm text-[var(--color-ink-strong)] transition">
                    <option value="">{{ __('workbench.common.choose_later') }}</option>
                    <option value="g">g</option>
                    <option value="kg">kg</option>
                    <option value="ml">ml</option>
                    <option value="l">l</option>
                    <option value="oz">oz</option>
                    <option value="lb">lb</option>
                    <option value="fl_oz">fl oz</option>
                </select>
            </div>
            <p class="mt-2 text-xs leading-5 text-[var(--color-ink-soft)]">{{ __('workbench.settings.nominal_content_help') }}</p>
        </div>
    </div>

    <div x-show="productionOutputType === 'finished_product'" class="mt-4 flex flex-col gap-3 rounded-lg border border-[var(--color-line)] bg-[var(--color-panel)] p-4 sm:flex-row sm:items-center sm:justify-between">
        <p class="max-w-2xl text-xs leading-5 text-[var(--color-ink-soft)]">{{ __('workbench.settings.one_batch_one_product_help') }}</p>
        <button type="button" x-show="hasSavedRecipe" x-cloak @click="duplicateFormula()" :disabled="!canDuplicateFormula || isSaving" class="sk-btn shrink-0 bg-[var(--color-active)] text-[var(--color-on-active)] hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-60">{{ __('workbench.settings.duplicate_for_another_size') }}</button>
    </div>

    <div x-show="productionOutputType === 'manufactured_ingredient'" x-cloak class="mt-4 grid gap-4 lg:grid-cols-2">
        <div class="sk-inset p-4">
            <label for="production-output-ingredient" class="sk-eyebrow">{{ __('workbench.settings.choose_manufactured_ingredient') }}</label>
            <select id="production-output-ingredient" x-model="outputIngredientId" class="mt-3 w-full rounded-lg bg-[var(--color-field)] px-3 py-2.5 text-sm text-[var(--color-ink-strong)] transition">
                <option value="">{{ __('workbench.common.choose_later') }}</option>
                <template x-for="ingredient in manufacturedIngredients" :key="ingredient.id">
                    <option :value="String(ingredient.id)" x-text="ingredient.name"></option>
                </template>
            </select>
        </div>

        <div class="sk-inset p-4">
            <label for="new-manufactured-ingredient-name" class="sk-eyebrow">{{ __('workbench.settings.new_manufactured_ingredient_name') }}</label>
            <div class="mt-3 flex gap-2">
                <input id="new-manufactured-ingredient-name" x-model="manufacturedIngredientName" @keydown.enter.prevent="createManufacturedIngredient()" type="text" maxlength="255" class="min-w-0 flex-1 rounded-lg bg-[var(--color-field)] px-4 py-2.5 text-sm text-[var(--color-ink-strong)] transition" />
                <button type="button" @click="createManufacturedIngredient()" :disabled="manufacturedIngredientStatus === 'saving'" class="sk-btn shrink-0 bg-[var(--color-active)] text-[var(--color-on-active)] hover:opacity-90 disabled:cursor-wait disabled:opacity-60">{{ __('workbench.settings.create_manufactured_ingredient') }}</button>
            </div>
            <p x-show="manufacturedIngredientMessage" x-cloak class="mt-2 text-xs leading-5" :class="manufacturedIngredientStatus === 'error' ? 'text-[var(--color-danger-strong)]' : 'text-[var(--color-ink-soft)]'" x-text="manufacturedIngredientMessage"></p>
        </div>
    </div>
</section>
