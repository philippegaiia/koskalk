<div x-data @organization-editor-opened.window="$nextTick(() => $refs.editor?.scrollIntoView({ block: 'center' }))" class="mx-auto max-w-app space-y-6">
    <header class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-3xl font-semibold text-[var(--color-ink-strong)]">{{ __('organization.manage') }}</h1>
            <p class="mt-2 max-w-2xl text-sm text-[var(--color-ink-soft)]">{{ __('organization.management_intro') }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('recipes.index') }}" wire:navigate class="sk-btn sk-btn-outline">{{ __('organization.back_to_products') }}</a>
            @if ($canEdit)
                <button type="button" wire:click="createBrand" class="sk-btn sk-btn-primary">{{ __('organization.create_brand') }}</button>
            @endif
        </div>
    </header>

    @if ($statusMessage)
        <p role="status" class="rounded-xl bg-[var(--color-success-soft)] px-4 py-3 text-sm text-[var(--color-ink-strong)]">{{ $statusMessage }}</p>
    @endif
    @if (! $canEdit)
        <p class="text-sm text-[var(--color-ink-soft)]">{{ __('organization.management_read_only') }}</p>
    @endif
    @if ($errors->any())
        <div role="alert" class="rounded-xl bg-[var(--color-danger-soft)] px-4 py-3 text-sm text-[var(--color-danger-strong)]">
            <ul class="list-disc space-y-1 pl-4">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    @if ($editorType !== null && $canEdit)
        <section x-ref="editor" class="sk-card space-y-5 p-5 sm:p-6">
            <h2 class="text-xl font-semibold">{{ __('organization.'.($editorType === 'brand' ? ($editingId === null ? 'create_brand' : 'edit_brand') : ($editingId === null ? 'create_collection' : 'edit_collection'))) }}</h2>
            <form wire:submit="save" class="space-y-5">
                {{ $this->form }}
                <div class="flex justify-end gap-2">
                    <button type="button" wire:click="cancelEdit" class="sk-btn sk-btn-ghost">{{ __('organization.cancel') }}</button>
                    <button type="submit" wire:loading.attr="disabled" wire:target="save" class="sk-btn sk-btn-primary">{{ __('organization.save') }}</button>
                </div>
            </form>
        </section>
    @endif

    @if ($deletionType !== null && $canEdit)
        <section x-ref="editor" class="sk-card space-y-5 border border-[var(--color-danger-strong)] p-5 sm:p-6">
            <h2 class="text-xl font-semibold">{{ __('organization.'.($deletionType === 'brand' ? 'delete_brand' : 'delete_collection')) }}: {{ $pendingDeletion?->name }}</h2>
            <p class="text-sm text-[var(--color-ink-soft)]">{{ __('organization.delete_help') }}</p>
            @if ($deletionType === 'brand')
                <p class="text-sm text-[var(--color-ink-soft)]">{{ __('organization.brand_children_help') }}</p>
            @endif
            <form wire:submit="deleteGroup" class="space-y-5">
                {{ $this->deletionForm }}
                <div class="flex justify-end gap-2">
                    <button type="button" wire:click="cancelDelete" class="sk-btn sk-btn-ghost">{{ __('organization.cancel') }}</button>
                    <button type="submit" wire:loading.attr="disabled" wire:target="deleteGroup" class="sk-btn bg-[var(--color-danger-strong)] text-white">{{ __('organization.confirm_delete') }}</button>
                </div>
            </form>
        </section>
    @endif

    @forelse ($brands as $brand)
        <section wire:key="organization-brand-{{ $brand->id }}" class="sk-card overflow-hidden">
            <header class="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--color-line)] px-5 py-4">
                <div>
                    <h2 class="text-xl font-semibold text-[var(--color-ink-strong)]">{{ $brand->name }}</h2>
                    <p class="mt-1 text-sm text-[var(--color-ink-soft)]">{{ __('organization.products_count', ['count' => $brand->recipes_count]) }}</p>
                </div>
                @if ($canEdit)
                    <div class="flex flex-wrap gap-2">
                        <button type="button" wire:click="createCollection({{ $brand->id }})" class="sk-btn sk-btn-outline">{{ __('organization.create_collection') }}</button>
                        <button type="button" wire:click="editBrand({{ $brand->id }})" aria-label="{{ __('organization.edit_brand') }}: {{ $brand->name }}" class="sk-btn sk-btn-ghost">{{ __('organization.edit') }}</button>
                        <button type="button" wire:click="confirmDelete('brand', {{ $brand->id }})" aria-label="{{ __('organization.delete_brand') }}: {{ $brand->name }}" class="sk-btn sk-btn-ghost text-[var(--color-danger-strong)]">{{ __('organization.delete') }}</button>
                    </div>
                @endif
            </header>
            <ul class="divide-y divide-[var(--color-line)]">
                @forelse ($brand->productCollections as $collection)
                    <li wire:key="organization-collection-{{ $collection->id }}" class="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                        <div>
                            <h3 class="font-medium text-[var(--color-ink-strong)]">{{ $collection->name }}</h3>
                            <p class="mt-1 text-sm text-[var(--color-ink-soft)]">{{ __('organization.products_count', ['count' => $collection->recipes_count]) }}</p>
                        </div>
                        @if ($canEdit)
                            <div class="flex gap-2">
                                <button type="button" wire:click="editCollection({{ $collection->id }})" aria-label="{{ __('organization.edit_collection') }}: {{ $collection->name }}" class="sk-btn sk-btn-ghost">{{ __('organization.edit') }}</button>
                                <button type="button" wire:click="confirmDelete('collection', {{ $collection->id }})" aria-label="{{ __('organization.delete_collection') }}: {{ $collection->name }}" class="sk-btn sk-btn-ghost text-[var(--color-danger-strong)]">{{ __('organization.delete') }}</button>
                            </div>
                        @endif
                    </li>
                @empty
                    <li class="px-5 py-5 text-sm text-[var(--color-ink-soft)]">{{ __('organization.no_collections_yet') }}</li>
                @endforelse
            </ul>
        </section>
    @empty
        <section class="sk-card p-8 text-center text-[var(--color-ink-soft)]">{{ __('organization.no_brands_yet') }}</section>
    @endforelse
</div>
