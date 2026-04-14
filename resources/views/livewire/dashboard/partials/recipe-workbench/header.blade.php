<section class="sk-card p-5">
 <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
 <div class="min-w-0 flex-1">
 <div class="flex flex-wrap items-center gap-2">
 <p class="sk-eyebrow">Formula name</p>
 <span class="rounded-full border border-[var(--color-line)] bg-[var(--color-panel)] px-3 py-1.5 text-xs font-medium text-[var(--color-ink-soft)]" x-text="formulaWorkbenchLabel"></span>
 <span x-show="productTypeName" x-cloak class="sk-badge sk-badge-neutral" x-text="productTypeName"></span>
 </div>
 <input x-model="formulaName" type="text" placeholder="Untitled soap formula" class="mt-2 w-full rounded-[1.25rem] border border-[var(--color-line)] bg-[var(--color-field)] px-4 py-3 text-2xl font-semibold text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]" />
 </div>

 <div class="flex flex-wrap gap-2 lg:justify-end">
 <button type="button" @click="saveDraft()" :disabled="!oilPercentageIsBalanced || isSaving" :class="!oilPercentageIsBalanced || isSaving ? 'cursor-not-allowed bg-[var(--color-line)] text-[var(--color-ink-soft)]' : 'bg-[var(--color-accent)] text-white hover:bg-[var(--color-accent-hover)]'" class="rounded-full px-4 py-2.5 text-sm font-medium transition">
 <span x-text="isSaving ? 'Saving…' : 'Save draft'"></span>
 </button>
 <button type="button" @click="saveRecipe()" :disabled="!oilPercentageIsBalanced || isSaving" :class="!oilPercentageIsBalanced || isSaving ? 'cursor-not-allowed border-[var(--color-line)] text-[var(--color-ink-soft)]' : 'border-[var(--color-line-strong)] bg-white text-[var(--color-ink-strong)] hover:bg-[var(--color-accent-soft)]'" class="rounded-full border px-4 py-2.5 text-sm font-medium transition">
 Save recipe
 </button>
 <a x-show="hasCurrentSavedFormula" x-cloak :href="savedRecipeUrl" class="inline-flex rounded-full border border-[var(--color-line)] px-4 py-2.5 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel)]">
 Open recipe
 </a>
 <button type="button" x-show="hasSavedRecipe" x-cloak @click="duplicateFormula()" :disabled="!oilPercentageIsBalanced || isSaving" :class="!oilPercentageIsBalanced || isSaving ? 'cursor-not-allowed border-[var(--color-line)] text-[var(--color-ink-soft)]' : 'border-[var(--color-line)] bg-white text-[var(--color-ink-soft)] hover:bg-[var(--color-accent-soft)]'" class="rounded-full border px-4 py-2.5 text-sm font-medium transition">
 Duplicate
 </button>
 </div>
 </div>

 <div class="mt-4 flex flex-wrap gap-2 border-t border-[var(--color-line)] pt-4">
 <template x-if="saveMessage">
 <span :class="saveStatus === 'error' ? 'border-[var(--color-danger-soft)] bg-[var(--color-danger-soft)] text-[var(--color-danger-strong)]' : 'border-[var(--color-line)] bg-white text-[var(--color-ink-soft)]'" class="rounded-full border px-3 py-1.5 text-xs font-medium" x-text="saveMessage"></span>
 </template>
 <span class="rounded-full border border-[var(--color-line)] bg-[var(--color-panel)] px-3 py-1.5 text-xs font-medium text-[var(--color-ink-soft)]" x-text="manufacturingModeLabel"></span>
 <span class="rounded-full border border-[var(--color-line)] bg-[var(--color-panel)] px-3 py-1.5 text-xs font-medium text-[var(--color-ink-soft)]" x-text="exposureModeLabel"></span>
 <span class="rounded-full border border-[var(--color-line)] bg-[var(--color-panel)] px-3 py-1.5 text-xs font-medium text-[var(--color-ink-soft)]" x-text="`Regime ${regulatoryRegime.toUpperCase()}`"></span>
 </div>

 <template x-if="needsCatalogReview">
 <div class="mt-4 rounded-[1.5rem] border border-[var(--color-warning-soft)] bg-[var(--color-warning-soft)] px-4 py-3 text-sm text-[var(--color-warning-strong)]">
 <p class="font-medium" x-text="catalogReview?.message"></p>
 </div>
 </template>

 @if (session('status'))
 <div class="mt-4 rounded-[1.5rem] border border-[var(--color-success-soft)] bg-[var(--color-success-soft)] px-4 py-3 text-sm text-[var(--color-success-strong)]">
 {{ session('status') }}
 </div>
 @endif
</section>
