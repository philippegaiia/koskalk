<x-production-bench.page active="production-setup" subnavigation="planning">
    <header>
        <p class="sk-eyebrow">{{ __('locations.planning_and_storage') }}</p>
        <h1 class="mt-2 text-3xl font-semibold text-[var(--color-ink-strong)]">{{ __('locations.title') }}</h1>
        <p class="mt-2 max-w-2xl text-sm text-[var(--color-ink-soft)]">{{ __('locations.intro') }}</p>
    </header>

    @if (! $isEditable)
        <p role="status" class="rounded-xl bg-[var(--color-warning-soft)] px-4 py-3 text-sm text-[var(--color-warning-strong)]">
            {{ $accessMessage }}
        </p>
    @endif

    <form wire:submit="save" class="space-y-6">
        <fieldset @disabled(! $isEditable)>
            {{ $this->form }}
        </fieldset>

        @error('data.production_daily_limit')
            <p class="text-sm text-[var(--color-danger-strong)]">{{ $message }}</p>
        @enderror

        <div class="flex justify-end">
            <button type="submit" class="sk-btn sk-btn-primary" @disabled(! $isEditable)>
                {{ __('locations.save') }}
            </button>
        </div>
    </form>

    @if ($usesProductionLocations)
        <livewire:production-bench.production.production-location-manager :key="'production-location-manager'" />
    @endif

    @if ($usesStorageLocations)
        <livewire:production-bench.production.storage-location-manager :key="'storage-location-manager'" />
    @endif
</x-production-bench.page>
