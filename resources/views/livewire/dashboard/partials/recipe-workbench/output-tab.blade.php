@php
    $isCosmeticWorkbench = $isCosmeticWorkbench ?? false;
    $isPublicCalculator = $isPublicCalculator ?? false;
@endphp

<div x-show="activeWorkbenchTab === 'output'" x-cloak role="tabpanel" aria-labelledby="tab-output" id="panel-output" class="space-y-6 pb-24">
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
 <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_290px] lg:items-start">
 <section class="sk-card p-5">
 <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
 <div class="min-w-0">
 <p class="sk-eyebrow">Cured soap output</p>
 <p class="mt-1 max-w-3xl text-sm text-[var(--color-ink-soft)]">The cured-bar ingredient output used to prepare labels and review declared fragrance components.</p>
 </div>
 <span class="rounded-full border border-[var(--color-line)] bg-[var(--color-panel)] px-3 py-1.5 text-xs font-medium text-[var(--color-ink-soft)]">Cured basis</span>
 </div>

 <div class="mt-4">
 <div class="min-w-0 overflow-hidden rounded-lg border border-[var(--color-line)] bg-white">
 <div class="border-b border-[var(--color-line)] bg-[var(--color-panel)] px-4 py-3">
 <p class="font-medium text-[var(--color-ink-strong)]">Cured soap composition</p>
 <p class="mt-1 text-xs leading-5 text-[var(--color-ink-soft)]">Main ingredient rows normalized on the cured soap basis.</p>
 </div>
 <template x-if="curedSoapIngredientRows.length > 0">
 <div class="overflow-x-auto">
 <table class="min-w-full divide-y divide-[var(--color-line)] text-sm">
 <thead class="text-left text-xs font-semibold tracking-[0.14em] text-[var(--color-ink-soft)] uppercase">
 <tr>
 <th class="px-4 py-3">Label</th>
 <th class="px-4 py-3">Role</th>
 <th class="px-4 py-3">% SOAP</th>
 <th class="px-4 py-3" x-text="`Weight (${oilUnit})`"></th>
 <th class="px-4 py-3">Sources</th>
 </tr>
 </thead>
 <tbody class="divide-y divide-[var(--color-line)]">
 <template x-for="row in curedSoapIngredientRows" :key="row.label">
 <tr>
 <td class="px-4 py-3 align-middle font-medium text-[var(--color-ink-strong)]" x-text="row.label"></td>
 <td class="px-4 py-3 align-middle text-[var(--color-ink-soft)]" x-text="outputRowKindLabel(row)"></td>
 <td class="numeric px-4 py-3 align-middle font-medium text-[var(--color-ink-strong)]" x-text="`${format(row.percent_of_cured_basis, 3)}%`"></td>
 <td class="numeric px-4 py-3 align-middle text-[var(--color-ink-soft)]" x-text="`${format(row.adjusted_weight, 2)}`"></td>
 <td class="px-4 py-3 align-middle text-[var(--color-ink-soft)]">
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
 <td class="px-4 py-3 text-[var(--color-ink-soft)]">Cured soap basis</td>
 <td class="numeric px-4 py-3 font-semibold text-[var(--color-ink-strong)]" x-text="`${format(curedSoapIngredientTotalPercent, 3)}%`"></td>
 <td class="numeric px-4 py-3 text-[var(--color-ink-soft)]" x-text="`${format(curedSoapIngredientTotalWeight, 2)}`"></td>
 <td class="px-4 py-3"></td>
 </tr>
 </tbody>
 </table>
 </div>
 </template>
 <template x-if="curedSoapIngredientRows.length === 0">
 <div class="px-4 py-5 text-sm text-[var(--color-ink-soft)]">
 Add enough formula data to resolve the cured-soap ingredient output.
 </div>
 </template>
 </div>
 </div>
 </section>

 <section class="sk-card p-5">
 <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
 <div class="min-w-0">
 <p class="sk-eyebrow">Label output basis</p>
 <p class="mt-1 max-w-3xl text-sm text-[var(--color-ink-soft)]">Label percentages use the cured soap weight, including 11% residual water. Allergens are shown separately but are already included in their source oils.</p>
 </div>
 </div>

 <div class="mt-4 grid gap-3 sm:grid-cols-3 lg:grid-cols-1">
 <div class="sk-inset p-4">
 <p class="sk-eyebrow">Cured bar basis</p>
 <p class="numeric mt-3 text-xl font-semibold text-[var(--color-ink-strong)]" x-text="`${format(curedSoapOutputBasisWeight, 1)} ${oilUnit}`"></p>
 </div>
 <div class="sk-inset p-4">
 <p class="sk-eyebrow">Residual water</p>
 <p class="numeric mt-3 text-xl font-semibold text-[var(--color-ink-strong)]" x-text="`${format(curedSoapResidualWaterWeight, 1)} ${oilUnit}`"></p>
 </div>
 <div class="sk-inset p-4">
 <p class="sk-eyebrow">Cured soap total</p>
 <p class="numeric mt-3 text-xl font-semibold text-[var(--color-ink-strong)]" x-text="`${format(curedSoapIngredientTotalPercent, 1)}%`"></p>
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

 <template x-if="curedSoapAllergenRows.length > 0">
 <div class="overflow-x-auto">
 <table class="min-w-full divide-y divide-[var(--color-line)] text-sm">
 <thead class="bg-[var(--color-panel)] text-left text-xs font-semibold tracking-[0.14em] text-[var(--color-ink-soft)] uppercase">
 <tr>
 <th class="px-5 py-3">Allergen</th>
 <th class="px-5 py-3">% SOAP</th>
 <th class="px-5 py-3" x-text="`Weight (${oilUnit})`"></th>
 <th class="px-5 py-3">Sources</th>
 </tr>
 </thead>
 <tbody class="divide-y divide-[var(--color-line)] bg-white">
 <template x-for="row in curedSoapAllergenRows" :key="row.label">
 <tr>
 <td class="px-5 py-4 align-middle font-medium text-[var(--color-ink-strong)]" x-text="row.label"></td>
 <td class="numeric px-5 py-4 align-middle font-medium text-[var(--color-ink-strong)]" x-text="`${format(row.percent_of_cured_basis, 4)}%`"></td>
 <td class="numeric px-5 py-4 align-middle text-[var(--color-ink-soft)]" x-text="`${format(row.adjusted_weight, 4)}`"></td>
 <td class="px-5 py-4 align-middle text-[var(--color-ink-soft)]">
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

 <template x-if="curedSoapAllergenRows.length === 0">
 <div class="px-5 py-6 text-sm text-[var(--color-ink-soft)]">
 No declared allergens are currently appended to the generated list.
 </div>
 </template>
 </section>

 <template x-if="curedSoapIngredientRows.some(row => row.source_is_user_owned?.some(Boolean)) || curedSoapAllergenRows.some(row => row.source_is_user_owned?.some(Boolean))">
     <p class="px-1 text-[0.625rem] leading-4 text-[var(--color-ink-soft)]">
         <span class="mr-1 inline-block size-1.5 rounded-full bg-[var(--color-ink-soft)] opacity-60"></span>
         User-created or user-modified ingredient. Data has not been verified by Soapkraft.
     </p>
 </template>
@endif

 @unless ($isPublicCalculator)
        <x-workflow-action-bar max-width="max-w-app" data-output-save-bar>
 <x-slot:leading>
 <p x-show="saveMessage" class="text-sm text-[var(--color-ink-soft)]" role="status" x-text="saveMessage"></p>
 </x-slot:leading>
 <button type="button" @click="publish()" :disabled="isFormulaLocked || !canSaveRecipe || isSaving" class="sk-btn sk-btn-primary">
 <span x-text="isFormulaLocked ? t('header.locked') : (isSaving ? t('header.saving') : t('header.save'))"></span>
 </button>
 </x-workflow-action-bar>
 @endunless
</div>
