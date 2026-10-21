<section class="sk-card space-y-4 p-5" aria-labelledby="quick-supplier-heading">
    <h3 id="quick-supplier-heading" class="text-base font-semibold text-[var(--color-ink-strong)]">{{ __('dossiers.raw_materials.new_supplier') }}</h3>
    <form wire:submit="save" class="space-y-4">
        {{ $this->form }}
        <div class="flex justify-end">
            <button type="submit" class="sk-btn sk-btn-primary min-h-11 py-2.5">{{ __('dossiers.raw_materials.create_supplier') }}</button>
        </div>
    </form>
</section>
