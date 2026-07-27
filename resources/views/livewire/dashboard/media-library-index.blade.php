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
                        accept=".jpg,.jpeg,.png,.webp,.heic,.heif,.pdf,image/jpeg,image/png,image/webp,image/heic,image/heif,application/pdf"
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

                    @if ($labels->isNotEmpty())
                        <details class="w-full rounded-xl border border-[var(--color-line)] bg-[var(--color-panel)] px-3 py-2 text-sm">
                            <summary class="cursor-pointer text-xs font-medium text-[var(--color-ink-soft)]">
                                {{ __('media_library.labels.apply_on_upload') }}
                            </summary>
                            <div class="mt-2 grid grid-cols-2 gap-2">
                                @foreach ($labels as $label)
                                    <label class="flex items-center gap-2 text-xs text-[var(--color-ink-strong)]">
                                        <input wire:model="uploadLabelIds" type="checkbox" value="{{ $label->id }}" class="rounded border-[var(--color-line)] text-[var(--color-accent)] focus:ring-[var(--color-accent)]" />
                                        <span class="truncate">{{ $label->name }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </details>
                    @endif

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

    <section data-media-gallery-section class="space-y-4">
        <div data-media-filter-toolbar class="flex flex-col gap-4 rounded-[var(--radius-md)] border border-[var(--color-line)] bg-[var(--color-field-muted)] px-5 py-4 lg:flex-row lg:items-center lg:justify-between">
            <div role="group" class="flex flex-wrap items-center gap-2" aria-label="{{ __('media_library.filters.aria_label') }}">
                <div data-media-usage-filter role="group" class="flex items-center gap-2" aria-label="{{ __('media_library.filters.usage') }}">
                    <span id="media-usage-filter-label" class="text-[10px] font-semibold uppercase tracking-[0.08em] text-[var(--color-ink-soft)]">
                        {{ __('media_library.filters.usage') }}
                    </span>
                    <div class="flex flex-wrap gap-1">
                        @foreach (['all', 'used', 'unused'] as $value)
                            <button type="button" wire:click="$set('usageFilter', '{{ $value }}')" aria-pressed="{{ $usageFilter === $value ? 'true' : 'false' }}" class="{{ $usageFilter === $value ? 'border-[var(--color-accent)] bg-[var(--color-accent-soft)] text-[var(--color-accent-strong)]' : 'border-[var(--color-line)] bg-[var(--color-panel)] text-[var(--color-ink-soft)] hover:border-[var(--color-line-strong)] hover:text-[var(--color-ink-strong)]' }} rounded-full border px-3 py-2 text-xs font-medium transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)]">
                                {{ $value === 'all' ? __('media_library.filters.any_usage') : __('media_library.filters.'.$value) }}
                            </button>
                        @endforeach
                    </div>
                </div>

                <span aria-hidden="true" class="mx-1 hidden h-6 w-px bg-[var(--color-line)] sm:block"></span>

                <select data-media-status-filter wire:model.live="statusFilter" class="rounded-full border border-[var(--color-line)] bg-[var(--color-panel)] px-3 py-2 text-xs text-[var(--color-ink-soft)] focus:border-[var(--color-accent)] focus:ring-1 focus:ring-[var(--color-accent)]" aria-label="{{ __('media_library.filters.processing_status') }}">
                    <option value="all">{{ __('media_library.statuses.all') }}</option>
                    <option value="processing">{{ __('media_library.statuses.processing') }}</option>
                    <option value="ready">{{ __('media_library.statuses.ready') }}</option>
                    <option value="failed">{{ __('media_library.statuses.failed') }}</option>
                </select>

                <select data-media-type-filter wire:model.live="typeFilter" class="rounded-full border border-[var(--color-line)] bg-[var(--color-panel)] px-3 py-2 text-xs text-[var(--color-ink-soft)] focus:border-[var(--color-accent)] focus:ring-1 focus:ring-[var(--color-accent)]" aria-label="{{ __('media_library.filters.type') }}">
                    <option value="all">{{ __('media_library.filters.all_types') }}</option>
                    <option value="image">{{ __('media_library.filters.images') }}</option>
                    <option value="pdf">{{ __('media_library.filters.pdfs') }}</option>
                </select>

                @if ($labels->isNotEmpty())
                    <details data-media-label-filter class="relative">
                        <summary class="cursor-pointer list-none rounded-full border border-[var(--color-line)] bg-[var(--color-panel)] px-3 py-2 text-xs text-[var(--color-ink-soft)] transition hover:border-[var(--color-line-strong)] hover:text-[var(--color-ink-strong)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)]">
                            {{ __('media_library.labels.filter') }}
                            @if ($labelFilter !== [])
                                <span class="ml-1 text-[var(--color-accent-strong)]">· {{ count($labelFilter) }}</span>
                            @endif
                        </summary>
                        <div class="absolute left-0 z-20 mt-2 min-w-52 space-y-2 rounded-xl border border-[var(--color-line)] bg-[var(--color-panel)] p-3 shadow-lg">
                            @foreach ($labels as $label)
                                <label class="flex items-center gap-2 text-xs text-[var(--color-ink-strong)]">
                                    <input wire:model.live="labelFilter" type="checkbox" value="{{ $label->id }}" class="rounded border-[var(--color-line)] text-[var(--color-accent)] focus:ring-[var(--color-accent)]" />
                                    <span class="truncate">{{ $label->name }}</span>
                                </label>
                            @endforeach
                        </div>
                    </details>
                @endif
            </div>

            <label class="sk-field min-w-64">
                <span class="text-[var(--color-ink-soft)]">{{ __('media_library.search') }}</span>
                <input wire:model.live.debounce.250ms="search" type="search" placeholder="{{ __('media_library.search_placeholder') }}" class="sk-field-control" />
            </label>
        </div>

        @if ($assets->isEmpty())
            <div class="rounded-[var(--radius-md)] border border-[var(--color-line)] bg-[var(--color-panel)] px-5 py-12 text-center">
                <h4 class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('media_library.empty.title') }}</h4>
                <p class="mt-2 text-sm text-[var(--color-ink-soft)]">{{ __('media_library.empty.description') }}</p>
            </div>
        @else
            <div data-media-gallery-grid class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 2xl:grid-cols-6 gap-4 sm:gap-5">
                @foreach ($assets as $asset)
                    <article data-media-card wire:key="media-asset-{{ $asset->id }}" class="overflow-hidden rounded-[var(--radius-md)] border border-[var(--color-line)] bg-[var(--color-panel)]">
                        <div data-media-card-preview class="relative grid w-full aspect-square place-items-center overflow-hidden bg-[var(--color-panel-strong)]">
                            @if ($asset->status === \App\MediaAssetStatus::Ready && $asset->getFirstMedia('master'))
                                <img src="{{ route('media.show', [$asset, 'thumbnail']) }}" alt="" class="size-full object-cover" />
                            @elseif ($asset->status === \App\MediaAssetStatus::Ready && $asset->type === \App\MediaAssetType::Pdf)
                                <div data-media-pdf-placeholder class="grid size-full place-items-center p-6 text-center text-[var(--color-ink-soft)]">
                                    <div>
                                        <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" class="mx-auto size-12" stroke="currentColor" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M7 3h7l4 4v14H7zM14 3v5h5M9.5 15.5h5M9.5 12.5h5" />
                                        </svg>
                                        <p class="mt-2 text-xs font-semibold">{{ __('media_library.documents.pdf') }}</p>
                                    </div>
                                </div>
                            @else
                                <div class="grid size-full place-items-center p-6 text-center">
                                    <div>
                                        <div class="mx-auto size-12 animate-pulse rounded-xl bg-[var(--color-field-outline)]"></div>
                                        <p class="mt-3 text-sm font-medium text-[var(--color-ink-soft)]">{{ __('media_library.statuses.'.$asset->status->value) }}</p>
                                    </div>
                                </div>
                            @endif
                        </div>

                        <div class="space-y-2.5 p-3">
                            <div data-media-card-name-row class="min-w-0">
                                <h4 data-media-display-name class="truncate text-[11px] font-medium leading-4 text-[var(--color-ink-strong)]" title="{{ $asset->displayName() }}">{{ $asset->displayName() }}</h4>
                            </div>

                            <div data-media-card-meta-row class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-3">
                                <div class="min-w-0">
                                    <div class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
                                        <button
                                            id="media-asset-usage-{{ $asset->id }}"
                                            data-media-usage-link
                                            type="button"
                                            wire:click="openAssetPanel({{ $asset->id }}, 'usage')"
                                            aria-haspopup="dialog"
                                            aria-controls="media-asset-panel"
                                            aria-expanded="{{ $selectedAsset?->is($asset) ? 'true' : 'false' }}"
                                            class="text-[10px] font-medium text-[var(--color-accent-strong)] underline decoration-current/40 underline-offset-2 hover:decoration-current focus-visible:rounded-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)]"
                                        >
                                            {{ trans_choice('media_library.usage', $asset->usages_count, ['count' => $asset->usages_count]) }}
                                            <span aria-hidden="true">›</span>
                                        </button>
                                        @if ($asset->type === \App\MediaAssetType::Pdf && $asset->status === \App\MediaAssetStatus::Ready)
                                            <a href="{{ route('media.download', $asset) }}" class="text-[10px] font-medium text-[var(--color-ink-soft)] underline decoration-current/40 underline-offset-2">
                                                {{ __('media_library.documents.download') }}
                                            </a>
                                        @endif
                                    </div>
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
                                        class="grid size-8 place-items-center rounded-lg border border-[var(--color-line)] bg-[var(--color-field-muted)] text-[var(--color-ink-soft)] transition hover:border-[var(--color-line-strong)] hover:bg-[var(--color-field)] hover:text-[var(--color-ink-strong)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)]"
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

            <div class="pt-2">
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
                class="flex h-full w-full flex-col border-l border-[var(--color-line)] bg-[var(--color-panel)] shadow-xl sm:max-w-[29rem]"
            >
                <header data-media-panel-header class="flex items-center gap-3.5 border-b border-[var(--color-line)] px-5 py-4">
                    @if ($selectedAsset->getFirstMedia('master'))
                        <img src="{{ route('media.show', [$selectedAsset, 'thumbnail']) }}" alt="" class="size-14 shrink-0 rounded-lg object-cover" />
                    @else
                        <span class="grid size-14 shrink-0 place-items-center rounded-lg bg-[var(--color-field-muted)] text-xs font-semibold text-[var(--color-ink-soft)]">PDF</span>
                    @endif
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
                        class="grid size-10 shrink-0 place-items-center rounded-lg border border-[var(--color-line)] bg-[var(--color-field-muted)] text-[var(--color-ink-soft)] transition hover:border-[var(--color-line-strong)] hover:bg-[var(--color-field)] hover:text-[var(--color-ink-strong)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)]"
                    >
                        <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" class="size-4" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" d="m6 6 12 12M18 6 6 18" />
                        </svg>
                    </button>
                </header>

                <div role="tablist" aria-label="{{ __('media_library.panel.tabs_label') }}" class="grid grid-cols-2 border-b border-[var(--color-line)] px-5">
                    <button
                        type="button"
                        role="tab"
                        wire:click="showAssetPanelTab('settings')"
                        aria-selected="{{ $assetPanelTab === 'settings' ? 'true' : 'false' }}"
                        class="{{ $assetPanelTab === 'settings' ? 'border-[var(--color-active)] text-[var(--color-active-strong)]' : 'border-transparent text-[var(--color-ink-soft)]' }} border-b-2 px-2 py-3.5 text-xs font-semibold transition hover:text-[var(--color-ink-strong)] focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-[var(--color-active)]"
                    >
                        {{ __('media_library.panel.settings') }}
                    </button>
                    <button
                        type="button"
                        role="tab"
                        wire:click="showAssetPanelTab('usage')"
                        aria-selected="{{ $assetPanelTab === 'usage' ? 'true' : 'false' }}"
                        class="{{ $assetPanelTab === 'usage' ? 'border-[var(--color-active)] text-[var(--color-active-strong)]' : 'border-transparent text-[var(--color-ink-soft)]' }} border-b-2 px-2 py-3.5 text-xs font-semibold transition hover:text-[var(--color-ink-strong)] focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-[var(--color-active)]"
                    >
                        {{ trans_choice('media_library.usage', $selectedAsset->usages_count, ['count' => $selectedAsset->usages_count]) }}
                    </button>
                </div>

                <div data-media-panel-scroll class="min-h-0 flex-1 overflow-y-auto p-5">
                    @if ($assetPanelTab === 'settings')
                        @if ($canUpdateMedia && $selectedAsset->status === \App\MediaAssetStatus::Ready)
                            <div class="space-y-6">
                                <form data-media-panel-section wire:submit="renameFromInput({{ $selectedAsset->id }})" class="space-y-2.5">
                                    <label class="block text-xs font-medium text-[var(--color-ink-soft)]" for="display-name-{{ $selectedAsset->id }}">
                                        {{ __('media_library.display_name') }}
                                    </label>
                                    <div class="flex gap-2">
                                        <input id="display-name-{{ $selectedAsset->id }}" wire:model="displayNames.{{ $selectedAsset->id }}" type="text" maxlength="255" required @if ($errors->has('displayNames.'.$selectedAsset->id)) aria-describedby="display-name-error-{{ $selectedAsset->id }}" @endif aria-invalid="{{ $errors->has('displayNames.'.$selectedAsset->id) ? 'true' : 'false' }}" class="min-w-0 flex-1 rounded-lg border border-[var(--color-field-outline)] bg-[var(--color-field)] px-3 py-2.5 text-sm text-[var(--color-ink-strong)] focus:border-[var(--color-accent)] focus:ring-1 focus:ring-[var(--color-accent)]" />
                                        <button type="submit" class="sk-btn sk-btn-outline px-3 text-xs">{{ __('media_library.save_name') }}</button>
                                    </div>
                                    @error('displayNames.'.$selectedAsset->id)
                                        <p id="display-name-error-{{ $selectedAsset->id }}" class="text-xs text-[var(--color-danger-strong)]">{{ $message }}</p>
                                    @enderror
                                </form>

                                <section data-media-panel-section class="space-y-3.5 border-t border-[var(--color-line)] pt-5">
                                    @php
                                        $selectedPanelLabelIds = collect($selectedLabelIds)->map(static fn ($id): int => (int) $id);
                                        $assignedPanelLabels = $labels->whereIn('id', $selectedPanelLabelIds);
                                        $availablePanelLabels = $labels->whereNotIn('id', $selectedPanelLabelIds);
                                    @endphp

                                    <div class="flex items-center justify-between gap-3">
                                        <h3 class="text-[11px] font-semibold tracking-[0.08em] text-[var(--color-ink-soft)] uppercase">{{ __('media_library.labels.heading') }}</h3>
                                        <span class="text-[10px] text-[var(--color-ink-soft)]">{{ count($selectedLabelIds) }}/8</span>
                                    </div>

                                    @if ($assignedPanelLabels->isNotEmpty())
                                        <div data-media-assigned-labels class="flex flex-wrap gap-1.5">
                                            @foreach ($assignedPanelLabels as $label)
                                                <span wire:key="assigned-media-label-{{ $label->id }}" class="inline-flex min-w-0 items-center gap-1 rounded-full border border-[var(--color-line)] bg-[var(--color-active-soft)] py-0.5 pl-2.5 pr-1 text-xs text-[var(--color-active-strong)]">
                                                    <span class="max-w-44 truncate">{{ $label->name }}</span>
                                                    <button
                                                        type="button"
                                                        wire:click="removeSelectedLabel({{ $label->id }})"
                                                        wire:loading.attr="disabled"
                                                        wire:target="removeSelectedLabel({{ $label->id }})"
                                                        aria-label="{{ __('media_library.labels.remove', ['name' => $label->name]) }}"
                                                        class="grid size-6 shrink-0 place-items-center rounded-full text-[var(--color-active-strong)] transition hover:bg-[var(--color-field)] focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-[var(--color-active)] disabled:opacity-50"
                                                    >
                                                        <span aria-hidden="true">&times;</span>
                                                    </button>
                                                </span>
                                            @endforeach
                                        </div>
                                    @elseif ($labels->isNotEmpty())
                                        <p class="text-xs leading-5 text-[var(--color-ink-soft)]">{{ __('media_library.labels.none_assigned') }}</p>
                                    @else
                                        <p class="text-xs leading-5 text-[var(--color-ink-soft)]">{{ __('media_library.labels.none') }}</p>
                                    @endif

                                    @if ($availablePanelLabels->isNotEmpty() && count($selectedLabelIds) < 8)
                                        <div
                                            data-media-label-popover
                                            x-data="{ open: false }"
                                            x-on:click.outside="open = false"
                                            x-on:keydown.escape.prevent.stop="open = false; $refs.trigger.focus()"
                                            class="relative"
                                        >
                                            <button
                                                x-ref="trigger"
                                                data-media-label-trigger
                                                type="button"
                                                x-on:click="open = ! open"
                                                aria-haspopup="listbox"
                                                aria-controls="media-label-options"
                                                x-bind:aria-expanded="open.toString()"
                                                class="flex min-h-10 w-full items-center justify-between gap-3 rounded-lg border bg-[var(--color-field)] px-3 py-2 text-left text-xs text-[var(--color-ink-strong)] transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-active)]"
                                                x-bind:class="open ? 'border-[var(--color-active)]' : 'border-[var(--color-field-outline)] hover:border-[var(--color-line-strong)]'"
                                            >
                                                <span>{{ __('media_library.labels.choose') }}</span>
                                                <svg aria-hidden="true" viewBox="0 0 20 20" fill="none" class="size-4 shrink-0 text-[var(--color-ink-soft)] transition-transform duration-150" x-bind:class="{ 'rotate-180': open }" stroke="currentColor" stroke-width="1.8">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m6 8 4 4 4-4" />
                                                </svg>
                                            </button>

                                            <div
                                                x-cloak
                                                x-show="open"
                                                x-transition:enter="transition ease-out duration-150"
                                                x-transition:enter-start="opacity-0 -translate-y-1"
                                                x-transition:enter-end="opacity-100 translate-y-0"
                                                x-transition:leave="transition ease-in duration-100"
                                                x-transition:leave-start="opacity-100"
                                                x-transition:leave-end="opacity-0"
                                                id="media-label-options"
                                                data-media-label-options
                                                role="listbox"
                                                class="mt-1.5 max-h-52 overflow-y-auto rounded-lg border border-[var(--color-line)] bg-[var(--color-panel)] p-1.5"
                                            >
                                                @foreach ($availablePanelLabels as $label)
                                                    <button
                                                        wire:key="available-media-label-{{ $label->id }}"
                                                        type="button"
                                                        role="option"
                                                        aria-selected="false"
                                                        wire:click="assignLabel({{ $label->id }})"
                                                        wire:loading.attr="disabled"
                                                        wire:target="assignLabel({{ $label->id }})"
                                                        class="flex min-h-10 w-full items-center justify-between gap-3 rounded-md px-3 py-2 text-left text-xs text-[var(--color-ink-strong)] transition hover:bg-[var(--color-field-muted)] focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-[var(--color-active)] disabled:cursor-wait disabled:opacity-50"
                                                    >
                                                        <span class="truncate">{{ $label->name }}</span>
                                                        <span aria-hidden="true" class="text-base leading-none text-[var(--color-active-strong)]">+</span>
                                                    </button>
                                                @endforeach
                                            </div>
                                        </div>
                                    @elseif (count($selectedLabelIds) >= 8)
                                        <p class="text-xs leading-5 text-[var(--color-ink-soft)]">{{ __('media_library.labels.asset_limit', ['count' => 8]) }}</p>
                                    @endif
                                    @error('selectedLabelIds')
                                        <p class="text-xs text-[var(--color-danger-strong)]">{{ $message }}</p>
                                    @enderror

                                    <form wire:submit="createLabel" class="flex gap-2">
                                        <input wire:model="newLabelName" type="text" maxlength="30" placeholder="{{ __('media_library.labels.new_placeholder') }}" class="min-w-0 flex-1 rounded-lg border border-[var(--color-field-outline)] bg-[var(--color-field)] px-3 py-2.5 text-xs text-[var(--color-ink-strong)] focus:border-[var(--color-accent)] focus:ring-1 focus:ring-[var(--color-accent)]" />
                                        <button type="submit" class="sk-btn sk-btn-outline px-3 text-xs">{{ __('media_library.labels.add') }}</button>
                                    </form>
                                    @error('newLabelName')
                                        <p class="text-xs text-[var(--color-danger-strong)]">{{ $message }}</p>
                                    @enderror
                                </section>

                                @if ($selectedAsset->type === \App\MediaAssetType::Pdf)
                                    <section data-media-panel-section class="border-t border-[var(--color-line)] pt-5">
                                        <a href="{{ route('media.download', $selectedAsset) }}" class="sk-btn sk-btn-primary">{{ __('media_library.documents.download') }}</a>
                                    </section>
                                @else
                                <section data-media-panel-section x-data="{ focalX: {{ $selectedAsset->focal_x }}, focalY: {{ $selectedAsset->focal_y }} }" class="border-t border-[var(--color-line)] pt-5">
                                    <h3 class="text-[11px] font-semibold tracking-[0.08em] text-[var(--color-ink-soft)] uppercase">{{ __('media_library.crop.adjust') }}</h3>
                                    <div class="mt-3 space-y-3">
                                        <div class="relative grid aspect-[4/3] place-items-center overflow-hidden rounded-lg bg-[var(--color-field-muted)]">
                                            <img src="{{ route('media.show', [$selectedAsset, 'master']) }}" alt="" class="size-full object-contain" />
                                            <span
                                                aria-hidden="true"
                                                class="pointer-events-none absolute size-5 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-[var(--color-field)] bg-[var(--color-active)] shadow"
                                                x-bind:style="`left: ${focalX}%; top: ${focalY}%`"
                                            ></span>
                                        </div>
                                        <label class="block text-xs font-medium text-[var(--color-ink-soft)]">
                                            {{ __('media_library.crop.horizontal') }}
                                            <input x-model.number="focalX" type="range" min="0" max="100" step="1" class="mt-1 w-full accent-[var(--color-active)]" />
                                        </label>
                                        <label class="block text-xs font-medium text-[var(--color-ink-soft)]">
                                            {{ __('media_library.crop.vertical') }}
                                            <input x-model.number="focalY" type="range" min="0" max="100" step="1" class="mt-1 w-full accent-[var(--color-active)]" />
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
                                @endif
                            </div>
                        @endif
                    @else
                        <label class="block">
                            <span class="sr-only">{{ __('media_library.panel.search_usage') }}</span>
                            <input wire:model.live.debounce.250ms="usageSearch" type="search" placeholder="{{ __('media_library.panel.search_usage') }}" class="w-full rounded-lg border border-[var(--color-field-outline)] bg-[var(--color-field)] px-3 py-2.5 text-sm text-[var(--color-ink-strong)] focus:border-[var(--color-accent)] focus:ring-1 focus:ring-[var(--color-accent)]" />
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
                    <footer class="flex items-center justify-between gap-3 border-t border-[var(--color-line)] bg-[var(--color-field-muted)] px-5 py-4">
                        @if ($selectedAsset->usages_count > 0)
                            <p class="text-[11px] leading-4 text-[var(--color-ink-soft)]">{{ __('media_library.detach_before_removing') }}</p>
                        @else
                            <span></span>
                        @endif
                        <button
                            type="button"
                            wire:click="remove({{ $selectedAsset->id }})"
                            wire:loading.attr="disabled"
                            wire:target="remove({{ $selectedAsset->id }})"
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
