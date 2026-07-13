@extends('layouts.app-shell')

@section('title', $version->name.' · Formula Sheet · '.config('app.name'))
@section('page_heading', 'Formula Sheet')

@section('content')
    <div class="mx-auto max-w-[90rem] space-y-6">
        @php
            /** @var array<string, string>|null $currentReplaceConfirmation */
            $currentReplaceConfirmation = session('currentReplaceConfirmation');
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
            $isHistorical = (bool) ($isHistorical ?? false);
            $productionPreview = $productionPreview ?? null;
            $productionBatches = $productionBatches ?? collect();
            $otherSavedVersions = collect($recoverySnapshots ?? [])
                ->reject(fn (array $savedVersion): bool => (bool) ($savedVersion['is_current'] ?? false));
            $sheetRoute = $isHistorical
                ? route('recipes.version', ['recipe' => $recipe->id, 'version' => $version->id])
                : route('recipes.saved', ['recipe' => $recipe->id]);
            $isCosmeticFormula = $recipe->productFamily?->calculation_basis === 'total_formula';
            $formatNumber = fn (mixed $value, int $precision = 2): string => rtrim(rtrim(number_format((float) $value, $precision, '.', ''), '0'), '.');
            $formatMoney = fn (mixed $value, string $currency): string => $formatNumber($value, 2).' '.$currency;

            $finalIngredientListText = $snapshot['labeling']['print_ingredient_list_text']
                ?? ($snapshot['labeling']['final_ingredient_list']['final_text'] ?? null)
                ?? ($snapshot['labeling']['final_label_text'] ?? '');
            $plainLanguageListText = $snapshot['labeling']['print_plain_ingredient_list_text']
                ?? data_get($snapshot, 'labeling.plain_language_list.final_label_text', '');
        @endphp

        @if (is_array($currentReplaceConfirmation))
            <section class="rounded-xl border border-[var(--color-warning-soft)] bg-[var(--color-warning-soft)]/35 p-5">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div class="min-w-0">
                        <p class="sk-eyebrow">Formula confirmation</p>
                        <h2 class="mt-2 text-lg font-semibold text-[var(--color-ink-strong)]">
                            {{ $currentReplaceConfirmation['title'] ?? 'Replace the current formula?' }}
                        </h2>
                        <p class="mt-2 max-w-3xl text-sm leading-7 text-[var(--color-ink-soft)]">
                            {{ $currentReplaceConfirmation['body'] ?? 'Confirming this action will replace the current formula.' }}
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <form method="POST" action="{{ $currentReplaceConfirmation['action_url'] ?? route('recipes.saved', $recipe->id) }}">
                            @csrf
                            <input type="hidden" name="confirm_replace_current" value="1" />
                            <button type="submit" class="inline-flex rounded-full bg-[var(--color-accent-strong)] px-4 py-2 text-sm font-medium text-white transition hover:bg-[var(--color-accent)]">
                                {{ $currentReplaceConfirmation['action_label'] ?? 'Replace formula' }}
                            </button>
                        </form>
                        <a href="{{ route('recipes.saved', $recipe->id) }}" class="inline-flex rounded-full border border-[var(--color-line)] bg-white px-4 py-2 text-sm font-medium text-[var(--color-ink-strong)] transition hover:bg-[var(--color-panel)]">
                            Keep current formula
                        </a>
                    </div>
                </div>
            </section>
        @endif

        <section class="sk-card p-5">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="sk-eyebrow">Formula sheet</p>
                        @if ($isHistorical)
                            <span class="rounded-full border border-[var(--color-warning-soft)] bg-[var(--color-warning-soft)] px-3 py-1 text-xs font-medium text-[var(--color-ink-strong)]">Previous version</span>
                        @else
                            <span class="rounded-full border border-[var(--color-success-soft)] bg-[var(--color-success-soft)] px-3 py-1 text-xs font-medium text-[var(--color-success-strong)]">Saved formula</span>
                        @endif
                    </div>
                    <h1 class="mt-2 text-2xl font-semibold text-[var(--color-ink-strong)]">{{ $version->name }}</h1>
                    <p class="mt-2 max-w-3xl text-sm text-[var(--color-ink-soft)]">
                        Use this saved formula for scaling, printing, and export. Quantity changes here only affect the sheet output.
                    </p>

                    <div class="mt-4 flex flex-wrap gap-2">
                        @if ($isHistorical)
                            <a href="{{ route('recipes.saved', $recipe->id) }}" class="inline-flex rounded-full border border-[var(--color-line-strong)] bg-white px-4 py-2 text-sm font-medium text-[var(--color-ink-strong)] transition hover:bg-[var(--color-panel)]">
                                Back to active formula
                            </a>
                        @endif
                        <a href="{{ route('recipes.edit', $recipe->id) }}" class="inline-flex rounded-full border border-[var(--color-line-strong)] bg-white px-4 py-2 text-sm font-medium text-[var(--color-ink-strong)] transition hover:bg-[var(--color-panel)]">
                            Open formula
                        </a>
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

                <form method="GET" action="{{ $sheetRoute }}" class="sk-inset p-4 lg:min-w-[16rem]">
                    <p class="sk-eyebrow">Scale quantity</p>
                    <label class="mt-2 block text-sm font-medium text-[var(--color-ink-strong)]" for="oil_weight">{{ $isCosmeticFormula ? 'Total batch quantity' : 'Oil quantity' }}</label>
                    <div class="mt-2 flex items-center gap-2">
                        <input id="oil_weight" name="oil_weight" type="number" min="0.01" step="0.01" value="{{ rtrim(rtrim(number_format($selectedOilWeight, 2, '.', ''), '0'), '.') }}" class="numeric w-full rounded-lg border border-[var(--color-line)] bg-[var(--color-field)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]" />
                        <span class="numeric rounded-full border border-[var(--color-line)] bg-white px-3 py-1.5 text-xs font-medium text-[var(--color-ink-soft)]">{{ $snapshot['draft']['oilUnit'] ?? 'g' }}</span>
                    </div>
                    <div class="mt-3 flex gap-2">
                        <button type="submit" class="rounded-full bg-[var(--color-ink-strong)] px-4 py-2 text-sm font-medium text-white transition hover:bg-[var(--color-accent-strong)]">
                            Recalculate
                        </button>
                        <a href="{{ $sheetRoute }}" class="rounded-full border border-[var(--color-line)] px-4 py-2 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-white">
                            Reset
                        </a>
                    </div>
                </form>
            </div>
        </section>

        @if ($otherSavedVersions->isNotEmpty())
            <details class="sk-card p-5">
                <summary class="cursor-pointer text-sm font-semibold text-[var(--color-ink-strong)]">Version history</summary>

                <div class="mt-4 space-y-3">
                    @foreach ($otherSavedVersions as $savedVersion)
                        <article class="flex flex-col gap-3 rounded-xl border border-[var(--color-line)] p-4 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <p class="font-medium text-[var(--color-ink-strong)]">{{ $savedVersion['name'] }}</p>
                                <p class="numeric mt-1 text-xs text-[var(--color-ink-soft)]">
                                    {{ filled($savedVersion['saved_at'] ?? null) ? \Illuminate\Support\Carbon::parse($savedVersion['saved_at'])->format('Y-m-d H:i') : 'Date not recorded' }}
                                </p>
                            </div>

                            <div class="flex flex-wrap gap-2">
                                <a href="{{ route('recipes.version', ['recipe' => $recipe->id, 'version' => $savedVersion['id']]) }}" class="inline-flex rounded-full border border-[var(--color-line)] px-4 py-2 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel)]">
                                    View version
                                </a>
                                <form method="POST" action="{{ route('recipes.use-version-as-current', ['recipe' => $recipe->id, 'version' => $savedVersion['id']]) }}">
                                    @csrf
                                    <button type="submit" class="inline-flex rounded-full border border-[var(--color-line-strong)] bg-white px-4 py-2 text-sm font-medium text-[var(--color-ink-strong)] transition hover:bg-[var(--color-panel)]">
                                        Restore to current formula
                                    </button>
                                </form>
                            </div>
                        </article>
                    @endforeach
                </div>
            </details>
        @endif

        <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @php
                $summaryUnit = $snapshot['draft']['oilUnit'] ?? 'g';
                $summaryValue = $summaryUnit === 'g'
                    ? number_format((float) $selectedOilWeight, 0, '.', '')
                    : rtrim(rtrim(number_format((float) $selectedOilWeight, 2, '.', ''), '0'), '.');
                $summary = [
                    ['label' => $isCosmeticFormula ? 'Total batch quantity' : ($summaryUnit === 'g' ? 'Batch weight' : 'Batch size'), 'value' => $summaryValue, 'unit' => $summaryUnit],
                    ['label' => 'Ingredients', 'value' => count($productionPreview['ingredient_rows'] ?? []), 'unit' => ''],
                    ['label' => 'Packaging items', 'value' => count($packagingPlanRows), 'unit' => ''],
                    ['label' => 'Recorded batches', 'value' => $productionBatches->count(), 'unit' => ''],
                ];
            @endphp
            @foreach ($summary as $card)
                <article class="sk-card p-4">
                    <p class="sk-eyebrow">{{ $card['label'] }}</p>
                    <p class="numeric mt-2 text-2xl font-semibold text-[var(--color-ink-strong)]">{{ $card['value'] }}<span class="ml-1 text-sm font-medium text-[var(--color-ink-soft)]">{{ $card['unit'] }}</span></p>
                </article>
            @endforeach
        </section>

        <section class="grid gap-4 lg:grid-cols-2">
            @if ($canRecordProduction && is_array($productionPreview))
                <form method="POST" action="{{ route('recipes.production-batches.store', ['recipe' => $recipe->id]) }}" class="sk-card p-5">
                    @csrf
                    <input type="hidden" name="recipe_version_id" value="{{ $version->id }}" />
                    <input type="hidden" name="batch_basis" value="{{ old('batch_basis', $formatNumber($productionPreview['batch_basis_value'] ?? $batchContext['batch_basis'])) }}" />

                    <p class="sk-eyebrow">Production</p>
                    <h2 class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">Record production</h2>
                    <p class="mt-1 text-sm text-[var(--color-ink-soft)]">Freeze the saved formula, current prices, packaging plan, and lot annotations into a production batch.</p>

                    @error('costing')
                        <p class="mt-3 rounded-lg border border-[var(--color-warning-soft)] bg-[var(--color-warning-soft)]/35 px-3 py-2 text-sm font-medium text-[var(--color-warning-strong)]">{{ $message }}</p>
                    @enderror

                    @if ($productionPreview['has_unpriced_rows'] ?? false)
                        <p class="mt-3 rounded-lg border border-[var(--color-warning-soft)] bg-[var(--color-warning-soft)]/35 px-3 py-2 text-sm font-medium text-[var(--color-warning-strong)]">
                            Some rows are unpriced. Add prices before recording production.
                        </p>
                    @endif

                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                        <label class="block">
                            <span class="text-sm font-medium text-[var(--color-ink-strong)]">Production batch number</span>
                            <input name="production_batch_number" value="{{ old('production_batch_number', $batchContext['batch_number']) }}" type="text" class="mt-2 w-full rounded-lg border border-[var(--color-line)] bg-[var(--color-field)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]" />
                            @error('production_batch_number')
                                <span class="mt-1 block text-xs font-medium text-[var(--color-danger-strong)]">{{ $message }}</span>
                            @enderror
                        </label>

                        <label class="block">
                            <span class="text-sm font-medium text-[var(--color-ink-strong)]">Manufacture date</span>
                            <input name="manufacture_date" value="{{ old('manufacture_date', $batchContext['manufacture_date']) }}" type="date" class="mt-2 w-full rounded-lg border border-[var(--color-line)] bg-[var(--color-field)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]" />
                            @error('manufacture_date')
                                <span class="mt-1 block text-xs font-medium text-[var(--color-danger-strong)]">{{ $message }}</span>
                            @enderror
                        </label>

                        <div class="block">
                            <span class="text-sm font-medium text-[var(--color-ink-strong)]">{{ $productionPreview['batch_basis_label'] ?? 'Oil quantity' }}</span>
                            <p class="numeric mt-2 rounded-lg border border-[var(--color-line)] bg-[var(--color-panel-strong)] px-3 py-2 text-sm font-medium text-[var(--color-ink-strong)]">
                                {{ old('batch_basis', $formatNumber($productionPreview['batch_basis_value'] ?? $batchContext['batch_basis'])) }}
                                <span class="text-[var(--color-ink-soft)]">{{ $productionPreview['batch_basis_unit'] ?? 'g' }}</span>
                            </p>
                            @error('batch_basis')
                                <span class="mt-1 block text-xs font-medium text-[var(--color-danger-strong)]">{{ $message }}</span>
                            @enderror
                        </div>

                        <label class="block">
                            <span class="text-sm font-medium text-[var(--color-ink-strong)]">Units produced</span>
                            <input name="units_produced" value="{{ old('units_produced', $productionPreview['units_produced'] ?? $batchContext['units_produced']) }}" type="text" inputmode="numeric" class="numeric mt-2 w-full rounded-lg border border-[var(--color-line)] bg-[var(--color-field)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]" />
                            @error('units_produced')
                                <span class="mt-1 block text-xs font-medium text-[var(--color-danger-strong)]">{{ $message }}</span>
                            @enderror
                        </label>
                    </div>

                    @if (($productionPreview['ingredient_rows'] ?? []) !== [])
                        <div class="mt-4 rounded-lg border border-[var(--color-line)] bg-white">
                            <div class="border-b border-[var(--color-line)] px-3 py-2">
                                <h3 class="text-sm font-semibold text-[var(--color-ink-strong)]">Ingredient lot numbers</h3>
                            </div>
                            <div class="divide-y divide-[var(--color-line)]">
                                @foreach ($productionPreview['ingredient_rows'] as $ingredientRow)
                                    <label class="grid gap-2 px-3 py-3 md:grid-cols-[minmax(0,1fr)_9rem]">
                                        <span>
                                            <span class="block text-sm font-medium text-[var(--color-ink-strong)]">{{ $ingredientRow['ingredient_name'] }}</span>
                                            <span class="numeric mt-1 block text-xs text-[var(--color-ink-soft)]">{{ $formatNumber($ingredientRow['quantity'], 2) }} {{ $ingredientRow['unit'] }}</span>
                                        </span>
                                        <input name="ingredient_lot_numbers[{{ $ingredientRow['lot_key'] }}]" value="{{ old('ingredient_lot_numbers.'.$ingredientRow['lot_key']) }}" type="text" placeholder="Lot #" class="w-full rounded-lg border border-[var(--color-line)] bg-[var(--color-field)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]" />
                                    </label>
                                    @error('ingredient_lot_numbers.'.$ingredientRow['lot_key'])
                                        <span class="block px-3 pb-3 text-xs font-medium text-[var(--color-danger-strong)]">{{ $message }}</span>
                                    @enderror
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <label class="mt-3 block">
                        <span class="text-sm font-medium text-[var(--color-ink-strong)]">Production notes</span>
                        <textarea name="production_notes" rows="2" class="mt-2 w-full rounded-lg border border-[var(--color-line)] bg-[var(--color-field)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]" placeholder="Trace, pH, ambient conditions, deviations…">{{ old('production_notes') }}</textarea>
                        @error('production_notes')
                            <span class="mt-1 block text-xs font-medium text-[var(--color-danger-strong)]">{{ $message }}</span>
                        @enderror
                    </label>

                    <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
                        <dl class="grid grid-cols-2 gap-x-6 gap-y-1 text-sm sm:grid-cols-4">
                            <div>
                                <dt class="text-xs text-[var(--color-ink-soft)]">Ingredient cost</dt>
                                <dd class="numeric font-semibold text-[var(--color-ink-strong)]">{{ $formatMoney($productionPreview['ingredient_total'] ?? 0, $productionPreview['currency'] ?? 'EUR') }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-[var(--color-ink-soft)]">Packaging cost</dt>
                                <dd class="numeric font-semibold text-[var(--color-ink-strong)]">
                                    @if (($productionPreview['units_produced'] ?? null) !== null)
                                        {{ $formatMoney($productionPreview['packaging_total'] ?? 0, $productionPreview['currency'] ?? 'EUR') }}
                                    @else
                                        <span class="text-[var(--color-ink-soft)]">Set units produced</span>
                                    @endif
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs text-[var(--color-ink-soft)]">Total cost</dt>
                                <dd class="numeric font-semibold text-[var(--color-ink-strong)]">{{ $formatMoney($productionPreview['total_cost'] ?? 0, $productionPreview['currency'] ?? 'EUR') }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-[var(--color-ink-soft)]">Cost per finished unit</dt>
                                <dd class="numeric font-semibold text-[var(--color-ink-strong)]">
                                    @if (($productionPreview['cost_per_unit'] ?? null) !== null)
                                        {{ $formatMoney($productionPreview['cost_per_unit'], $productionPreview['currency'] ?? 'EUR') }}
                                    @else
                                        <span class="text-[var(--color-ink-soft)]">Not set</span>
                                    @endif
                                </dd>
                            </div>
                        </dl>
                        <button type="submit" class="rounded-full bg-[var(--color-ink-strong)] px-4 py-2 text-sm font-medium text-white transition hover:bg-[var(--color-accent-strong)]">
                            Record production
                        </button>
                    </div>
                </form>
            @endif

            @if ($productionBatches->isNotEmpty() || $canRecordProduction)
                <article class="sk-card flex flex-col">
                    <div class="border-b border-[var(--color-line)] px-5 py-4">
                        <p class="sk-eyebrow">History</p>
                        <h2 class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">Production history</h2>
                    </div>

                    @if ($productionBatches->isNotEmpty())
                        <div class="divide-y divide-[var(--color-line)]">
                            @foreach ($productionBatches as $productionBatch)
                                <div class="flex flex-col gap-2 px-5 py-3 lg:flex-row lg:items-center lg:justify-between">
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium text-[var(--color-ink-strong)]">
                                            <span class="numeric">{{ $productionBatch->manufacture_date?->format('Y-m-d') }}</span>
                                            <span class="text-[var(--color-ink-soft)]"> · </span>
                                            {{ $productionBatch->production_batch_number ?: 'No batch number' }}
                                        </p>
                                        <p class="numeric mt-0.5 text-xs text-[var(--color-ink-soft)]">
                                            {{ $formatNumber($productionBatch->batch_basis_value) }} {{ $productionBatch->batch_basis_unit }} · {{ $productionBatch->units_produced }} units · {{ $formatMoney($productionBatch->cost_per_unit, $productionBatch->currency) }}/unit
                                        </p>
                                    </div>
                                    <div class="flex gap-3 text-sm font-medium">
                                        <a href="{{ route('production-batches.show', $productionBatch) }}" class="text-[var(--color-accent-strong)] transition hover:text-[var(--color-accent)]">View</a>
                                        <a href="{{ route('production-batches.print', $productionBatch) }}" class="text-[var(--color-accent-strong)] transition hover:text-[var(--color-accent)]">Print</a>
                                        @can('delete', $productionBatch)
                                            <form method="POST" action="{{ route('production-batches.destroy', $productionBatch) }}" x-data="{ confirming: false }" @submit.prevent="confirming ? $el.submit() : (confirming = true)" class="inline">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" :class="confirming ? 'text-[var(--color-danger-strong)]' : 'text-[var(--color-ink-soft)] hover:text-[var(--color-danger-strong)]'" class="transition">
                                                    <span x-show="!confirming">Delete</span>
                                                    <span x-show="confirming" x-cloak>Confirm?</span>
                                                </button>
                                            </form>
                                        @endcan
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="px-5 py-8 text-center text-sm text-[var(--color-ink-soft)]">
                            No production batches recorded yet.
                        </div>
                    @endif
                </article>
            @else
                <article class="sk-card p-5">
                    <p class="sk-eyebrow">Production</p>
                    <h2 class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">Production</h2>
                    <p class="mt-2 text-sm text-[var(--color-ink-soft)]">You can view this saved formula, but only the owner can record production batches for it.</p>
                </article>
            @endif
        </section>

        <section class="sk-card overflow-hidden">
            <div class="border-b border-[var(--color-line)] px-5 py-4">
                <p class="sk-eyebrow">Packaging plan</p>
                <h2 class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">Packaging plan</h2>
                <p class="mt-1 text-sm text-[var(--color-ink-soft)]">Selected packaging items for one finished unit. Edit the packaging on the workbench, then save a new version to refresh this list.</p>
            </div>

            @if ($packagingPlanRows !== [])
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-[var(--color-panel)] text-xs uppercase tracking-[0.14em] text-[var(--color-ink-soft)]">
                            <tr>
                                <th class="px-5 py-3">Item</th>
                                <th class="numeric px-5 py-3">Per unit</th>
                                <th class="numeric px-5 py-3">Unit cost</th>
                                <th class="px-5 py-3">Notes</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--color-line)] bg-white">
                            @foreach ($packagingPlanRows as $packagingRow)
                                <tr>
                                    <td class="px-5 py-3 font-medium text-[var(--color-ink-strong)]">{{ $packagingRow['name'] }}</td>
                                    <td class="numeric px-5 py-3 text-[var(--color-ink-soft)]">{{ $formatNumber($packagingRow['components_per_unit'], 3) }} per unit</td>
                                    <td class="numeric px-5 py-3 text-[var(--color-ink-soft)]">
                                        @if ($packagingRow['catalog_price'] !== null)
                                            {{ $packagingRow['currency'] }} {{ $formatNumber($packagingRow['catalog_price'], 4) }}
                                        @else
                                            <span class="text-[var(--color-warning-strong)]">Not priced</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3 text-[var(--color-ink-soft)]">{{ $packagingRow['notes'] ?: '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="px-5 py-8 text-center">
                    <p class="text-sm text-[var(--color-ink-soft)]">No packaging items in this formula's plan yet.</p>
                    <a href="{{ route('recipes.edit', $recipe->id) }}" class="mt-3 inline-flex rounded-full border border-[var(--color-line)] px-4 py-2 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel)]">
                        Add packaging on the workbench
                    </a>
                </div>
            @endif
        </section>

        <section class="grid gap-4 lg:grid-cols-2">
            <article class="sk-card p-5">
                <p class="sk-eyebrow">Selected ingredients</p>
                <h2 class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">Final ingredient list</h2>
                <p class="mt-1 text-sm text-[var(--color-ink-soft)]">As it will appear on the label after processing.</p>
                <p class="mt-4 text-[0.98rem] leading-8 font-medium tracking-[0.01em] [font-stretch:88%] text-[var(--color-ink-strong)]">
                    {{ $finalIngredientListText ?: 'No final ingredient list generated yet.' }}
                </p>
            </article>

            <article class="sk-card p-5">
                <p class="sk-eyebrow">Selected ingredients</p>
                <h2 class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">Plain-language list</h2>
                <p class="mt-1 text-sm text-[var(--color-ink-soft)]">Consumer-friendly wording without the INCI chemistry names.</p>
                <p class="mt-4 text-[0.98rem] leading-8 font-medium tracking-[0.01em] [font-stretch:88%] text-[var(--color-ink-strong)]">
                    {{ $plainLanguageListText ?: 'No plain-language list generated yet.' }}
                </p>
            </article>
        </section>

        @if (session('status'))
            <div role="status" class="rounded-xl border border-[var(--color-success-soft)] bg-[var(--color-success-soft)] px-6 py-4 text-sm text-[var(--color-success-strong)]">
                {{ session('status') }}
            </div>
        @endif
    </div>
@endsection
