@php
    $sourceIngredientId = $sourceIngredientId ?? null;
    $editorDirtyGuard = $editorDirtyGuard ?? false;
    $hasDestinationWorkspace = filled($destinationWorkspaceName);
    $duplicateDestinationCopy = $hasDestinationWorkspace
        ? __('ingredients.duplicate.preview.copy', ['workspace' => $destinationWorkspaceName])
        : __('ingredients.duplicate.preview.library_copy');
@endphp

<div
    x-data="ingredientDuplicationModal({
        searchUrl: @js(route('ingredients.search-platform')),
        duplicateUrl: @js(route('ingredients.duplicate')),
        initialIngredientId: @js($sourceIngredientId),
        destinationWorkspaceId: @js($destinationWorkspaceId),
        destinationWorkspaceSignature: @js($duplicateDestinationSignature),
        lipidCategoryLabel: @js(__('ingredients.categories.lipids.label')),
        isEditorDirty: @if ($editorDirtyGuard) () => hasPendingChanges() || blocksNavigation() @else null @endif,
        messages: @js([
            'searchFailed' => __('ingredients.duplicate.errors.search_failed'),
            'authExpired' => __('ingredients.duplicate.errors.auth_expired'),
            'duplicateFailed' => __('ingredients.duplicate.errors.duplicate_failed'),
            'invalidResponse' => __('ingredients.duplicate.errors.invalid_response'),
            'reloadGuidance' => __('ingredients.duplicate.errors.reload_guidance'),
            'sourceUnavailable' => __('ingredients.duplicate.errors.source_unavailable'),
            'unsavedChanges' => __('ingredients.duplicate.unsaved_changes'),
            'review' => __('ingredients.duplicate.preview.review'),
            'source' => __('ingredients.duplicate.preview.source'),
            'kohSapUnit' => __('ingredients.duplicate.preview.koh_sap_unit'),
            'naohSapUnit' => __('ingredients.duplicate.preview.naoh_sap_unit'),
            'unavailable' => __('ingredients.duplicate.preview.unavailable'),
            'sources' => [
                'platform' => __('ingredients.duplicate.preview.source_soapkraft'),
                'user' => __('ingredients.duplicate.preview.source_user'),
                'workspace' => __('ingredients.duplicate.preview.source_workspace'),
                'fallback' => __('ingredients.duplicate.preview.source_unknown'),
            ],
        ]),
    })"
    data-ingredient-editor-ignore-dirty
    class="inline-flex flex-col items-start gap-2"
