<x-production-bench.page active="production-setup" subnavigation="presets">
    @if (! $isBenchActive && ! $isReadOnly)
        <section class="sk-card p-8 text-center">
            <h1 class="text-3xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.common.inactive') }}</h1>
        </section>
    @else
        <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="sk-eyebrow">{{ __('production_bench.navigation.settings') }}</p>
                <h1 id="preset-heading" class="mt-2 text-3xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.settings.presets') }}</h1>
                <p class="mt-2 max-w-2xl text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.settings.presets_help') }}</p>
            </div>
            @if ($isBenchActive && ! $isReadOnly)
                <a href="{{ route('production-bench.production.settings.presets.create') }}" wire:navigate class="sk-btn sk-btn-primary">{{ __('production_bench.settings.new_batch_size') }}</a>
            @endif
        </header>

        @if ($isReadOnly)
            <p role="status" class="rounded-xl bg-[var(--color-warning-soft)] px-4 py-3 text-sm text-[var(--color-warning-strong)]">{{ __('production_bench.common.read_only') }}</p>
        @endif

        <section class="overflow-hidden sk-card">
            <div class="grid gap-3 border-b border-[var(--color-line)] bg-[var(--color-panel-muted)] p-4 sm:grid-cols-[minmax(0,1fr)_12rem]">
                <label class="text-sm">
                    <span class="font-medium">{{ __('production_bench.common.search') }}</span>
                    <input wire:model.live.debounce.300ms="search" type="search" class="sk-input mt-1 w-full" placeholder="{{ __('production_bench.settings.search_batch_sizes') }}">
                </label>
                <label class="text-sm">
                    <span class="font-medium">{{ __('production_bench.common.status') }}</span>
                    <select wire:model.live="status" class="sk-input mt-1 w-full">
                        <option value="active">{{ __('production_bench.common.active') }}</option>
                        <option value="all">{{ __('production_bench.common.all') }}</option>
                        <option value="inactive">{{ __('production_bench.common.inactive') }}</option>
                    </select>
                </label>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[760px] text-left text-sm">
                    <thead class="bg-[var(--color-panel-muted)] text-xs uppercase tracking-wide text-[var(--color-ink-soft)]">
                        <tr>
                            <th class="px-5 py-3">{{ __('production_bench.settings.preset_name') }}</th>
                            <th class="px-4 py-3">{{ __('production_bench.settings.batch_size') }}</th>
                            <th class="px-4 py-3">{{ __('production_bench.settings.expected_units') }}</th>
                            <th class="px-4 py-3">{{ __('production_bench.settings.applicable_products') }}</th>
                            <th class="px-4 py-3">{{ __('production_bench.common.status') }}</th>
                            <th class="px-5 py-3 text-right">{{ __('production_bench.common.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--color-line)]">
                        @forelse ($presets as $preset)
                            <tr wire:key="batch-size-{{ $preset->id }}" class="align-top transition hover:bg-[var(--color-panel-strong)]">
                                <td class="px-5 py-4 font-medium text-[var(--color-ink-strong)]">{{ $preset->name }}</td>
                                <td class="numeric px-4 py-4">{{ \App\Support\NumberLocale::formatAdaptiveDecimal($preset->basis_input_value, 0, 3, auth()->user()?->number_locale) }} {{ $preset->basis_input_unit->value }}</td>
                                <td class="numeric px-4 py-4">{{ $preset->expected_units }}</td>
                                <td class="px-4 py-4">
                                    @if ($preset->recipes_count > 0)
                                        <details class="max-w-xs">
                                            <summary class="cursor-pointer font-medium text-[var(--color-accent-strong)]">{{ __('production_bench.settings.view_products') }} · {{ trans_choice('production_bench.settings.products_selected', $preset->recipes_count, ['count' => $preset->recipes_count]) }}</summary>
                                            <ul class="mt-2 max-h-40 space-y-1 overflow-y-auto rounded-lg bg-[var(--color-panel-muted)] p-3 text-xs text-[var(--color-ink-soft)]">
                                                @foreach ($preset->recipes as $recipe)
                                                    <li>{{ $recipe->name }}@if ($recipe->pivot->is_default) <span class="text-[var(--color-accent-strong)]">· {{ __('production_bench.settings.default') }}</span>@endif</li>
                                                @endforeach
                                            </ul>
                                        </details>
                                    @else
                                        <span class="text-[var(--color-ink-soft)]">{{ __('production_bench.settings.no_applicable_products') }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-4"><span class="rounded-full px-2.5 py-1 text-xs font-medium {{ $preset->is_active ? 'bg-[var(--color-success-soft)] text-[var(--color-success-strong)]' : 'bg-[var(--color-field-muted)] text-[var(--color-ink-soft)]' }}">{{ $preset->is_active ? __('production_bench.common.active') : __('production_bench.common.inactive') }}</span></td>
                                <td class="px-5 py-4 text-right">
                                    <span class="inline-flex items-center gap-3">
                                        @if ($isBenchActive && ! $isReadOnly)
                                            <a href="{{ route('production-bench.production.settings.presets.edit', $preset) }}" wire:navigate class="font-medium text-[var(--color-accent-strong)] hover:underline">{{ __('production_bench.common.edit') }}</a>
                                            <button type="button" wire:click="delete({{ $preset->id }})" wire:confirm="{{ __('production_bench.settings.delete_preset_confirm') }}" class="font-medium text-[var(--color-danger-strong)] hover:underline">{{ __('production_bench.common.delete') }}</button>
                                        @else
                                            —
                                        @endif
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-6 py-12 text-center text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.settings.no_presets') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <x-table-pagination :paginator="$presets" />
        </section>
    @endif
</x-production-bench.page>
