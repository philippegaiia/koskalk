<div x-cloak x-show="isOrganizationDialogOpen" x-trap.inert.noscroll="isOrganizationDialogOpen" @keydown.escape.window="if (isOrganizationDialogOpen) { $event.preventDefault(); $event.stopPropagation(); closeOrganizationDialog(); }" @click.self="closeOrganizationDialog()" class="fixed inset-0 z-[80] grid place-items-center bg-black/40 p-4" role="dialog" aria-modal="true" aria-labelledby="product-organization-heading" id="product-organization-dialog">
    <section class="sk-card max-h-[calc(100dvh-2rem)] w-full max-w-xl overflow-y-auto overscroll-contain p-5 sm:p-6">
        <h3 id="product-organization-heading" class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('organization.organization') }}</h3>
        <p class="mt-1 text-sm text-[var(--color-ink-soft)]">{{ __('organization.selection_help') }}</p>
        <fieldset :disabled="!canWriteRecipe || (isSaving && !hasSavedRecipe)" class="mt-4">
            @include('livewire.dashboard.partials.recipe-workbench.product-organization')
        </fieldset>
        <div class="mt-5 flex flex-wrap items-center justify-between gap-3">
            <a href="{{ route('product-organization.index') }}" wire:navigate class="text-sm font-medium text-[var(--color-accent-strong)] hover:underline">{{ __('organization.manage') }}</a>
            <button type="button" @click="closeOrganizationDialog()" class="sk-btn sk-btn-outline">{{ __('organization.close') }}</button>
        </div>
    </section>
</div>
