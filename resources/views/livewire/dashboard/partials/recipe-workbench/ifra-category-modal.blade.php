<div class="border-t border-[var(--color-line)] pt-4">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <p class="sk-eyebrow">{{ __('workbench.ifra.title') }}</p>
            <template x-if="ifraCategorySelectionMode === 'automatic' && selectedIfraProductCategory">
                <p class="mt-1 text-sm font-medium text-[var(--color-ink-strong)]">
                    {{ __('workbench.ifra.suggested_from_product_type') }}
                    <span class="numeric" x-text="`· Cat ${selectedIfraProductCategory.code}`"></span>
                </p>
            </template>
            <template x-if="ifraCategorySelectionMode !== 'automatic' && selectedIfraProductCategory">
                <p class="mt-1 text-sm font-medium text-[var(--color-ink-strong)]" x-text="`Cat ${selectedIfraProductCategory.code} · ${selectedIfraProductCategory.short_name ?? selectedIfraProductCategory.name}`"></p>
            </template>
            <template x-if="! selectedIfraProductCategory">
                <p class="mt-1 text-sm font-medium text-[var(--color-ink-strong)]">{{ __('workbench.ifra.no_category') }}</p>
            </template>
        </div>

        <button x-ref="ifraCategoryTrigger" type="button" @click="openIfraCategoryModal()" class="sk-btn sk-btn-outline shrink-0">
            {{ __('workbench.ifra.review') }}
        </button>
    </div>

    <p class="mt-3 text-xs leading-5 text-[var(--color-ink-soft)]">{{ __('workbench.ifra.disclaimer') }}</p>

    <div
        x-cloak
        x-show="isIfraCategoryModalOpen"
        @keydown.escape.window="closeIfraCategoryModal()"
        @click.self="closeIfraCategoryModal()"
        class="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4"
        role="dialog"
        aria-modal="true"
        aria-labelledby="ifra-category-modal-heading"
    >
        <div x-trap.inert.noscroll="isIfraCategoryModalOpen" class="sk-card max-h-[calc(100dvh-2rem)] w-full max-w-2xl overflow-y-auto p-5 sm:p-6">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="sk-eyebrow">{{ __('workbench.ifra.eyebrow') }}</p>
                    <h3 id="ifra-category-modal-heading" class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('workbench.ifra.modal_title') }}</h3>
                    <template x-if="ifraGuidance.amendment">
                        <p class="mt-2 text-sm text-[var(--color-ink-soft)]" x-text="t('ifra.amendment', { amendment: ifraGuidance.amendment.code })"></p>
                    </template>
                </div>
                <button x-ref="ifraCategoryModalClose" type="button" @click="closeIfraCategoryModal()" class="sk-btn sk-btn-ghost">{{ __('workbench.ifra.close') }}</button>
            </div>

            <div class="mt-5 space-y-3">
                <template x-if="ifraGuidance.options.length">
                    <div class="space-y-2">
                        <p class="sk-eyebrow">{{ __('workbench.ifra.suggested_options') }}</p>
                        <template x-for="option in ifraGuidance.options" :key="`ifra-mapped-${option.mapping_id}`">
                            <button
                                type="button"
                                @click="option.is_default ? useSuggestedIfraCategory() : selectIfraCategory(option.id)"
                                class="block w-full rounded-xl border border-[var(--color-line)] bg-[var(--color-panel)] px-4 py-3 text-left transition-colors hover:border-[var(--color-accent)] hover:bg-[var(--color-accent-soft)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)]"
                            >
                                <span class="flex items-start justify-between gap-4">
                                    <span>
                                        <span class="block text-sm font-semibold text-[var(--color-ink-strong)]" x-text="`Cat ${option.code} · ${option.short_name ?? option.name}`"></span>
                                        <span x-show="option.guidance" class="mt-1 block text-xs leading-5 text-[var(--color-ink-soft)]" x-text="option.guidance"></span>
                                    </span>
                                    <span x-show="option.is_default" class="shrink-0 rounded-full bg-[var(--color-active-soft)] px-2.5 py-1 text-xs font-medium text-[var(--color-ink-strong)]">{{ __('workbench.ifra.suggested') }}</span>
                                </span>
                            </button>
                        </template>
                    </div>
                </template>

                <template x-if="! ifraGuidance.options.length">
                    <p class="rounded-xl bg-[var(--color-field-muted)] px-4 py-3 text-sm text-[var(--color-ink-soft)]">{{ __('workbench.ifra.no_suggestion') }}</p>
                </template>

                <button x-show="! showAllIfraCategories" type="button" @click="showAllIfraCategories = true" class="sk-action-link">
                    {{ __('workbench.ifra.choose_another') }}
                </button>

                <div x-cloak x-show="showAllIfraCategories" class="space-y-2 border-t border-[var(--color-line)] pt-4">
                    <p class="sk-eyebrow">{{ __('workbench.ifra.all_categories') }}</p>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <template x-for="category in ifraGuidance.all_categories" :key="`ifra-all-${category.id}`">
                            <button
                                type="button"
                                @click="selectIfraCategory(category.id)"
                                class="rounded-lg border border-[var(--color-line)] px-3 py-2.5 text-left text-sm text-[var(--color-ink-strong)] transition-colors hover:border-[var(--color-accent)] hover:bg-[var(--color-accent-soft)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)]"
                                x-text="`Cat ${category.code} · ${category.short_name ?? category.name}`"
                            ></button>
                        </template>
                    </div>
                </div>
            </div>

            <details x-show="futureIfraMilestones.length" class="mt-5 border-t border-[var(--color-line)] pt-4">
                <summary class="cursor-pointer text-sm font-medium text-[var(--color-ink-strong)]">{{ __('workbench.ifra.timing_title') }}</summary>
                <div class="mt-3 space-y-2 text-xs leading-5 text-[var(--color-ink-soft)]">
                    <p>{{ __('workbench.ifra.timing_help') }}</p>
                    <template x-for="milestone in futureIfraMilestones" :key="`${milestone.creation_track}-${milestone.effective_on}`">
                        <p>
                            <span class="font-medium text-[var(--color-ink-strong)]" x-text="milestone.creation_track === 'new_creation' ? t('ifra.new_creations') : t('ifra.existing_creations')"></span>
                            <span class="numeric" x-text="` · ${milestone.effective_on}`"></span>
                        </p>
                    </template>
                </div>
            </details>

            <div class="mt-6 flex flex-wrap justify-between gap-2 border-t border-[var(--color-line)] pt-4">
                <button type="button" @click="clearIfraCategory()" class="sk-btn sk-btn-ghost">{{ __('workbench.ifra.no_category') }}</button>
                <div class="flex flex-wrap gap-2">
                    <button x-show="ifraCategorySelectionMode !== 'automatic' && ifraGuidance.default_category_id" type="button" @click="useSuggestedIfraCategory()" class="sk-btn sk-btn-outline">{{ __('workbench.ifra.use_suggested') }}</button>
                    <button type="button" @click="closeIfraCategoryModal()" class="sk-btn sk-btn-primary">{{ __('workbench.ifra.done') }}</button>
                </div>
            </div>
        </div>
    </div>
</div>
