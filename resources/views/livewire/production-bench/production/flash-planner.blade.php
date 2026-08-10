<x-production-bench.page>
    <div
        x-data="{ celebrating: false, timer: null }"
        x-on:flash-productions-generated.window="celebrating = true; clearTimeout(timer); timer = setTimeout(() => celebrating = false, 5200)"
        class="space-y-6"
    >
    <div x-cloak x-show="celebrating" x-transition.opacity role="status" aria-live="polite" class="flash-celebration relative overflow-hidden rounded-2xl border border-[var(--color-accent)] bg-[var(--color-accent-soft)] px-5 py-5 shadow-sm sm:px-6">
        <div class="relative z-10 flex items-center gap-4">
            <div class="flash-celebration-orb grid size-12 shrink-0 place-items-center rounded-full bg-[var(--color-accent)] text-2xl text-white shadow-sm motion-safe:animate-pulse">✦</div>
            <div>
                <p class="font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.flash.celebration_title') }}</p>
                <p class="mt-1 text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.flash.celebration_help') }}</p>
            </div>
        </div>
        <span aria-hidden="true" class="flash-spark flash-spark-a">✦</span>
        <span aria-hidden="true" class="flash-spark flash-spark-b">•</span>
        <span aria-hidden="true" class="flash-spark flash-spark-c">✦</span>
    </div>

    @if (! $isBenchActive && ! $isReadOnly)
        <section class="sk-card p-8 text-center">
            <h1 class="text-3xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.common.inactive') }}</h1>
            <a href="{{ route('production-bench.home') }}" wire:navigate class="mt-4 inline-block text-sm font-medium text-[var(--color-accent)]">{{ __('production_bench.title') }}</a>
        </section>
    @else
        <header class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="sk-eyebrow">{{ __('production_bench.navigation.production_workflow') }}</p>
                <h1 class="mt-2 text-3xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.flash.title') }}</h1>
                <p class="mt-2 max-w-3xl text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.flash.intro') }}</p>
            </div>
            @if ($isReadOnly)
                <p role="status" class="rounded-xl bg-[var(--color-warning-soft)] px-4 py-3 text-sm text-[var(--color-warning-strong)]">{{ __('production_bench.common.read_only') }}</p>
            @endif
        </header>

        <section aria-labelledby="flash-lines-heading" class="sk-card space-y-5 p-5 sm:p-6">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h2 id="flash-lines-heading" class="text-xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.flash.products') }}</h2>
                    <p class="mt-1 text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.flash.products_help') }}</p>
                </div>
                <button type="button" wire:click="addLine" @disabled($isReadOnly) class="sk-btn sk-btn-secondary">{{ __('production_bench.flash.add_product') }}</button>
            </div>

            <div class="space-y-4">
                @foreach ($lines as $index => $line)
                    <article wire:key="flash-line-{{ $index }}" class="rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel-muted)] p-4 sm:p-5">
                        @php
                            $applicablePresets = $presets->filter(fn ($preset): bool => $preset->recipes->contains('id', (int) ($line['recipe_id'] ?? 0)));
                            $batchMode = (string) ($line['batch_mode'] ?? 'custom');
                        @endphp
                        <div class="mb-4 flex items-center justify-between gap-3">
                            <h3 class="font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.flash.product_line', ['number' => $index + 1]) }}</h3>
                            @if (count($lines) > 1)
                                <button type="button" wire:click="removeLine({{ $index }})" class="text-sm font-medium text-[var(--color-danger-strong)]">{{ __('production_bench.flash.remove_product') }}</button>
                            @endif
                        </div>

                        <div class="grid items-end gap-x-4 gap-y-5 md:grid-cols-2 xl:grid-cols-12">
                            <label class="space-y-2 md:col-span-2 xl:col-span-5">
                                <span class="text-sm font-medium">{{ __('production_bench.settings.product') }} <span aria-hidden="true" class="text-[var(--color-accent)]">*</span></span>
                                <select wire:model.live="lines.{{ $index }}.recipe_id" aria-required="true" @disabled($isReadOnly) class="sk-input w-full">
                                    <option value="">{{ __('production_bench.settings.choose_product') }}</option>
                                    @foreach ($recipes as $recipe)
                                        <option value="{{ $recipe->id }}">{{ $recipe->name }}</option>
                                    @endforeach
                                </select>
                            </label>

                            @if (filled($line['recipe_id'] ?? null) && $applicablePresets->isNotEmpty())
                                <label class="space-y-2 md:col-span-2 xl:col-span-4">
                                    <span class="text-sm font-medium">{{ __('production_bench.flash.batch_size') }} <span aria-hidden="true" class="text-[var(--color-accent)]">*</span></span>
                                    <select wire:model.live="lines.{{ $index }}.batch_mode" aria-required="true" @disabled($isReadOnly) class="sk-input w-full">
                                        @if ($applicablePresets->count() > 1 && blank($batchMode))
                                            <option value="">{{ __('production_bench.flash.choose_batch_size') }}</option>
                                        @endif
                                        @foreach ($applicablePresets as $preset)
                                            <option value="{{ $preset->id }}">{{ $preset->name }} · {{ \App\Support\NumberLocale::formatAdaptiveDecimal($preset->basis_input_value, 0, 3, auth()->user()?->number_locale) }} {{ $preset->basis_input_unit->value }} / {{ $preset->expected_units }}</option>
                                        @endforeach
                                        <option value="custom">{{ __('production_bench.flash.use_custom_quantities') }}</option>
                                    </select>
                                </label>
                            @endif

                            <label class="space-y-2 xl:col-span-3 xl:max-w-36">
                                <span class="text-sm font-medium">{{ __('production_bench.flash.desired_units') }} <span aria-hidden="true" class="text-[var(--color-accent)]">*</span></span>
                                <input wire:model.live="lines.{{ $index }}.desired_units" type="number" min="1" step="1" inputmode="numeric" aria-required="true" @disabled($isReadOnly) class="sk-input w-full font-mono" placeholder="100">
                            </label>

                            @if (filled($line['recipe_id'] ?? null) && $batchMode === 'custom')
                                <p class="md:col-span-2 xl:col-span-12 border-t border-[var(--color-line)] pt-4 text-sm text-[var(--color-ink-soft)]">
                                    {{ $applicablePresets->isEmpty() ? __('production_bench.flash.no_batch_sizes') : __('production_bench.flash.custom_quantities_help') }}
                                </p>
                                <label class="space-y-2 xl:col-span-4">
                                    <span class="text-sm font-medium">{{ __('production_bench.flash.batch_quantity') }} <span aria-hidden="true" class="text-[var(--color-accent)]">*</span></span>
                                    <div class="flex gap-2">
                                        <input wire:model.live="lines.{{ $index }}.basis_input_value" inputmode="decimal" aria-required="true" @disabled($isReadOnly) class="sk-input min-w-0 flex-1 font-mono" placeholder="12">
                                        <select wire:model.live="lines.{{ $index }}.basis_input_unit" aria-required="true" @disabled($isReadOnly) class="sk-input w-20">
                                            @foreach (\App\Enums\MassUnit::cases() as $unit)
                                                <option value="{{ $unit->value }}">{{ $unit->value }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </label>

                                <label class="space-y-2 xl:col-span-4">
                                    <span class="text-sm font-medium">{{ __('production_bench.flash.units_per_batch') }} <span aria-hidden="true" class="text-[var(--color-accent)]">*</span></span>
                                    <input wire:model.live="lines.{{ $index }}.expected_units_per_batch" type="number" min="1" step="1" inputmode="numeric" aria-required="true" @disabled($isReadOnly) class="sk-input w-full font-mono" placeholder="100">
                                </label>
                            @elseif (filled($line['recipe_id'] ?? null) && $batchMode !== '')
                                <p class="md:col-span-2 xl:col-span-12 border-t border-[var(--color-line)] pt-4 text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.flash.batch_size_fixed_help') }}</p>
                            @endif

                            <label class="space-y-2 md:col-span-2 xl:col-span-5">
                                <span class="text-sm font-medium">{{ __('production_bench.production.task_set') }} <span class="font-normal text-[var(--color-ink-muted)]">{{ __('production_bench.flash.optional') }}</span></span>
                                <select wire:model.live="lines.{{ $index }}.task_set_id" @disabled($isReadOnly) class="sk-input w-full">
                                    <option value="">{{ __('production_bench.production.no_task_set') }}</option>
                                    @foreach ($taskSets as $taskSet)
                                        @if ((int) ($line['recipe_id'] ?? 0) > 0 && $taskSet->recipes->contains('id', (int) $line['recipe_id']))
                                            <option value="{{ $taskSet->id }}">{{ $taskSet->name }}</option>
                                        @endif
                                    @endforeach
                                </select>
                            </label>
                        </div>
                    </article>
                @endforeach
            </div>
        </section>

        <section aria-labelledby="flash-date-heading" class="sk-card space-y-5 p-5 sm:p-6">
            <div>
                <h2 id="flash-date-heading" class="text-xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.flash.schedule_heading') }}</h2>
                <p class="mt-1 text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.flash.schedule_help') }}</p>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="space-y-2">
                    <span class="text-sm font-medium">{{ __('production_bench.production.production_date') }} <span aria-hidden="true" class="text-[var(--color-accent)]">*</span></span>
                    <input wire:model.live="firstDate" type="date" aria-required="true" @disabled($isReadOnly) class="sk-input w-full">
                </label>
                <label class="space-y-2">
                    <span class="text-sm font-medium">{{ __('production_bench.flash.batches_per_day') }} <span aria-hidden="true" class="text-[var(--color-accent)]">*</span></span>
                    <input wire:model.live="batchesPerDay" type="number" min="1" step="1" inputmode="numeric" aria-required="true" @disabled($isReadOnly) class="sk-input w-full font-mono">
                </label>
            </div>
            @if ($simulationError)
                <p role="alert" class="rounded-xl bg-[var(--color-warning-soft)] px-4 py-3 text-sm text-[var(--color-warning-strong)]">{{ $simulationError }}</p>
            @endif
            <div class="flex flex-wrap justify-end gap-3">
                <button type="button" wire:click="previewDates" wire:loading.attr="disabled" @disabled($isReadOnly) class="sk-btn sk-btn-primary">{{ __('production_bench.flash.generate_preview') }}</button>
            </div>
        </section>

        @if ($simulation)
            <section aria-labelledby="flash-summary-heading" class="sk-card overflow-hidden">
                @php($numberLocale = auth()->user()?->number_locale)
                <div class="border-b border-[var(--color-line)] p-5 sm:p-6">
                    <h2 id="flash-summary-heading" class="text-xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.flash.summary') }}</h2>
                    <p class="mt-1 text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.flash.summary_help') }}</p>
                </div>
                <div class="grid gap-3 border-b border-[var(--color-line)] p-5 sm:grid-cols-2 lg:grid-cols-6 sm:p-6">
                    <div><span class="block text-xs uppercase tracking-wide text-[var(--color-ink-soft)]">{{ __('production_bench.flash.desired_units') }}</span><strong class="mt-1 block font-mono text-lg">{{ \App\Support\NumberLocale::formatDecimal($simulation['totals']['desired_units'], 0, $numberLocale) }}</strong></div>
                    <div><span class="block text-xs uppercase tracking-wide text-[var(--color-ink-soft)]">{{ __('production_bench.flash.expected_units') }}</span><strong class="mt-1 block font-mono text-lg">{{ \App\Support\NumberLocale::formatDecimal($simulation['totals']['expected_units'], 0, $numberLocale) }}</strong></div>
                    <div><span class="block text-xs uppercase tracking-wide text-[var(--color-ink-soft)]">{{ __('production_bench.flash.extra_units') }}</span><strong class="mt-1 block font-mono text-lg">{{ \App\Support\NumberLocale::formatDecimal($simulation['totals']['extra_units'], 0, $numberLocale) }}</strong></div>
                    <div><span class="block text-xs uppercase tracking-wide text-[var(--color-ink-soft)]">{{ __('production_bench.flash.whole_batches') }}</span><strong class="mt-1 block font-mono text-lg">{{ \App\Support\NumberLocale::formatDecimal($simulation['totals']['whole_batches'], 0, $numberLocale) }}</strong></div>
                    <div><span class="block text-xs uppercase tracking-wide text-[var(--color-ink-soft)]">{{ __('production_bench.flash.task_minutes') }}</span><strong class="mt-1 block font-mono text-lg">{{ \App\Support\NumberLocale::formatDecimal($simulation['totals']['task_minutes'], 0, $numberLocale) }}</strong></div>
                    <div>
                        <span class="block text-xs uppercase tracking-wide text-[var(--color-ink-soft)]">{{ __('production_bench.flash.budget') }}</span>
                        <strong class="mt-1 block font-mono text-lg">
                            @if ($simulation['totals']['budget'] !== null)
                                {{ \App\Support\NumberLocale::formatAdaptiveDecimal($simulation['totals']['budget'], 2, 3, $numberLocale) }} {{ $simulation['totals']['budget_currency'] }}
                            @else
                                —
                            @endif
                        </strong>
                    </div>
                </div>
                @if ($simulation['totals']['missing_prices'] > 0 || $simulation['totals']['budget_currency'] === null)
                    <p class="border-b border-[var(--color-line)] bg-[var(--color-panel-muted)] px-5 py-3 text-sm text-[var(--color-ink-soft)] sm:px-6">
                        {{ $simulation['totals']['missing_prices'] > 0 ? __('production_bench.flash.budget_missing_prices') : __('production_bench.flash.budget_mixed_currencies') }}
                    </p>
                @endif
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[900px] text-left text-sm">
                        <thead class="bg-[var(--color-panel-muted)] text-xs uppercase tracking-wide text-[var(--color-ink-soft)]">
                            <tr>
                                <th class="px-5 py-3">{{ __('production_bench.production.material') }}</th>
                                <th class="px-4 py-3 text-right">{{ __('production_bench.flash.required') }}</th>
                                <th class="px-4 py-3 text-right">{{ __('production_bench.flash.current_price') }}</th>
                                <th class="px-5 py-3 text-right">{{ __('production_bench.flash.estimated_cost') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--color-line)]">
                            @foreach ($simulation['requirements'] as $requirement)
                                <tr>
                                    <td class="px-5 py-4 font-medium text-[var(--color-ink-strong)]">{{ $requirement['subject_name'] }}</td>
                                    <td class="px-4 py-4 text-right font-mono tabular-nums">{{ \App\Support\NumberLocale::formatAdaptiveDecimal($requirement['required_display'], 2, 3, $numberLocale) }} {{ $requirement['display_unit'] }}</td>
                                    <td class="px-4 py-4 text-right font-mono tabular-nums">
                                        @if ($requirement['display_unit_price'] !== null)
                                            {{ \App\Support\NumberLocale::formatAdaptiveDecimal($requirement['display_unit_price'], 2, 3, $numberLocale) }} {{ $requirement['price_currency'] }} / {{ $requirement['display_unit'] === 'unit' ? __('production_bench.common.unit') : $requirement['display_unit'] }}
                                        @else
                                            {{ __('production_bench.flash.price_missing') }}
                                        @endif
                                    </td>
                                    <td class="px-5 py-4 text-right font-mono tabular-nums">
                                        @if ($requirement['estimated_cost'] !== null)
                                            {{ \App\Support\NumberLocale::formatAdaptiveDecimal($requirement['estimated_cost'], 2, 3, $numberLocale) }} {{ $requirement['price_currency'] }}
                                        @else
                                            —
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif

        @if ($showDatePreview)
            <section aria-labelledby="flash-preview-heading" class="sk-card space-y-5 p-5 sm:p-6">
                <div>
                    <h2 id="flash-preview-heading" class="text-xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.flash.date_preview') }}</h2>
                    <p class="mt-1 text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.flash.date_preview_help') }}</p>
                </div>
                <div class="divide-y divide-[var(--color-line)] rounded-xl border border-[var(--color-line)]">
                    @foreach ($datePreview as $proposal)
                        <div class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                            <span class="font-medium text-[var(--color-ink-strong)]">{{ $proposal['recipe_name'] }} · {{ __('production_bench.flash.batch_label', ['number' => $proposal['batch_number'], 'total' => $proposal['batch_total']]) }}</span>
                            <span class="font-mono text-sm text-[var(--color-ink-soft)]">{{ $proposal['production_date'] }} → {{ $proposal['estimated_ready_on'] }}</span>
                        </div>
                        @foreach ($proposal['tasks'] as $task)
                            <div class="flex flex-col gap-1 bg-[var(--color-panel-muted)] px-4 py-2 pl-8 text-sm sm:flex-row sm:items-center sm:justify-between">
                                <span>{{ $task['name'] }}</span>
                                <span class="font-mono text-[var(--color-ink-soft)]">{{ $task['scheduled_for'] }}</span>
                            </div>
                        @endforeach
                    @endforeach
                </div>
                <p class="rounded-xl bg-[var(--color-panel-muted)] px-4 py-3 text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.flash.generation_next_step') }}</p>
                <div class="flex flex-wrap justify-end gap-3">
                    <button type="button" wire:click="generate" wire:loading.attr="disabled" @disabled($isReadOnly) class="sk-btn sk-btn-primary">{{ __('production_bench.flash.generate_productions') }}</button>
                </div>
            </section>
        @endif
    @endif
    </div>
</x-production-bench.page>
