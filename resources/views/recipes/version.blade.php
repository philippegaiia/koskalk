@extends('layouts.app-shell')

@section('title', $recipe->name.' · Official Saved Recipe · '.config('app.name'))
@section('page_heading', 'Official Saved Recipe')

@section('content')
    <div class="mx-auto max-w-[90rem] space-y-6">
        @php
            /** @var array<string, string>|null $draftReplaceConfirmation */
            $draftReplaceConfirmation = session('draftReplaceConfirmation');
            $printQuery = [
                'recipe' => $recipe->id,
                'oil_weight' => $selectedOilWeight,
            ];

            foreach (['batch_number', 'batch_basis', 'manufacture_date', 'units_produced'] as $batchQueryKey) {
                if (filled($batchContext[$batchQueryKey] ?? null)) {
                    $printQuery[$batchQueryKey] = $batchContext[$batchQueryKey];
                }
            }

            $canRecordProduction = (bool) ($canRecordProduction ?? false);
            $productionPreview = $productionPreview ?? null;
            $productionBatches = $productionBatches ?? collect();
            $formatNumber = fn (mixed $value, int $precision = 2): string => rtrim(rtrim(number_format((float) $value, $precision, '.', ''), '0'), '.');
            $formatMoney = fn (mixed $value, string $currency): string => $formatNumber($value, 2).' '.$currency;
        @endphp

        @if (is_array($draftReplaceConfirmation))
            <section class="rounded-xl border border-[var(--color-warning-soft)] bg-[var(--color-warning-soft)]/35 p-5">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div class="min-w-0">
                        <p class="sk-eyebrow">Draft confirmation</p>
                        <h2 class="mt-2 text-lg font-semibold text-[var(--color-ink-strong)]">
                            {{ $draftReplaceConfirmation['title'] ?? 'Replace the current draft?' }}
                        </h2>
                        <p class="mt-2 max-w-3xl text-sm leading-7 text-[var(--color-ink-soft)]">
                            {{ $draftReplaceConfirmation['body'] ?? 'Confirming this action will replace the current draft.' }}
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <form method="POST" action="{{ $draftReplaceConfirmation['action_url'] ?? route('recipes.saved', $recipe->id) }}">
                            @csrf
                            <input type="hidden" name="confirm_replace_draft" value="1" />
                            <button type="submit" class="inline-flex rounded-full bg-[var(--color-accent-strong)] px-4 py-2 text-sm font-medium text-white transition hover:bg-[var(--color-accent)]">
                                {{ $draftReplaceConfirmation['action_label'] ?? 'Replace draft' }}
                            </button>
                        </form>
                        <a href="{{ route('recipes.saved', $recipe->id) }}" class="inline-flex rounded-full border border-[var(--color-line)] bg-white px-4 py-2 text-sm font-medium text-[var(--color-ink-strong)] transition hover:bg-[var(--color-panel)]">
                            Keep current draft
                        </a>
                    </div>
                </div>
            </section>
        @endif

        <section class="sk-card p-5">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="sk-eyebrow">Reference formula</p>
                        <span class="rounded-full border border-[var(--color-success-soft)] bg-[var(--color-success-soft)] px-3 py-1 text-xs font-medium text-[var(--color-success-strong)]">Reference formula</span>
                    </div>
                    <h1 class="mt-2 text-2xl font-semibold text-[var(--color-ink-strong)]">{{ $version->name }}</h1>
                    <p class="mt-2 max-w-3xl text-sm text-[var(--color-ink-soft)]">
                        Read-only reference formula. To change it, edit the draft and save that draft as the reference formula. The oil quantity here is only for scaling and printing.
                    </p>

                    <div class="mt-4 flex flex-wrap gap-2">
                        <form method="POST" action="{{ route('recipes.saved.edit-in-draft', $recipe->id) }}">
                            @csrf
                            <button type="submit" class="inline-flex rounded-full border border-[var(--color-line-strong)] bg-white px-4 py-2 text-sm font-medium text-[var(--color-ink-strong)] transition hover:bg-[var(--color-panel)]">
                                Edit in draft
                            </button>
                        </form>
                        <form method="POST" action="{{ route('recipes.duplicate', $recipe->id) }}">
                            @csrf
                            <button type="submit" class="inline-flex rounded-full border border-[var(--color-line)] px-4 py-2 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel)]">
                                Duplicate
                            </button>
                        </form>
                        <a href="{{ route('recipes.print.production', $printQuery) }}" class="inline-flex rounded-full border border-[var(--color-line)] px-4 py-2 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel)]">
                            Batch production sheet
                        </a>
                        <a href="{{ route('recipes.print.technical', $printQuery) }}" class="inline-flex rounded-full border border-[var(--color-line)] px-4 py-2 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel)]">
                            Technical recipe sheet
                        </a>
                        <a href="{{ route('recipes.print.costing', $printQuery) }}" class="inline-flex rounded-full bg-[var(--color-accent-strong)] px-4 py-2 text-sm font-medium text-white transition hover:bg-[var(--color-accent)]">
                            Costing sheet
                        </a>
                        <a href="{{ route('recipes.export.xlsx', $printQuery) }}" class="inline-flex rounded-full border border-[var(--color-line)] px-4 py-2 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel)]">
                            Export Excel
                        </a>
                        <a href="{{ route('recipes.export.csv', $printQuery) }}" class="inline-flex rounded-full border border-[var(--color-line)] px-4 py-2 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel)]">
                            Export CSV
                        </a>
                    </div>
                </div>

                <form method="GET" action="{{ route('recipes.saved', ['recipe' => $recipe->id]) }}" class="sk-inset p-4 lg:min-w-[16rem]">
                    <p class="sk-eyebrow">Scale quantity</p>
                    <label class="mt-2 block text-sm font-medium text-[var(--color-ink-strong)]" for="oil_weight">Oil quantity</label>
                    <div class="mt-2 flex items-center gap-2">
                        <input id="oil_weight" name="oil_weight" type="number" min="0.01" step="0.01" value="{{ rtrim(rtrim(number_format($selectedOilWeight, 2, '.', ''), '0'), '.') }}" class="numeric w-full rounded-lg border border-[var(--color-line)] bg-[var(--color-field)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]" />
                        <span class="numeric rounded-full border border-[var(--color-line)] bg-white px-3 py-1.5 text-xs font-medium text-[var(--color-ink-soft)]">{{ $snapshot['draft']['oilUnit'] ?? 'g' }}</span>
                    </div>
                    <div class="mt-3 flex gap-2">
                        <button type="submit" class="rounded-full bg-[var(--color-ink-strong)] px-4 py-2 text-sm font-medium text-white transition hover:bg-[var(--color-accent-strong)]">
                            Recalculate
                        </button>
                        <a href="{{ route('recipes.saved', ['recipe' => $recipe->id]) }}" class="rounded-full border border-[var(--color-line)] px-4 py-2 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-white">
                            Reset
                        </a>
                    </div>
                </form>
            </div>
        </section>

        @if (count($recoverySnapshots) > 1)
        <details class="sk-card overflow-hidden">
            <summary class="cursor-pointer list-none border-b border-[var(--color-line)] px-5 py-4 marker:hidden">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <p class="sk-eyebrow">Recovery snapshots</p>
                        <p class="mt-1 text-sm text-[var(--color-ink-soft)]">Older saved states. Restore one as current, or load it into the draft for editing.</p>
                    </div>
                    <span class="rounded-full border border-[var(--color-line)] bg-white px-3 py-1 text-xs font-medium text-[var(--color-ink-soft)]">
                        {{ count($recoverySnapshots) - 1 }} previous saves
                    </span>
                </div>
            </summary>

            <div class="divide-y divide-[var(--color-line)]">
                @foreach ($recoverySnapshots as $snapshotVersion)
                    @if (! $snapshotVersion['is_current'])
                    <div class="flex flex-col gap-3 px-5 py-3 lg:flex-row lg:items-center lg:justify-between">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="text-sm font-medium text-[var(--color-ink-strong)]">{{ $snapshotVersion['name'] }}</p>
                                <span class="rounded-full border border-[var(--color-line)] bg-white px-2.5 py-0.5 text-[11px] font-medium text-[var(--color-ink-soft)]">Recovery</span>
                            </div>
                            <p class="mt-1 text-xs text-[var(--color-ink-soft)]">
                                Saved {{ \Illuminate\Support\Carbon::parse($snapshotVersion['saved_at'])->format('Y-m-d H:i') }}
                            </p>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <form method="POST" action="{{ route('recipes.use-version-as-draft', ['recipe' => $recipe->id, 'version' => $snapshotVersion['id']]) }}">
                                @csrf
                                <button type="submit" class="inline-flex rounded-full border border-[var(--color-line)] px-3 py-1.5 text-xs font-medium text-[var(--color-ink-soft)] transition hover:bg-white">
                                    Load into draft
                                </button>
                            </form>
                            <form method="POST" action="{{ route('recipes.saved.restore', ['recipe' => $recipe->id, 'version' => $snapshotVersion['id']]) }}">
                                @csrf
                                <button type="submit" class="inline-flex rounded-full bg-[var(--color-accent-strong)] px-3 py-1.5 text-xs font-medium text-white transition hover:bg-[var(--color-accent)]">
                                    Restore as reference formula
                                </button>
                            </form>
                        </div>
                    </div>
                    @endif
                @endforeach
            </div>
        </details>
        @endif

        <section class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_24rem]">
            <div class="sk-card overflow-hidden">
                <div class="border-b border-[var(--color-line)] px-5 py-4">
                    <p class="sk-eyebrow">Packaging plan</p>
                    <h2 class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">Packaging plan</h2>
                    <p class="mt-2 text-sm text-[var(--color-ink-soft)]">Read-only packaging structure for one finished unit.</p>
                </div>

                @if ($packagingPlanRows !== [])
                    <div class="divide-y divide-[var(--color-line)]">
                        @foreach ($packagingPlanRows as $packagingRow)
                            <div class="flex flex-col gap-3 px-5 py-3 lg:flex-row lg:items-center lg:justify-between">
                                <div>
                                    <p class="font-medium text-[var(--color-ink-strong)]">{{ $packagingRow['name'] }}</p>
                                    @if ($packagingRow['notes'])
                                        <p class="mt-1 text-xs text-[var(--color-ink-soft)]">{{ $packagingRow['notes'] }}</p>
                                    @endif
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <span class="numeric rounded-full border border-[var(--color-line)] bg-white px-3 py-1 text-xs font-medium text-[var(--color-ink-soft)]">
                                        {{ rtrim(rtrim(number_format((float) $packagingRow['components_per_unit'], 3, '.', ''), '0'), '.') }} per unit
                                    </span>
                                    @if ($packagingRow['catalog_price'] !== null)
                                        <span class="numeric rounded-full border border-[var(--color-line)] bg-white px-3 py-1 text-xs font-medium text-[var(--color-ink-soft)]">
                                            {{ $packagingRow['currency'] }} {{ rtrim(rtrim(number_format((float) $packagingRow['catalog_price'], 4, '.', ''), '0'), '.') }}
                                        </span>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="px-5 py-6 text-sm text-[var(--color-ink-soft)]">
                        No packaging plan is saved for this reference formula yet.
                    </div>
                @endif
            </div>

            @if ($canRecordProduction && is_array($productionPreview))
                <form method="POST" action="{{ route('recipes.production-batches.store', ['recipe' => $recipe->id]) }}" class="sk-card p-5">
                    @csrf

                    <p class="sk-eyebrow">Production</p>
                    <h2 class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">Record production</h2>
                    <p class="mt-2 text-sm text-[var(--color-ink-soft)]">Freeze the saved formula, current prices, packaging plan, and lot annotations into a production batch.</p>

                    @error('costing')
                        <p class="mt-4 rounded-lg border border-[var(--color-warning-soft)] bg-[var(--color-warning-soft)]/35 px-3 py-2 text-sm font-medium text-[var(--color-warning-strong)]">{{ $message }}</p>
                    @enderror

                    @if ($productionPreview['has_unpriced_rows'] ?? false)
                        <p class="mt-4 rounded-lg border border-[var(--color-warning-soft)] bg-[var(--color-warning-soft)]/35 px-3 py-2 text-sm font-medium text-[var(--color-warning-strong)]">
                            Some rows are unpriced. Add prices before recording production.
                        </p>
                    @endif

                    <label class="mt-4 block">
                        <span class="text-sm font-medium text-[var(--color-ink-strong)]">Production batch number</span>
                        <input name="production_batch_number" value="{{ old('production_batch_number', $batchContext['batch_number']) }}" type="text" class="mt-2 w-full rounded-lg border border-[var(--color-line)] bg-[var(--color-field)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]" />
                        @error('production_batch_number')
                            <span class="mt-1 block text-xs font-medium text-[var(--color-danger-strong)]">{{ $message }}</span>
                        @enderror
                    </label>

                    <label class="mt-3 block">
                        <span class="text-sm font-medium text-[var(--color-ink-strong)]">Manufacture date</span>
                        <input name="manufacture_date" value="{{ old('manufacture_date', $batchContext['manufacture_date']) }}" type="date" class="mt-2 w-full rounded-lg border border-[var(--color-line)] bg-[var(--color-field)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]" />
                        @error('manufacture_date')
                            <span class="mt-1 block text-xs font-medium text-[var(--color-danger-strong)]">{{ $message }}</span>
                        @enderror
                    </label>

                    <label class="mt-3 block">
                        <span class="text-sm font-medium text-[var(--color-ink-strong)]">{{ $productionPreview['batch_basis_label'] ?? 'Oil quantity' }}</span>
                        <div class="mt-2 flex items-center gap-2">
                            <input name="batch_basis" value="{{ old('batch_basis', $formatNumber($productionPreview['batch_basis_value'] ?? $batchContext['batch_basis'])) }}" type="text" inputmode="decimal" class="numeric w-full rounded-lg border border-[var(--color-line)] bg-[var(--color-field)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]" />
                            <span class="numeric rounded-full border border-[var(--color-line)] bg-white px-3 py-1.5 text-xs font-medium text-[var(--color-ink-soft)]">{{ $productionPreview['batch_basis_unit'] ?? 'g' }}</span>
                        </div>
                        @error('batch_basis')
                            <span class="mt-1 block text-xs font-medium text-[var(--color-danger-strong)]">{{ $message }}</span>
                        @enderror
                    </label>

                    <label class="mt-3 block">
                        <span class="text-sm font-medium text-[var(--color-ink-strong)]">Units produced</span>
                        <input name="units_produced" value="{{ old('units_produced', $productionPreview['units_produced'] ?? $batchContext['units_produced']) }}" type="text" inputmode="numeric" class="numeric mt-2 w-full rounded-lg border border-[var(--color-line)] bg-[var(--color-field)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]" />
                        @error('units_produced')
                            <span class="mt-1 block text-xs font-medium text-[var(--color-danger-strong)]">{{ $message }}</span>
                        @enderror
                    </label>

                    <div class="mt-5 rounded-lg border border-[var(--color-line)] bg-white">
                        <div class="border-b border-[var(--color-line)] px-3 py-2">
                            <h3 class="text-sm font-semibold text-[var(--color-ink-strong)]">Ingredient lot numbers</h3>
                        </div>

                        @if (($productionPreview['ingredient_rows'] ?? []) !== [])
                            <div class="divide-y divide-[var(--color-line)]">
                                @foreach ($productionPreview['ingredient_rows'] as $ingredientRow)
                                    <label class="grid gap-2 px-3 py-3 md:grid-cols-[minmax(0,1fr)_9rem]">
                                        <span>
                                            <span class="block text-sm font-medium text-[var(--color-ink-strong)]">{{ $ingredientRow['ingredient_name'] }}</span>
                                            <span class="numeric mt-1 block text-xs text-[var(--color-ink-soft)]">{{ $formatNumber($ingredientRow['quantity'], 2) }} {{ $ingredientRow['unit'] }}</span>
                                        </span>
                                        <input name="ingredient_lot_numbers[{{ $ingredientRow['lot_key'] }}]" value="{{ old('ingredient_lot_numbers.'.$ingredientRow['lot_key']) }}" type="text" class="w-full rounded-lg border border-[var(--color-line)] bg-[var(--color-field)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]" />
                                    </label>
                                    @error('ingredient_lot_numbers.'.$ingredientRow['lot_key'])
                                        <span class="block px-3 pb-3 text-xs font-medium text-[var(--color-danger-strong)]">{{ $message }}</span>
                                    @enderror
                                @endforeach
                            </div>
                        @else
                            <p class="px-3 py-4 text-sm text-[var(--color-ink-soft)]">No priced ingredient rows are available yet.</p>
                        @endif
                    </div>

                    <label class="mt-4 block">
                        <span class="text-sm font-medium text-[var(--color-ink-strong)]">Production notes</span>
                        <textarea name="production_notes" rows="3" class="mt-2 w-full rounded-lg border border-[var(--color-line)] bg-[var(--color-field)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]">{{ old('production_notes') }}</textarea>
                        @error('production_notes')
                            <span class="mt-1 block text-xs font-medium text-[var(--color-danger-strong)]">{{ $message }}</span>
                        @enderror
                    </label>

                    <dl class="mt-5 divide-y divide-[var(--color-line)] rounded-lg border border-[var(--color-line)] bg-white text-sm">
                        <div class="flex items-center justify-between gap-3 px-3 py-2">
                            <dt class="text-[var(--color-ink-soft)]">Ingredient cost</dt>
                            <dd class="numeric font-medium text-[var(--color-ink-strong)]">{{ $formatMoney($productionPreview['ingredient_total'] ?? 0, $productionPreview['currency'] ?? 'EUR') }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3 px-3 py-2">
                            <dt class="text-[var(--color-ink-soft)]">Packaging cost</dt>
                            <dd class="numeric font-medium text-[var(--color-ink-strong)]">{{ $formatMoney($productionPreview['packaging_total'] ?? 0, $productionPreview['currency'] ?? 'EUR') }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3 px-3 py-2">
                            <dt class="text-[var(--color-ink-soft)]">Total production cost</dt>
                            <dd class="numeric font-medium text-[var(--color-ink-strong)]">{{ $formatMoney($productionPreview['total_cost'] ?? 0, $productionPreview['currency'] ?? 'EUR') }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3 px-3 py-2">
                            <dt class="text-[var(--color-ink-soft)]">Cost per finished unit</dt>
                            <dd class="numeric font-medium text-[var(--color-ink-strong)]">
                                @if (($productionPreview['cost_per_unit'] ?? null) !== null)
                                    {{ $formatMoney($productionPreview['cost_per_unit'], $productionPreview['currency'] ?? 'EUR') }}
                                @else
                                    Not set
                                @endif
                            </dd>
                        </div>
                    </dl>

                    <button type="submit" class="mt-5 rounded-full bg-[var(--color-ink-strong)] px-4 py-2 text-sm font-medium text-white transition hover:bg-[var(--color-accent-strong)]">
                        Record production
                    </button>
                </form>
            @endif
        </section>

        @if ($canRecordProduction)
            <section class="sk-card overflow-hidden">
                <div class="border-b border-[var(--color-line)] px-5 py-4">
                    <p class="sk-eyebrow">Production</p>
                    <h2 class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">Production history</h2>
                </div>

                @if ($productionBatches->isNotEmpty())
                    <div class="divide-y divide-[var(--color-line)]">
                        @foreach ($productionBatches as $productionBatch)
                            <div class="grid gap-3 px-5 py-4 lg:grid-cols-[8rem_minmax(0,1fr)_12rem_9rem_8rem] lg:items-center">
                                <p class="numeric text-sm font-medium text-[var(--color-ink-strong)]">{{ $productionBatch->manufacture_date?->format('Y-m-d') }}</p>
                                <p class="text-sm font-medium text-[var(--color-ink-strong)]">{{ $productionBatch->production_batch_number ?: 'No batch number' }}</p>
                                <p class="numeric text-sm text-[var(--color-ink-soft)]">{{ $formatNumber($productionBatch->batch_basis_value, 2) }} {{ $productionBatch->batch_basis_unit }} {{ $productionBatch->batch_basis_label }}</p>
                                <p class="numeric text-sm text-[var(--color-ink-soft)]">{{ $productionBatch->units_produced }} units</p>
                                <div class="flex flex-wrap items-center gap-3 lg:justify-end">
                                    <span class="numeric text-sm font-medium text-[var(--color-ink-strong)]">{{ $formatMoney($productionBatch->cost_per_unit, $productionBatch->currency) }}</span>
                                    <a href="{{ route('production-batches.show', $productionBatch) }}" class="text-sm font-medium text-[var(--color-accent-strong)] transition hover:text-[var(--color-accent)]">View</a>
                                    <a href="{{ route('production-batches.print', $productionBatch) }}" class="text-sm font-medium text-[var(--color-accent-strong)] transition hover:text-[var(--color-accent)]">Print</a>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="px-5 py-6 text-sm text-[var(--color-ink-soft)]">
                        No production batches recorded yet.
                    </div>
                @endif
            </section>
        @endif

        @include('recipes.partials.version-sheet', [
            'recipe' => $recipe,
            'snapshot' => $snapshot,
            'phaseSections' => $phaseSections,
            'summaryCards' => $summaryCards,
            'contextRows' => $contextRows,
            'lyeRows' => $lyeRows,
            'showDetails' => true,
        ])
    </div>
@endsection
