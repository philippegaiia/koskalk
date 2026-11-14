@extends('layouts.app-shell')

@section('title', $recipe->name.' · '.__('formula_documents.title').' · '.config('app.name'))
@section('page_heading', __('formula_documents.title'))

@section('content')
    @php
        /** @var array<string, string>|null $currentReplaceConfirmation */
        $currentReplaceConfirmation = session('currentReplaceConfirmation');
        $printQuery = [
            'recipe' => $recipe->public_id,
            'oil_weight' => $selectedOilWeight,
        ];

        foreach (['batch_number', 'batch_basis', 'manufacture_date', 'units_produced'] as $batchQueryKey) {
            if (filled($batchContext[$batchQueryKey] ?? null)) {
                $printQuery[$batchQueryKey] = $batchContext[$batchQueryKey];
            }
        }

        $canRecordProduction = (bool) ($canRecordProduction ?? false);
        $canUpdateRecipe = (bool) ($canUpdateRecipe ?? false);
        $canDuplicateRecipe = (bool) ($canDuplicateRecipe ?? false);
        $canRestoreVersion = (bool) ($canRestoreVersion ?? false);
        $isHistorical = (bool) ($isHistorical ?? false);
        if ($isHistorical) {
            $printQuery['version'] = $version->public_id;
        }
        $productionPreview = $productionPreview ?? null;
        $productionBatches = $productionBatches ?? null;
        $batchSearch = $batchSearch ?? '';
        $otherSavedVersions = collect($recoverySnapshots ?? [])
            ->reject(fn (array $savedVersion): bool => (bool) ($savedVersion['is_current'] ?? false));
        $sheetRoute = $isHistorical
            ? route('recipes.version', ['recipe' => $recipe, 'version' => $version])
            : route('recipes.saved', ['recipe' => $recipe]);
        $isCosmeticFormula = $recipe->productFamily?->calculation_basis === 'total_formula';
        $formatNumber = fn (mixed $value, int $precision = 2): string => rtrim(rtrim(number_format((float) $value, $precision, '.', ''), '0'), '.');
        $formatMoney = fn (mixed $value, string $currency): string => $formatNumber($value, 2).' '.$currency;
        $initialUnitsProduced = old('units_produced', $productionPreview['units_produced'] ?? $batchContext['units_produced']);
        $initialUnitsProduced = is_scalar($initialUnitsProduced) ? (string) $initialUnitsProduced : '';
        $previewCurrency = $productionPreview['currency'] ?? 'EUR';
        $productionCostPreview = [
            'unitsProduced' => $initialUnitsProduced,
            'ingredientCost' => $productionPreview['ingredient_total'] ?? 0,
            'packagingCostPerUnit' => collect($productionPreview['packaging_rows'] ?? [])->sum('cost_per_finished_unit'),
            'currency' => $previewCurrency,
        ];
    @endphp

    <div class="mx-auto max-w-app space-y-6" x-data="productionCostPreview({{ Js::from($productionCostPreview) }})">
        @error('expected_revision')
            <p role="alert">{{ $message }}</p>
        @enderror

        @if ($canUpdateRecipe && is_array($currentReplaceConfirmation))
            <section class="rounded-xl border border-[var(--color-warning-soft)] bg-[var(--color-warning-soft)]/35 p-5">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div class="min-w-0">
                        <p class="sk-eyebrow">{{ __('formula_documents.replace_confirmation.eyebrow') }}</p>
                        <h2 class="mt-2 text-lg font-semibold text-[var(--color-ink-strong)]">
                            {{ $currentReplaceConfirmation['title'] ?? __('formula_documents.replace_confirmation.title') }}
                        </h2>
                        <p class="mt-2 max-w-3xl text-sm leading-7 text-[var(--color-ink-soft)]">
                            {{ $currentReplaceConfirmation['body'] ?? __('formula_documents.replace_confirmation.body') }}
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <form method="POST" action="{{ $currentReplaceConfirmation['action_url'] ?? route('recipes.saved', $recipe) }}">
                            @csrf
                            <input type="hidden" name="expected_revision" value="{{ $recipe->edit_revision }}">
                            <input type="hidden" name="confirm_replace_current" value="1" />
                            <button type="submit" class="sk-btn sk-btn-primary min-h-11">
                                {{ $currentReplaceConfirmation['action_label'] ?? __('formula_documents.replace_confirmation.action') }}
                            </button>
                        </form>
                        <a href="{{ route('recipes.saved', $recipe) }}" class="sk-btn sk-btn-outline min-h-11">
                            {{ __('formula_documents.replace_confirmation.keep') }}
                        </a>
                    </div>
                </div>
            </section>
        @endif

        <section class="sk-card p-5">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="sk-eyebrow">{{ __('formula_documents.title') }}</p>
                        @if ($isHistorical)
                            <span class="rounded-full border border-[var(--color-warning-soft)] bg-[var(--color-warning-soft)] px-3 py-1 text-xs font-medium text-[var(--color-ink-strong)]">{{ __('formula_documents.states.history') }}</span>
                        @else
                            <span class="rounded-full border border-[var(--color-success-soft)] bg-[var(--color-success-soft)] px-3 py-1 text-xs font-medium text-[var(--color-success-strong)]">{{ __('formula_documents.states.saved') }}</span>
                        @endif
                    </div>
                    <h1 class="mt-2 text-2xl font-semibold text-[var(--color-ink-strong)]">{{ $recipe->name }}</h1>
                    <p class="mt-2 max-w-3xl text-sm text-[var(--color-ink-soft)]">{{ __('formula_documents.intro') }}</p>

                    <div class="mt-4 flex flex-wrap gap-2">
                        @if ($isHistorical)
                            <a href="{{ route('recipes.saved', $recipe) }}" class="sk-btn sk-btn-outline min-h-11">
                                {{ __('formula_documents.actions.back_to_current') }}
                            </a>
                        @endif
                        <a href="{{ route('recipes.edit', $recipe) }}" class="sk-btn sk-btn-outline min-h-11">
                            {{ __('formula_documents.actions.open') }}
                        </a>
                        @if ($canDuplicateRecipe)
                            <form method="POST" action="{{ route('recipes.duplicate', $recipe) }}">
                                @csrf
                                <button type="submit" class="sk-btn sk-btn-outline min-h-11">
                                    {{ __('products.actions.duplicate') }}
                                </button>
                            </form>
                        @endif
                        @if ($canShareFormula ?? false)
                            @can('share', $recipe)
                                <a href="{{ route('formula-shares.create', $recipe) }}" class="sk-btn sk-btn-outline min-h-11">{{ __('sharing.share') }}</a>
                            @endcan
                        @endif
                        <form method="GET" action="{{ route('recipes.print.recipe', ['recipe' => $recipe]) }}" class="flex flex-wrap items-center gap-2">
                            @foreach (collect($printQuery)->except('recipe') as $key => $value)
                                <input type="hidden" name="{{ $key }}" value="{{ $value }}" />
                            @endforeach
                            <button type="submit" class="sk-btn sk-btn-outline min-h-11 gap-2 whitespace-nowrap">
                                <x-action-icon name="printer" />{{ __('formula_documents.actions.print_sheet') }}
                            </button>
                        </form>
                        <div x-data="{ savedExportMenuOpen: false }" @click.outside="savedExportMenuOpen = false" class="relative">
                            <button x-ref="savedExportMenuTrigger" type="button" aria-haspopup="menu" :aria-expanded="savedExportMenuOpen" @click="savedExportMenuOpen = !savedExportMenuOpen; if (savedExportMenuOpen) $nextTick(() => $refs.savedExcelExport.focus())" class="sk-btn sk-btn-outline min-h-11 gap-2 whitespace-nowrap">
                                <x-action-icon name="download" />{{ __('formula_documents.actions.export_formula') }}<x-action-icon name="chevron-down" />
                            </button>
                            <div x-show="savedExportMenuOpen" x-cloak role="menu" @keydown.escape.stop.prevent="savedExportMenuOpen = false; $refs.savedExportMenuTrigger.focus()" class="absolute right-0 top-full z-20 mt-2 min-w-48 rounded-xl border border-[var(--color-line)] bg-[var(--color-panel)] p-1 shadow-lg">
                                <a x-ref="savedExcelExport" role="menuitem" href="{{ route('recipes.export.xlsx', $printQuery) }}" @keydown.arrow-down.prevent="$refs.savedCsvExport.focus()" @keydown.arrow-up.prevent="$refs.savedCsvExport.focus()" class="block min-h-11 rounded-lg px-3 py-2 text-sm font-medium text-[var(--color-ink-strong)] hover:bg-[var(--color-panel-strong)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-[var(--color-accent)]">{{ __('formula_documents.actions.export_xlsx_option') }}</a>
                                <a x-ref="savedCsvExport" role="menuitem" href="{{ route('recipes.export.csv', $printQuery) }}" @keydown.arrow-down.prevent="$refs.savedExcelExport.focus()" @keydown.arrow-up.prevent="$refs.savedExcelExport.focus()" class="block min-h-11 rounded-lg px-3 py-2 text-sm font-medium text-[var(--color-ink-strong)] hover:bg-[var(--color-panel-strong)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-[var(--color-accent)]">{{ __('formula_documents.actions.export_csv_option') }}</a>
                            </div>
                        </div>
                    </div>
                </div>

                <form method="GET" action="{{ $sheetRoute }}" class="sk-inset p-4 lg:min-w-[16rem]">
                    @foreach (collect($printQuery)->only(['batch_number', 'manufacture_date']) as $key => $value)
                        <input type="hidden" name="{{ $key }}" value="{{ $value }}" />
                    @endforeach
                    @if ($canRecordProduction || filled($initialUnitsProduced))
                        <input type="hidden" name="units_produced" value="{{ $initialUnitsProduced }}" x-bind:value="unitsProduced" />
                    @endif
                    @if ($batchSearch !== '')
                        <input type="hidden" name="batch_search" value="{{ $batchSearch }}" />
                    @endif
                    <p class="sk-eyebrow">{{ __('formula_documents.scale.title') }}</p>
                    <label class="mt-2 block text-sm font-medium text-[var(--color-ink-strong)]" for="oil_weight">{{ $isCosmeticFormula ? __('formula_documents.scale.total_batch_quantity') : __('formula_documents.scale.oil_quantity') }}</label>
                    <div class="mt-2 flex items-center gap-2">
                        <input id="oil_weight" name="oil_weight" type="number" min="0.01" step="0.01" value="{{ rtrim(rtrim(number_format($selectedOilWeight, 2, '.', ''), '0'), '.') }}" class="numeric w-full rounded-lg border border-[var(--color-line)] bg-[var(--color-field)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]" />
                        <span class="numeric rounded-full border border-[var(--color-line)] bg-white px-3 py-1.5 text-xs font-medium text-[var(--color-ink-soft)]">{{ $snapshot['draft']['oilUnit'] ?? 'g' }}</span>
                    </div>
                    <div class="mt-3 flex gap-2">
                        <button type="submit" class="sk-btn sk-btn-primary min-h-11">
                            {{ __('formula_documents.actions.recalculate') }}
                        </button>
                        <a href="{{ $sheetRoute }}" class="sk-btn sk-btn-outline min-h-11">
                            {{ __('formula_documents.actions.reset') }}
                        </a>
                    </div>
                </form>
            </div>
        </section>

        @if ($otherSavedVersions->isNotEmpty())
            <details class="sk-card p-5">
                <summary class="cursor-pointer text-sm font-semibold text-[var(--color-ink-strong)]">{{ __('formula_documents.history.title') }}</summary>

                <div class="mt-4 space-y-3">
                    @foreach ($otherSavedVersions as $savedVersion)
                        <article class="flex flex-col gap-3 rounded-xl border border-[var(--color-line)] p-4 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <p class="font-medium text-[var(--color-ink-strong)]">{{ __('formula_documents.history.entry') }}</p>
                                <p class="numeric mt-1 text-xs text-[var(--color-ink-soft)]">
                                    {{ filled($savedVersion['saved_at'] ?? null) ? \Illuminate\Support\Carbon::parse($savedVersion['saved_at'])->format('Y-m-d H:i') : __('formula_documents.history.date_not_recorded') }}
                                </p>
                            </div>

                            <div class="flex flex-wrap gap-2">
                                <a href="{{ route('recipes.version', ['recipe' => $recipe, 'version' => $savedVersion['public_id']]) }}" class="inline-flex rounded-full border border-[var(--color-line)] px-4 py-2 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel)]">
                                    {{ __('formula_documents.actions.view_saved') }}
                                </a>
                                @if ($canRestoreVersion)
                                    <form method="POST" action="{{ route('recipes.use-version-as-current', ['recipe' => $recipe, 'version' => $savedVersion['public_id']]) }}">
                                        @csrf
                                        <input type="hidden" name="expected_revision" value="{{ $recipe->edit_revision }}">
                                        <button type="submit" class="sk-btn sk-btn-outline min-h-11">
                                            {{ __('formula_documents.actions.use_as_current') }}
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
            </details>
        @endif

        @include('recipes.partials.version-sheet', [
            'formulaDocument' => $formulaDocument,
            'compositionExportUrl' => route('recipes.export.composition', $printQuery),
        ])

        <section class="grid gap-4 lg:grid-cols-2">
            @if ($canRecordProduction && is_array($productionPreview))
                <form method="POST" action="{{ route('recipes.production-batches.store', ['recipe' => $recipe]) }}" class="sk-card p-5">
                    @csrf
                    <input type="hidden" name="recipe_version_id" value="{{ $version->id }}" />
                    <input type="hidden" name="batch_basis" value="{{ old('batch_basis', $formatNumber($productionPreview['batch_basis_value'] ?? $batchContext['batch_basis'])) }}" />

                    <p class="sk-eyebrow">{{ __('batch_log.record.eyebrow') }}</p>
                    <h2 class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('batch_log.record.title') }}</h2>
                    <p class="mt-1 text-sm text-[var(--color-ink-soft)]">{{ __('batch_log.record.intro') }}</p>

                    @error('costing')
                        <p class="mt-3 rounded-lg border border-[var(--color-warning-soft)] bg-[var(--color-warning-soft)]/35 px-3 py-2 text-sm font-medium text-[var(--color-warning-strong)]">{{ $message }}</p>
                    @enderror

                    @if ($productionPreview['has_unpriced_rows'] ?? false)
                        <p class="mt-3 rounded-lg border border-[var(--color-warning-soft)] bg-[var(--color-warning-soft)]/35 px-3 py-2 text-sm font-medium text-[var(--color-warning-strong)]">
                            {{ $productionPreview['calculation_error'] ?? __('batch_log.record.unpriced') }}
                        </p>
                    @endif

                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                        <label class="block">
                            <span class="text-sm font-medium text-[var(--color-ink-strong)]">{{ __('batch_log.fields.batch_number') }}</span>
                            <input name="production_batch_number" value="{{ old('production_batch_number', $batchContext['batch_number']) }}" type="text" class="mt-2 w-full rounded-lg border border-[var(--color-line)] bg-[var(--color-field)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]" />
                            @error('production_batch_number')
                                <span class="mt-1 block text-xs font-medium text-[var(--color-danger-strong)]">{{ $message }}</span>
                            @enderror
                        </label>

                        <label class="block">
                            <span class="text-sm font-medium text-[var(--color-ink-strong)]">{{ __('batch_log.fields.manufacture_date') }}</span>
                            <input name="manufacture_date" value="{{ old('manufacture_date', $batchContext['manufacture_date']) }}" type="date" class="mt-2 w-full rounded-lg border border-[var(--color-line)] bg-[var(--color-field)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]" />
                            @error('manufacture_date')
                                <span class="mt-1 block text-xs font-medium text-[var(--color-danger-strong)]">{{ $message }}</span>
                            @enderror
                        </label>

                        <div class="block">
                            <span class="text-sm font-medium text-[var(--color-ink-strong)]">{{ $isCosmeticFormula ? __('formula_documents.scale.total_batch_quantity') : __('formula_documents.scale.oil_quantity') }}</span>
                            <p class="numeric mt-2 rounded-lg border border-[var(--color-line)] bg-[var(--color-panel-strong)] px-3 py-2 text-sm font-medium text-[var(--color-ink-strong)]">
                                {{ old('batch_basis', $formatNumber($productionPreview['batch_basis_value'] ?? $batchContext['batch_basis'])) }}
                                <span class="text-[var(--color-ink-soft)]">{{ $productionPreview['batch_basis_unit'] ?? 'g' }}</span>
                            </p>
                            @error('batch_basis')
                                <span class="mt-1 block text-xs font-medium text-[var(--color-danger-strong)]">{{ $message }}</span>
                            @enderror
                        </div>

                        <label class="block">
                            <span class="text-sm font-medium text-[var(--color-ink-strong)]">{{ __('batch_log.fields.units_produced') }}</span>
                            <input name="units_produced" value="{{ $initialUnitsProduced }}" x-model="unitsProduced" type="text" inputmode="numeric" class="numeric mt-2 w-full rounded-lg border border-[var(--color-line)] bg-[var(--color-field)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]" />
                            @error('units_produced')
                                <span class="mt-1 block text-xs font-medium text-[var(--color-danger-strong)]">{{ $message }}</span>
                            @enderror
                        </label>
                    </div>

                    @if (($productionPreview['ingredient_rows'] ?? []) !== [])
                        <div class="mt-4 rounded-lg border border-[var(--color-line)] bg-white">
                            <div class="border-b border-[var(--color-line)] px-3 py-2">
                                <h3 class="text-sm font-semibold text-[var(--color-ink-strong)]">{{ __('batch_log.fields.lot_numbers') }}</h3>
                            </div>
                            <div class="divide-y divide-[var(--color-line)]">
                                @foreach ($productionPreview['ingredient_rows'] as $ingredientRow)
                                    <label class="grid gap-2 px-3 py-3 md:grid-cols-[minmax(0,1fr)_9rem]">
                                        <span>
                                            <span class="block text-sm font-medium text-[var(--color-ink-strong)]">{{ $ingredientRow['ingredient_name'] }}</span>
                                            <span class="numeric mt-1 block text-xs text-[var(--color-ink-soft)]">{{ $formatNumber($ingredientRow['quantity'], 2) }} {{ $ingredientRow['unit'] }}</span>
                                        </span>
                                        <input name="ingredient_lot_numbers[{{ $ingredientRow['lot_key'] }}]" value="{{ old('ingredient_lot_numbers.'.$ingredientRow['lot_key']) }}" type="text" placeholder="{{ __('batch_log.fields.lot_placeholder') }}" class="w-full rounded-lg border border-[var(--color-line)] bg-[var(--color-field)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]" />
                                    </label>
                                    @error('ingredient_lot_numbers.'.$ingredientRow['lot_key'])
                                        <span class="block px-3 pb-3 text-xs font-medium text-[var(--color-danger-strong)]">{{ $message }}</span>
                                    @enderror
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <label class="mt-3 block">
                        <span class="text-sm font-medium text-[var(--color-ink-strong)]">{{ __('batch_log.fields.notes') }}</span>
                        <textarea name="production_notes" rows="2" class="mt-2 w-full rounded-lg border border-[var(--color-line)] bg-[var(--color-field)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]" placeholder="{{ __('batch_log.fields.notes_placeholder') }}">{{ old('production_notes') }}</textarea>
                        @error('production_notes')
                            <span class="mt-1 block text-xs font-medium text-[var(--color-danger-strong)]">{{ $message }}</span>
                        @enderror
                    </label>

                    <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
                        <dl aria-live="polite" class="grid grid-cols-2 gap-x-6 gap-y-1 text-sm sm:grid-cols-4">
                            <div>
                                <dt class="text-xs text-[var(--color-ink-soft)]">{{ __('batch_log.costs.ingredient') }}</dt>
                                <dd class="numeric font-semibold text-[var(--color-ink-strong)]">{{ $formatMoney($productionPreview['ingredient_total'] ?? 0, $productionPreview['currency'] ?? 'EUR') }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-[var(--color-ink-soft)]">{{ __('batch_log.costs.packaging') }}</dt>
                                <dd x-text="unitCount === null ? {{ Js::from(__('batch_log.costs.set_units')) }} : money(packagingCost)" class="numeric font-semibold text-[var(--color-ink-strong)]">
                                    @if (($productionPreview['units_produced'] ?? null) !== null)
                                        {{ $formatMoney($productionPreview['packaging_total'] ?? 0, $productionPreview['currency'] ?? 'EUR') }}
                                    @else
                                        <span class="text-[var(--color-ink-soft)]">{{ __('batch_log.costs.set_units') }}</span>
                                    @endif
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs text-[var(--color-ink-soft)]">{{ __('batch_log.costs.total') }}</dt>
                                <dd x-text="money(totalCost)" class="numeric font-semibold text-[var(--color-ink-strong)]">{{ $formatMoney($productionPreview['total_cost'] ?? 0, $previewCurrency) }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-[var(--color-ink-soft)]">{{ __('batch_log.costs.per_finished_unit') }}</dt>
                                <dd x-text="costPerUnit === null ? {{ Js::from(__('batch_log.costs.not_set')) }} : money(costPerUnit)" class="numeric font-semibold text-[var(--color-ink-strong)]">
                                    @if (($productionPreview['cost_per_unit'] ?? null) !== null)
                                        {{ $formatMoney($productionPreview['cost_per_unit'], $productionPreview['currency'] ?? 'EUR') }}
                                    @else
                                        <span class="text-[var(--color-ink-soft)]">{{ __('batch_log.costs.not_set') }}</span>
                                    @endif
                                </dd>
                            </div>
                        </dl>
                        <button type="submit" class="sk-btn sk-btn-primary min-h-11">
                            {{ __('batch_log.actions.record') }}
                        </button>
                    </div>
                </form>
            @endif

            @if ($productionBatches?->isNotEmpty() || $productionBatches?->hasPages() || $batchSearch !== '' || $canRecordProduction)
                <article id="production-history" class="sk-card flex flex-col">
                    <div class="border-b border-[var(--color-line)] px-5 py-4">
                        <p class="sk-eyebrow">{{ __('batch_log.history.eyebrow') }}</p>
                        <h2 class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('batch_log.history.title') }}</h2>
                        <form method="GET" action="{{ $sheetRoute }}#production-history" class="mt-3 flex flex-wrap items-center gap-2">
                            @foreach (collect($printQuery)->except(['recipe', 'version', 'units_produced']) as $key => $value)
                                <input type="hidden" name="{{ $key }}" value="{{ $value }}" />
                            @endforeach
                            @if ($canRecordProduction || filled($initialUnitsProduced))
                                <input type="hidden" name="units_produced" value="{{ $initialUnitsProduced }}" x-bind:value="unitsProduced" />
                            @endif
                            <label for="batch_search" class="sr-only">{{ __('batch_log.history.search') }}</label>
                            <input id="batch_search" name="batch_search" type="search" value="{{ $batchSearch }}" maxlength="120" placeholder="{{ __('batch_log.history.search') }}" class="min-h-11 min-w-0 flex-1 rounded-lg border border-[var(--color-line)] bg-[var(--color-field)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] focus:outline-2 focus:outline-[var(--color-accent)]" />
                            <button type="submit" class="sk-btn sk-btn-outline min-h-11">{{ __('products.filters.search.label') }}</button>
                        </form>
                    </div>

                    @if ($productionBatches?->isNotEmpty())
                        <div class="divide-y divide-[var(--color-line)]">
                            @foreach ($productionBatches as $productionBatch)
                                <div class="flex flex-col gap-2 px-5 py-3 lg:flex-row lg:items-center lg:justify-between">
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium text-[var(--color-ink-strong)]">
                                            {{ $productionBatch->recipe_name }}
                                        </p>
                                        <p class="mt-0.5 text-xs text-[var(--color-ink-soft)]">
                                            <span class="numeric">{{ $productionBatch->manufacture_date?->format('Y-m-d') }}</span>
                                            <span class="text-[var(--color-ink-soft)]"> · </span>
                                            {{ $productionBatch->production_batch_number ?: __('batch_log.snapshot.no_batch_number') }}
                                        </p>
                                        <p class="numeric mt-0.5 text-xs text-[var(--color-ink-soft)]">
                                            {{ __('batch_log.history.summary', ['quantity' => $formatNumber($productionBatch->batch_basis_value).' '.$productionBatch->batch_basis_unit, 'units' => $productionBatch->units_produced, 'cost' => $formatMoney($productionBatch->cost_per_unit, $productionBatch->currency)]) }}
                                        </p>
                                    </div>
                                    <div class="flex gap-3 text-sm font-medium">
                                        <a href="{{ route('production-batches.show', $productionBatch) }}" class="text-[var(--color-accent-strong)] transition hover:text-[var(--color-accent)]">{{ __('batch_log.actions.view') }}</a>
                                        <a href="{{ route('production-batches.print', $productionBatch) }}" class="text-[var(--color-accent-strong)] transition hover:text-[var(--color-accent)]">{{ __('batch_log.actions.print') }}</a>
                                        @can('delete', $productionBatch)
                                            <form method="POST" action="{{ route('production-batches.destroy', $productionBatch) }}" x-data="{ confirming: false }" @submit.prevent="confirming ? $el.submit() : (confirming = true)" class="inline">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" :class="confirming ? 'text-[var(--color-danger-strong)]' : 'text-[var(--color-ink-soft)] hover:text-[var(--color-danger-strong)]'" class="transition">
                                                    <span x-show="!confirming">{{ __('batch_log.actions.delete') }}</span>
                                                    <span x-show="confirming" x-cloak>{{ __('batch_log.actions.confirm') }}</span>
                                                </button>
                                            </form>
                                        @endcan
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="px-5 py-8 text-center text-sm text-[var(--color-ink-soft)]">
                            {{ __($batchSearch === '' ? 'batch_log.history.empty' : 'batch_log.history.no_matches') }}
                        </div>
                    @endif

                    @if ($productionBatches?->hasPages())
                        <nav class="flex justify-between gap-2 border-t border-[var(--color-line)] px-5 py-3" aria-label="{{ __('table.pagination.label') }}">
                            @if ($productionBatches->previousPageUrl())
                                <a href="{{ $productionBatches->previousPageUrl() }}" rel="prev" class="sk-btn sk-btn-outline min-h-11">{{ __('table.pagination.previous') }}</a>
                            @endif
                            @if ($productionBatches->nextPageUrl())
                                <a href="{{ $productionBatches->nextPageUrl() }}" rel="next" class="sk-btn sk-btn-outline ms-auto min-h-11">{{ __('table.pagination.next') }}</a>
                            @endif
                        </nav>
                    @endif
                </article>
            @else
                <article class="sk-card p-5">
                    <p class="sk-eyebrow">{{ __('batch_log.access.eyebrow') }}</p>
                    <h2 class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('batch_log.access.title') }}</h2>
                    @if ($canUpdateRecipe)
                        <p class="mt-2 text-sm text-[var(--color-ink-soft)]">{{ __('batch_log.access.plan_excludes') }}</p>
                    @else
                        <p class="mt-2 text-sm text-[var(--color-ink-soft)]">{{ __('batch_log.access.owner_only') }}</p>
                    @endif
                </article>
            @endif
        </section>

        <section class="sk-card overflow-hidden">
            <div class="border-b border-[var(--color-line)] px-5 py-4">
                <p class="sk-eyebrow">{{ __('formula_documents.packaging_plan.title') }}</p>
                <h2 class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('formula_documents.packaging_plan.title') }}</h2>
                <p class="mt-1 text-sm text-[var(--color-ink-soft)]">{{ __('formula_documents.packaging_plan.intro') }}</p>
            </div>

            @if ($packagingPlanRows !== [])
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-[var(--color-panel)] text-xs uppercase tracking-[0.14em] text-[var(--color-ink-soft)]">
                            <tr>
                                <th class="px-5 py-3">{{ __('formula_documents.packaging_plan.item') }}</th>
                                <th class="numeric px-5 py-3">{{ __('formula_documents.packaging_plan.per_unit') }}</th>
                                <th class="numeric px-5 py-3">{{ __('formula_documents.packaging_plan.unit_cost') }}</th>
                                <th class="px-5 py-3">{{ __('formula_documents.packaging_plan.notes') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--color-line)] bg-white">
                            @foreach ($packagingPlanRows as $packagingRow)
                                <tr>
                                    <td class="px-5 py-3 font-medium text-[var(--color-ink-strong)]">{{ $packagingRow['name'] }}</td>
                                    <td class="numeric px-5 py-3 text-[var(--color-ink-soft)]">{{ __('formula_documents.packaging_plan.per_unit_value', ['count' => $formatNumber($packagingRow['components_per_unit'], 3)]) }}</td>
                                    <td class="numeric px-5 py-3 text-[var(--color-ink-soft)]">
                                        @if ($packagingRow['catalog_price'] !== null)
                                            {{ $packagingRow['currency'] }} {{ $formatNumber($packagingRow['catalog_price'], 4) }}
                                        @else
                                            <span class="text-[var(--color-warning-strong)]">{{ __('formula_documents.packaging_plan.not_priced') }}</span>
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
                    <p class="text-sm text-[var(--color-ink-soft)]">{{ __('formula_documents.packaging_plan.empty') }}</p>
                    <a href="{{ route('recipes.edit', $recipe) }}" class="mt-3 sk-btn sk-btn-outline min-h-11">
                        {{ __('formula_documents.packaging_plan.add') }}
                    </a>
                </div>
            @endif
        </section>

    </div>
@endsection