>
    <button
        x-ref="opener"
        type="button"
        @click="openModal()"
        :disabled="isBlocked()"
        aria-haspopup="dialog"
        :aria-expanded="open.toString()"
        class="sk-btn sk-btn-primary justify-center disabled:cursor-not-allowed disabled:opacity-60"
    >
        {{ __('ingredients.duplicate.button') }}
    </button>
    <p
        x-cloak
        x-show="isBlocked()"
        class="max-w-xs text-xs leading-5 text-[var(--color-ink-soft)]"
        x-text="messages.unsavedChanges"
    ></p>

    <template x-if="open">
        <div
            x-cloak
            class="fixed inset-0 z-50 flex items-center justify-center bg-[color:oklch(from_var(--color-surface-strong)_l_c_h_/_0.55)] px-4 py-6"
            @click.self="!confirming && closeModal()"
            @keydown.escape.window="!confirming && closeModal()"
            role="dialog"
            aria-modal="true"
            aria-labelledby="ingredient-duplication-dialog-heading"
            aria-describedby="ingredient-duplication-dialog-description"
        >
            <div
                x-ref="dialog"
                tabindex="-1"
                @click.stop
                @keydown="trapFocus($event)"
                class="max-h-[calc(100dvh-3rem)] w-full max-w-2xl overflow-y-auto sk-card p-5 sm:p-6"
            >
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="sk-eyebrow">{{ __('ingredients.duplicate.eyebrow') }}</p>
                        <h3 id="ingredient-duplication-dialog-heading" class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('ingredients.duplicate.heading') }}</h3>
                        <p id="ingredient-duplication-dialog-description" class="mt-2 max-w-xl text-sm leading-6 text-[var(--color-ink-soft)]">{{ __('ingredients.duplicate.description') }}</p>
                    </div>
                    <button type="button" @click="!confirming && closeModal()" :disabled="confirming || redirecting" class="sk-btn sk-btn-ghost shrink-0 disabled:cursor-not-allowed disabled:opacity-60">{{ __('ingredients.actions.cancel') }}</button>
                </div>

                <div class="mt-5">
                    <label for="ingredient-duplication-search" class="sk-eyebrow">{{ __('ingredients.duplicate.search_label') }}</label>
                    <input
                        id="ingredient-duplication-search"
                        x-ref="searchInput"
                        x-model="query"
                        @input.debounce.300ms="search()"
                        :disabled="confirming || redirecting"
                        type="search"
                        autocomplete="off"
                        placeholder="{{ __('ingredients.duplicate.search_placeholder') }}"
                        :aria-invalid="searchError ? 'true' : 'false'"
                        aria-describedby="ingredient-duplication-search-error"
                        class="mt-2 w-full rounded-lg border border-[var(--color-line)] bg-[var(--color-field)] px-4 py-3 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition placeholder:text-[var(--color-ink-soft)] focus:outline-2 focus:outline-[var(--color-accent)]"
                    />
                </div>

                <p
                    x-cloak
                    x-show="searchError"
                    id="ingredient-duplication-search-error"
                    role="alert"
                    aria-live="assertive"
                    class="mt-3 rounded-lg bg-[var(--color-danger-soft)] px-3 py-2 text-sm leading-6 text-[var(--color-danger-strong)]"
                    x-text="searchError"
                ></p>

                <template x-if="!selected">
                    <div class="mt-4">
                        <ul class="max-h-64 list-none overflow-y-auto divide-y divide-[var(--color-line)] rounded-lg border border-[var(--color-line)] p-0" aria-label="{{ __('ingredients.duplicate.results_label') }}">
                            <template x-if="loading">
                                <li class="px-4 py-6 text-center text-sm text-[var(--color-ink-soft)]" role="status">{{ __('ingredients.duplicate.searching') }}</li>
                            </template>

                            <template x-if="!loading && results.length === 0 && query.trim().length >= 2 && !searchError">
                                <li class="px-4 py-6 text-center text-sm text-[var(--color-ink-soft)]">{{ __('ingredients.duplicate.no_matches') }}</li>
                            </template>

                            <template x-if="!loading && results.length === 0 && query.trim().length < 2 && !searchError">
                                <li class="px-4 py-6 text-center text-sm text-[var(--color-ink-soft)]">{{ __('ingredients.duplicate.minimum_characters') }}</li>
                            </template>

                            <template x-for="item in results" :key="item.id">
                                <li>
                                    <button
                                        type="button"
                                        @click="selectCandidate(item)"
                                        class="flex w-full items-start justify-between gap-4 px-4 py-3 text-left transition hover:bg-[var(--color-field-muted)] focus-visible:z-10 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-[var(--color-accent)]"
                                    >
                                        <span class="min-w-0">
                                            <span class="block break-words [overflow-wrap:anywhere] text-sm font-medium text-[var(--color-ink-strong)]" x-text="item.name"></span>
                                            <span class="mt-0.5 block break-words [overflow-wrap:anywhere] text-xs text-[var(--color-ink-soft)]" x-text="[item.inci_name, item.category, sourceLabel(item)].filter(Boolean).join(' · ')"></span>
                                        </span>
                                        <span
                                            class="shrink-0 text-xs font-medium"
                                            :class="item.duplication.available ? 'text-[var(--color-accent-strong)]' : 'text-[var(--color-danger-strong)]'"
                                            x-text="item.duplication.available ? messages.review : messages.unavailable"
                                        ></span>
                                    </button>
                                </li>
                            </template>
                        </ul>
                    </div>
                </template>

                <template x-if="selected">
                    <section class="mt-5" aria-labelledby="ingredient-duplication-preview-heading">
                        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-[var(--color-line)] pb-4">
                            <div>
                                <p class="sk-eyebrow">{{ __('ingredients.duplicate.preview.eyebrow') }}</p>
                                <h4 x-ref="previewHeading" id="ingredient-duplication-preview-heading" tabindex="-1" class="mt-1 text-xl font-semibold text-[var(--color-ink-strong)] focus:outline-none" x-text="selected.name"></h4>
                            </div>
                            <button type="button" @click="chooseAnother()" :disabled="confirming || redirecting" class="sk-btn sk-btn-outline disabled:cursor-not-allowed disabled:opacity-60">{{ __('ingredients.duplicate.preview.choose_another') }}</button>
                        </div>

                        <p class="mt-4 rounded-lg bg-[var(--color-accent-soft)] px-4 py-3 text-sm leading-6 text-[var(--color-ink-strong)]">
                            {{ $duplicateDestinationCopy }}
                        </p>

                        <dl class="mt-4 grid gap-3 rounded-lg border border-[var(--color-line)] bg-[var(--color-field-muted)] p-4 sm:grid-cols-2">
                            <div x-show="selected.category">
                                <dt class="sk-eyebrow">{{ __('ingredients.duplicate.preview.category') }}</dt>
                                <dd class="mt-1 text-sm text-[var(--color-ink-strong)]" x-text="selected.category"></dd>
                            </div>
                            <div x-show="selected.inci_name">
                                <dt class="sk-eyebrow">{{ __('ingredients.duplicate.preview.inci_name') }}</dt>
                                <dd class="mt-1 text-sm text-[var(--color-ink-strong)]" x-text="selected.inci_name"></dd>
                            </div>
                            <div x-show="selected.identifiers.length" class="sm:col-span-2">
                                <dt class="sk-eyebrow">{{ __('ingredients.duplicate.preview.identifiers') }}</dt>
                                <dd class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-sm text-[var(--color-ink-strong)]">
                                    <template x-for="identifier in selected.identifiers" :key="`${identifier.scheme}-${identifier.value}`">
                                        <span class="numeric" x-text="`${String(identifier.scheme ?? '').toUpperCase()}: ${identifier.value ?? ''}`"></span>
                                    </template>
                                </dd>
                            </div>
                            <div x-show="selected.aliases.length" class="sm:col-span-2">
                                <dt class="sk-eyebrow">{{ __('ingredients.duplicate.preview.aliases') }}</dt>
                                <dd class="mt-1 text-sm text-[var(--color-ink-strong)]" x-text="selected.aliases.join(' · ')"></dd>
                            </div>
                        </dl>

                        <template x-if="!selected.duplication.available">
                            <p role="alert" class="mt-4 rounded-lg bg-[var(--color-danger-soft)] px-4 py-3 text-sm leading-6 text-[var(--color-danger-strong)]" x-text="selected.duplication.reason || messages.unavailable"></p>
                        </template>

                        <template x-if="selected.duplication.available">
                            <div class="mt-4 space-y-4">
                                <div class="rounded-lg border border-[var(--color-line)] p-4">
                                    <p class="sk-eyebrow">{{ __('ingredients.duplicate.preview.restrictions') }}</p>
                                    <ul class="mt-2 space-y-2 text-sm leading-6 text-[var(--color-ink-soft)]">
                                        <li>{{ __('ingredients.duplicate.preview.images_reset') }}</li>
                                        <li>{{ __('ingredients.duplicate.preview.guidance_override') }}</li>
                                        <li>{{ __('ingredients.duplicate.preview.source_unchanged') }}</li>
                                    </ul>
                                </div>

                                <template x-if="chemistryState() === 'inherited'">
                                    <div class="space-y-3 rounded-lg bg-[var(--color-chemistry-soft)] px-4 py-3 text-sm leading-6 text-[var(--color-ink-strong)]">
                                        <p>{{ __('ingredients.duplicate.preview.inherited_chemistry') }}</p>
                                        <template x-if="selected.duplication.chemistry">
                                            <div>
                                                <p class="sk-eyebrow">{{ __('ingredients.duplicate.preview.chemistry_limits') }}</p>
                                                <dl class="mt-2 grid gap-2 sm:grid-cols-3">
                                                    <div>
                                                        <dt class="text-xs text-[var(--color-ink-soft)]">{{ __('ingredients.duplicate.preview.koh_sap_range') }}</dt>
                                                        <dd class="numeric" x-text="`${selected.duplication.chemistry.koh_sap.minimum}–${selected.duplication.chemistry.koh_sap.maximum} ${messages.kohSapUnit} (${messages.source} ${selected.duplication.chemistry.koh_sap.original} ${messages.kohSapUnit})`"></dd>
                                                    </div>
                                                    <div>
                                                        <dt class="text-xs text-[var(--color-ink-soft)]">{{ __('ingredients.duplicate.preview.naoh_sap_range') }}</dt>
                                                        <dd class="numeric" x-text="`${selected.duplication.chemistry.naoh_sap.minimum}–${selected.duplication.chemistry.naoh_sap.maximum} ${messages.naohSapUnit} (${messages.source} ${selected.duplication.chemistry.naoh_sap.original} ${messages.naohSapUnit})`"></dd>
                                                    </div>
                                                    <div>
                                                        <dt class="text-xs text-[var(--color-ink-soft)]">{{ __('ingredients.duplicate.preview.fatty_acid_total_range') }}</dt>
                                                        <dd class="numeric" x-text="`${selected.duplication.chemistry.fatty_acid_total.minimum}%–${selected.duplication.chemistry.fatty_acid_total.maximum}%`"></dd>
                                                    </div>
                                                </dl>
                                                <template x-if="selected.duplication.chemistry.fatty_acids.length">
                                                    <div class="mt-3">
                                                        <p class="text-xs text-[var(--color-ink-soft)]">{{ __('ingredients.duplicate.preview.fatty_acid_ranges') }}</p>
                                                        <ul class="mt-1 space-y-1">
                                                            <template x-for="fattyAcid in selected.duplication.chemistry.fatty_acids" :key="fattyAcid.id">
                                                                <li class="numeric" x-text="fattyAcid.display || `${fattyAcid.name}: ${fattyAcid.minimum}%–${fattyAcid.maximum}% (${messages.source} ${fattyAcid.original}%)`"></li>
                                                            </template>
                                                        </ul>
                                                    </div>
                                                </template>
                                            </div>
                                        </template>
                                    </div>
                                </template>
                                <template x-if="chemistryState() === 'untrusted'">
                                    <p class="rounded-lg bg-[var(--color-field-muted)] px-4 py-3 text-sm leading-6 text-[var(--color-ink-soft)]">{{ __('ingredients.duplicate.preview.untrusted_chemistry') }}</p>
                                </template>
                            </div>
                        </template>

                        <p
                            x-cloak
                            x-show="duplicateError"
                            role="alert"
                            aria-live="assertive"
                            class="mt-4 rounded-lg bg-[var(--color-danger-soft)] px-4 py-3 text-sm leading-6 text-[var(--color-danger-strong)]"
                            x-text="duplicateError"
                        ></p>

                        <div class="mt-5 flex flex-col-reverse gap-2 border-t border-[var(--color-line)] pt-4 sm:flex-row sm:justify-end">
                            <button type="button" @click="!confirming && closeModal()" :disabled="confirming || redirecting" class="sk-btn sk-btn-outline disabled:cursor-not-allowed disabled:opacity-60">{{ __('ingredients.actions.cancel') }}</button>
                            <button
                                type="button"
                                @click="confirmDuplicate()"
                                :disabled="!selected.duplication.available || confirming || redirecting"
                                class="sk-btn sk-btn-primary disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                <span x-show="!confirming">{{ __('ingredients.duplicate.preview.confirm') }}</span>
                                <span x-show="confirming" x-cloak>{{ __('ingredients.duplicate.preview.confirming') }}</span>
                            </button>
                        </div>
                    </section>
                </template>
            </div>
        </div>
    </template>
</div>
