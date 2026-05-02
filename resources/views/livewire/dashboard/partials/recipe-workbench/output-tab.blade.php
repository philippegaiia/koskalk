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
 <p class="sk-eyebrow">Batch weight</p>
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

 @include('livewire.dashboard.partials.recipe-workbench.ingredient-list-preview')
@else
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

 <div class="mt-4 grid gap-3 md:grid-cols-3">
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

 @include('livewire.dashboard.partials.recipe-workbench.ingredient-list-preview')

 <section class="overflow-hidden sk-card">
 <div class="border-b border-[var(--color-line)] px-5 py-4">
 <p class="sk-eyebrow">Ingredient basis</p>
 <p class="mt-1 text-sm text-[var(--color-ink-soft)]">These percentages are normalized to 100% across the main ingredient rows only, using the cured soap basis.</p>
 </div>

 <template x-if="drySoapIngredientRows.length > 0">
 <div class="overflow-x-auto">
 <table class="min-w-full divide-y divide-[var(--color-line)] text-sm">
 <thead class="bg-[var(--color-panel)] text-left text-xs font-semibold tracking-[0.14em] text-[var(--color-ink-soft)] uppercase">
 <tr>
 <th class="px-5 py-3">Label</th>
 <th class="px-5 py-3">Role</th>
 <th class="px-5 py-3">Dry soap %</th>
 <th class="px-5 py-3" x-text="`Weight (${oilUnit})`"></th>
 <th class="px-5 py-3">Sources</th>
 </tr>
 </thead>
 <tbody class="divide-y divide-[var(--color-line)] bg-white">
 <template x-for="row in drySoapIngredientRows" :key="row.label">
 <tr>
 <td class="px-5 py-4 align-top font-medium text-[var(--color-ink-strong)]" x-text="row.label"></td>
 <td class="px-5 py-4 align-top text-[var(--color-ink-soft)]" x-text="outputRowKindLabel(row)"></td>
 <td class="numeric px-5 py-4 align-top font-medium text-[var(--color-ink-strong)]" x-text="`${format(row.percent_of_dry_basis, 3)}%`"></td>
 <td class="numeric px-5 py-4 align-top text-[var(--color-ink-soft)]" x-text="`${format(row.adjusted_weight, 2)}`"></td>
 <td class="px-5 py-4 align-top text-[var(--color-ink-soft)]">
     <template x-for="(source, idx) in row.source_ingredients" :key="idx">
         <span class="inline-flex items-center gap-1">
             <span x-show="row.source_is_user_owned?.[idx]" class="inline-block size-1.5 rounded-full bg-amber-400" title="Ingredient not curated by the platform"></span>
             <span x-text="source"></span>
         </span>
     </template>
 </td>
 </tr>
 </template>
 <tr class="bg-[var(--color-panel)]">
 <td class="px-5 py-4 font-semibold text-[var(--color-ink-strong)]">Total</td>
 <td class="px-5 py-4 text-[var(--color-ink-soft)]">Excluding allergens</td>
 <td class="numeric px-5 py-4 font-semibold text-[var(--color-ink-strong)]" x-text="`${format(drySoapIngredientTotalPercent, 3)}%`"></td>
 <td class="numeric px-5 py-4 text-[var(--color-ink-soft)]" x-text="`${format(drySoapIngredientTotalWeight, 2)}`"></td>
 <td class="px-5 py-4"></td>
 </tr>
 </tbody>
 </table>
 </div>
 </template>

 <template x-if="drySoapIngredientRows.length === 0">
 <div class="px-5 py-6 text-sm text-[var(--color-ink-soft)]">
 Add enough formula data to resolve the dry-soap ingredient output.
 </div>
 </template>
 </section>

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
             <span x-show="row.source_is_user_owned?.[idx]" class="inline-block size-1.5 rounded-full bg-amber-400" title="Ingredient not curated by the platform"></span>
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
     <p class="rounded-lg bg-amber-50 px-4 py-2.5 text-xs text-amber-700">
         <span class="inline-block size-1.5 rounded-full bg-amber-400 mr-1"></span>
         Ingredient not curated by the platform. Compliance data is user-maintained.
     </p>
 </template>
@endif
</div>
