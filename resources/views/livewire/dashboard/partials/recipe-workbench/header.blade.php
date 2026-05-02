<section class="sk-card p-5">
 <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
 <div class="min-w-0 flex-1">
	 <div class="flex flex-wrap items-center gap-2">
	 <p class="sk-eyebrow">Editable draft</p>
	 <span class="rounded-full border border-[var(--color-line)] bg-[var(--color-panel)] px-3 py-1.5 text-xs font-medium text-[var(--color-ink-soft)]" x-text="formulaWorkbenchLabel"></span>
	 <span x-show="productTypeName" x-cloak class="sk-badge sk-badge-neutral" x-text="productTypeName"></span>
	 <template x-if="saveMessage">
	 <span role="status" :class="saveStatus === 'error' ? 'border-[var(--color-danger-soft)] bg-[var(--color-danger-soft)] text-[var(--color-danger-strong)]' : 'border-[var(--color-line)] bg-white text-[var(--color-ink-soft)]'" class="rounded-full border px-3 py-1.5 text-xs font-medium" x-text="saveMessage"></span>
	 </template>
	 <template x-if="calculationPreviewMessage">
	 <span role="status" class="rounded-full border border-[var(--color-danger-soft)] bg-[var(--color-danger-soft)] px-3 py-1.5 text-xs font-medium text-[var(--color-danger-strong)]" x-text="calculationPreviewMessage"></span>
	 </template>
	 <span class="rounded-full border border-[var(--color-line)] bg-[var(--color-panel)] px-3 py-1.5 text-xs font-medium text-[var(--color-ink-soft)]" x-text="manufacturingModeLabel"></span>
	 <span class="rounded-full border border-[var(--color-line)] bg-[var(--color-panel)] px-3 py-1.5 text-xs font-medium text-[var(--color-ink-soft)]" x-text="exposureModeLabel"></span>
	 <span class="rounded-full border border-[var(--color-line)] bg-[var(--color-panel)] px-3 py-1.5 text-xs font-medium text-[var(--color-ink-soft)]" x-text="`Regime ${regulatoryRegime.toUpperCase()}`"></span>
	 </div>
	 <input x-model="formulaName" type="text" aria-label="Formula name" :placeholder="isCosmeticFormula ? 'Untitled cosmetic formula' : 'Untitled soap formula'" class="mt-2 w-full rounded-[1.25rem] border border-[var(--color-line)] bg-[var(--color-field)] px-4 py-3 text-2xl font-semibold text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]" />
	 </div>

 <div class="flex flex-wrap gap-2 lg:justify-end">
 <button type="button" @click="saveDraft()" :disabled="!canSaveDraft || isSaving" :class="!canSaveDraft || isSaving ? 'cursor-not-allowed bg-[var(--color-line)] text-[var(--color-ink-soft)]' : 'bg-[var(--color-accent)] text-white hover:bg-[var(--color-accent-hover)]'" class="rounded-full px-4 py-2.5 text-sm font-medium transition">
 <span x-text="isSaving ? 'Saving…' : 'Save draft'"></span>
 </button>
 <button type="button" @click="requestOfficialRecipeSave()" :disabled="!canSaveRecipe || isSaving" :class="!canSaveRecipe || isSaving ? 'cursor-not-allowed border-[var(--color-line)] text-[var(--color-ink-soft)]' : 'border-[var(--color-line-strong)] bg-white text-[var(--color-ink-strong)] hover:bg-[var(--color-accent-soft)]'" class="rounded-full border px-4 py-2.5 text-sm font-medium transition">
 Save as official recipe
 </button>
 <a x-show="hasCurrentSavedFormula" x-cloak :href="savedRecipeUrl" class="inline-flex rounded-full border border-[var(--color-line)] px-4 py-2.5 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel)]">
 Open official recipe
 </a>
 <button type="button" x-show="hasSavedRecipe" x-cloak @click="duplicateFormula()" :disabled="!canDuplicateFormula || isSaving" :class="!canDuplicateFormula || isSaving ? 'cursor-not-allowed border-[var(--color-line)] text-[var(--color-ink-soft)]' : 'border-[var(--color-line)] bg-white text-[var(--color-ink-soft)] hover:bg-[var(--color-accent-soft)]'" class="rounded-full border px-4 py-2.5 text-sm font-medium transition">
 Duplicate
	 </button>
	 </div>
	 </div>

 <div x-show="isOfficialSaveModalOpen" x-cloak role="dialog" aria-modal="true" aria-labelledby="official-save-heading" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @keydown.escape.window="isOfficialSaveModalOpen = false" @click.self="isOfficialSaveModalOpen = false">
 <div @keydown.escape="isOfficialSaveModalOpen = false" class="w-full max-w-lg rounded-lg border border-[var(--color-line)] bg-white p-5 shadow-xl">
 <p class="sk-eyebrow">Official recipe</p>
 <h2 id="official-save-heading" class="mt-2 text-xl font-semibold text-[var(--color-ink-strong)]">Update official recipe?</h2>
 <p class="mt-3 text-sm leading-7 text-[var(--color-ink-soft)]">
 This will replace the official saved recipe with your current draft. The official recipe is what you open, print, duplicate, and use as the reference formula.
 </p>
 <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
 <button type="button" @click="isOfficialSaveModalOpen = false" class="rounded-lg border border-[var(--color-line)] px-4 py-2.5 text-sm font-medium text-[var(--color-ink-strong)] transition hover:bg-[var(--color-panel)]">
 Cancel
 </button>
 <button type="button" @click="saveRecipe()" :disabled="isSaving" class="rounded-lg bg-[var(--color-accent-strong)] px-4 py-2.5 text-sm font-medium text-white transition hover:bg-[var(--color-accent)] disabled:cursor-not-allowed disabled:bg-[var(--color-line)] disabled:text-[var(--color-ink-soft)]">
 Update official recipe
 </button>
 </div>
 </div>
 </div>

	 <template x-if="needsCatalogReview">
 <div role="status" class="mt-4 rounded-[1.5rem] border border-[var(--color-warning-soft)] bg-[var(--color-warning-soft)] px-4 py-3 text-sm text-[var(--color-warning-strong)]">
 <p class="font-medium" x-text="catalogReview?.message"></p>
 </div>
 </template>

 @if (session('status'))
 <div role="status" class="mt-4 rounded-[1.5rem] border border-[var(--color-success-soft)] bg-[var(--color-success-soft)] px-4 py-3 text-sm text-[var(--color-success-strong)]">
 {{ session('status') }}
 </div>
 @endif
</section>
