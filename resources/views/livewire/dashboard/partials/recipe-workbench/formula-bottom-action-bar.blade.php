@php($isPublicCalculator = $isPublicCalculator ?? false)

<div class="pointer-events-none fixed bottom-0 left-0 right-0 z-30 px-3 pb-3 sm:px-5 lg:left-[var(--app-sidebar-width,0rem)]">
 <section id="formula-save-bar" aria-label="Formula save bar" class="pointer-events-auto mx-auto max-w-[90rem] rounded-[1rem] bg-[color-mix(in_oklab,var(--color-panel)_82%,transparent)] px-4 py-3 shadow-[0_-8px_24px_rgba(60,50,30,0.10)] backdrop-blur-md">
 <span class="sr-only">Zero quantity diagnostics preserve formula ingredients at 0.</span>
 <div id="formula-bottom-diagnostics-details" x-show="isFormulaDiagnosticsOpen" x-cloak class="mb-3 grid gap-2 sm:grid-cols-2 xl:grid-cols-5">
 <template x-for="card in formulaDiagnosticCards" :key="`bottom-detail-${card.id}`">
 <article
 :class="{
 'bg-[var(--color-success-soft)] text-[var(--color-success-strong)]': card.tone === 'success',
 'bg-[var(--color-chemistry-soft)] text-[var(--color-chemistry-strong)]': card.tone === 'chemistry',
 'bg-[var(--color-info-soft)] text-[var(--color-info-strong)]': card.tone === 'info',
 'bg-[var(--color-warning-soft)] text-[var(--color-warning-strong)]': card.tone === 'warning',
 'bg-[var(--color-danger-soft)] text-[var(--color-danger-strong)]': card.tone === 'danger',
 'bg-[var(--color-field-muted)] text-[var(--color-ink-soft)]': card.tone === 'neutral',
 }"
 class="min-w-0 rounded-lg px-3 py-2.5 motion-safe:transition motion-safe:duration-200">
 <div class="flex items-start justify-between gap-3">
 <p class="sk-eyebrow" x-text="card.label"></p>
 <span class="mt-1 size-1.5 shrink-0 rounded-full bg-current opacity-70"></span>
 </div>
 <p x-effect="pulseDiagnosticValue($el, card.value)" class="numeric mt-2 truncate text-base font-semibold text-[var(--color-ink-strong)]" x-text="card.value"></p>
 <p class="mt-1 line-clamp-2 text-xs leading-5 text-current opacity-80" x-text="card.detail"></p>
 </article>
 </template>
 </div>
 <div class="flex flex-wrap items-center gap-2 lg:flex-nowrap">
 <p class="sk-eyebrow shrink-0">Formula status</p>
 <div class="flex min-w-0 flex-1 gap-2 overflow-x-auto pb-0.5">
 <template x-for="card in formulaDiagnosticSummaryCards" :key="`bottom-summary-${card.id}`">
 <span
 :class="{
 'bg-[var(--color-success-soft)] text-[var(--color-success-strong)]': card.tone === 'success',
 'bg-[var(--color-chemistry-soft)] text-[var(--color-chemistry-strong)]': card.tone === 'chemistry',
 'bg-[var(--color-info-soft)] text-[var(--color-info-strong)]': card.tone === 'info',
 'bg-[var(--color-warning-soft)] text-[var(--color-warning-strong)]': card.tone === 'warning',
 'bg-[var(--color-danger-soft)] text-[var(--color-danger-strong)]': card.tone === 'danger',
 'bg-[var(--color-field-muted)] text-[var(--color-ink-soft)]': card.tone === 'neutral',
 }"
 class="inline-flex min-h-8 shrink-0 items-center gap-2 rounded-full px-3 py-1 text-xs font-medium">
 <span x-text="card.label"></span>
 <span class="numeric font-semibold text-[var(--color-ink-strong)]" x-text="card.value"></span>
 </span>
 </template>
 </div>
 <div class="flex shrink-0 flex-wrap items-center gap-2 sm:flex-nowrap">
 <button type="button"
 @click="toggleFormulaDiagnostics()"
 :aria-expanded="isFormulaDiagnosticsOpen.toString()"
 aria-controls="formula-bottom-diagnostics-details"
 class="inline-flex min-h-9 items-center justify-center rounded-lg bg-[var(--color-field-muted)] px-3 py-2 text-sm font-medium text-[var(--color-ink-soft)] transition hover:text-[var(--color-ink-strong)]">
 <span x-text="isFormulaDiagnosticsOpen ? 'Hide details' : 'Show details'"></span>
 </button>
 <button type="button" @click="publish()" :disabled="isFormulaLocked || !canSaveRecipe || isSaving" :class="isFormulaLocked || !canSaveRecipe || isSaving ? 'cursor-not-allowed bg-[var(--color-line)] text-[var(--color-ink-soft)]' : 'bg-[var(--color-accent)] text-[var(--color-on-accent)] hover:bg-[var(--color-accent-hover)]'" class="inline-flex min-h-9 items-center justify-center rounded-lg px-4 py-2 text-sm font-medium transition">
 <span x-text="isFormulaLocked ? 'Locked' : (isSaving ? 'Saving...' : 'Save')"></span>
 </button>
 </div>
 </div>
 </section>
</div>
