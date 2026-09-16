<section aria-labelledby="production-locations-heading" class="sk-card space-y-5 p-5 sm:p-6">
    <header>
        <h2 id="production-locations-heading" class="text-xl font-semibold text-[var(--color-ink-strong)]">{{ __('locations.production_locations.title') }}</h2>
        <p class="mt-1 max-w-2xl text-sm text-[var(--color-ink-soft)]">{{ __('locations.production_locations.intro') }}</p>
    </header>

    @if (! $isEditable)
        <p role="status" class="rounded-xl bg-[var(--color-warning-soft)] px-4 py-3 text-sm text-[var(--color-warning-strong)]">
            {{ $accessMessage }}
        </p>
    @endif

    <form wire:submit="save" class="space-y-4">
        <fieldset @disabled(! $isEditable)>
            {{ $this->form }}
        </fieldset>

        @error('data.location')
            <p role="alert" class="text-sm text-[var(--color-danger-strong)]">{{ $message }}</p>
        @enderror
        @error('data.production_bench')
            <p role="alert" class="text-sm text-[var(--color-danger-strong)]">{{ $message }}</p>
        @enderror

        <div class="flex flex-wrap justify-end gap-3">
            @if ($editingLocationPublicId)
                <button type="button" wire:click="cancelEdit" class="sk-btn sk-btn-ghost" @disabled(! $isEditable)>
                    {{ __('locations.cancel') }}
                </button>
            @endif
            <button type="submit" class="sk-btn sk-btn-primary" wire:loading.attr="disabled" wire:target="save" @disabled(! $isEditable)>
                {{ $editingLocationPublicId ? __('locations.production_locations.save_changes') : __('locations.production_locations.add') }}
            </button>
        </div>
    </form>

    <div class="overflow-hidden rounded-xl border border-[var(--color-line)]">
        <h3 class="border-b border-[var(--color-line)] bg-[var(--color-panel-muted)] px-4 py-3 text-sm font-semibold text-[var(--color-ink-strong)]">{{ __('locations.production_locations.list_title') }}</h3>
        <ul class="divide-y divide-[var(--color-line)]">
            @forelse ($locations as $location)
                <li wire:key="production-location-{{ $location->public_id }}" class="flex flex-col gap-3 px-4 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="font-medium text-[var(--color-ink-strong)]">{{ $location->name }}</p>
                        <p class="mt-1 text-xs text-[var(--color-ink-soft)]">
                            {{ __('locations.production_locations.daily_limit_summary', ['limit' => $location->daily_production_limit]) }}
                            · {{ $location->is_active ? __('locations.active') : __('locations.inactive') }}
                        </p>
                    </div>
                    @if ($isEditable)
                        <div class="flex items-center gap-3">
                            <button type="button" wire:click="edit('{{ $location->public_id }}')" class="text-sm font-medium text-[var(--color-accent-strong)] hover:underline">
                                {{ __('locations.edit') }}
                            </button>
                            @if ($location->is_active)
                                <button type="button" wire:click="archive('{{ $location->public_id }}')" wire:confirm="{{ __('locations.production_locations.archive_confirm') }}" class="text-sm font-medium text-[var(--color-danger-strong)] hover:underline" wire:loading.attr="disabled" wire:target="archive">
                                    {{ __('locations.archive') }}
                                </button>
                            @else
                                <button type="button" wire:click="restore('{{ $location->public_id }}')" class="text-sm font-medium text-[var(--color-accent-strong)] hover:underline" wire:loading.attr="disabled" wire:target="restore">
                                    {{ __('locations.restore') }}
                                </button>
                            @endif
                        </div>
                    @endif
                </li>
            @empty
                <li class="px-4 py-8 text-sm text-[var(--color-ink-soft)]">{{ __('locations.production_locations.none') }}</li>
            @endforelse
        </ul>
    </div>
</section>
