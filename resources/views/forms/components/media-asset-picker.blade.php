@php
    $statePath = $getStatePath();
    $selectedAssets = $getSelectedMediaAssets();
    $isMultiple = $isMultiple();
    $maximumItems = $getMaximumItems();
    $canUpload = $canUpload();
    $usage = $getMediaAssetUsage();
    $preserveAspectRatio = $shouldPreserveAspectRatio();
    $embedded = $isEmbedded();
    $pickerId = 'media-picker-'.$getId();
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        x-data="mediaAssetPicker({
            assetsUrl: @js(route('media.picker-assets')),
            livewire: $wire,
            statePath: @js($statePath),
            state: $wire.{{ $applyStateBindingModifiers("\$entangle('{$statePath}')") }},
            multiple: @js($isMultiple),
            maximumItems: @js($maximumItems),
            preserveAspectRatio: @js($preserveAspectRatio),
            embedded: @js($embedded),
            messages: {
                refreshFailed: @js(__('media_library.picker.refresh_failed')),
                uploadFailed: @js(__('media_library.picker.upload_failed')),
                pollingStopped: @js(__('media_library.picker.polling_stopped')),
                processingFailed: @js(__('media_library.picker.processing_failed')),
            },
        })"
        data-media-picker-assets-url="{{ route('media.picker-assets') }}"
        class="space-y-3"
    >
        @unless ($embedded)
            @if ($selectedAssets->isNotEmpty())
                <div class="flex flex-wrap gap-3">
                    @foreach ($selectedAssets as $asset)
                        <div class="flex items-center gap-3 rounded-xl border border-[var(--color-line)] bg-[var(--color-field-muted)] p-2 pr-3">
                            @if ($preserveAspectRatio)
                                <span class="grid aspect-[4/3] w-32 shrink-0 place-items-center overflow-hidden rounded-lg bg-[var(--color-panel-strong)]">
                                    <img src="{{ route('media.show', [$asset, 'master']) }}" alt="" class="size-full object-contain" draggable="false" />
                                </span>
                            @else
                                <img src="{{ route('media.show', [$asset, 'thumbnail']) }}" alt="" class="size-14 rounded-lg object-cover" draggable="false" />
                            @endif
                            <div class="min-w-0"><span class="block max-w-52 truncate text-sm font-medium text-[var(--color-ink-strong)]">{{ $asset->displayName() }}</span>@if (filled($asset->display_name) && $asset->display_name !== $asset->original_filename)<span class="block max-w-52 truncate text-xs text-[var(--color-ink-soft)]">{{ $asset->original_filename }}</span>@endif</div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="rounded-xl border border-dashed border-[var(--color-line)] bg-[var(--color-field-muted)] px-4 py-5 text-sm text-[var(--color-ink-soft)]">{{ __('media_library.picker.no_selection') }}</div>
            @endif

            <div class="flex flex-wrap gap-2">
                <button x-ref="trigger" type="button" x-on:click="openPicker()" class="sk-btn sk-btn-primary">{{ $isMultiple ? __('media_library.picker.choose_multiple') : __('media_library.picker.choose') }}</button>
                <button type="button" x-show="multiple ? (Array.isArray(state) && state.length) : state" x-on:click="state = multiple ? [] : null" class="sk-btn border border-[var(--color-line)] text-[var(--color-ink-soft)]">{{ __('media_library.picker.clear') }}</button>
            </div>
        @endunless

        @if ($isEmbedded())
            <div data-media-picker-embedded class="overflow-hidden rounded-[var(--radius-lg)] border border-[var(--color-line)] bg-[var(--color-panel)]">
        @else
            <div x-cloak x-show="open" x-trap.inert.noscroll="open" x-on:keydown.escape.window="closePicker()" class="fixed inset-0 z-[70] grid place-items-center bg-black/40 p-4" role="dialog" aria-modal="true" aria-label="{{ __('media_library.picker.choose') }}">
                <div x-on:click.outside="closePicker()" class="max-h-[88dvh] w-full max-w-5xl overflow-hidden rounded-[var(--radius-lg)] bg-[var(--color-panel)] shadow-2xl">
        @endif
            @unless ($embedded)
                <div class="flex items-start justify-between gap-4 border-b border-[var(--color-line)] px-5 py-4">
                    <div><h3 class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('media_library.picker.choose') }}</h3><p class="mt-1 text-sm text-[var(--color-ink-soft)]">{{ $isMultiple ? __('media_library.picker.select_multiple', ['count' => $maximumItems]) : __('media_library.picker.select_one') }}</p></div>
                    <button type="button" x-on:click="closePicker()" class="grid size-10 place-items-center rounded-lg text-[var(--color-ink-soft)] hover:bg-[var(--color-field-muted)]" aria-label="{{ __('media_library.picker.close') }}">×</button>
                </div>
            @endunless

                <div class="border-b border-[var(--color-line)] px-5 pt-3" role="tablist" aria-label="{{ __('media_library.picker.choose') }}">
                    <button id="{{ $pickerId }}-library-tab" data-media-picker-library-tab x-ref="libraryTab" type="button" role="tab" aria-controls="{{ $pickerId }}-library-panel" x-on:click="activeTab = 'library'" x-on:keydown.arrow-right.prevent="moveTabFocus(1)" x-on:keydown.arrow-left.prevent="moveTabFocus(-1)" x-on:keydown.home.prevent="focusTab('library')" x-on:keydown.end.prevent="focusTab('upload')" x-bind:aria-selected="activeTab === 'library'" x-bind:tabindex="activeTab === 'library' ? 0 : -1" class="border-b-2 px-3 py-2 text-sm font-medium" x-bind:class="activeTab === 'library' ? 'border-[var(--color-accent)] text-[var(--color-accent-strong)]' : 'border-transparent text-[var(--color-ink-soft)]'">{{ __('media_library.picker.library') }}</button>
                    <button id="{{ $pickerId }}-upload-tab" data-media-picker-upload-tab x-ref="uploadTab" type="button" role="tab" aria-controls="{{ $pickerId }}-upload-panel" x-on:click="activeTab = 'upload'" x-on:keydown.arrow-right.prevent="moveTabFocus(1)" x-on:keydown.arrow-left.prevent="moveTabFocus(-1)" x-on:keydown.home.prevent="focusTab('library')" x-on:keydown.end.prevent="focusTab('upload')" x-bind:aria-selected="activeTab === 'upload'" x-bind:tabindex="activeTab === 'upload' ? 0 : -1" class="border-b-2 px-3 py-2 text-sm font-medium" x-bind:class="activeTab === 'upload' ? 'border-[var(--color-accent)] text-[var(--color-accent-strong)]' : 'border-transparent text-[var(--color-ink-soft)]'">{{ __('media_library.picker.upload_new') }}</button>
                </div>

                <div class="max-h-[56dvh] overflow-y-auto p-5">
                    <div id="{{ $pickerId }}-library-panel" aria-labelledby="{{ $pickerId }}-library-tab" x-show="activeTab === 'library'" role="tabpanel" class="space-y-4">
                        <label for="{{ $pickerId }}-search" class="sr-only">{{ __('media_library.picker.search_label') }}</label>
                        <input id="{{ $pickerId }}-search" x-ref="search" x-model="search" x-on:input.debounce.350ms="loadAssets(true)" type="search" placeholder="{{ __('media_library.picker.search_placeholder') }}" class="w-full rounded-lg border border-[var(--color-line)] bg-[var(--color-panel)] px-3 py-2 text-sm text-[var(--color-ink-strong)]" />
                        <div data-media-picker-pending-status x-show="pendingUpload" role="status" aria-live="polite" aria-atomic="true" class="rounded-xl border border-[var(--color-line)] bg-[var(--color-accent-soft)] p-4">
                            <div class="flex items-center justify-between gap-3"><p class="text-sm font-semibold text-[var(--color-ink-strong)]"><span x-show="pendingUpload?.status === 'processing'">{{ __('media_library.picker.processing') }}</span><span x-show="pendingUpload?.status === 'failed'">{{ __('media_library.picker.failed') }}</span></p><span x-show="pendingUpload?.status === 'processing'" class="text-sm tabular-nums text-[var(--color-ink-soft)]" x-text="`${pendingUpload?.progress ?? 0}%`"></span></div>
                            <div x-show="pendingUpload?.status === 'processing'" class="mt-3 h-2 overflow-hidden rounded-full bg-white/70"><div class="h-full rounded-full bg-[var(--color-accent)] transition-all" x-bind:style="`width: ${pendingUpload?.progress ?? 0}%`"></div></div>
                            <p x-show="pendingUpload?.failureReason" x-text="pendingUpload?.failureReason" class="mt-2 text-sm text-[var(--color-danger)]"></p>
                            <div x-show="pendingUpload?.status === 'failed'" class="mt-3 flex gap-2"><button data-media-picker-retry type="button" x-show="pendingUpload?.retryUrl" x-on:click="retryUpload()" class="sk-btn sk-btn-primary">{{ __('media_library.picker.retry') }}</button><button data-media-picker-remove type="button" x-show="pendingUpload?.removeUrl" x-on:click="removeUpload()" class="sk-btn border border-[var(--color-line)]">{{ __('media_library.picker.remove') }}</button></div>
                        </div>
                        <div data-media-picker-assets-error x-show="assetsError" role="alert" class="rounded-xl border border-[var(--color-danger)] p-4 text-sm text-[var(--color-danger)]"><p x-text="assetsError"></p><button type="button" x-on:click="retryAssets()" class="mt-3 font-medium underline">{{ __('media_library.picker.retry') }}</button></div>
                        <div x-show="! assetsLoading && ! assetsError && assets.length === 0" class="py-12 text-center"><p class="font-semibold text-[var(--color-ink-strong)]">{{ __('media_library.picker.empty_title') }}</p><p class="mt-2 text-sm text-[var(--color-ink-soft)]">{{ __('media_library.picker.empty_description') }}</p></div>
                        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
                            <template x-for="asset in assets" x-bind:key="asset.id">
                                <button type="button" data-media-picker-asset x-bind:data-media-picker-status="asset.status" x-bind:data-media-picker-selectable="asset.status === 'ready' ? 'true' : 'false'" x-bind:disabled="asset.status !== 'ready'" x-on:click="select(asset.id)" x-bind:aria-pressed="selected(asset.id)" x-bind:class="selected(asset.id) ? 'border-[var(--color-accent)] ring-2 ring-[var(--color-accent)]/25' : 'border-[var(--color-line)]'" class="overflow-hidden rounded-xl border text-left disabled:cursor-not-allowed disabled:opacity-65">
                                    <span x-bind:class="preserveAspectRatio ? 'aspect-[4/3]' : 'aspect-square'" class="grid place-items-center bg-[var(--color-panel-strong)]"><img x-show="asset.thumbnail_url" x-bind:src="preserveAspectRatio ? asset.master_url : asset.thumbnail_url" alt="" x-bind:class="preserveAspectRatio ? 'object-contain' : 'object-cover'" class="size-full" draggable="false" /><span x-show="! asset.thumbnail_url" class="text-xs text-[var(--color-ink-soft)]" x-text="asset.status === 'processing' ? `${asset.progress}%` : messages.processingFailed"></span></span>
                                    <span class="block truncate px-2 pt-2 text-sm font-medium" x-text="asset.display_name"></span>
                                    <span x-show="asset.display_name !== asset.original_filename" class="block truncate px-2 pb-2 text-xs text-[var(--color-ink-soft)]" x-text="asset.original_filename"></span>
                                </button>
                            </template>
                        </div>
                        <button x-show="hasMoreAssets" x-on:click="loadMoreAssets()" type="button" class="sk-btn border border-[var(--color-line)]">{{ __('media_library.picker.load_more') }}</button>
                    </div>
                    <div id="{{ $pickerId }}-upload-panel" aria-labelledby="{{ $pickerId }}-upload-tab" x-show="activeTab === 'upload'" role="tabpanel" class="space-y-4">
                        @if ($canUpload)
                            <div data-media-picker-upload-form class="space-y-4">
                                <div>
                                    <span class="mb-2 block text-sm font-medium text-[var(--color-ink-strong)]">{{ __('media_library.picker.image') }}</span>
                                    <input
                                        id="{{ $pickerId }}-upload"
                                        x-ref="uploadInput"
                                        x-on:change="selectUploadFile($event)"
                                        data-media-picker-file-input
                                        type="file"
                                        name="upload"
                                        accept=".jpg,.jpeg,.png,.webp,.heic,.heif,image/jpeg,image/png,image/webp,image/heic,image/heif"
                                        class="peer sr-only"
                                    />
                                    <div class="flex min-h-12 flex-wrap items-center gap-3 rounded-lg border border-[var(--color-line)] bg-[var(--color-field)] p-2">
                                        <label
                                            for="{{ $pickerId }}-upload"
                                            data-media-picker-file-trigger
                                            class="sk-btn cursor-pointer border border-[var(--color-accent)] bg-[var(--color-accent-soft)] text-[var(--color-accent-strong)] peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-[var(--color-accent)]"
                                        >
                                            {{ __('media_library.picker.choose_file') }}
                                        </label>
                                        <span class="min-w-0 flex-1 truncate text-sm text-[var(--color-ink-soft)]" x-text="uploadFilename || @js(__('media_library.picker.no_file_selected'))"></span>
                                    </div>
                                </div>
                                <p x-show="uploadError" x-text="uploadError" role="alert" class="text-sm text-[var(--color-danger)]"></p>
                                <div x-show="uploadSubmitting" class="space-y-2">
                                    <div class="flex items-center justify-between gap-3 text-xs text-[var(--color-ink-soft)]">
                                        <span>{{ __('media_library.uploading') }}</span>
                                        <span class="tabular-nums" x-text="`${uploadProgress}%`"></span>
                                    </div>
                                    <div
                                        data-media-picker-upload-progress-bar
                                        role="progressbar"
                                        aria-valuemin="0"
                                        aria-valuemax="100"
                                        x-bind:aria-valuenow="uploadProgress"
                                        class="h-2 w-full overflow-hidden rounded-full bg-[var(--color-field-muted)]"
                                    >
                                        <div class="h-full rounded-full bg-[var(--color-accent)] transition-[width] duration-150" x-bind:style="`width: ${uploadProgress}%`"></div>
                                    </div>
                                </div>
                                <button type="button" x-on:click="uploadNew()" x-bind:disabled="uploadSubmitting" class="sk-btn sk-btn-primary disabled:cursor-wait disabled:opacity-65">
                                    <span x-show="! uploadSubmitting">{{ __('media_library.upload') }}</span>
                                    <span x-show="uploadSubmitting">{{ __('media_library.picker.processing') }}</span>
                                </button>
                            </div>
                        @else
                            <div data-media-picker-upload-unavailable class="rounded-xl border border-[var(--color-line)] bg-[var(--color-field-muted)] p-4 text-sm text-[var(--color-ink-soft)]">{{ __('media_library.picker.upload_unavailable') }}@if ($usage['limit'] !== null) {{ __('media_library.quota.limited', ['used' => $usage['used'], 'limit' => $usage['limit']]) }}@endif</div>
                        @endif
                    </div>
                </div>

            @unless ($embedded)
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-[var(--color-line)] bg-[var(--color-field-muted)] px-5 py-4"><a href="{{ route('media.index') }}" target="_blank" rel="noopener" class="text-sm font-medium text-[var(--color-accent-strong)] underline-offset-4 hover:underline">{{ __('media_library.picker.manage') }}</a><button type="button" x-on:click="closePicker()" class="sk-btn sk-btn-primary">{{ __('media_library.picker.done') }}</button></div>
            @else
                <div class="border-t border-[var(--color-line)] bg-[var(--color-field-muted)] px-5 py-3"><a href="{{ route('media.index') }}" target="_blank" rel="noopener" class="text-sm font-medium text-[var(--color-accent-strong)] underline-offset-4 hover:underline">{{ __('media_library.picker.manage') }}</a></div>
            @endunless
        @unless ($embedded)
            </div>
        @endunless
        </div>
    </div>
</x-dynamic-component>
