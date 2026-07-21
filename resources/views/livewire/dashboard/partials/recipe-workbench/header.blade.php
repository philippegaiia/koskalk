@php
    $workbench = $workbench ?? [];
    $recipePublicId = $workbench['recipe']['public_id'] ?? null;
    $isPublicCalculator = $isPublicCalculator ?? false;
@endphp

<section class="{{ $isPublicCalculator ? 'pb-1' : 'sk-formula-header' }}">
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

    <div class="{{ $isPublicCalculator ? 'mt-3' : 'mt-4' }} flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <input
            x-model="formulaName"
            type="text"
            aria-label="{{ __('workbench.header.product_name') }}"
            :disabled="isFormulaLocked"
            :placeholder="isCosmeticFormula ? @js(__('workbench.header.untitled_cosmetic')) : @js(__('workbench.header.untitled_soap'))"
            class="sk-formula-title-control min-w-0 flex-1 bg-transparent px-0 pb-2 pt-1 text-3xl font-semibold tracking-tight text-[var(--color-ink-strong)] transition disabled:cursor-not-allowed disabled:text-[var(--color-ink-soft)]"
        />

        @unless ($isPublicCalculator)
            <div class="sk-formula-actions flex shrink-0 flex-wrap items-center gap-2 lg:justify-end">
                <button type="button" @click="publish()" :disabled="isFormulaLocked || !canSaveRecipe || isSaving" :class="isFormulaLocked || !canSaveRecipe || isSaving ? 'cursor-not-allowed bg-[var(--color-line)] text-[var(--color-ink-soft)]' : 'bg-[var(--color-accent)] text-[var(--color-on-accent)] hover:bg-[var(--color-accent-hover)]'" class="sk-btn">
                    <span x-text="isFormulaLocked ? t('header.locked') : (isSaving ? t('header.saving') : t('header.save'))"></span>
                </button>

                @if ($recipePublicId)
                    @if ((bool) ($workbench['recipe']['is_locked'] ?? false))
                        <form method="POST" action="{{ route('recipes.unlock', $recipePublicId) }}">
                            @csrf
                            <button type="submit" class="sk-btn bg-[var(--color-warning-soft)] text-[var(--color-warning-strong)] hover:bg-[var(--color-panel)]">
                                {{ __('workbench.header.unlock_product') }}
                            </button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('recipes.lock', $recipePublicId) }}">
                            @csrf
                            <button type="submit" class="sk-btn sk-btn-outline">
                                {{ __('workbench.header.lock_product') }}
                            </button>
                        </form>
                    @endif
                @else
                    <button type="button" disabled title="{{ __('workbench.header.save_before_locking') }}" class="sk-btn sk-btn-outline opacity-55">
                        {{ __('workbench.header.lock_product') }}
                    </button>
                @endif

                <details x-data="{ open: false }" :open="open" @toggle="open = $el.open" @click.outside="open = false" @keydown.escape.prevent.stop="open = false" class="relative">
                    <summary class="sk-btn sk-btn-ghost size-10 cursor-pointer list-none px-0 [&::-webkit-details-marker]:hidden" aria-haspopup="menu" :aria-expanded="open.toString()">
                        <span aria-hidden="true" class="text-lg leading-none tracking-[0.12em]">•••</span>
                        <span class="sr-only">{{ __('workbench.header.more_actions') }}</span>
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

                        <button type="button" x-show="hasSavedRecipe" x-cloak @click="duplicateFormula()" :disabled="!canDuplicateFormula || isSaving" :class="!canDuplicateFormula || isSaving ? 'cursor-not-allowed text-[var(--color-ink-soft)]' : 'text-[var(--color-ink-soft)] hover:bg-[var(--color-accent-soft)] hover:text-[var(--color-ink-strong)]'" class="mt-1 flex w-full rounded-md px-3 py-2.5 text-left text-sm font-medium transition">
                            {{ __('workbench.header.duplicate_product') }}
                        </button>
                    </div>
                </details>
            </div>
        @endunless
    </div>

    <div x-show="productTypeName || saveMessage || calculationPreviewMessage" x-cloak class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-[var(--color-ink-soft)]">
        <span x-show="productTypeName" class="sk-badge sk-badge-neutral" x-text="productTypeName"></span>
        <template x-if="saveMessage">
            <span role="status" :class="saveStatus === 'error' ? 'text-[var(--color-danger-strong)]' : 'text-[var(--color-ink-soft)]'" x-text="saveMessage"></span>
        </template>
        <template x-if="calculationPreviewMessage">
            <span role="status" class="text-[var(--color-danger-strong)]" x-text="calculationPreviewMessage"></span>
        </template>
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
