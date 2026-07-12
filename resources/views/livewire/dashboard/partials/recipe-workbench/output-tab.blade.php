@php($isCosmeticWorkbench = $isCosmeticWorkbench ?? false)

<div x-show="activeWorkbenchTab === 'output'" x-cloak role="tabpanel" aria-labelledby="tab-output" id="panel-output" class="space-y-6">
@if ($isCosmeticWorkbench)
 <section class="sk-card p-5">
 <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
 <div class="min-w-0">
 <p class="sk-eyebrow">Formula output</p>
 <p class="mt-1 max-w-3xl text-sm text-[var(--color-ink-soft)]">This view reads the full cosmetic formula basis.</p>
 </div>
 <span class="rounded-full border border-[var(--color-line)] bg-[var(--color-panel)] px-3 py-1.5 text-xs font-medium text-[var(--color-ink-soft)]">Full formula basis</span>
 </div>

 <div class="mt-4 grid gap-3 md:grid-cols-3">
 <div class="sk-inset p-4">
 <p class="sk-eyebrow">Total batch quantity</p>
 <p class="numeric mt-3 text-2xl font-semibold text-[var(--color-ink-strong)]" x-text="`${format(oilWeight, 3)} ${oilUnit}`"></p>
 </div>
 <div class="sk-inset p-4">
 <p class="sk-eyebrow">Formula total</p>
 <p class="numeric mt-3 text-2xl font-semibold text-[var(--color-ink-strong)]" x-text="`${format(totalOilPercentage(), 2)}%`"></p>
 </div>
 <div class="sk-inset p-4">
 <p class="sk-eyebrow">Ingredient rows</p>
 <p class="numeric mt-3 text-2xl font-semibold text-[var(--color-ink-strong)]" x-text="cosmeticFormulaRows().length"></p>
 </div>
 </div>
 </section>

 <section class="sk-card p-5">
 <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
 <div class="min-w-0">
 <p class="sk-eyebrow">Ingredient output</p>
 <p class="mt-1 max-w-3xl text-sm text-[var(--color-ink-soft)]">Ingredients sorted from highest to lowest formula share.</p>
 </div>
 <span class="rounded-full border border-[var(--color-line)] bg-[var(--color-panel)] px-3 py-1.5 text-xs font-medium text-[var(--color-ink-soft)]">Descending</span>
 </div>

 <template x-if="cosmeticOutputIngredientRows.length > 0">
 <div class="mt-4 overflow-x-auto rounded-lg border border-[var(--color-line)] bg-white">
 <table class="min-w-full divide-y divide-[var(--color-line)] text-sm">
 <thead class="text-left text-xs font-semibold tracking-[0.14em] text-[var(--color-ink-soft)] uppercase">
 <tr>
 <th class="px-4 py-3">Ingredient</th>
 <th class="px-4 py-3">Phase</th>
 <th class="px-4 py-3">% formula</th>
 <th class="px-4 py-3" x-text="`Weight (${oilUnit})`"></th>
 </tr>
 </thead>
 <tbody class="divide-y divide-[var(--color-line)]">
 <template x-for="row in cosmeticOutputIngredientRows" :key="row.id">
 <tr>
 <td class="px-4 py-3 align-top">
 <p class="font-medium text-[var(--color-ink-strong)]" x-text="row.name"></p>
 <p x-show="row.inci_name" class="mt-1 text-xs text-[var(--color-ink-soft)]" x-text="row.inci_name"></p>
 </td>
 <td class="px-4 py-3 align-top text-[var(--color-ink-soft)]" x-text="row.phase"></td>
 <td class="numeric px-4 py-3 align-top font-medium text-[var(--color-ink-strong)]" x-text="`${format(row.percentage, 3)}%`"></td>
 <td class="numeric px-4 py-3 align-top text-[var(--color-ink-soft)]" x-text="`${format(row.weight, 2)}`"></td>
 </tr>
 </template>
 <tr class="bg-[var(--color-panel)]">
 <td class="px-4 py-3 font-semibold text-[var(--color-ink-strong)]">Total</td>
 <td class="px-4 py-3 text-[var(--color-ink-soft)]">Full formula</td>
 <td class="numeric px-4 py-3 font-semibold text-[var(--color-ink-strong)]" x-text="`${format(cosmeticOutputIngredientTotalPercent, 3)}%`"></td>
 <td class="numeric px-4 py-3 text-[var(--color-ink-soft)]" x-text="`${format(cosmeticOutputIngredientTotalWeight, 2)}`"></td>
 </tr>
 </tbody>
 </table>
 </div>
 </template>
 <template x-if="cosmeticOutputIngredientRows.length === 0">
 <div class="mt-4 rounded-lg bg-[var(--color-field)] px-4 py-5 text-sm text-[var(--color-ink-soft)]">
 Add ingredients to build the cosmetic output list.
 </div>
 </template>
 </section>

 @include('livewire.dashboard.partials.recipe-workbench.ingredient-list-preview')
 @include('livewire.dashboard.partials.recipe-workbench.restrictions-preview')
