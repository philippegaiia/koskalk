<div class="mx-auto w-full max-w-7xl space-y-6" @if ($hasProcessingAssets) wire:poll.5s.visible @endif>
    <section class="sk-card p-5 sm:p-6">
        <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
            <div data-media-library-summary class="min-w-0 flex-1">
                <p class="sk-eyebrow">{{ __('media_library.eyebrow') }}</p>
                <h3 class="mt-2 text-xl font-semibold text-[var(--color-ink-strong)] sm:text-2xl">{{ __('media_library.title') }}</h3>
                <p class="mt-2 max-w-3xl text-sm leading-7 text-[var(--color-ink-soft)]">
                    {{ __('media_library.description') }}
                </p>
                <p class="mt-5 text-sm text-[var(--color-ink-soft)]">
                    @if ($usage['limit'] === null)
                        {{ __('media_library.quota.unlimited', ['used' => $usage['used']]) }}
                    @else
                        {{ __('media_library.quota.limited', ['used' => $usage['used'], 'limit' => $usage['limit']]) }}
                        @if (! $usage['allowed'])
                            · {{ __('media_library.uploads_blocked') }}
                        @endif
                    @endif
                </p>
            </div>

            @if ($canUpdateMedia)
                <form
                    x-data="mediaLibraryUploader({
                        livewire: $wire,
                        maxFiles: 5,
                        remaining: @js($usage['remaining']),
                        messages: {
                            selectedFiles: @js(__('media_library.selected_files')),
                            batchLimit: @js(__('media_library.batch_limit')),
                            batchPosition: @js(__('media_library.batch_position')),
                            batchQuota: @js(__('media_library.batch_quota')),
                            uploadFailed: @js(__('media_library.batch_file_failed')),
                            removeFile: @js(__('media_library.remove_file')),
                        },
                    })"
                    x-on:submit.prevent="uploadBatch()"
                    class="flex w-full max-w-md flex-col gap-3 lg:items-end"
                >
                    <input
                        id="media-library-upload"
                        x-ref="fileInput"
                        x-on:change="selectFiles($event)"
                        data-media-library-file-input
                        type="file"
                        multiple
                        accept=".jpg,.jpeg,.png,.webp,.heic,.heif,image/jpeg,image/png,image/webp,image/heic,image/heif"
                        @disabled(! $usage['allowed'])
                        @if (! $usage['allowed']) data-media-upload-disabled @endif
                        class="peer sr-only"
                    />
                    <div class="flex w-full min-h-12 flex-wrap items-center gap-3 rounded-xl border border-[var(--color-line)] bg-[var(--color-field)] p-2">
                        <label
                            for="media-library-upload"
                            aria-disabled="{{ $usage['allowed'] ? 'false' : 'true' }}"
                            class="sk-btn cursor-pointer border border-[var(--color-accent)] bg-[var(--color-accent-soft)] text-[var(--color-accent-strong)] peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-[var(--color-accent)] aria-disabled:cursor-not-allowed aria-disabled:opacity-60"
                        >
                            {{ __('media_library.choose_files') }}
                        </label>
                        <span
                            class="min-w-0 flex-1 truncate text-sm text-[var(--color-ink-soft)]"
                            x-text="files.length ? choiceMessage('selectedFiles', files.length) : @js(__('media_library.picker.no_file_selected'))"
                        ></span>
                    </div>

                    <div
                        x-cloak
                        x-show="files.length"
                        data-media-library-selected-files
                        class="w-full overflow-hidden rounded-xl border border-[var(--color-line)] bg-[var(--color-panel)]"
                    >
                        <template x-for="(entry, index) in files" x-bind:key="entry.id">
                            <div class="border-b border-[var(--color-line)] px-3 py-2 last:border-b-0">
                                <div class="flex items-center gap-3">
                                    <div class="min-w-0 flex-1">
                                        <p data-media-library-selected-filename class="truncate text-xs font-medium leading-5 text-[var(--color-ink-strong)]" x-text="entry.name"></p>
                                        <p x-show="entry.error" x-text="entry.error" role="alert" class="mt-1 text-xs text-[var(--color-danger-strong)]"></p>
                                    </div>
                                    <span
                                        x-show="entry.status === 'uploading'"
                                        class="text-xs tabular-nums text-[var(--color-ink-soft)]"
                                        x-text="`${entry.progress}%`"
                                    ></span>
                                    <button
                                        type="button"
                                        data-media-library-remove-file
                                        x-on:click="removeFile(index)"
                                        x-bind:disabled="uploading"
                                        x-bind:aria-label="message('removeFile', { name: entry.name })"
                                        class="grid size-8 shrink-0 place-items-center rounded-lg text-[var(--color-ink-soft)] hover:bg-[var(--color-field-muted)] hover:text-[var(--color-ink-strong)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)] disabled:cursor-not-allowed disabled:opacity-40"
                                    >
                                        <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" class="size-4" stroke="currentColor" stroke-width="1.8">
                                            <path stroke-linecap="round" d="M6 6l12 12M18 6 6 18" />
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        </template>
                    </div>

                    <p
                        x-cloak
                        x-show="overBatchLimit"
                        data-media-library-batch-limit
                        role="alert"
                        class="w-full text-xs leading-5 text-[var(--color-danger-strong)]"
                        x-text="message('batchLimit', { max: maxFiles, count: batchOverflow })"
                    ></p>
                    <p
                        x-cloak
                        x-show="overQuotaLimit"
                        role="alert"
                        class="w-full text-xs leading-5 text-[var(--color-danger-strong)]"
                        x-text="message('batchQuota', { count: remaining })"
                    ></p>
                    @error('upload')
                        <p class="w-full text-xs text-[var(--color-danger-strong)]">{{ $message }}</p>
                    @enderror

                    <div
                        x-cloak
                        x-show="uploading"
                        data-media-library-batch-progress
                        role="status"
                        aria-live="polite"
                        aria-atomic="true"
                        class="w-full space-y-1.5"
                    >
                        <div class="flex items-center justify-between gap-3 text-xs text-[var(--color-ink-soft)]">
                            <span class="inline-flex items-center gap-2">
                                <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" class="size-4 motion-safe:animate-spin" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" d="M12 3a9 9 0 1 1-9 9" />
                                </svg>
                                <span x-text="message('batchPosition', { current: currentIndex + 1, total: files.length })"></span>
                            </span>
                            <span class="tabular-nums" x-text="`${currentProgress}%`"></span>
                        </div>
                        <div
                            role="progressbar"
                            aria-valuemin="0"
                            aria-valuemax="100"
                            x-bind:aria-valuenow="currentProgress"
                            class="h-1.5 w-full overflow-hidden rounded-full bg-[var(--color-field-muted)]"
                        >
                            <div class="h-full rounded-full bg-[var(--color-accent)] transition-[width] duration-150" x-bind:style="`width: ${currentProgress}%`"></div>
                        </div>
                    </div>

                    <button type="submit" x-bind:disabled="! canUpload" class="sk-btn sk-btn-primary justify-center disabled:cursor-not-allowed disabled:opacity-60">
                        <span x-show="! uploading">{{ __('media_library.upload_selected') }}</span>
                        <span x-show="uploading">{{ __('media_library.uploading') }}</span>
                    </button>
                </form>
            @endif
        </div>
    </section>

    <section class="overflow-hidden sk-card p-0">
        <div class="flex flex-col gap-4 border-b border-[var(--color-line)] bg-[var(--color-field-muted)] px-5 py-4 lg:flex-row lg:items-center lg:justify-between">
            <div role="group" class="flex flex-wrap gap-2" aria-label="{{ __('media_library.filters.aria_label') }}">
                @foreach (['all', 'used', 'unused'] as $value)
                    <button type="button" wire:click="$set('usageFilter', '{{ $value }}')" aria-pressed="{{ $usageFilter === $value ? 'true' : 'false' }}" class="{{ $usageFilter === $value ? 'border-[var(--color-accent)] bg-[var(--color-accent-soft)] text-[var(--color-accent-strong)]' : 'border-[var(--color-line)] bg-white text-[var(--color-ink-soft)]' }} rounded-full border px-4 py-2 text-sm font-medium">
                        {{ __('media_library.filters.'.$value) }}
                    </button>
                @endforeach

                <select wire:model.live="statusFilter" class="rounded-full border border-[var(--color-line)] bg-white px-4 py-2 text-sm text-[var(--color-ink-soft)]" aria-label="{{ __('media_library.filters.processing_status') }}">
                    <option value="all">{{ __('media_library.statuses.all') }}</option>
                    <option value="processing">{{ __('media_library.statuses.processing') }}</option>
                    <option value="ready">{{ __('media_library.statuses.ready') }}</option>
                    <option value="failed">{{ __('media_library.statuses.failed') }}</option>
                </select>
            </div>

            <label class="sk-field min-w-64">
                <span class="text-[var(--color-ink-soft)]">{{ __('media_library.search') }}</span>
                <input wire:model.live.debounce.250ms="search" type="search" placeholder="{{ __('media_library.search_placeholder') }}" class="sk-field-control" />
            </label>
        </div>

        @if ($assets->isEmpty())
            <div class="px-5 py-12 text-center">
                <h4 class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('media_library.empty.title') }}</h4>
                <p class="mt-2 text-sm text-[var(--color-ink-soft)]">{{ __('media_library.empty.description') }}</p>
            </div>
        @else
            <div data-media-gallery-grid class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 2xl:grid-cols-6 gap-3 p-3 sm:gap-4 sm:p-5">
                @foreach ($assets as $asset)
                    <article data-media-card wire:key="media-asset-{{ $asset->id }}" class="overflow-hidden rounded-[var(--radius-md)] border border-[var(--color-line)] bg-[var(--color-panel)]">
                        <div class="relative grid aspect-square place-items-center overflow-hidden bg-[var(--color-panel-strong)]">
                            @if ($asset->status === \App\MediaAssetStatus::Ready)
                                <img src="{{ route('media.show', [$asset, 'thumbnail']) }}" alt="" class="size-full object-cover" />
                            @else
                                <div class="grid size-full place-items-center p-6 text-center">
                                    <div>
                                        <div class="mx-auto size-12 animate-pulse rounded-xl bg-[var(--color-field-outline)]"></div>
                                        <p class="mt-3 text-sm font-medium text-[var(--color-ink-soft)]">{{ __('media_library.statuses.'.$asset->status->value) }}</p>
                                    </div>
                                </div>
                            @endif
                        </div>

                        <div class="space-y-2 p-2.5">
                            <div class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-2">
                                <div class="min-w-0">
                                    <h4 data-media-display-name class="truncate text-xs font-medium leading-5 text-[var(--color-ink-strong)]" title="{{ $asset->displayName() }}">{{ $asset->displayName() }}</h4>
                                    <button
                                        id="media-asset-usage-{{ $asset->id }}"
                                        data-media-usage-link
                                        type="button"
                                        wire:click="openAssetPanel({{ $asset->id }}, 'usage')"
                                        aria-haspopup="dialog"
                                        aria-controls="media-asset-panel"
                                        aria-expanded="{{ $selectedAsset?->is($asset) ? 'true' : 'false' }}"
                                        class="mt-0.5 text-[11px] font-medium text-[var(--color-accent-strong)] underline decoration-current/40 underline-offset-2 hover:decoration-current focus-visible:rounded-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)]"
                                    >
                                    {{ trans_choice('media_library.usage', $asset->usages_count, ['count' => $asset->usages_count]) }}
                                        <span aria-hidden="true">›</span>
                                    </button>
                                </div>

                                @if ($asset->status === \App\MediaAssetStatus::Ready && $canUpdateMedia)
                                    <button
                                        id="media-asset-settings-{{ $asset->id }}"
                                        data-media-settings-trigger
                                        type="button"
                                        wire:click="openAssetPanel({{ $asset->id }}, 'settings')"
                                        aria-label="{{ __('media_library.panel.settings_for', ['name' => $asset->displayName()]) }}"
                                        title="{{ __('media_library.panel.settings') }}"
                                        aria-haspopup="dialog"
                                        aria-controls="media-asset-panel"
                                        aria-expanded="{{ $selectedAsset?->is($asset) ? 'true' : 'false' }}"
                                        class="grid size-9 place-items-center rounded-lg border border-[var(--color-line)] bg-[var(--color-field-muted)] text-[var(--color-ink-soft)] transition hover:border-[var(--color-line-strong)] hover:text-[var(--color-ink-strong)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)]"
                                    >
                                        <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" class="size-4" stroke="currentColor" stroke-width="1.8">
                                            <path stroke-linecap="round" d="M4 7h10m4 0h2M4 17h2m4 0h10M14 4v6M7 14v6" />
                                        </svg>
                                    </button>
                                @endif
                            </div>

                            @if ($asset->status === \App\MediaAssetStatus::Processing)
                                <div>
                                    <div class="h-2 overflow-hidden rounded-full bg-[var(--color-field-muted)]">
                                        <div class="h-full rounded-full bg-[var(--color-accent)] transition-all" style="width: {{ $asset->progress }}%"></div>
                                    </div>
                                    <p class="mt-1 text-xs text-[var(--color-ink-soft)]">{{ $asset->progress }}% · {{ __('media_library.processing_stages.'.($asset->processing_stage ?: 'queued')) }}</p>
                                </div>
                            @elseif ($asset->status === \App\MediaAssetStatus::Failed)
                                <p role="alert" class="text-sm leading-6 text-[var(--color-danger-strong)]">{{ $asset->failure_reason }}</p>
                                @if ($canUpdateMedia || $canDeleteMedia)
                                    <div class="flex flex-wrap gap-2">
                                        @if ($canUpdateMedia)
                                            <button type="button" wire:click="retry({{ $asset->id }})" class="sk-btn sk-btn-primary">{{ __('media_library.actions.retry') }}</button>
                                        @endif
                                        @if ($canDeleteMedia)
                                            <button type="button" wire:click="remove({{ $asset->id }})" class="sk-btn border border-[var(--color-danger-soft)] text-[var(--color-danger-strong)]">{{ __('media_library.actions.remove') }}</button>
                                        @endif
                                    </div>
                                @endif
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>

            <div class="border-t border-[var(--color-line)] px-5 py-4">
                {{ $assets->links() }}
            </div>
        @endif
    </section>

    @if ($selectedAsset)
        <div
            data-media-asset-panel
            id="media-asset-panel"
            x-data="{
                closePanel() {
                    const trigger = document.getElementById('media-asset-settings-{{ $selectedAsset->id }}')
                        ?? document.getElementById('media-asset-usage-{{ $selectedAsset->id }}')

                    $wire.closeAssetPanel().then(() => $nextTick(() => trigger?.focus()))
                },
            }"
            x-on:keydown.escape.window="closePanel()"
            x-on:click.self="closePanel()"
            class="fixed inset-0 z-[80] flex justify-end bg-black/40"
        >
            <aside
                x-trap.inert.noscroll="true"
                x-init="$nextTick(() => $refs.closeButton.focus())"
                role="dialog"
                aria-modal="true"
                aria-labelledby="media-asset-panel-heading"
                class="flex h-full w-full flex-col border-l border-[var(--color-line)] bg-[var(--color-panel)] shadow-xl sm:max-w-[27.5rem]"
            >
                <header class="flex items-center gap-3 border-b border-[var(--color-line)] px-4 py-3">
                    <img src="{{ route('media.show', [$selectedAsset, 'thumbnail']) }}" alt="" class="size-12 shrink-0 rounded-lg object-cover" />
                    <div class="min-w-0 flex-1">
                        <h2 id="media-asset-panel-heading" class="truncate text-sm font-semibold text-[var(--color-ink-strong)]">{{ $selectedAsset->displayName() }}</h2>
                        <p class="mt-0.5 truncate text-[11px] text-[var(--color-ink-soft)]" title="{{ $selectedAsset->original_filename }}">
                            {{ __('media_library.original_filename', ['name' => $selectedAsset->original_filename]) }}
                        </p>
                    </div>
                    <button
                        x-ref="closeButton"
                        type="button"
                        x-on:click="closePanel()"
                        aria-label="{{ __('media_library.panel.close') }}"
                        class="grid size-9 shrink-0 place-items-center rounded-lg border border-[var(--color-line)] bg-[var(--color-field-muted)] text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)]"
                    >
                        <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" class="size-4" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" d="m6 6 12 12M18 6 6 18" />
                        </svg>
                    </button>
                </header>

                <div role="tablist" aria-label="{{ __('media_library.panel.tabs_label') }}" class="grid grid-cols-2 border-b border-[var(--color-line)] px-4">
                    <button
                        type="button"
                        role="tab"
                        wire:click="showAssetPanelTab('settings')"
                        aria-selected="{{ $assetPanelTab === 'settings' ? 'true' : 'false' }}"
                        class="{{ $assetPanelTab === 'settings' ? 'border-[var(--color-accent)] text-[var(--color-accent-strong)]' : 'border-transparent text-[var(--color-ink-soft)]' }} border-b-2 px-2 py-3 text-xs font-semibold"
                    >
                        {{ __('media_library.panel.settings') }}
                    </button>
                    <button
                        type="button"
                        role="tab"
                        wire:click="showAssetPanelTab('usage')"
                        aria-selected="{{ $assetPanelTab === 'usage' ? 'true' : 'false' }}"
                        class="{{ $assetPanelTab === 'usage' ? 'border-[var(--color-accent)] text-[var(--color-accent-strong)]' : 'border-transparent text-[var(--color-ink-soft)]' }} border-b-2 px-2 py-3 text-xs font-semibold"
                    >
                        {{ trans_choice('media_library.usage', $selectedAsset->usages_count, ['count' => $selectedAsset->usages_count]) }}
                    </button>
                </div>

                <div data-media-panel-scroll class="min-h-0 flex-1 overflow-y-auto p-4">
                    @if ($assetPanelTab === 'settings')
                        @if ($canUpdateMedia && $selectedAsset->status === \App\MediaAssetStatus::Ready)
                            <div class="space-y-5">
                                <form wire:submit="renameFromInput({{ $selectedAsset->id }})" class="space-y-2">
                                    <label class="block text-xs font-medium text-[var(--color-ink-soft)]" for="display-name-{{ $selectedAsset->id }}">
                                        {{ __('media_library.display_name') }}
                                    </label>
                                    <div class="flex gap-2">
                                        <input id="display-name-{{ $selectedAsset->id }}" wire:model="displayNames.{{ $selectedAsset->id }}" type="text" maxlength="255" required @if ($errors->has('displayNames.'.$selectedAsset->id)) aria-describedby="display-name-error-{{ $selectedAsset->id }}" @endif aria-invalid="{{ $errors->has('displayNames.'.$selectedAsset->id) ? 'true' : 'false' }}" class="min-w-0 flex-1 rounded-lg border border-[var(--color-line)] bg-white px-3 py-2 text-sm text-[var(--color-ink-strong)]" />
                                        <button type="submit" class="sk-btn px-3 py-2 text-xs">{{ __('media_library.save_name') }}</button>
                                    </div>
                                    @error('displayNames.'.$selectedAsset->id)
                                        <p id="display-name-error-{{ $selectedAsset->id }}" class="text-xs text-[var(--color-danger-strong)]">{{ $message }}</p>
                                    @enderror
                                </form>

                                <section x-data="{ focalX: {{ $selectedAsset->focal_x }}, focalY: {{ $selectedAsset->focal_y }} }" class="border-t border-[var(--color-line)] pt-4">
                                    <h3 class="text-xs font-semibold text-[var(--color-ink-strong)]">{{ __('media_library.crop.adjust') }}</h3>
                                    <div class="mt-3 space-y-3">
                                        <div class="relative grid aspect-[4/3] place-items-center overflow-hidden rounded-lg bg-[var(--color-field-muted)]">
                                            <img src="{{ route('media.show', [$selectedAsset, 'master']) }}" alt="" class="size-full object-contain" />
                                            <span
                                                aria-hidden="true"
                                                class="pointer-events-none absolute size-5 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-white bg-[var(--color-accent)] shadow"
                                                x-bind:style="`left: ${focalX}%; top: ${focalY}%`"
                                            ></span>
                                        </div>
                                        <label class="block text-xs font-medium text-[var(--color-ink-soft)]">
                                            {{ __('media_library.crop.horizontal') }}
                                            <input x-model.number="focalX" type="range" min="0" max="100" step="1" class="mt-1 w-full accent-[var(--color-accent)]" />
                                        </label>
                                        <label class="block text-xs font-medium text-[var(--color-ink-soft)]">
                                            {{ __('media_library.crop.vertical') }}
                                            <input x-model.number="focalY" type="range" min="0" max="100" step="1" class="mt-1 w-full accent-[var(--color-accent)]" />
                                        </label>
                                        <button
                                            type="button"
                                            wire:click="updateFocalPoint({{ $selectedAsset->id }}, focalX, focalY)"
                                            wire:loading.attr="disabled"
                                            wire:target="updateFocalPoint"
                                            class="sk-btn sk-btn-primary"
                                        >
                                            {{ __('media_library.crop.save') }}
                                        </button>
                                    </div>
                                </section>
                            </div>
                        @endif
                    @else
                        <label class="block">
                            <span class="sr-only">{{ __('media_library.panel.search_usage') }}</span>
                            <input wire:model.live.debounce.250ms="usageSearch" type="search" placeholder="{{ __('media_library.panel.search_usage') }}" class="w-full rounded-lg border border-[var(--color-line)] bg-white px-3 py-2 text-sm text-[var(--color-ink-strong)]" />
                        </label>

                        @if ($selectedUsageGroups->isEmpty())
                            <p class="py-10 text-center text-sm text-[var(--color-ink-soft)]">
                                {{ $selectedAsset->usages_count === 0 ? __('media_library.panel.no_usages') : __('media_library.panel.no_matching_usages') }}
                            </p>
                        @else
                            <div class="mt-4 space-y-5">
                                @foreach ($selectedUsageGroups as $group => $usages)
                                    <section>
                                        <div class="flex items-center justify-between gap-3">
                                            <h3 class="text-[11px] font-semibold tracking-wide text-[var(--color-ink-soft)] uppercase">{{ __('media_library.panel.groups.'.$group) }}</h3>
                                            <span class="text-[11px] text-[var(--color-ink-soft)]">{{ $usages->count() }}</span>
                                        </div>
                                        <ul class="mt-2 overflow-hidden rounded-lg border border-[var(--color-line)]">
                                            @foreach ($usages as $usage)
                                                @php($usable = $usage->usable)
                                                <li class="border-b border-[var(--color-line)] px-3 py-2.5 last:border-b-0">
                                                    @if ($usable instanceof \App\Models\Recipe && $usable->workspace_id === $selectedAsset->workspace_id)
                                                        <a href="{{ route('recipes.edit', $usable) }}" class="text-xs font-medium text-[var(--color-accent-strong)] underline underline-offset-2">{{ $usable->name }}</a>
                                                    @elseif ($usable instanceof \App\Models\Ingredient && $usable->workspace_id === $selectedAsset->workspace_id)
                                                        <a href="{{ route('ingredients.edit', $usable) }}" class="text-xs font-medium text-[var(--color-accent-strong)] underline underline-offset-2">{{ $usable->display_name }}</a>
                                                    @elseif ($usable instanceof \App\Models\UserPackagingItem && $usable->user_id === $user?->id)
                                                        <a href="{{ route('packaging-items.edit', $usable) }}" class="text-xs font-medium text-[var(--color-accent-strong)] underline underline-offset-2">{{ $usable->name }}</a>
                                                    @else
                                                        <span class="text-xs text-[var(--color-ink-soft)]">{{ __('media_library.missing_target') }}</span>
                                                    @endif
                                                    <p class="mt-0.5 text-[11px] text-[var(--color-ink-soft)]">{{ __('media_library.roles.'.$usage->role->value) }}</p>
                                                </li>
                                            @endforeach
                                        </ul>
                                    </section>
                                @endforeach
                            </div>
                        @endif
                    @endif
                </div>

                @if ($canDeleteMedia && $selectedAsset->status === \App\MediaAssetStatus::Ready)
                    <footer class="flex items-center justify-between gap-3 border-t border-[var(--color-line)] px-4 py-3">
                        @if ($selectedAsset->usages_count > 0)
                            <p class="text-[11px] leading-4 text-[var(--color-ink-soft)]">{{ __('media_library.detach_before_removing') }}</p>
                        @else
                            <span></span>
                        @endif
                        <button
                            type="button"
                            wire:click="remove({{ $selectedAsset->id }})"
                            @if ($selectedAsset->usages_count === 0) wire:confirm="{{ __('media_library.panel.delete_confirm', ['name' => $selectedAsset->displayName()]) }}" @endif
                            @disabled($selectedAsset->usages_count > 0)
                            aria-label="{{ __('media_library.panel.delete') }}"
                            title="{{ $selectedAsset->usages_count > 0 ? __('media_library.detach_before_removing') : __('media_library.panel.delete') }}"
                            class="inline-flex shrink-0 items-center gap-1.5 rounded-lg px-2 py-1.5 text-xs font-medium text-[var(--color-danger-strong)] hover:bg-[var(--color-danger-soft)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-danger)] disabled:cursor-not-allowed disabled:opacity-45"
                        >
                            <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" class="size-4" stroke="currentColor" stroke-width="1.8">
                                <path stroke-linecap="round" d="M4 7h16m-10 4v6m4-6v6M6 7l1 13h10l1-13M9 7V4h6v3" />
                            </svg>
                            <span>{{ __('media_library.panel.delete') }}</span>
                        </button>
                    </footer>
                @endif
            </aside>
        </div>
    @endif
</div>
