<x-production-bench.page>
    @if (! $isBenchActive && ! $isReadOnly)
        <section class="sk-card p-8 text-center">
            <h1 class="text-3xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.common.inactive') }}</h1>
            <a href="{{ route('production-bench.home') }}" wire:navigate class="mt-4 inline-block text-sm font-medium text-[var(--color-accent)]">{{ __('production_bench.title') }}</a>
        </section>
    @endif

    @if ($isBenchActive || $isReadOnly)
        @php
            $mutationLocked = $isReadOnly || ! $canMutate;
            $identity = $productionDetail['identity'];
            $primaryAction = $productionDetail['primary_action'];
            $release = $productionDetail['release'];
            $output = $productionDetail['output'];
        @endphp

        <div
            x-data
            x-on:early-start-confirmation-requested.window="if (window.confirm($event.detail.message)) { $wire.confirmEarlyStart(); }"
            class="space-y-6"
        >
            @if ($isReadOnly)
                <p role="status" class="rounded-xl bg-[var(--color-warning-soft)] px-4 py-3 text-sm text-[var(--color-warning-strong)]">{{ __('production_bench.common.read_only') }}</p>
            @endif

            <header class="sk-card space-y-5 p-5 sm:p-6" data-testid="production-header">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div class="min-w-0">
                        <a href="{{ route('production-bench.production.index') }}" wire:navigate class="text-sm font-medium text-[var(--color-accent-strong)] hover:underline">← {{ __('production_bench.production.back_to_list') }}</a>
                        <div class="mt-4 flex flex-wrap items-baseline gap-x-4 gap-y-1">
                            <p class="sk-eyebrow font-mono">{{ $identity['identifier'] }}</p>
                            @if ($identity['planned_for'])
                                <p class="text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.production.production_date') }}: <span class="font-mono tabular-nums text-[var(--color-ink-strong)]">{{ $identity['planned_for'] }}</span></p>
                            @endif
                        </div>
                        <h1 class="mt-2 text-3xl font-semibold text-[var(--color-ink-strong)]">{{ $identity['product_name'] }}</h1>
                        <div class="mt-3 flex flex-wrap gap-x-5 gap-y-1 text-sm text-[var(--color-ink-soft)]">
                            <span>{{ __('production_bench.settings.batch_size') }}: <strong class="font-mono font-medium text-[var(--color-ink-strong)]">{{ $identity['basis'] }}</strong></span>
                            <span>{{ __('production_bench.settings.expected_units') }}: <strong class="font-mono font-medium text-[var(--color-ink-strong)]">{{ $identity['expected_units'] }}</strong></span>
                            @if ($identity['formula_version'])
                                <span>{{ __('production_bench.production.formula.source_version') }}: <strong class="font-mono font-medium text-[var(--color-ink-strong)]">{{ $identity['formula_version'] }}</strong></span>
                            @endif
                        </div>
                    </div>

                    <div class="flex shrink-0 flex-col items-stretch gap-2 sm:items-end">
                        @if ($primaryAction === 'schedule')
                            <div class="flex flex-col gap-1 sm:flex-row sm:items-start">
                                <input type="date" wire:model="scheduleDate" required class="sk-input py-2 text-sm" @disabled($mutationLocked)>
                                <button type="button" data-testid="primary-production-action" wire:click="scheduleProduction" wire:loading.attr="disabled" @disabled($mutationLocked) class="sk-btn sk-btn-primary">{{ __('production_bench.production.schedule') }}</button>
                            </div>
                            @error('scheduleDate') <p role="alert" class="text-xs text-[var(--color-danger-strong)]">{{ $message }}</p> @enderror
                        @elseif ($primaryAction === 'prepare_stock')
                            <a data-testid="primary-production-action" href="{{ route('production-bench.production.prepare', $production) }}" wire:navigate class="sk-btn sk-btn-primary">{{ __('production_bench.production.prepare_stock') }}</a>
                        @elseif ($primaryAction === 'assign_batch_number')
                            <button type="button" data-testid="primary-production-action" wire:click="assignBatchNumber" wire:confirm="{{ __('production_bench.production.assign_batch_number_confirm') }}" wire:loading.attr="disabled" @disabled($mutationLocked) class="sk-btn sk-btn-primary">{{ __('production_bench.production.assign_batch_number') }}</button>
                        @elseif ($primaryAction === 'start')
                            <button type="button" data-testid="primary-production-action" wire:click="start" wire:loading.attr="disabled" @disabled($mutationLocked) class="sk-btn sk-btn-primary">{{ __('production_bench.production.start') }}</button>
                        @elseif ($primaryAction === 'complete')
                            <a data-testid="primary-production-action" href="#completion-section" class="sk-btn sk-btn-primary">{{ __('production_bench.production.complete') }}</a>
                        @elseif ($primaryAction === 'release_batch')
                            <button type="button" data-testid="primary-production-action" wire:click="releaseOutput" wire:loading.attr="disabled" @disabled($mutationLocked || ! $release['ready']) class="sk-btn sk-btn-primary">{{ __('production_bench.production.release_batch') }}</button>
                        @endif
                    </div>
                </div>

                <ol data-testid="production-lifecycle" class="flex flex-wrap items-start gap-2 border-t border-[var(--color-line)] pt-5 sm:flex-nowrap sm:gap-0">
                    @foreach ($productionDetail['lifecycle'] as $step)
                        <li data-state="{{ $step['state'] }}" class="flex min-w-[6.5rem] flex-1 items-start gap-2 text-xs sm:min-w-0 sm:flex-col sm:items-center sm:text-center">
                            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full border text-xs font-semibold
                                @if ($step['state'] === 'completed') border-[var(--color-success)] bg-[var(--color-success-soft)] text-[var(--color-success-strong)]
                                @elseif ($step['state'] === 'current') border-[var(--color-accent)] bg-[var(--color-accent-soft)] text-[var(--color-accent-strong)]
                                @elseif ($step['state'] === 'terminal') border-[var(--color-danger)] bg-[var(--color-danger-soft)] text-[var(--color-danger-strong)]
                                @else border-[var(--color-line-strong)] bg-[var(--color-surface-muted)] text-[var(--color-ink-muted)] @endif
                            ">{{ $step['state'] === 'completed' ? '✓' : $loop->iteration }}</span>
                            <span class="pt-1 font-medium
                                @if ($step['state'] === 'current') text-[var(--color-accent-strong)]
                                @elseif ($step['state'] === 'terminal') text-[var(--color-danger-strong)]
                                @else text-[var(--color-ink-soft)] @endif
                            ">{{ $step['label'] }}</span>
                        </li>
                    @endforeach
                </ol>
            </header>

            @error('production_bench')
                <p role="alert" class="rounded-xl bg-[var(--color-danger-soft)] px-4 py-3 text-sm text-[var(--color-danger-strong)]">{{ $message }}</p>
            @enderror

            @if (in_array($production->status, [\App\Enums\ProductionRunStatus::Scheduled, \App\Enums\ProductionRunStatus::Reserved], true))
                <section class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-[var(--color-accent)] bg-[var(--color-accent-soft)] p-4">
                    <div>
                        <p class="font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.production.stock_preparation') }}</p>
                        <p class="mt-1 text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.production.prepare_stock_help_short') }}</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        @if ($productionDetail['has_active_reservations'])
                            <button type="button" wire:click="releaseStock" wire:loading.attr="disabled" @disabled($mutationLocked) class="sk-btn sk-btn-ghost">{{ __('production_bench.production.release_stock') }}</button>
                        @endif
                        @if ($primaryAction !== 'prepare_stock')
                            <a href="{{ route('production-bench.production.prepare', $production) }}" wire:navigate class="sk-btn sk-btn-outline">{{ __('production_bench.production.prepare_stock') }}</a>
                        @endif
                    </div>
                </section>
                @error('production') <p role="alert" class="rounded-xl bg-[var(--color-danger-soft)] px-4 py-3 text-sm text-[var(--color-danger-strong)]">{{ $message }}</p> @enderror
            @endif

            <section data-testid="batch-materials-table" aria-labelledby="batch-materials-heading" class="sk-card overflow-hidden">
                <div class="border-b border-[var(--color-line)] p-5 sm:p-6">
                    <h2 id="batch-materials-heading" class="text-xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.production.batch_materials') }}</h2>
                    <span class="sr-only">{{ __('production_bench.production.formula.title') }}</span>
                    <p class="mt-1 text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.production.batch_materials_help') }}</p>
                </div>
                @error('actuals') <p role="alert" class="border-b border-[var(--color-line)] px-5 py-3 text-sm text-[var(--color-danger-strong)] sm:px-6">{{ $message }}</p> @enderror
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-[var(--color-line)] text-sm">
                        <thead class="bg-[var(--color-surface-muted)] text-left text-xs uppercase tracking-wide text-[var(--color-ink-muted)]">
                            <tr>
                                <th class="px-5 py-3 font-medium sm:px-6">{{ __('production_bench.production.material') }}</th>
                                <th class="px-5 py-3 text-right font-medium sm:px-6">{{ __('production_bench.production.planned') }}</th>
                                <th class="px-5 py-3 font-medium sm:px-6">{{ __('production_bench.production.reserved_lots') }}</th>
                                <th class="px-5 py-3 font-medium sm:px-6">{{ __('production_bench.production.actual_used') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--color-line)]">
                            @php $lastGroup = null; @endphp
                            @forelse ($productionDetail['materials'] as $material)
                                @if ($lastGroup !== $material['group_key'])
                                    <tr>
                                        <th colspan="4" class="bg-[var(--color-surface-muted)] px-5 py-2 text-left text-xs uppercase tracking-wide text-[var(--color-ink-muted)] sm:px-6">{{ $material['group_name'] }}</th>
                                    </tr>
                                    @php $lastGroup = $material['group_key']; @endphp
                                @endif
                                <tr data-testid="material-row" data-material-key="{{ $material['key'] }}" class="align-top">
                                    <td class="px-5 py-4 sm:px-6">
                                        <p class="font-medium text-[var(--color-ink-strong)]">
                                            {{ $material['material_name'] }}
                                            @if ($material['percentage'])
                                                <span class="ml-1 font-mono text-xs font-normal text-[var(--color-ink-soft)]">({{ $material['percentage'] }})</span>
                                            @endif
                                        </p>
                                        @if ($material['note'])
                                            <p class="mt-1 text-xs text-[var(--color-ink-soft)]">{{ $material['note'] }}</p>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-4 text-right font-mono tabular-nums text-[var(--color-ink-strong)] sm:px-6">{{ $material['planned']['quantity'] }} {{ $material['planned']['unit'] }}</td>
                                    <td class="px-5 py-4 text-[var(--color-ink-soft)] sm:px-6">
                                        @if ($material['reservation']['tracked'])
                                            <p class="font-mono tabular-nums text-[var(--color-ink-strong)]">{{ $material['reservation']['total'] }} {{ $material['planned']['unit'] }}</p>
                                            @if ($material['reservation']['lots'])
                                                <div class="mt-1 flex flex-wrap gap-1.5">
                                                    @foreach ($material['reservation']['lots'] as $lot)
                                                        <span class="rounded-md bg-[var(--color-surface-muted)] px-2 py-1 font-mono text-xs tabular-nums">{{ $lot['code'] }} · {{ $lot['quantity'] }}</span>
                                                    @endforeach
                                                </div>
                                            @else
                                                <span class="text-xs">—</span>
                                            @endif
                                        @else
                                            <span class="text-xs">{{ __('production_bench.production.actuals_not_stock_tracked') }}</span>
                                        @endif
                                    </td>
                                    <td class="min-w-[14rem] px-5 py-4 sm:px-6">
                                        @if ($material['actual']['mode'] === 'hidden')
                                            <span class="text-[var(--color-ink-muted)]">—</span>
                                        @else
                                            <div class="space-y-2">
                                                @forelse ($material['actual']['rows'] as $actualRow)
                                                    <div class="flex flex-wrap items-center gap-2">
                                                        @if (str_starts_with($actualRow['state_key'], 'actualRows.'))
                                                            <input type="number" inputmode="decimal" min="0" step="any" wire:model.live.debounce.500ms="{{ $actualRow['state_key'] }}.quantity" aria-label="{{ __('production_bench.production.actuals_quantity', ['name' => $material['material_name']]) }}" @disabled($mutationLocked || $material['actual']['mode'] !== 'editable') class="sk-input w-32 text-right font-mono">
                                                            <input type="text" wire:model.live.debounce.500ms="{{ $actualRow['state_key'] }}.note" placeholder="{{ __('production_bench.production.actuals_note_placeholder') }}" aria-label="{{ __('production_bench.production.actuals_note_placeholder') }}" @disabled($mutationLocked || $material['actual']['mode'] !== 'editable') class="sk-input min-w-32 flex-1 text-sm">
                                                        @else
                                                            <input type="number" inputmode="decimal" min="0" step="any" wire:model.live.debounce.500ms="{{ $actualRow['state_key'] }}.actual_mass_grams" aria-label="{{ __('production_bench.production.actuals_quantity', ['name' => $material['material_name']]) }}" @disabled($mutationLocked || $material['actual']['mode'] !== 'editable') class="sk-input w-32 text-right font-mono">
                                                            <span class="font-mono text-xs text-[var(--color-ink-soft)]">g</span>
                                                        @endif
                                                    </div>
                                                @empty
                                                    <span class="text-[var(--color-ink-muted)]">—</span>
                                                @endforelse
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="p-8 text-center text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.production.no_requirements') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($production->status === \App\Enums\ProductionRunStatus::InProduction)
                    <span class="sr-only">{{ __('production_bench.production.actuals_title') }}</span>
                    <div class="flex items-center justify-end gap-3 border-t border-[var(--color-line)] p-4 sm:px-6">
                        @if ($actualsDirty)
                            <span class="text-xs text-[var(--color-ink-soft)]">{{ __('production_bench.production.actuals_unsaved') }}</span>
                        @endif
                        <button type="button" wire:click="saveActuals" wire:loading.attr="disabled" @disabled($mutationLocked) class="sk-btn sk-btn-primary">{{ __('production_bench.production.actuals_save') }}</button>
                    </div>
                @elseif ($production->status === \App\Enums\ProductionRunStatus::Completed)
                    <p class="border-t border-[var(--color-line)] px-5 py-3 text-xs text-[var(--color-ink-soft)] sm:px-6">{{ __('production_bench.production.actuals_posted_readonly') }}</p>
                @endif
            </section>

            @if ($production->status === \App\Enums\ProductionRunStatus::InProduction)
                <section aria-labelledby="readiness-heading" class="sk-card overflow-hidden">
                    <div class="border-b border-[var(--color-line)] p-5 sm:p-6"><h2 id="readiness-heading" class="text-xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.production.readiness_title') }}</h2></div>
                    <ul class="divide-y divide-[var(--color-line)] text-sm">
                        @foreach ([
                            'actuals' => 'readiness_actuals',
                            'coverage' => 'readiness_coverage',
                            'output' => 'readiness_output',
                            'date' => 'readiness_date',
                            'number' => 'readiness_number',
                            'costs' => 'readiness_costs',
                        ] as $readinessKey => $readinessLabel)
                            <li class="flex items-start gap-3 px-5 py-3 sm:px-6">
                                <span class="mt-0.5">{{ $completionReadiness[$readinessKey]['ok'] ? '✓' : '✗' }}</span>
                                <span>{{ __('production_bench.production.'.$readinessLabel) }}{{ $completionReadiness[$readinessKey]['message'] ? ': '.$completionReadiness[$readinessKey]['message'] : '' }}</span>
                            </li>
                        @endforeach
                    </ul>
                </section>

                <section id="completion-section" aria-labelledby="completion-heading" class="sk-card space-y-4 p-5 sm:p-6">
                    <div>
                        <h2 id="completion-heading" class="text-xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.production.complete_title') }}</h2>
                        <p class="mt-1 text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.production.complete_help') }}</p>
                    </div>
                    @error('production') <p role="alert" class="rounded-xl bg-[var(--color-danger-soft)] px-4 py-3 text-sm text-[var(--color-danger-strong)]">{{ $message }}</p> @enderror
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="block text-sm"><span class="font-medium">{{ __('production_bench.production.output_kind') }}</span><select wire:model.live="outputMode" @disabled($mutationLocked) class="sk-input mt-1 w-full"><option value="units">{{ __('production_bench.production.output_units') }}</option><option value="intermediate">{{ __('production_bench.production.output_intermediate') }}</option></select></label>
                        <label class="block text-sm"><span class="font-medium">{{ __('production_bench.production.output_quantity') }}</span><input type="number" inputmode="decimal" min="0" step="any" wire:model.live="actualOutputQuantity" @disabled($mutationLocked) class="sk-input mt-1 w-full font-mono"></label>
                        @if ($outputMode === 'intermediate')
                            <label class="block text-sm"><span class="font-medium">{{ __('production_bench.production.output_intermediate_ingredient') }}</span><select wire:model.live="outputIngredientId" @disabled($mutationLocked) class="sk-input mt-1 w-full"><option value="">{{ __('production_bench.production.choose_intermediate') }}</option>@foreach ($intermediateIngredients as $ingredient)<option value="{{ $ingredient->id }}">{{ $ingredient->display_name }}</option>@endforeach</select></label>
                        @endif
                        <label class="block text-sm"><span class="font-medium">{{ __('production_bench.production.manufacture_date') }}</span><input type="date" wire:model.live="manufactureDate" @disabled($mutationLocked) class="sk-input mt-1 w-full"></label>
                    </div>
                    @error('actual_output_quantity') <p role="alert" class="text-sm text-[var(--color-danger-strong)]">{{ $message }}</p> @enderror
                    @error('manufacture_date') <p role="alert" class="text-sm text-[var(--color-danger-strong)]">{{ $message }}</p> @enderror
                    @error('output_ingredient_id') <p role="alert" class="text-sm text-[var(--color-danger-strong)]">{{ $message }}</p> @enderror
                    <div class="flex justify-end"><button type="button" wire:click="complete" wire:confirm="{{ __('production_bench.production.complete_confirm') }}" wire:loading.attr="disabled" @disabled($mutationLocked) class="sk-btn sk-btn-primary">{{ __('production_bench.production.complete') }}</button></div>
                </section>

                <section aria-labelledby="abort-heading" class="sk-card space-y-4 p-5 sm:p-6">
                    <div><h2 id="abort-heading" class="text-xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.production.abort_title') }}</h2><p class="mt-1 text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.production.abort_help') }}</p></div>
                    <label class="block text-sm"><span class="font-medium">{{ __('production_bench.production.abort_reason') }}</span><textarea wire:model="abortReason" rows="2" maxlength="2000" required @disabled($mutationLocked) class="sk-input mt-1 w-full"></textarea></label>
                    @error('abort_reason') <p role="alert" class="text-sm text-[var(--color-danger-strong)]">{{ $message }}</p> @enderror
                    <div class="flex justify-end"><button type="button" wire:click="abort" wire:confirm="{{ __('production_bench.production.abort_confirm') }}" wire:loading.attr="disabled" @disabled($mutationLocked) class="sk-btn sk-btn-danger">{{ __('production_bench.production.abort') }}</button></div>
                </section>
            @endif

            @if ($production->outputLot !== null)
                <section id="output-lot-section" aria-labelledby="output-lot-heading" class="sk-card overflow-hidden">
                    <div class="border-b border-[var(--color-line)] p-5 sm:p-6">
                        <div class="flex flex-wrap items-center justify-between gap-2"><h2 id="output-lot-heading" class="text-xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.production.output_lot') }}</h2><span class="font-mono text-sm font-semibold text-[var(--color-ink-strong)]">{{ $production->outputLot->internal_lot_code }}</span></div>
                        <p class="mt-1 text-sm text-[var(--color-ink-soft)]">{{ $production->outputLot->subjectName() }} · {{ $production->outputLot->status->value === 'quarantined' ? __('production_bench.production.output_quarantined') : __('production_bench.production.output_released_label') }}@if ($production->outputLot->available_from) · {{ __('production_bench.production.output_available_from', ['date' => $production->outputLot->available_from->format('Y-m-d')]) }} @endif</p>
                        @if ($output['actual'] !== null)
                            <div class="mt-4 grid gap-3 border-t border-[var(--color-line)] pt-4 text-sm sm:grid-cols-4">
                                <div><p class="text-xs uppercase tracking-wide text-[var(--color-ink-muted)]">{{ __('production_bench.production.output_planned') }}</p><p class="mt-1 font-mono tabular-nums text-[var(--color-ink-strong)]">{{ $output['planned'] }} {{ $output['unit'] }}</p></div>
                                <div><p class="text-xs uppercase tracking-wide text-[var(--color-ink-muted)]">{{ __('production_bench.production.output_actual') }}</p><p class="mt-1 font-mono tabular-nums text-[var(--color-ink-strong)]">{{ $output['actual'] }} {{ $output['unit'] }}</p></div>
                                <div><p class="text-xs uppercase tracking-wide text-[var(--color-ink-muted)]">{{ __('production_bench.production.output_variance') }}</p><p class="mt-1 font-mono tabular-nums text-[var(--color-ink-strong)]">{{ $output['variance'] }} {{ $output['unit'] }}</p></div>
                                <div><p class="text-xs uppercase tracking-wide text-[var(--color-ink-muted)]">{{ __('production_bench.production.output_variance_percentage') }}</p><p class="mt-1 font-mono tabular-nums text-[var(--color-ink-strong)]">{{ $output['variance_percentage'] !== null ? $output['variance_percentage'].'%' : '—' }}</p></div>
                            </div>
                        @endif
                    </div>
                    @error('output') <p role="alert" class="border-b border-[var(--color-line)] px-5 py-3 text-sm text-[var(--color-danger-strong)] sm:px-6">{{ $message }}</p> @enderror
                    @if ($production->outputLot->status->value === 'quarantined')
                        <div class="space-y-2 p-5 sm:p-6">
                            <p class="text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.production.output_release_help') }}</p>
                            @if (! $release['ready'])
                                <ul class="list-disc space-y-1 pl-5 text-xs text-[var(--color-warning-strong)]">
                                    @if (! $release['ready_date_reached']) <li>{{ __('production_bench.production.release_wait_until', ['date' => $release['available_from']]) }}</li> @endif
                                    @if (! $release['tasks_complete']) <li>{{ __('production_bench.production.release_tasks_incomplete', ['count' => count($release['incomplete_tasks'])]) }}</li> @endif
                                </ul>
                            @endif
                        </div>
                    @else
                        <div class="space-y-4 p-5 sm:p-6">
                            <div class="grid gap-4 sm:grid-cols-4">
                                <label class="block text-sm"><span class="font-medium">{{ __('production_bench.production.issue_kind') }}</span><select wire:model.live="issueKind" @disabled($mutationLocked) class="sk-input mt-1 w-full"><option value="shipment">{{ __('production_bench.production.issue_shipment') }}</option><option value="sample">{{ __('production_bench.production.issue_sample') }}</option><option value="damaged">{{ __('production_bench.production.issue_damaged') }}</option><option value="internal_use">{{ __('production_bench.production.issue_internal_use') }}</option></select></label>
                                <label class="block text-sm"><span class="font-medium">{{ __('production_bench.production.issue_quantity') }}</span><input type="number" inputmode="decimal" min="0" step="any" wire:model.live="issueQuantity" @disabled($mutationLocked) class="sk-input mt-1 w-full font-mono"></label>
                                <label class="block text-sm sm:col-span-2"><span class="font-medium">{{ __('production_bench.production.issue_note') }}</span><input type="text" wire:model.live="issueNote" @disabled($mutationLocked) class="sk-input mt-1 w-full"></label>
                            </div>
                            <div class="flex justify-end"><button type="button" wire:click="issueFinishedGoods" wire:loading.attr="disabled" @disabled($mutationLocked) class="sk-btn sk-btn-primary">{{ __('production_bench.production.issue') }}</button></div>
                        </div>
                    @endif
                </section>
            @endif

            <section aria-labelledby="tasks-detail-heading" class="sk-card overflow-hidden">
                <div class="border-b border-[var(--color-line)] p-5 sm:p-6"><h2 id="tasks-detail-heading" class="text-xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.production.tasks') }}</h2></div>
                @error('task_task') <div role="alert" class="border-b border-[var(--color-line)] px-5 py-3 text-sm text-[var(--color-danger-strong)] sm:px-6">{{ $message }}</div> @enderror
                <div class="divide-y divide-[var(--color-line)]">
                    @forelse ($production->tasks as $task)
                        <div class="flex flex-col gap-2 px-5 py-4 sm:px-6">
                            <p class="font-medium text-[var(--color-ink-strong)]">{{ $task->name_snapshot }}</p>
                            <div class="flex flex-wrap items-center gap-2">
                                <select aria-label="{{ __('production_bench.production.choose_department') }}" wire:change="assignTaskDepartment({{ $task->id }}, $event.target.value)" class="sk-input w-40 py-1.5 text-sm" @disabled($mutationLocked || in_array($production->status->value, ['completed', 'cancelled', 'aborted'], true))><option value="">{{ __('production_bench.production.choose_department') }}</option>@foreach ($departments as $department)<option value="{{ $department->id }}" @selected($task->department_id === $department->id)>{{ $department->name }}</option>@endforeach</select>
                                <select aria-label="{{ __('production_bench.production.choose_employee') }}" wire:change="assignTask({{ $task->id }}, $event.target.value)" class="sk-input w-40 py-1.5 text-sm" @disabled($mutationLocked || in_array($production->status->value, ['completed', 'cancelled', 'aborted'], true))><option value="">{{ __('production_bench.production.choose_employee') }}</option>@foreach ($employees as $employee)<option value="{{ $employee->id }}" @selected($task->employee_id === $employee->id)>{{ $employee->first_name }} {{ $employee->last_name }}</option>@endforeach</select>
                                @if ($task->completed_at === null && ! in_array($production->status->value, ['in_production', 'completed', 'cancelled', 'aborted'], true))
                                    <input type="date" value="{{ $task->scheduled_for->format('Y-m-d') }}" wire:change="rescheduleTask({{ $task->id }}, $event.target.value)" class="sk-input py-1.5 text-sm" @disabled($mutationLocked)>
                                @else
                                    <p class="font-mono tabular-nums text-[var(--color-ink-strong)]">{{ $task->scheduled_for->format('Y-m-d') }}</p>
                                @endif
                                @if ($task->completed_at) <span class="text-xs text-[var(--color-ink-soft)]">{{ __('production_bench.production.completed_task') }}</span> @endif
                                @if (! in_array($production->status->value, ['cancelled', 'aborted'], true))
                                    <button type="button" wire:click="toggleTask({{ $task->id }})" wire:loading.attr="disabled" @disabled($mutationLocked) class="sk-btn sk-btn-ghost py-1.5 text-sm">{{ $task->completed_at ? __('production_bench.production.reopen_task') : __('production_bench.production.mark_complete') }}</button>
                                @endif
                            </div>
                        </div>
                    @empty
                        <p class="p-8 text-center text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.production.no_tasks') }}</p>
                    @endforelse
                </div>
            </section>

            <section aria-labelledby="journal-heading" class="sk-card overflow-hidden">
                <div class="border-b border-[var(--color-line)] p-5 sm:p-6"><h2 id="journal-heading" class="text-xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.production.journal') }}</h2></div>
                <div class="divide-y divide-[var(--color-line)]">
                    @forelse ($production->journalEntries as $entry)
                        <div class="px-5 py-4 sm:px-6"><p class="whitespace-pre-line text-sm text-[var(--color-ink-strong)]">{{ $entry->body }}</p><p class="mt-2 text-xs text-[var(--color-ink-soft)]">{{ $entry->created_at?->format('Y-m-d H:i') }} · {{ $entry->createdBy?->name ?? __('production_bench.production.journal_unknown_author') }}</p></div>
                    @empty
                        <p class="p-8 text-center text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.production.journal_empty') }}</p>
                    @endforelse
                </div>
                @if (! $mutationLocked && ! in_array($production->status->value, ['completed', 'aborted', 'cancelled'], true))
                    <div class="space-y-3 border-t border-[var(--color-line)] p-5 sm:p-6">
                        <textarea wire:model="journalBody" rows="3" maxlength="20000" @disabled($mutationLocked) class="sk-input mt-1 w-full" placeholder="{{ __('production_bench.production.journal_placeholder') }}"></textarea>
                        <div class="flex justify-end"><button type="button" wire:click="saveJournalEntry" wire:loading.attr="disabled" @disabled($mutationLocked) class="sk-btn sk-btn-primary">{{ __('production_bench.production.journal_add') }}</button></div>
                    </div>
                @endif
            </section>

            @if (in_array($production->status->value, ['draft', 'scheduled', 'reserved'], true))
                <section aria-labelledby="cancel-production-heading" class="sk-card space-y-4 p-5 sm:p-6">
                    <div><h2 id="cancel-production-heading" class="text-xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.production.cancel') }}</h2><p class="mt-1 text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.production.cancel_help') }}</p></div>
                    <form wire:submit="cancel" class="space-y-3"><label class="block text-sm"><span class="font-medium">{{ __('production_bench.production.cancel_reason') }}</span><textarea wire:model="cancellationReason" rows="2" required @disabled($mutationLocked) class="sk-input mt-1 w-full"></textarea>@error('cancellationReason')<span class="mt-1 block text-xs text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror</label><button type="submit" wire:loading.attr="disabled" @disabled($mutationLocked) class="sk-btn sk-btn-danger">{{ __('production_bench.production.cancel') }}</button></form>
                </section>
            @endif
        </div>
    @endif
</x-production-bench.page>
