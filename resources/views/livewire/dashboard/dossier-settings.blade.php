<section class="sk-card space-y-5 p-5" aria-labelledby="dossier-settings-heading">
    <div>
        <h3 id="dossier-settings-heading" class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('dossiers.settings.heading') }}</h3>
        <p class="mt-1 text-sm leading-6 text-[var(--color-ink-soft)]">{{ __('dossiers.settings.description') }}</p>
    </div>

    @if ($status)
        <p role="status" class="rounded-lg bg-[var(--color-success-soft)] px-4 py-3 text-sm text-[var(--color-success-strong)]">{{ $status }}</p>
    @endif

    <form wire:submit="save" class="space-y-5">
        <fieldset class="sk-inset space-y-3 p-4">
            <legend class="sk-eyebrow">{{ __('dossiers.settings.responsible_person') }}</legend>
            @foreach ([
                'responsible_person_name' => 'text',
                'responsible_person_address' => 'textarea',
                'responsible_person_siret' => 'text',
            ] as $field => $type)
                <label class="block">
                    <span class="text-sm font-medium text-[var(--color-ink-strong)]">{{ __('dossiers.settings.'.$field) }}</span>
                    @if ($type === 'textarea')
                        <textarea wire:model="{{ $field }}" rows="3" aria-label="{{ __('dossiers.settings.'.$field) }}" class="mt-2 w-full rounded-lg bg-[var(--color-field)] px-3 py-2.5 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] focus:outline-2 focus:outline-[var(--color-accent)]"></textarea>
                    @else
                        <input wire:model="{{ $field }}" type="text" aria-label="{{ __('dossiers.settings.'.$field) }}" class="mt-2 w-full rounded-lg bg-[var(--color-field)] px-3 py-2.5 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] focus:outline-2 focus:outline-[var(--color-accent)]">
                    @endif
                    @error($field)
                        <p role="alert" class="mt-1 text-xs text-[var(--color-danger-strong)]">{{ $message }}</p>
                    @enderror
                </label>
            @endforeach
        </fieldset>

        @foreach (['manufacturer', 'packer'] as $site)
            <fieldset class="sk-inset space-y-3 p-4">
                <legend class="sk-eyebrow">{{ __('dossiers.settings.'.$site) }}</legend>
                <p class="text-xs leading-5 text-[var(--color-ink-soft)]">{{ __('dossiers.settings.site_help') }}</p>
                <label class="block">
                    <span class="text-sm font-medium text-[var(--color-ink-strong)]">{{ __('dossiers.settings.site_name') }}</span>
                    <input wire:model="{{ $site }}_name" type="text" aria-label="{{ __('dossiers.settings.'.$site) }} — {{ __('dossiers.settings.site_name') }}" class="mt-2 w-full rounded-lg bg-[var(--color-field)] px-3 py-2.5 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] focus:outline-2 focus:outline-[var(--color-accent)]">
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-[var(--color-ink-strong)]">{{ __('dossiers.settings.site_address') }}</span>
                    <textarea wire:model="{{ $site }}_address" rows="3" aria-label="{{ __('dossiers.settings.'.$site) }} — {{ __('dossiers.settings.site_address') }}" class="mt-2 w-full rounded-lg bg-[var(--color-field)] px-3 py-2.5 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] focus:outline-2 focus:outline-[var(--color-accent)]"></textarea>
                </label>
            </fieldset>
        @endforeach

        <fieldset class="sk-inset space-y-3 p-4">
            <legend class="sk-eyebrow">{{ __('dossiers.settings.numbering_prefix') }}</legend>
            <input wire:model="numbering_prefix" type="text" aria-label="{{ __('dossiers.settings.numbering_prefix') }}" class="w-40 rounded-lg bg-[var(--color-field)] px-3 py-2.5 font-mono text-sm uppercase text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] focus:outline-2 focus:outline-[var(--color-accent)]">
            <p class="text-xs leading-5 text-[var(--color-ink-soft)]">{{ __('dossiers.settings.numbering_help') }}</p>
            @error('numbering_prefix')
                <p role="alert" class="text-xs text-[var(--color-danger-strong)]">{{ $message }}</p>
            @enderror
        </fieldset>

        <fieldset class="sk-inset space-y-3 p-4">
            <legend class="sk-eyebrow">{{ __('dossiers.settings.assessor') }}</legend>
            @foreach (['assessor_name' => 'text', 'assessor_address' => 'textarea', 'assessor_qualification' => 'textarea'] as $field => $type)
                <label class="block">
                    <span class="text-sm font-medium text-[var(--color-ink-strong)]">{{ __('dossiers.settings.'.$field) }}</span>
                    @if ($type === 'textarea')
                        <textarea wire:model="{{ $field }}" rows="3" aria-label="{{ __('dossiers.settings.'.$field) }}" class="mt-2 w-full rounded-lg bg-[var(--color-field)] px-3 py-2.5 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] focus:outline-2 focus:outline-[var(--color-accent)]"></textarea>
                    @else
                        <input wire:model="{{ $field }}" type="text" aria-label="{{ __('dossiers.settings.'.$field) }}" class="mt-2 w-full rounded-lg bg-[var(--color-field)] px-3 py-2.5 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] focus:outline-2 focus:outline-[var(--color-accent)]">
                    @endif
                </label>
            @endforeach
        </fieldset>

        <div class="flex justify-end">
            <button type="submit" class="sk-btn sk-btn-primary min-h-11 py-2.5">{{ __('dossiers.settings.save') }}</button>
        </div>
    </form>
</section>
