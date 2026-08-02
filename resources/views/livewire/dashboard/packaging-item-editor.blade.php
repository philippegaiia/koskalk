<div class="mx-auto w-full max-w-5xl space-y-6">
 <section class="sk-card p-5 sm:p-6">
 <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
 <div class="min-w-0">
 <h3 class="text-2xl font-semibold text-[var(--color-ink-strong)]">
 {{ $packagingItem ? __('packaging.editor.edit.heading') : __('packaging.editor.create.heading') }}
 </h3>
 <p class="mt-3 max-w-3xl text-sm leading-7 text-[var(--color-ink-soft)]">
 {{ $packagingItem ? __('packaging.editor.edit.intro') : __('packaging.editor.create.intro') }}
 </p>
 </div>

</div>
 </section>

 <form wire:submit="save" class="space-y-4 pb-24">
 {{ $this->form }}

 <x-workflow-action-bar data-packaging-save-bar>
 <a href="{{ route('packaging-items.index') }}" wire:navigate class="sk-btn sk-btn-ghost">
 {{ __('packaging.actions.cancel') }}
 </a>
 <button type="submit" wire:loading.attr="disabled" wire:target="save" class="sk-btn sk-btn-primary">
 {{ $packagingItem ? __('packaging.editor.actions.save') : __('packaging.editor.actions.create') }}
 </button>
 </x-workflow-action-bar>
 </form>

 <x-filament-actions::modals />
</div>
