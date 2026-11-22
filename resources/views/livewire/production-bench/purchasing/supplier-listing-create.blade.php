<x-production-bench.page active="purchasing" :subnavigation="$navigationSection">
    <script type="application/json" data-contextual-help-scope>{!! \Illuminate\Support\Js::encode($contextualHelp) !!}</script>
    <header class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0 flex-1">
            @if ($lockedSupplier)
                <p class="sk-eyebrow">{{ $lockedSupplier->code }} · {{ $lockedSupplier->name }}</p>
            @endif
            <div data-contextual-help-heading class="flex flex-wrap items-center gap-x-3 gap-y-1">
                <h1 @class(['mt-2' => $lockedSupplier, 'text-3xl font-semibold text-[var(--color-ink-strong)]'])>{{ $editingListingPublicId ? __('production_bench.listing.edit') : __('production_bench.listing.new') }}</h1>
                <x-contextual-help.index-button :help="$contextualHelp" tab="purchasing" />
            </div>
        </div>

    </header>

    <form wire:submit="save" class="space-y-4 pb-24">
        {{ $this->form }}

        @if ($documents->isNotEmpty())
            <section class="sk-card space-y-3 p-5" aria-labelledby="listing-documents-heading">
                <h2 id="listing-documents-heading" class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.receipt.documents') }}</h2>
                <ul class="divide-y divide-[var(--color-line)]">
                    @foreach ($documents as $document)
                        @php($asset = $document->mediaAsset)
                        @php($isPdf = $asset->type === \App\Enums\MediaAssetType::Pdf)
                        <li wire:key="listing-document-{{ $document->id }}" class="flex flex-wrap items-start justify-between gap-3 py-3 text-sm">
                            <div class="min-w-0">
                                <a href="{{ $isPdf ? route('media.download', [$asset, 'inline' => 1]) : route('media.show', [$asset, 'master']) }}" target="_blank" rel="noopener noreferrer" aria-label="{{ __($isPdf ? 'media_library.open_pdf_for' : 'media_library.open_image_for', ['name' => $asset->original_filename]) }}" class="break-all font-medium text-[var(--color-accent-strong)] hover:underline">
                                    {{ $asset->original_filename }}
                                    <span aria-hidden="true">↗</span>
                                </a>
                                <p class="mt-1 text-xs text-[var(--color-ink-soft)]">{{ __('production_bench.receipt.document_types.'.$document->type->value) }} · {{ $document->created_at->toDateString() }}</p>
                                @if ($document->note)
                                    <p class="mt-1 text-[var(--color-ink-soft)]">{{ $document->note }}</p>
                                @endif
                            </div>
                            @if ($canDelete)
                                <button type="button" wire:click="detachDocument({{ $document->id }})" wire:confirm="{{ __('production_bench.listing.document_detach_confirm') }}" wire:loading.attr="disabled" wire:target="detachDocument" class="text-xs font-medium text-[var(--color-danger-strong)] hover:underline">{{ __('production_bench.receipt.document_detach') }}</button>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @error('data.production_bench') <p class="text-sm text-[var(--color-danger-strong)]">{{ $message }}</p> @enderror

        <x-workflow-action-bar data-production-bench-save-bar>
            @if ($editingListingPublicId)
                <x-slot:leading>
                    @if ($canDelete)<button type="button" wire:click="delete" wire:confirm="{{ __('production_bench.listing.delete_confirm') }}" class="sk-btn sk-btn-danger" wire:loading.attr="disabled" wire:target="delete">
                        {{ __('production_bench.listing.delete') }}
                    </button>@endif
                </x-slot:leading>
            @endif

            <a href="{{ $lockedSupplier ? route('production-bench.purchasing.supplier', $lockedSupplier) : route('production-bench.purchasing.listings') }}" wire:navigate class="sk-btn sk-btn-ghost">
                {{ __('production_bench.common.cancel') }}
            </a>
            <button type="submit" class="sk-btn sk-btn-primary" wire:loading.attr="disabled">
                {{ $editingListingPublicId ? __('production_bench.listing.save_changes') : __('production_bench.listing.save') }}
            </button>
        </x-workflow-action-bar>
    </form>
</x-production-bench.page>
