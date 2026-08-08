<x-production-bench.page>
    @if (! $isBenchActive && ! $isReadOnly)
        <section class="sk-card p-8 text-center">
            <h1 class="text-3xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.common.inactive') }}</h1>
            <a href="{{ route('production-bench.home') }}" wire:navigate class="mt-4 inline-block text-sm font-medium text-[var(--color-accent)]">{{ __('production_bench.title') }}</a>
        </section>
    @else
        <header class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="sk-eyebrow">{{ __('production_bench.navigation.production_workflow') }}</p>
                <h1 class="mt-2 text-3xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.production.create_title') }}</h1>
                <p class="mt-2 max-w-2xl text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.production.create_intro') }}</p>
            </div>
            @if ($isReadOnly)
                <p role="status" class="rounded-xl bg-[var(--color-warning-soft)] px-4 py-3 text-sm text-[var(--color-warning-strong)]">{{ __('production_bench.common.read_only') }}</p>
            @endif
        </header>

        <form class="space-y-6">

            <section aria-labelledby="production-details-heading" class="sk-card space-y-5 p-5 sm:p-6">
                <div>
                    <h2 id="production-details-heading" class="text-xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.production.details') }}</h2>
                    <p class="mt-1 text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.production.details_help') }}</p>
                </div>

                <div class="grid gap-5 md:grid-cols-2">
                    <label class="space-y-2">
                        <span class="text-sm font-medium">{{ __('production_bench.settings.product') }}</span>
                        <select wire:model.live="recipeId" required @disabled($isReadOnly) class="sk-input w-full">
                            <option value="">{{ __('production_bench.settings.choose_product') }}</option>
                            @foreach ($recipes as $recipe)
                                <option value="{{ $recipe->id }}">{{ $recipe->name }}</option>
                            @endforeach
                        </select>
                        @error('recipeId') <span class="text-xs text-[var(--color-danger-strong)]">{{ $message }}</span> @enderror
                    </label>

                    <label class="space-y-2">
                        <span class="text-sm font-medium">{{ __('production_bench.production.preset') }}</span>
                        <select wire:model.live="presetId" @disabled($isReadOnly || $recipeId === '') class="sk-input w-full">
                            <option value="">{{ __('production_bench.production.no_preset') }}</option>
                            @foreach ($presets as $preset)
                                <option value="{{ $preset->id }}">{{ $preset->name }} · {{ $preset->basis_input_value }} {{ $preset->basis_input_unit->value }} / {{ $preset->expected_units }}</option>
                            @endforeach
                        </select>
                        <span class="block text-xs text-[var(--color-ink-soft)]">{{ __('production_bench.production.preset_help') }}</span>
                    </label>

                    <label class="space-y-2">
                        <span class="text-sm font-medium">{{ __('production_bench.production.basis') }}</span>
                        <div class="flex gap-2">
                            <input wire:model.live="basisInputValue" inputmode="decimal" required @disabled($isReadOnly) class="sk-input min-w-0 flex-1 font-mono" placeholder="12">
                            <select wire:model.live="basisInputUnit" @disabled($isReadOnly) class="sk-input w-24">
                                @foreach (\App\Enums\MassUnit::cases() as $unit)
                                    <option value="{{ $unit->value }}">{{ $unit->value }}</option>
                                @endforeach
                            </select>
                        </div>
                        <span class="block text-xs text-[var(--color-ink-soft)]">{{ __('production_bench.production.basis_help') }}</span>
                        @error('basisInputValue') <span class="text-xs text-[var(--color-danger-strong)]">{{ $message }}</span> @enderror
                    </label>

                    <label class="space-y-2">
                        <span class="text-sm font-medium">{{ __('production_bench.settings.expected_units') }}</span>
                        <input wire:model.live="expectedUnits" type="number" min="1" step="1" inputmode="numeric" required @disabled($isReadOnly) class="sk-input w-full font-mono" placeholder="100">
                        <span class="block text-xs text-[var(--color-ink-soft)]">{{ __('production_bench.production.expected_units_help') }}</span>
                        @error('expectedUnits') <span class="text-xs text-[var(--color-danger-strong)]">{{ $message }}</span> @enderror
                    </label>

                    <label class="space-y-2">
                        <input wire:model.live="plannedFor" type="date" @disabled($isReadOnly) class="sk-input w-full">

                        <span class="block text-xs text-[var(--color-ink-soft)]">{{ __('production_bench.production.production_date_help') }}</span>
                        @error('plannedFor') <span class="text-xs text-[var(--color-danger-strong)]">{{ $message }}</span> @enderror
                    </label>

                    <label class="space-y-2">
                        <span class="text-sm font-medium">{{ __('production_bench.production.task_set') }}</span>
                        <select wire:model.live="taskSetId" @disabled($isReadOnly) class="sk-input w-full">
                            <option value="">{{ __('production_bench.production.no_task_set') }}</option>
                            @foreach ($taskSets as $taskSet)
                                <option value="{{ $taskSet->id }}">{{ $taskSet->name }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="space-y-2 md:col-span-2">
                        <span class="text-sm font-medium">{{ __('production_bench.common.notes') }} <span class="font-normal text-[var(--color-ink-muted)]">{{ __('production_bench.production.optional') }}</span></span>
                        <textarea wire:model="notes" rows="2" @disabled($isReadOnly) class="sk-input w-full"></textarea>
                        @error('notes') <span class="text-xs text-[var(--color-danger-strong)]">{{ $message }}</span> @enderror
                    </label>
                </div>

                @if ($preview['non_working_date'])
                    <p role="status" class="rounded-xl bg-[var(--color-warning-soft)] px-4 py-3 text-sm text-[var(--color-warning-strong)]">{{ __('production_bench.production.non_working_date') }}</p>
                @endif
            </section>

            <section aria-labelledby="requirements-heading" class="sk-card overflow-hidden">
                <div class="border-b border-[var(--color-line)] p-5 sm:p-6">
                    <h2 id="requirements-heading" class="text-xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.production.requirements_preview') }}</h2>
                    <p class="mt-1 text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.production.requirements_help') }}</p>
                </div>
                @if ($preview['requirements'])
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[760px] text-left text-sm">
                            <thead class="bg-[var(--color-panel-muted)] text-xs uppercase tracking-wide text-[var(--color-ink-soft)]">
                                <tr>
                                    <th class="px-5 py-3">{{ __('production_bench.production.material') }}</th>
                                    <th class="px-4 py-3">{{ __('production_bench.production.percentage') }}</th>
                                    <th class="px-4 py-3 text-right">{{ __('production_bench.production.required') }}</th>
                                    <th class="px-4 py-3 text-right">{{ __('production_bench.production.available') }}</th>
                                    <th class="px-4 py-3 text-right">{{ __('production_bench.production.incoming') }}</th>
                                    <th class="px-5 py-3 text-right">{{ __('production_bench.production.shortage') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[var(--color-line)]">
                                @foreach ($preview['requirements'] as $requirement)
                                    <tr>
                                        <td class="px-5 py-4 font-medium text-[var(--color-ink-strong)]">{{ $requirement['subject_name'] }}</td>
                                        <td class="px-4 py-4 font-mono tabular-nums">{{ $requirement['percentage'] ? $requirement['percentage'].'%' : '—' }}</td>
                                        <td class="px-4 py-4 text-right font-mono tabular-nums">{{ $requirement['required'] }} {{ $requirement['unit'] }}</td>
                                        <td class="px-4 py-4 text-right font-mono tabular-nums">{{ $requirement['available'] }} {{ $requirement['unit'] }}</td>
                                        <td class="px-4 py-4 text-right font-mono tabular-nums">{{ $requirement['incoming'] }} {{ $requirement['unit'] }}</td>
                                        <td @class([
                                            'px-5 py-4 text-right font-mono tabular-nums',
                                            'text-[var(--color-danger-strong)] font-semibold' => $requirement['shortage'] !== '0.00' && $requirement['shortage'] !== '0',
                                        ])>{{ $requirement['shortage'] }} {{ $requirement['unit'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="p-8 text-center text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.production.choose_product_to_preview') }}</p>
                @endif
            </section>

            <section aria-labelledby="task-preview-heading" class="sk-card overflow-hidden">
                <div class="border-b border-[var(--color-line)] p-5 sm:p-6">
                    <h2 id="task-preview-heading" class="text-xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.production.task_schedule') }}</h2>
                    <p class="mt-1 text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.production.task_schedule_help') }}</p>
                </div>
                @if ($preview['tasks'])
                    <div class="divide-y divide-[var(--color-line)]">
                        @foreach ($preview['tasks'] as $task)
                            <div class="flex flex-col gap-1 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                                <span class="font-medium text-[var(--color-ink-strong)]">{{ $task['name'] }}</span>
                                <span class="font-mono text-sm tabular-nums text-[var(--color-ink-soft)]">{{ $task['scheduled_for'] }} @if($task['duration_minutes'] !== null) · {{ $task['duration_minutes'] }} {{ __('production_bench.settings.duration_minutes') }} @endif</span>
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="p-8 text-center text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.production.no_tasks') }}</p>
                @endif
            </section>

            <div class="flex flex-wrap items-center justify-end gap-3">
                <a href="{{ route('production-bench.production.settings.presets') }}" wire:navigate class="sk-btn sk-btn-ghost">{{ __('production_bench.production.setup_link') }}</a>
                <button type="button" wire:click="saveDraft" wire:loading.attr="disabled" @disabled($isReadOnly) class="sk-btn sk-btn-ghost">{{ __('production_bench.production.save_as_draft') }}</button>
                <button type="button" wire:click="plan" wire:loading.attr="disabled" @disabled($isReadOnly) class="sk-btn sk-btn-primary">{{ __('production_bench.production.schedule') }}</button>
            </div>

        </form>
    @endif
</x-production-bench.page>
