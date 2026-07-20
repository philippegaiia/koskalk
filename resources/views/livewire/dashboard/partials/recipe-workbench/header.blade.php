@php
 $workbench = $workbench ?? [];
 $recipePublicId = $workbench['recipe']['public_id'] ?? null;
 $hasSavedFormula = (bool) ($workbench['recipe']['has_saved_formula'] ?? false);
 $savedFormulaUrl = $workbench['recipe']['saved_formula_url'] ?? null;
 $isFormulaLocked = (bool) ($workbench['recipe']['is_locked'] ?? false);
 $isPublicCalculator = $isPublicCalculator ?? false;
@endphp

<section class="{{ $isPublicCalculator ? 'pb-1' : 'sk-card p-5' }}">
 <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
 <div class="min-w-0 flex-1">
	 <div class="flex flex-wrap items-center gap-2">
	 <p class="sk-eyebrow">{{ __('workbench.header.section') }}</p>
	 <span :class="isFormulaLocked ? 'border border-[var(--color-warning-soft)] bg-[var(--color-warning-soft)] text-[var(--color-warning-strong)]' : 'bg-[var(--color-panel)] text-[var(--color-ink-soft)]'" class="rounded-full px-3 py-1.5 text-xs font-medium shadow-sm" x-text="formulaWorkbenchLabel"></span>
	 <span x-show="productTypeName" x-cloak class="sk-badge sk-badge-neutral" x-text="productTypeName"></span>
	 <template x-if="saveMessage">
	 <span role="status" :class="saveStatus === 'error' ? 'bg-[var(--color-danger-soft)] text-[var(--color-danger-strong)]' : 'bg-white text-[var(--color-ink-soft)]'" class="rounded-full px-3 py-1.5 text-xs font-medium shadow-sm" x-text="saveMessage"></span>
	 </template>
	 <template x-if="calculationPreviewMessage">
	 <span role="status" class="rounded-full bg-[var(--color-danger-soft)] px-3 py-1.5 text-xs font-medium text-[var(--color-danger-strong)] shadow-sm" x-text="calculationPreviewMessage"></span>
	 </template>
	 @if ($isPublicCalculator)
	 <label class="ml-auto inline-flex items-center gap-2 text-xs font-medium text-[var(--color-ink-soft)]">
	 <span>{{ __('number_formats.label') }}</span>
	 <select x-model="numberLocale" @change="persistNumberLocale()" class="max-w-[14rem] rounded-lg border border-[var(--color-line)] bg-[var(--color-field)] px-2.5 py-1.5 text-xs text-[var(--color-ink-strong)]">
	 @foreach (($workbench['numberLocaleOptions'] ?? []) as $locale => $label)
	 <option value="{{ $locale }}">{{ $label }}</option>
	 @endforeach
	 </select>
	 </label>
	 @endif
	 </div>
	 <input x-model="formulaName" type="text" aria-label="{{ __('workbench.header.product_name') }}" :disabled="isFormulaLocked" :placeholder="isCosmeticFormula ? @js(__('workbench.header.untitled_cosmetic')) : @js(__('workbench.header.untitled_soap'))" class="sk-formula-title-control mt-2 w-full rounded-[1.25rem] border border-[var(--color-line)] bg-[var(--color-field)] px-4 py-3 text-2xl font-semibold text-[var(--color-ink-strong)] transition disabled:cursor-not-allowed disabled:bg-[var(--color-panel)] disabled:text-[var(--color-ink-soft)]" />
	 </div>

	 @unless ($isPublicCalculator)
	 <div class="flex flex-wrap gap-2 lg:justify-end">
	 @if ($hasSavedFormula && is_string($savedFormulaUrl))
	 <a href="{{ $savedFormulaUrl }}" class="inline-flex items-center gap-2 rounded-full border border-[var(--color-line)] bg-white px-4 py-2.5 text-sm font-medium text-[var(--color-ink-strong)] shadow-sm transition hover:bg-[var(--color-panel)]">
	 <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
	 <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6M9 8h6M5.25 5.25A2.25 2.25 0 0 1 7.5 3h9A2.25 2.25 0 0 1 18.75 5.25v13.5A2.25 2.25 0 0 1 16.5 21h-9A2.25 2.25 0 0 1 5.25 18.75V5.25Z" />
	 </svg>
	 {{ __('workbench.header.product_sheet') }}
	 </a>
	 @endif
	 <button type="button" @click="publish()" :disabled="isFormulaLocked || !canSaveRecipe || isSaving" :class="isFormulaLocked || !canSaveRecipe || isSaving ? 'cursor-not-allowed bg-[var(--color-line)] text-[var(--color-ink-soft)]' : 'bg-[var(--color-accent)] text-[var(--color-on-accent)] hover:bg-[var(--color-accent-hover)]'" class="rounded-full px-4 py-2.5 text-sm font-medium transition">
	 <span x-text="isFormulaLocked ? t('header.locked') : (isSaving ? t('header.saving') : t('header.save'))"></span>
	 </button>
 @if ($recipePublicId)
	 @if ($isFormulaLocked)
 <form method="POST" action="{{ route('recipes.unlock', $recipePublicId) }}">
	 @csrf
	 <button type="submit" class="rounded-full bg-[var(--color-warning-soft)] px-4 py-2.5 text-sm font-medium text-[var(--color-warning-strong)] transition hover:bg-[var(--color-panel)]">
	 {{ __('workbench.header.unlock_product') }}
	 </button>
	 </form>
	 @else
 <form method="POST" action="{{ route('recipes.lock', $recipePublicId) }}">
	 @csrf
	 <button type="submit" class="rounded-full border border-[var(--color-line)] bg-white px-4 py-2.5 text-sm font-medium text-[var(--color-ink-soft)] shadow-sm transition hover:bg-[var(--color-panel)] hover:text-[var(--color-ink-strong)]">
	 {{ __('workbench.header.lock_product') }}
	 </button>
	 </form>
	 @endif
	 @endif
	 <details x-data="{ open: false }" :open="open" @toggle="open = $el.open" @click.outside="open = false" @keydown.escape.prevent.stop="open = false" class="relative">
	 <summary class="list-none rounded-full bg-white px-4 py-2.5 text-sm font-medium text-[var(--color-ink-soft)] shadow-sm transition hover:bg-[var(--color-panel)] hover:text-[var(--color-ink-strong)] [&::-webkit-details-marker]:hidden" aria-haspopup="menu" :aria-expanded="open.toString()">
	 {{ __('workbench.header.more_actions') }}
	 </summary>
	 <div class="absolute right-0 z-40 mt-2 w-72 rounded-lg bg-white p-2 shadow-xl">
	 <div class="px-3 py-2">
	 <p class="sk-eyebrow">{{ __('workbench.header.product_details') }}</p>
	 <div class="mt-2 space-y-1 text-xs leading-5 text-[var(--color-ink-soft)]">
	 <p x-text="manufacturingModeLabel"></p>
	 <p x-text="exposureModeLabel"></p>
	 <p x-text="regulatoryRegimeLabel"></p>
	 </div>
	 </div>
	 @if ($hasSavedFormula && is_string($savedFormulaUrl))
	 <a href="{{ $savedFormulaUrl }}" class="mt-1 flex rounded-md px-3 py-2.5 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel)] hover:text-[var(--color-ink-strong)]">
	 {{ __('workbench.header.product_sheet') }}
	 </a>
	 @endif
	 <button type="button" x-show="hasSavedRecipe" x-cloak @click="duplicateFormula()" :disabled="!canDuplicateFormula || isSaving" :class="!canDuplicateFormula || isSaving ? 'cursor-not-allowed text-[var(--color-ink-soft)]' : 'text-[var(--color-ink-soft)] hover:bg-[var(--color-accent-soft)] hover:text-[var(--color-ink-strong)]'" class="mt-1 flex w-full rounded-md px-3 py-2.5 text-left text-sm font-medium transition">
	 {{ __('workbench.header.duplicate_product') }}
	 </button>
	 </div>
	 </details>
		 </div>
	 @endunless
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
