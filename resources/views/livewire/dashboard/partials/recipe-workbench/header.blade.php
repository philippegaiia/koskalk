@php
    $workbench = $workbench ?? [];
    $recipePublicId = $workbench['recipe']['public_id'] ?? null;
    $isPublicCalculator = $isPublicCalculator ?? false;
    $contextualHelp = $contextualHelp ?? ['topics' => [], 'tabs' => []];
@endphp

<section class="{{ $isPublicCalculator ? 'pb-1' : 'sk-formula-header' }}">
    <div data-contextual-help-heading class="flex flex-wrap items-center gap-x-3 gap-y-1">
    @if ($isPublicCalculator)
        <div class="flex flex-wrap items-center gap-2">
            <p class="sk-eyebrow">{{ __('workbench.header.section') }}</p>
            <span :class="isFormulaLocked ? 'border border-[var(--color-warning-soft)] bg-[var(--color-warning-soft)] text-[var(--color-warning-strong)]' : 'bg-[var(--color-panel)] text-[var(--color-ink-soft)]'" class="rounded-full px-3 py-1.5 text-xs font-medium" x-text="formulaWorkbenchLabel"></span>

            <label class="ml-auto inline-flex items-center gap-2 text-xs font-medium text-[var(--color-ink-soft)]">
                <span>{{ __('number_formats.label') }}</span>
                <select x-model="numberLocale" @change="persistNumberLocale()" class="max-w-[14rem] rounded-lg border border-[var(--color-line)] bg-[var(--color-field)] px-2.5 py-1.5 text-xs text-[var(--color-ink-strong)]">
                    @foreach (($workbench['numberLocaleOptions'] ?? []) as $locale => $label)
                        <option value="{{ $locale }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
        </div>
    @else
        <nav aria-label="{{ __('workbench.header.breadcrumb') }}" class="flex items-center gap-2 text-sm font-medium text-[var(--color-ink-soft)]">
            <a href="{{ route('recipes.index') }}" wire:navigate class="text-[var(--color-accent-strong)] transition hover:text-[var(--color-accent-hover)]">{{ __('navigation.items.formulas') }}</a>
            <span aria-hidden="true" class="text-[var(--color-line-strong)]">/</span>
            <span x-text="formulaWorkbenchLabel"></span>
        </nav>
    @endif

        <x-contextual-help.index-button :help="$contextualHelp" :dynamic-tab="true" />
    </div>

    <div class="{{ $isPublicCalculator ? 'mt-2' : 'mt-3' }} flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        <input
            x-model="formulaName"
            type="text"
            aria-label="{{ __('workbench.header.product_name') }}"
            @if (! $isPublicCalculator && ! ($workbench['canEditRecipe'] ?? false)) disabled @endif
            :disabled="!canWriteRecipe || (isSaving && !hasSavedRecipe)"
            :placeholder="isCosmeticFormula ? @js(__('workbench.header.untitled_cosmetic')) : @js(__('workbench.header.untitled_soap'))"
            class="sk-formula-title-control min-w-0 flex-1 bg-transparent px-0 pb-2 pt-1 text-3xl font-semibold tracking-tight text-[var(--color-ink-strong)] transition disabled:cursor-not-allowed disabled:text-[var(--color-ink-soft)]"
        />

        @unless ($isPublicCalculator)
            <div class="sk-formula-actions flex shrink-0 flex-wrap items-center gap-2 lg:justify-end">
                @if ($recipePublicId)
                    @if ($workbench['recipe']['can_manage_lock'] ?? false)
                        @if ((bool) ($workbench['recipe']['is_locked'] ?? false))
                            <form method="POST" action="{{ route('recipes.unlock', $recipePublicId) }}" @submit="submitRecipeControlMutation($event)">
                                @csrf
                                <input type="hidden" name="expected_revision" :value="editingRecipeRevision" value="{{ $workbench['editing']['recipe_revision'] ?? 0 }}">
                                <button type="submit" :disabled="!canSubmitRecipeControl" class="sk-btn bg-[var(--color-warning-soft)] text-[var(--color-warning-strong)] hover:bg-[var(--color-panel)]">
                                    {{ __('workbench.header.unlock_product') }}
                                </button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('recipes.lock', $recipePublicId) }}" @submit="submitRecipeControlMutation($event)">
                                @csrf
                                <input type="hidden" name="expected_revision" :value="editingRecipeRevision" value="{{ $workbench['editing']['recipe_revision'] ?? 0 }}">
                                <button type="submit" :disabled="!canSubmitRecipeControl" class="sk-btn sk-btn-outline">
                                    {{ __('workbench.header.lock_product') }}
                                </button>
                            </form>
                        @endif
                    @endif
                @else
                    <button type="button" disabled title="{{ __('workbench.header.save_before_locking') }}" class="sk-btn sk-btn-outline opacity-55">
                        {{ __('workbench.header.lock_product') }}
                    </button>
                @endif

                @if ($recipePublicId && ($workbench['recipe']['can_duplicate'] ?? false))
                    <button type="button" x-show="hasSavedRecipe" x-cloak @click="duplicateFormula()" :disabled="!canDuplicateFormula || isSaving" class="sk-btn sk-btn-outline">
                        {{ __('workbench.header.duplicate_product') }}
                    </button>
                @endif
            </div>
        @endunless
    </div>

    <div x-show="productTypeName || saveMessage || calculationPreviewMessage" x-cloak class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-[var(--color-ink-soft)]">
        <span x-show="productTypeName" class="sk-badge sk-badge-neutral" x-text="productTypeName"></span>
        <template x-if="saveMessage">
            <span role="status" :class="saveStatus === 'error' ? 'text-[var(--color-danger-strong)]' : 'text-[var(--color-ink-soft)]'" x-text="saveMessage"></span>
        </template>
        <template x-if="calculationPreviewMessage">
            <span role="status" class="text-[var(--color-danger-strong)]" x-text="calculationPreviewMessage"></span>
        </template>
    </div>

    <template x-if="needsCatalogReview">
        <div role="status" aria-live="polite" data-catalog-review-warning class="mt-3 rounded-lg border border-[var(--color-warning-soft)] bg-[var(--color-warning-soft)] px-3 py-2.5 text-sm text-[var(--color-warning-strong)]">
            <p class="font-medium" x-text="catalogReview?.message"></p>
        </div>
    </template>

</section>
