<x-production-bench.page compact>
    @if (! $isBenchActive && ! $isReadOnly)
        <section class="sk-card p-8 text-center">
            <h1 class="text-3xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.common.inactive') }}</h1>
            <a href="{{ route('production-bench.home') }}" wire:navigate class="mt-4 inline-block text-sm font-medium text-[var(--color-accent)]">{{ __('production_bench.title') }}</a>
        </section>
    @endif

    @if ($isBenchActive || $isReadOnly)
        @php $mutationLocked = $isReadOnly || ! $canMutate; @endphp
        @if ($isReadOnly)
            <p role="status" class="rounded-xl bg-[var(--color-warning-soft)] px-4 py-3 text-sm text-[var(--color-warning-strong)]">{{ __('production_bench.common.read_only') }}</p>
        @endif

    <header class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <a href="{{ route('production-bench.production.index') }}" wire:navigate class="text-sm font-medium text-[var(--color-accent-strong)] hover:underline">← {{ __('production_bench.production.back_to_list') }}</a>
            <div class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-1">
                <p class="sk-eyebrow">{{ $production->displayIdentifier() }}</p>
            </div>
            <h1 class="mt-2 text-3xl font-semibold text-[var(--color-ink-strong)]">{{ $production->displayRecipeName() }}</h1>
            <p class="mt-2 text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.production.detail_title') }}</p>
        </div>
        <span class="rounded-full bg-[var(--color-accent-soft)] px-3 py-1.5 text-sm font-medium text-[var(--color-accent-strong)]">{{ $production->status->label() }}</span>
    </header>

    <section class="sk-card grid gap-5 p-5 sm:grid-cols-2 lg:grid-cols-4" aria-labelledby="batch-identity-heading">
        <h2 id="batch-identity-heading" class="sr-only">{{ __('production_bench.production.batch_identity') }}</h2>
        <div>
            <p class="text-xs uppercase tracking-wide text-[var(--color-ink-muted)]">{{ __('production_bench.production.batch_number') }}</p>
            <p class="mt-1 font-mono tabular-nums text-[var(--color-ink-strong)]">{{ $production->batch_number ?? '—' }}</p>
        </div>
        <div>
            <p class="text-xs uppercase tracking-wide text-[var(--color-ink-muted)]">{{ __('production_bench.production.planning_reference') }}</p>
            <p class="mt-1 font-mono tabular-nums text-[var(--color-ink-strong)]">{{ $production->planning_batch_number }}</p>
        </div>
        @if ($production->batch_number_assigned_at)
            <div>
                <p class="text-xs uppercase tracking-wide text-[var(--color-ink-muted)]">{{ __('production_bench.production.batch_number_assigned_at') }}</p>
                <p class="mt-1 font-mono tabular-nums text-[var(--color-ink-strong)]">{{ $production->batch_number_assigned_at->format('Y-m-d H:i') }}</p>
            </div>
            <div>
                <p class="text-xs uppercase tracking-wide text-[var(--color-ink-muted)]">{{ __('production_bench.production.batch_number_assigned_by') }}</p>
                <p class="mt-1 text-[var(--color-ink-strong)]">{{ $production->batchNumberAssignedBy?->name ?? '—' }}</p>
            </div>
        @endif
    </section>

    @if ($canMutate && $production->batch_number === null && in_array($production->status->value, ['scheduled', 'reserved'], true) && $production->planned_for)
        <section class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-[var(--color-accent)] bg-[var(--color-accent-soft)] p-4" aria-labelledby="assign-batch-heading">
            <div>
                <h2 id="assign-batch-heading" class="font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.production.assign_batch_number') }}</h2>
                <p class="mt-1 text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.production.assign_batch_number_help') }}</p>
            </div>
            <button type="button" wire:click="assignBatchNumber" wire:confirm="{{ __('production_bench.production.assign_batch_number_confirm') }}" wire:loading.attr="disabled" wire:target="assignBatchNumber" class="sk-btn sk-btn-primary">{{ __('production_bench.production.assign_batch_number') }}</button>
        </section>
    @endif

    @error('production_bench')
        <p role="alert" class="rounded-xl bg-[var(--color-danger-soft)] px-4 py-3 text-sm text-[var(--color-danger-strong)]">{{ $message }}</p>
    @enderror

    @if (in_array($production->status->value, ['scheduled', 'reserved'], true))
        <section class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-[var(--color-accent)] bg-[var(--color-accent-soft)] p-4">
            <div>
                <p class="font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.production.prepare_stock') }}</p>
                <p class="mt-1 text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.production.prepare_stock_help_short') }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @if ($production->status->value === 'reserved')
                    <button type="button" wire:click="releaseStock" wire:loading.attr="disabled" @disabled($mutationLocked) class="sk-btn sk-btn-ghost">{{ __('production_bench.production.release_stock') }}</button>
                @endif
                <a href="{{ route('production-bench.production.prepare', $production) }}" wire:navigate class="sk-btn sk-btn-primary">{{ __('production_bench.production.prepare_stock') }}</a>
            </div>
        </section>
        @error('production')
            <p role="alert" class="rounded-xl bg-[var(--color-danger-soft)] px-4 py-3 text-sm text-[var(--color-danger-strong)]">{{ $message }}</p>
        @enderror
    @endif

    @if ($production->status->value === 'reserved' && $production->batch_number !== null)
        <section class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-[var(--color-accent)] bg-[var(--color-accent-soft)] p-4">
            <div>
                <p class="font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.production.start') }}</p>
                <p class="mt-1 text-sm text-[var(--color-ink-soft)]">{{ $production->batch_number }}</p>
            </div>
            <button type="button" wire:click="start" wire:confirm="{{ __('production_bench.production.start_confirm') }}" wire:loading.attr="disabled" @disabled($mutationLocked) class="sk-btn sk-btn-primary">{{ __('production_bench.production.start') }}</button>
        </section>
        @error('production')
            <p role="alert" class="rounded-xl bg-[var(--color-danger-soft)] px-4 py-3 text-sm text-[var(--color-danger-strong)]">{{ $message }}</p>
        @enderror
    @endif

    <section class="sk-card grid gap-5 p-5 sm:grid-cols-2 lg:grid-cols-4">
        <div><p class="text-xs uppercase tracking-wide text-[var(--color-ink-muted)]">{{ __('production_bench.production.production_date') }}</p><p class="mt-1 font-mono tabular-nums text-[var(--color-ink-strong)]">{{ $production->planned_for?->format('Y-m-d') ?? '—' }}</p></div>
        <div><p class="text-xs uppercase tracking-wide text-[var(--color-ink-muted)]">{{ __('production_bench.settings.batch_size') }}</p><p class="mt-1 font-mono tabular-nums text-[var(--color-ink-strong)]">{{ \App\Support\NumberLocale::formatAdaptiveDecimal($production->basis_input_value, 0, 3, auth()->user()?->number_locale) }} {{ $production->basis_input_unit->value }}</p></div>
        <div><p class="text-xs uppercase tracking-wide text-[var(--color-ink-muted)]">{{ __('production_bench.settings.expected_units') }}</p><p class="mt-1 font-mono tabular-nums text-[var(--color-ink-strong)]">{{ \App\Support\NumberLocale::formatDecimal($production->expected_units, 0, auth()->user()?->number_locale) }}</p></div>
        <div><p class="text-xs uppercase tracking-wide text-[var(--color-ink-muted)]">{{ __('production_bench.production.formula.source_version') }}</p><p class="mt-1 font-mono tabular-nums text-[var(--color-ink-strong)]">{{ $production->source_formula_version_number ?? '—' }}</p></div>
    </section>

    @if ($production->notes)
        <p class="sk-card p-5 text-sm text-[var(--color-ink-soft)]">{{ $production->notes }}</p>
    @endif

    @if ($production->formulaLines->isNotEmpty())
        <section aria-labelledby="formula-detail-heading" class="sk-card overflow-hidden">
            <div class="border-b border-[var(--color-line)] p-5 sm:p-6"><h2 id="formula-detail-heading" class="text-xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.production.formula.title') }}</h2></div>
            @foreach ($production->formulaLines->groupBy('phase_key_snapshot') as $lines)
                <div class="border-t border-[var(--color-line)] px-5 py-3 sm:px-6"><p class="text-xs uppercase tracking-wide text-[var(--color-ink-muted)]">{{ $lines->first()->phase_name_snapshot }}</p></div>
                <div class="divide-y divide-[var(--color-line)]">
                    @foreach ($lines as $line)
                        <div class="flex flex-col gap-1 px-5 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                            <div>
                                <p class="font-medium text-[var(--color-ink-strong)]">{{ $line->subject_name_snapshot }}</p>
                                <p class="text-xs text-[var(--color-ink-soft)]">{{ \App\Support\NumberLocale::formatAdaptiveDecimal($line->basis_percentage_snapshot, 0, 3, auth()->user()?->number_locale) }}%{{ $line->note_snapshot ? ' · '.$line->note_snapshot : '' }}</p>
                            </div>
                            <p class="font-mono tabular-nums text-[var(--color-ink-strong)]">{{ \App\Support\NumberLocale::formatAdaptiveDecimal($line->planned_mass_grams, 0, 3, auth()->user()?->number_locale) }} g</p>
                        </div>
                    @endforeach
                </div>
            @endforeach
        </section>
    @endif

    <section aria-labelledby="requirements-detail-heading" class="sk-card overflow-hidden">
        <div class="border-b border-[var(--color-line)] p-5 sm:p-6"><h2 id="requirements-detail-heading" class="text-xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.production.requirements') }}</h2></div>
        <div class="divide-y divide-[var(--color-line)]">
            @forelse ($production->requirements as $requirement)
                @php
                    $reservedCoverage = '0';
                    foreach ($requirement->reservations->where('status', \App\StockReservationStatus::Active) as $reservation) {
                        $reservedCoverage = bcadd($reservedCoverage, (string) $reservation->quantity, 9);
                    }
                    $requiredCoverage = $requirement->ingredient_id !== null
                        ? (string) $requirement->required_mass_grams
                        : (string) $requirement->required_units;
                    $coverageShort = bccomp($reservedCoverage, $requiredCoverage, 9) < 0;
                    $shortAmount = $coverageShort ? bcsub($requiredCoverage, $reservedCoverage, 9) : null;
                    $numberLocale = auth()->user()?->number_locale;
                    $reservedCoverageDisplay = \App\Support\NumberLocale::formatAdaptiveDecimal($reservedCoverage, 0, 3, $numberLocale);
                    $requiredCoverageDisplay = \App\Support\NumberLocale::formatAdaptiveDecimal($requiredCoverage, 0, 3, $numberLocale);
                    $shortAmountDisplay = $shortAmount !== null ? \App\Support\NumberLocale::formatAdaptiveDecimal($shortAmount, 0, 3, $numberLocale) : null;
                @endphp
                <div class="flex flex-col gap-2 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                    <div>
                        <p class="font-medium text-[var(--color-ink-strong)]">{{ $requirement->subject_name_snapshot }}</p>
                        <p class="text-xs text-[var(--color-ink-soft)]">
                            @if ($coverageShort)
                                {{ __('production_bench.production.coverage_short', ['reserved' => $reservedCoverageDisplay, 'required' => $requiredCoverageDisplay, 'short' => $shortAmountDisplay]) }}
                            @else
                                {{ __('production_bench.production.coverage_reserved', ['reserved' => $reservedCoverageDisplay, 'required' => $requiredCoverageDisplay]) }}
                            @endif
                        </p>
                    </div>
                    <p class="font-mono tabular-nums text-[var(--color-ink-strong)]">{{ $requirement->kind->value === 'ingredient' ? \App\Support\NumberLocale::formatAdaptiveDecimal($requirement->required_mass_grams, 0, 3, auth()->user()?->number_locale).' g' : \App\Support\NumberLocale::formatDecimal($requirement->required_units, 0, auth()->user()?->number_locale).' '.__('production_bench.inventory.units') }}</p>
                </div>
            @empty
                <p class="p-8 text-center text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.production.no_requirements') }}</p>
            @endforelse
        </div>
    </section>

    @if ($production->status->value === 'in_production')
        <section aria-labelledby="actuals-detail-heading" class="sk-card overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-[var(--color-line)] p-5 sm:p-6">
                <h2 id="actuals-detail-heading" class="text-xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.production.actuals_title') }}</h2>
                <span class="text-xs text-[var(--color-ink-soft)]">{{ __('production_bench.production.actuals_not_posted') }}</span>
            </div>
            @error('actuals')
                <p role="alert" class="border-b border-[var(--color-line)] px-5 py-3 text-sm text-[var(--color-danger-strong)] sm:px-6">{{ $message }}</p>
            @enderror
            <div class="divide-y divide-[var(--color-line)]">
                @forelse ($production->requirements as $requirement)
                    @php
                        $requirementId = (string) $requirement->id;
                        $actualRowsForRequirement = $actualRowsByRequirement[$requirementId] ?? [];
                    @endphp
                    <div>
                        <div class="px-5 pt-4 sm:px-6">
                            <p class="font-medium text-[var(--color-ink-strong)]">{{ $requirement->subject_name_snapshot }}</p>
                            <p class="text-xs text-[var(--color-ink-soft)]">{{ $requirement->kind->value === 'ingredient' ? \App\Support\NumberLocale::formatAdaptiveDecimal($requirement->required_mass_grams, 0, 3, auth()->user()?->number_locale).' g' : \App\Support\NumberLocale::formatDecimal($requirement->required_units, 0, auth()->user()?->number_locale).' '.__('production_bench.inventory.units') }}</p>
                        </div>
                        @foreach ($actualRowsForRequirement as $suffix => $actualRow)
                            @php
                                $actualKey = $requirementId.'-'.($actualRow['stock_lot_id'] ?? '');
                            @endphp
                            <div class="flex flex-col gap-2 px-5 py-2 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                                <p class="font-mono text-xs text-[var(--color-ink-soft)]">{{ $actualRow['lot_code'] ?? '—' }}</p>
                                <div class="flex flex-wrap items-center gap-2">
                                    <input type="number" inputmode="decimal" min="0" step="any" wire:model.live.debounce.500ms="actualRows.{{ $actualKey }}.quantity" aria-label="{{ __('production_bench.production.actuals_quantity', ['name' => $requirement->subject_name_snapshot]) }}" @disabled($mutationLocked) class="sk-input w-36 text-right font-mono">
                                    <input type="text" wire:model.live.debounce.500ms="actualRows.{{ $actualKey }}.note" placeholder="{{ __('production_bench.production.actuals_note_placeholder') }}" @disabled($mutationLocked) class="sk-input w-52 text-sm">
                                </div>
                            </div>
                        @endforeach
                    </div>
                @empty
                    <p class="p-8 text-center text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.production.no_requirements') }}</p>
                @endforelse
            </div>
            <div class="flex items-center justify-end gap-3 border-t border-[var(--color-line)] p-4 sm:px-6">
                @if ($actualsDirty)
                    <span class="text-xs text-[var(--color-ink-soft)]">{{ __('production_bench.production.actuals_unsaved') }}</span>
                @endif
                <button type="button" wire:click="saveActuals" wire:loading.attr="disabled" @disabled($mutationLocked) class="sk-btn sk-btn-primary">{{ __('production_bench.production.actuals_save') }}</button>
            </div>
        </section>
    @endif

    @if ($production->status->value === 'in_production')
        <section aria-labelledby="readiness-heading" class="sk-card overflow-hidden">
            <div class="border-b border-[var(--color-line)] p-5 sm:p-6"><h2 id="readiness-heading" class="text-xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.production.readiness_title') }}</h2></div>
            <ul class="divide-y divide-[var(--color-line)] text-sm">
                <li class="flex items-start gap-3 px-5 py-3 sm:px-6">
                    <span class="mt-0.5">{{ $completionReadiness['actuals']['ok'] ? '✓' : '✗' }}</span>
                    <span>{{ __('production_bench.production.readiness_actuals') }}{{ $completionReadiness['actuals']['message'] ? ': '.$completionReadiness['actuals']['message'] : '' }}</span>
                </li>
                <li class="flex items-start gap-3 px-5 py-3 sm:px-6">
                    <span class="mt-0.5">{{ $completionReadiness['coverage']['ok'] ? '✓' : '✗' }}</span>
                    <span>{{ __('production_bench.production.readiness_coverage') }}{{ $completionReadiness['coverage']['message'] ? ': '.$completionReadiness['coverage']['message'] : '' }}</span>
                </li>
                <li class="flex items-start gap-3 px-5 py-3 sm:px-6">
                    <span class="mt-0.5">{{ $completionReadiness['output']['ok'] ? '✓' : '✗' }}</span>
                    <span>{{ __('production_bench.production.readiness_output') }}</span>
                </li>
                <li class="flex items-start gap-3 px-5 py-3 sm:px-6">
                    <span class="mt-0.5">{{ $completionReadiness['date']['ok'] ? '✓' : '✗' }}</span>
                    <span>{{ __('production_bench.production.readiness_date') }}</span>
                </li>
                <li class="flex items-start gap-3 px-5 py-3 sm:px-6">
                    <span class="mt-0.5">{{ $completionReadiness['number']['ok'] ? '✓' : '✗' }}</span>
                    <span>{{ __('production_bench.production.readiness_number') }}</span>
                </li>
                <li class="flex items-start gap-3 px-5 py-3 sm:px-6">
                    <span class="mt-0.5">{{ $completionReadiness['costs']['ok'] ? '✓' : '✗' }}</span>
                    <span>{{ __('production_bench.production.readiness_costs') }}{{ $completionReadiness['costs']['message'] ? ': '.$completionReadiness['costs']['message'] : '' }}</span>
                </li>
            </ul>
        </section>
    @endif

    @if ($production->status->value === 'in_production')
        <section aria-labelledby="completion-heading" class="sk-card space-y-4 p-5 sm:p-6">
            <div>
                <h2 id="completion-heading" class="text-xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.production.complete_title') }}</h2>
                <p class="mt-1 text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.production.complete_help') }}</p>
            </div>
            @error('production')
                <p role="alert" class="rounded-xl bg-[var(--color-danger-soft)] px-4 py-3 text-sm text-[var(--color-danger-strong)]">{{ $message }}</p>
            @enderror
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="block text-sm">
                    <span class="font-medium">{{ __('production_bench.production.output_kind') }}</span>
                    <select wire:model.live="outputMode" @disabled($mutationLocked) class="sk-input mt-1 w-full">
                        <option value="units">{{ __('production_bench.production.output_units') }}</option>
                        <option value="intermediate">{{ __('production_bench.production.output_intermediate') }}</option>
                    </select>
                </label>
                <label class="block text-sm">
                    <span class="font-medium">{{ __('production_bench.production.output_quantity') }}</span>
                    <input type="number" inputmode="decimal" min="0" step="any" wire:model.live="actualOutputQuantity" @disabled($mutationLocked) class="sk-input mt-1 w-full font-mono">
                </label>
                @if ($outputMode === 'intermediate')
                    <label class="block text-sm">
                        <span class="font-medium">{{ __('production_bench.production.output_intermediate_ingredient') }}</span>
                        <select wire:model.live="outputIngredientId" @disabled($mutationLocked) class="sk-input mt-1 w-full">
                            <option value="">{{ __('production_bench.production.choose_intermediate') }}</option>
                            @foreach ($intermediateIngredients as $ingredient)
                                <option value="{{ $ingredient->id }}">{{ $ingredient->display_name }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif
                <label class="block text-sm">
                    <span class="font-medium">{{ __('production_bench.production.manufacture_date') }}</span>
                    <input type="date" wire:model.live="manufactureDate" @disabled($mutationLocked) class="sk-input mt-1 w-full">
                </label>
            </div>
            @error('actual_output_quantity')
                <p role="alert" class="text-sm text-[var(--color-danger-strong)]">{{ $message }}</p>
            @enderror
            @error('manufacture_date')
                <p role="alert" class="text-sm text-[var(--color-danger-strong)]">{{ $message }}</p>
            @enderror
            @error('output_ingredient_id')
                <p role="alert" class="text-sm text-[var(--color-danger-strong)]">{{ $message }}</p>
            @enderror
            <div class="flex justify-end">
                <button type="button" wire:click="complete" wire:confirm="{{ __('production_bench.production.complete_confirm') }}" wire:loading.attr="disabled" @disabled($mutationLocked) class="sk-btn sk-btn-primary">{{ __('production_bench.production.complete') }}</button>
            </div>
        </section>
    @endif

    @if ($production->status->value === 'in_production')
        <section aria-labelledby="abort-heading" class="sk-card space-y-4 p-5 sm:p-6">
            <div>
                <h2 id="abort-heading" class="text-xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.production.abort_title') }}</h2>
                <p class="mt-1 text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.production.abort_help') }}</p>
            </div>
            <label class="block text-sm">
                <span class="font-medium">{{ __('production_bench.production.abort_reason') }}</span>
                <textarea wire:model="abortReason" rows="2" maxlength="2000" required @disabled($mutationLocked) class="sk-input mt-1 w-full"></textarea>
            </label>
            @error('abort_reason')
                <p role="alert" class="text-sm text-[var(--color-danger-strong)]">{{ $message }}</p>
            @enderror
            <div class="flex justify-end">
                <button type="button" wire:click="abort" wire:confirm="{{ __('production_bench.production.abort_confirm') }}" wire:loading.attr="disabled" @disabled($mutationLocked) class="sk-btn sk-btn-ghost">{{ __('production_bench.production.abort') }}</button>
            </div>
        </section>
    @endif

    @if ($production->outputLot !== null)
        <section aria-labelledby="output-lot-heading" class="sk-card overflow-hidden">
            <div class="border-b border-[var(--color-line)] p-5 sm:p-6">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 id="output-lot-heading" class="text-xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.production.output_lot') }}</h2>
                    <span class="font-mono text-sm font-semibold text-[var(--color-ink-strong)]">{{ $production->outputLot->internal_lot_code }}</span>
                </div>
                <p class="mt-1 text-sm text-[var(--color-ink-soft)]">
                    {{ $production->outputLot->subjectName() }} ·
                    @if ($production->outputLot->status->value === 'quarantined')
                        {{ __('production_bench.production.output_quarantined') }}
                    @else
                        {{ __('production_bench.production.output_released_label') }}
                    @endif
                    @if ($production->outputLot->available_from)
                        · {{ __('production_bench.production.output_available_from', ['date' => $production->outputLot->available_from->format('Y-m-d')]) }}
                    @endif
                </p>
            </div>
            @error('output')
                <p role="alert" class="border-b border-[var(--color-line)] px-5 py-3 text-sm text-[var(--color-danger-strong)] sm:px-6">{{ $message }}</p>
            @enderror
            <div class="space-y-4 p-5 sm:p-6">
                @if ($production->outputLot->status->value === 'quarantined')
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <p class="text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.production.output_release_help') }}</p>
                        <button type="button" wire:click="releaseOutput" wire:loading.attr="disabled" @disabled($mutationLocked) class="sk-btn sk-btn-primary">{{ __('production_bench.production.release_output') }}</button>
                    </div>
                @else
                    <div class="grid gap-4 sm:grid-cols-4">
                        <label class="block text-sm">
                            <span class="font-medium">{{ __('production_bench.production.issue_kind') }}</span>
                            <select wire:model.live="issueKind" @disabled($mutationLocked) class="sk-input mt-1 w-full">
                                <option value="shipment">{{ __('production_bench.production.issue_shipment') }}</option>
                                <option value="sample">{{ __('production_bench.production.issue_sample') }}</option>
                                <option value="damaged">{{ __('production_bench.production.issue_damaged') }}</option>
                                <option value="internal_use">{{ __('production_bench.production.issue_internal_use') }}</option>
                            </select>
                        </label>
                        <label class="block text-sm">
                            <span class="font-medium">{{ __('production_bench.production.issue_quantity') }}</span>
                            <input type="number" inputmode="decimal" min="0" step="any" wire:model.live="issueQuantity" @disabled($mutationLocked) class="sk-input mt-1 w-full font-mono">
                        </label>
                        <label class="block text-sm sm:col-span-2">
                            <span class="font-medium">{{ __('production_bench.production.issue_note') }}</span>
                            <input type="text" wire:model.live="issueNote" @disabled($mutationLocked) class="sk-input mt-1 w-full">
                        </label>
                    </div>
                    <div class="flex justify-end">
                        <button type="button" wire:click="issueFinishedGoods" wire:loading.attr="disabled" @disabled($mutationLocked) class="sk-btn sk-btn-primary">{{ __('production_bench.production.issue') }}</button>
                    </div>
                @endif
            </div>
        </section>
    @endif

    <section aria-labelledby="journal-heading" class="sk-card overflow-hidden">
        <div class="border-b border-[var(--color-line)] p-5 sm:p-6"><h2 id="journal-heading" class="text-xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.production.journal') }}</h2></div>
        <div class="divide-y divide-[var(--color-line)]">
            @forelse ($production->journalEntries as $entry)
                <div class="px-5 py-4 sm:px-6">
                    <p class="whitespace-pre-line text-sm text-[var(--color-ink-strong)]">{{ $entry->body }}</p>
                    <p class="mt-2 text-xs text-[var(--color-ink-soft)]">{{ $entry->created_at?->format('Y-m-d H:i') }} · {{ $entry->createdBy?->name ?? __('production_bench.production.journal_unknown_author') }}</p>
                </div>
            @empty
                <p class="p-8 text-center text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.production.journal_empty') }}</p>
            @endforelse
        </div>
        @if ($production->documents->where('type', \App\ProductionDocumentType::Journal)->isNotEmpty())
            <div class="border-t border-[var(--color-line)] px-5 py-4 sm:px-6">
                <ul class="space-y-1 text-sm">
                    @foreach ($production->documents->where('type', \App\ProductionDocumentType::Journal) as $document)
                        <li class="flex items-center justify-between gap-3">
                            <span class="truncate text-[var(--color-ink-soft)]">{{ $document->mediaAsset?->original_filename ?? $document->media_asset_id }}</span>
                            @if ($document->note)
                                <span class="text-xs text-[var(--color-ink-muted)]">{{ $document->note }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
        @if (! $mutationLocked && ! in_array($production->status->value, ['completed', 'aborted', 'cancelled'], true))
            <div class="space-y-3 border-t border-[var(--color-line)] p-5 sm:p-6">
                <label class="block text-sm">
                    <span class="sr-only">{{ __('production_bench.production.journal_add') }}</span>
                    <textarea wire:model="journalBody" rows="3" maxlength="20000" @disabled($mutationLocked) class="sk-input mt-1 w-full" placeholder="{{ __('production_bench.production.journal_placeholder') }}"></textarea>
                </label>
                @error('body')
                    <p role="alert" class="text-sm text-[var(--color-danger-strong)]">{{ $message }}</p>
                @enderror
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div class="flex min-w-0 flex-1 flex-wrap items-end gap-2">
                        <label class="block text-sm">
                            <span class="sr-only">{{ __('production_bench.production.journal_document') }}</span>
                            <input type="file" wire:model="journalDocumentUpload" accept="image/*,.pdf" class="sk-input text-sm">
                        </label>
                        <input type="text" wire:model="journalDocumentNote" placeholder="{{ __('production_bench.production.journal_document_note') }}" class="sk-input w-48 text-sm">
                        <button type="button" wire:click="attachJournalDocument" wire:loading.attr="disabled" @disabled($mutationLocked) class="sk-btn sk-btn-outline">{{ __('production_bench.production.journal_document_attach') }}</button>
                    </div>
                    <button type="button" wire:click="saveJournalEntry" wire:loading.attr="disabled" @disabled($mutationLocked) class="sk-btn sk-btn-primary">{{ __('production_bench.production.journal_add') }}</button>
                </div>
            </div>
        @endif
    </section>

    <section aria-labelledby="tasks-detail-heading" class="sk-card overflow-hidden">
        <div class="border-b border-[var(--color-line)] p-5 sm:p-6"><h2 id="tasks-detail-heading" class="text-xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.production.tasks') }}</h2></div>
        @error('task_task') <div role="alert" class="border-b border-[var(--color-line)] px-5 py-3 text-sm text-[var(--color-danger-strong)] sm:px-6">{{ $message }}</div> @enderror
        @error('task_scheduled_for') <div role="alert" class="border-b border-[var(--color-line)] px-5 py-3 text-sm text-[var(--color-danger-strong)] sm:px-6">{{ $message }}</div> @enderror
        <div class="divide-y divide-[var(--color-line)]">
            @forelse ($production->tasks as $task)
                <div class="flex flex-col gap-2 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                    <div class="min-w-0">
                        <p class="font-medium text-[var(--color-ink-strong)]">{{ $task->name_snapshot }}</p>
                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <select aria-label="{{ __('production_bench.production.choose_department') }}" wire:change="assignTaskDepartment({{ $task->id }}, $event.target.value)" class="sk-input min-w-48 py-1.5 text-sm" @disabled($isReadOnly || in_array($production->status->value, ['completed', 'cancelled', 'aborted'], true))>
                                <option value="">{{ __('production_bench.production.choose_department') }}</option>
                                @foreach ($departments as $department)
                                    <option value="{{ $department->id }}" @selected($task->department_id === $department->id)>{{ $department->name }}</option>
                                @endforeach
                            </select>
                            @error('task_department') <span class="text-xs text-[var(--color-danger-strong)]">{{ $message }}</span> @enderror
                            <select aria-label="{{ __('production_bench.production.choose_employee') }}" wire:change="assignTask({{ $task->id }}, $event.target.value)" class="sk-input min-w-48 py-1.5 text-sm" @disabled($isReadOnly || in_array($production->status->value, ['completed', 'cancelled', 'aborted'], true))>
                                <option value="">{{ __('production_bench.production.choose_employee') }}</option>
                                @foreach ($employees as $employee)
                                    <option value="{{ $employee->id }}" @selected($task->employee_id === $employee->id)>{{ $employee->first_name }} {{ $employee->last_name }}</option>
                                @endforeach
                            </select>
                            @if ($task->employee)
                                <span class="text-xs text-[var(--color-ink-soft)]">{{ $task->employee->first_name.' '.$task->employee->last_name }}</span>
                            @endif
                            @error('task_employee') <span class="text-xs text-[var(--color-danger-strong)]">{{ $message }}</span> @enderror
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-3 sm:justify-end">
                        @if ($task->completed_at === null && ! in_array($production->status->value, ['in_production', 'completed', 'cancelled', 'aborted'], true))
                            <label class="sr-only" for="task-date-{{ $task->id }}">{{ __('production_bench.production.task_date') }}</label>
                            <input id="task-date-{{ $task->id }}" type="date" value="{{ $task->scheduled_for->format('Y-m-d') }}" wire:change="rescheduleTask({{ $task->id }}, $event.target.value)" class="sk-input py-1.5 text-sm">
                            @if ($task->scheduling_mode === 'custom' && $task->days_after_production !== 0)
                                <button type="button" wire:click="resetTaskDate({{ $task->id }})" wire:loading.attr="disabled" class="text-xs text-[var(--color-accent-strong)] hover:underline">{{ __('production_bench.production.reset_task_date') }}</button>
                            @endif
                        @else
                            <p class="font-mono tabular-nums text-[var(--color-ink-strong)]">{{ $task->scheduled_for->format('Y-m-d') }}</p>
                        @endif
                        @if($task->completed_at) <span class="text-xs text-[var(--color-ink-soft)]">{{ __('production_bench.production.completed_task') }}</span> @endif
                        <button type="button" wire:click="toggleTask({{ $task->id }})" wire:loading.attr="disabled" @disabled($isReadOnly || in_array($production->status->value, ['completed', 'cancelled', 'aborted'], true)) class="sk-btn sk-btn-ghost py-1.5 text-sm">
                            {{ $task->completed_at ? __('production_bench.production.reopen_task') : __('production_bench.production.mark_complete') }}
                        </button>
                    </div>
                </div>
            @empty
                <p class="p-8 text-center text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.production.no_tasks') }}</p>
            @endforelse
        </div>
    </section>

    @if (in_array($production->status->value, ['draft', 'scheduled', 'reserved'], true))
        <section aria-labelledby="cancel-production-heading" class="sk-card space-y-4 p-5 sm:p-6">
            <div><h2 id="cancel-production-heading" class="text-xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.production.cancel') }}</h2><p class="mt-1 text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.production.cancel_help') }}</p></div>
            <form wire:submit="cancel" class="space-y-3">
                <label class="block text-sm"><span class="font-medium">{{ __('production_bench.production.cancel_reason') }}</span><textarea wire:model="cancellationReason" rows="2" required @disabled($mutationLocked) class="sk-input mt-1 w-full"></textarea>@error('cancellationReason')<span class="mt-1 block text-xs text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror</label>
                <button type="submit" wire:loading.attr="disabled" @disabled($mutationLocked) class="sk-btn sk-btn-ghost">{{ __('production_bench.production.cancel') }}</button>
            </form>
        </section>
    @endif
    @endif
</x-production-bench.page>