@else
 <div class="grid gap-6 lg:grid-cols-[minmax(0,7fr)_minmax(18rem,3fr)] lg:items-start">
 <section class="sk-card p-5">
 <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
 <div class="min-w-0">
 <p class="sk-eyebrow">Production tables</p>
 <p class="mt-1 max-w-3xl text-sm text-[var(--color-ink-soft)]">The practical figures for weighing the batch and reading the cured bar composition.</p>
 </div>
 <span class="rounded-full border border-[var(--color-line)] bg-[var(--color-panel)] px-3 py-1.5 text-xs font-medium text-[var(--color-ink-soft)]">Figures first</span>
 </div>

 <div class="mt-4 grid gap-4">
 <div class="min-w-0 overflow-hidden rounded-lg border border-[var(--color-line)] bg-white">
 <div class="border-b border-[var(--color-line)] bg-[var(--color-panel)] px-4 py-3">
 <p class="font-medium text-[var(--color-ink-strong)]">Batch ingredients</p>
 <p class="mt-1 text-xs leading-5 text-[var(--color-ink-soft)]">What to weigh into the batch before saponification, including lye and water.</p>
 </div>
 <template x-if="batchIngredientRows.length > 0">
 <div class="overflow-x-auto">
 <table class="min-w-full divide-y divide-[var(--color-line)] text-sm">
 <thead class="text-left text-xs font-semibold tracking-[0.14em] text-[var(--color-ink-soft)] uppercase">
 <tr>
 <th class="px-4 py-3">Label</th>
 <th class="px-4 py-3">Stage</th>
 <th class="px-4 py-3">Formula %</th>
 <th class="px-4 py-3" x-text="`Weight (${oilUnit})`"></th>
 </tr>
 </thead>
 <tbody class="divide-y divide-[var(--color-line)]">
 <template x-for="row in batchIngredientRows" :key="row.id">
 <tr>
 <td class="px-4 py-3 align-top font-medium text-[var(--color-ink-strong)]" x-text="row.name"></td>
 <td class="px-4 py-3 align-top text-[var(--color-ink-soft)]" x-text="row.stage"></td>
 <td class="numeric px-4 py-3 align-top font-medium text-[var(--color-ink-strong)]" x-text="`${format(row.percent_of_formula, 3)}%`"></td>
 <td class="numeric px-4 py-3 align-top text-[var(--color-ink-soft)]" x-text="`${format(row.weight, 2)}`"></td>
 </tr>
 </template>
 <tr class="bg-[var(--color-panel)]">
 <td class="px-4 py-3 font-semibold text-[var(--color-ink-strong)]">Total</td>
 <td class="px-4 py-3 text-[var(--color-ink-soft)]">Wet batch</td>
 <td class="numeric px-4 py-3 font-semibold text-[var(--color-ink-strong)]" x-text="`${format(batchIngredientTotalPercent, 3)}%`"></td>
 <td class="numeric px-4 py-3 text-[var(--color-ink-soft)]" x-text="`${format(batchIngredientTotalWeight, 2)}`"></td>
 </tr>
 </tbody>
 </table>
 </div>
 </template>
 <template x-if="batchIngredientRows.length === 0">
 <div class="px-4 py-5 text-sm text-[var(--color-ink-soft)]">
 Add enough formula data to resolve the batch ingredient figures.
 </div>
 </template>
 </div>

 <div class="min-w-0 overflow-hidden rounded-lg border border-[var(--color-line)] bg-white">
 <div class="border-b border-[var(--color-line)] bg-[var(--color-panel)] px-4 py-3">
 <p class="font-medium text-[var(--color-ink-strong)]">Cured soap composition</p>
 <p class="mt-1 text-xs leading-5 text-[var(--color-ink-soft)]">Main ingredient rows normalized on the cured soap basis.</p>
 </div>
 <template x-if="drySoapIngredientRows.length > 0">
 <div class="overflow-x-auto">
 <table class="min-w-full divide-y divide-[var(--color-line)] text-sm">
 <thead class="text-left text-xs font-semibold tracking-[0.14em] text-[var(--color-ink-soft)] uppercase">
 <tr>
 <th class="px-4 py-3">Label</th>
 <th class="px-4 py-3">Role</th>
 <th class="px-4 py-3">Dry soap %</th>
 <th class="px-4 py-3" x-text="`Weight (${oilUnit})`"></th>
 <th class="px-4 py-3">Sources</th>
 </tr>
 </thead>
 <tbody class="divide-y divide-[var(--color-line)]">
 <template x-for="row in drySoapIngredientRows" :key="row.label">
 <tr>
 <td class="px-4 py-3 align-top font-medium text-[var(--color-ink-strong)]" x-text="row.label"></td>
 <td class="px-4 py-3 align-top text-[var(--color-ink-soft)]" x-text="outputRowKindLabel(row)"></td>
 <td class="numeric px-4 py-3 align-top font-medium text-[var(--color-ink-strong)]" x-text="`${format(row.percent_of_dry_basis, 3)}%`"></td>
 <td class="numeric px-4 py-3 align-top text-[var(--color-ink-soft)]" x-text="`${format(row.adjusted_weight, 2)}`"></td>
 <td class="px-4 py-3 align-top text-[var(--color-ink-soft)]">
     <template x-for="(source, idx) in row.source_ingredients" :key="idx">
         <span class="inline-flex items-center gap-1">
             <span x-show="row.source_is_user_owned?.[idx]" class="inline-block size-1.5 rounded-full bg-[var(--color-ink-soft)] opacity-60" title="User-created or user-modified ingredient"></span>
             <span x-text="source"></span>
         </span>
     </template>
 </td>
 </tr>
 </template>
 <tr class="bg-[var(--color-panel)]">
 <td class="px-4 py-3 font-semibold text-[var(--color-ink-strong)]">Total</td>
 <td class="px-4 py-3 text-[var(--color-ink-soft)]">Excluding allergens</td>
 <td class="numeric px-4 py-3 font-semibold text-[var(--color-ink-strong)]" x-text="`${format(drySoapIngredientTotalPercent, 3)}%`"></td>
 <td class="numeric px-4 py-3 text-[var(--color-ink-soft)]" x-text="`${format(drySoapIngredientTotalWeight, 2)}`"></td>
 <td class="px-4 py-3"></td>
 </tr>
 </tbody>
 </table>
 </div>
 </template>
 <template x-if="drySoapIngredientRows.length === 0">
 <div class="px-4 py-5 text-sm text-[var(--color-ink-soft)]">
 Add enough formula data to resolve the dry-soap ingredient output.
 </div>
 </template>
 </div>
 </div>
 </section>

 <section class="sk-card p-5">
 <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
 <div class="min-w-0">
 <p class="sk-eyebrow">Dry soap output</p>
 <p class="mt-1 max-w-3xl text-sm text-[var(--color-ink-soft)]">This view normalizes the selected acceptable ingredient list on the cured bar basis. It uses the same 11% residual water assumption as the cure-weight card, and allergens stay outside the 100% ingredient total.</p>
 </div>
 <div class="flex flex-wrap items-center gap-2">
 <span class="rounded-full border border-[var(--color-line)] bg-[var(--color-panel)] px-3 py-1.5 text-xs font-medium text-[var(--color-ink-soft)]">Dry soap basis</span>
 <span class="numeric rounded-full border border-[var(--color-line)] bg-white px-3 py-1.5 text-xs font-medium text-[var(--color-ink-soft)]">11% residual water</span>
 </div>
 </div>

 <div class="mt-4 grid gap-3 sm:grid-cols-3 lg:grid-cols-1">
 <div class="sk-inset p-4">
 <p class="sk-eyebrow">Cured bar basis</p>
 <p class="numeric mt-3 text-2xl font-semibold text-[var(--color-ink-strong)]" x-text="`${format(drySoapOutputBasisWeight, 1)} ${oilUnit}`"></p>
 </div>
 <div class="sk-inset p-4">
 <p class="sk-eyebrow">Residual water</p>
 <p class="numeric mt-3 text-2xl font-semibold text-[var(--color-ink-strong)]" x-text="`${format(drySoapResidualWaterWeight, 1)} ${oilUnit}`"></p>
 </div>
 <div class="sk-inset p-4">
 <p class="sk-eyebrow">Ingredient-only total</p>
 <p class="numeric mt-3 text-2xl font-semibold text-[var(--color-ink-strong)]" x-text="`${format(drySoapIngredientTotalPercent, 1)}%`"></p>
 </div>
 </div>
 </section>
 </div>

 @include('livewire.dashboard.partials.recipe-workbench.ingredient-list-preview')
 @include('livewire.dashboard.partials.recipe-workbench.restrictions-preview')

 <section class="overflow-hidden sk-card">
 <div class="border-b border-[var(--color-line)] px-5 py-4">
 <p class="sk-eyebrow">Declared allergens</p>
 <p class="mt-1 text-sm text-[var(--color-ink-soft)]">These are listed on the same cured basis for reference, but they are not counted inside the 100% ingredient total because they are already part of aromatic ingredients.</p>
 </div>

 <template x-if="drySoapAllergenRows.length > 0">
 <div class="overflow-x-auto">
 <table class="min-w-full divide-y divide-[var(--color-line)] text-sm">
 <thead class="bg-[var(--color-panel)] text-left text-xs font-semibold tracking-[0.14em] text-[var(--color-ink-soft)] uppercase">
 <tr>
 <th class="px-5 py-3">Allergen</th>
 <th class="px-5 py-3">Dry soap %</th>
 <th class="px-5 py-3" x-text="`Weight (${oilUnit})`"></th>
 <th class="px-5 py-3">Sources</th>
 </tr>
 </thead>
 <tbody class="divide-y divide-[var(--color-line)] bg-white">
 <template x-for="row in drySoapAllergenRows" :key="row.label">
 <tr>
 <td class="px-5 py-4 align-top font-medium text-[var(--color-ink-strong)]" x-text="row.label"></td>
 <td class="numeric px-5 py-4 align-top font-medium text-[var(--color-ink-strong)]" x-text="`${format(row.percent_of_dry_basis, 4)}%`"></td>
 <td class="numeric px-5 py-4 align-top text-[var(--color-ink-soft)]" x-text="`${format(row.adjusted_weight, 4)}`"></td>
 <td class="px-5 py-4 align-top text-[var(--color-ink-soft)]">
     <template x-for="(source, idx) in row.source_ingredients" :key="idx">
         <span class="inline-flex items-center gap-1">
             <span x-show="row.source_is_user_owned?.[idx]" class="inline-block size-1.5 rounded-full bg-[var(--color-ink-soft)] opacity-60" title="User-created or user-modified ingredient"></span>
             <span x-text="source"></span>
         </span>
     </template>
 </td>
 </tr>
 </template>
 </tbody>
 </table>
 </div>
 </template>

 <template x-if="drySoapAllergenRows.length === 0">
 <div class="px-5 py-6 text-sm text-[var(--color-ink-soft)]">
 No declared allergens are currently appended to the generated list.
 </div>
 </template>
 </section>

 <template x-if="drySoapIngredientRows.some(row => row.source_is_user_owned?.some(Boolean)) || drySoapAllergenRows.some(row => row.source_is_user_owned?.some(Boolean))">
     <p class="px-1 text-[0.625rem] leading-4 text-[var(--color-ink-soft)]">
         <span class="mr-1 inline-block size-1.5 rounded-full bg-[var(--color-ink-soft)] opacity-60"></span>
         User-created or user-modified ingredient. Data has not been verified by Soapkraft.
     </p>
 </template>
@endif
</div>
