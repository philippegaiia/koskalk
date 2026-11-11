@extends('layouts.app-shell')

@php
    $formatNumber = static fn (mixed $value, int $decimals = 2): string => $decimals === 0
        ? number_format((float) $value, 0, '.', '')
        : rtrim(rtrim(number_format((float) $value, $decimals, '.', ''), '0'), '.');
    $formatMoney = static fn (mixed $value, string $currency): string => $formatNumber($value, 2).' '.$currency;
    $formatQuantity = static fn (mixed $value, string $unit): string => $formatNumber($value, $unit === 'g' ? 0 : 2).' '.$unit;
    $versionLabel = $productionBatch->recipe_version_number
        ? __('batch_log.snapshot.version', ['number' => $productionBatch->recipe_version_number])
        : __('batch_log.snapshot.saved_formula');
@endphp

@section('title', $productionBatch->recipe_name.' · '.__('batch_log.snapshot.title').' · '.config('app.name'))
@section('page_heading', __('batch_log.snapshot.title'))

@section('content')
    <div class="mx-auto max-w-app space-y-6">
        <section class="sk-card p-5">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div class="min-w-0">
                    <p class="sk-eyebrow">{{ __('batch_log.snapshot.eyebrow') }}</p>
                    <h1 class="mt-2 text-2xl font-semibold text-[var(--color-ink-strong)]">{{ $productionBatch->recipe_name }}</h1>
                    <p class="mt-2 text-sm text-[var(--color-ink-soft)]">
                        {{ $versionLabel }} · {{ $productionBatch->manufacture_date?->format('Y-m-d') ?? __('batch_log.snapshot.date_not_recorded') }}
                    </p>
                </div>

                <div class="flex flex-wrap gap-2">
                    @if ($productionBatch->recipe !== null && auth()->user()->can('view', $productionBatch->recipe))
                        <a href="{{ route('recipes.saved', $productionBatch->recipe) }}" class="inline-flex rounded-full border border-[var(--color-line)] bg-white px-4 py-2 text-sm font-medium text-[var(--color-ink-strong)] transition hover:bg-[var(--color-panel)]">
                            {{ __('batch_log.actions.back_to_formula') }}
                        </a>
                    @endif
                    <a href="{{ route('production-batches.print', $productionBatch) }}" class="sk-btn sk-btn-outline min-h-11 gap-2 whitespace-nowrap">
                        <x-action-icon name="printer" />{{ __('batch_log.actions.print') }}
                    </a>
                    @can('delete', $productionBatch)
                        <form method="POST" action="{{ route('production-batches.destroy', $productionBatch) }}" x-data="{ confirming: false }" @submit.prevent="confirming ? $el.submit() : (confirming = true)" class="inline">
                            @csrf
                            @method('DELETE')
                            <button type="submit" :class="confirming ? 'bg-[var(--color-danger-strong)] text-white' : 'border border-[var(--color-line)] bg-white text-[var(--color-ink-soft)] hover:bg-[var(--color-danger-soft)] hover:text-[var(--color-danger-strong)]'" class="inline-flex rounded-full px-4 py-2 text-sm font-medium transition">
                                <span x-show="!confirming">{{ __('batch_log.actions.delete') }}</span>
                                <span x-show="confirming" x-cloak>{{ __('batch_log.actions.confirm_again') }}</span>
                            </button>
                        </form>
                    @endcan
                </div>
            </div>
        </section>

        <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <article class="sk-card p-4">
                <p class="sk-eyebrow">{{ __('batch_log.snapshot.batch') }}</p>
                <p class="mt-3 text-xl font-semibold text-[var(--color-ink-strong)]">{{ $productionBatch->production_batch_number ?: __('batch_log.snapshot.no_batch_number') }}</p>
            </article>

            <article class="sk-card p-4">
                <p class="sk-eyebrow">{{ $productionBatch->localizedBatchBasisLabel() }}</p>
                <p class="numeric mt-3 text-xl font-semibold text-[var(--color-ink-strong)]">
                    {{ $formatQuantity($productionBatch->batch_basis_value, $productionBatch->batch_basis_unit) }}
                </p>
            </article>

            <article class="sk-card p-4">
                <p class="sk-eyebrow">{{ __('batch_log.fields.units_produced') }}</p>
                <p class="numeric mt-3 text-xl font-semibold text-[var(--color-ink-strong)]">{{ $productionBatch->units_produced }}</p>
            </article>

            <article class="sk-card p-4">
                <p class="sk-eyebrow">{{ __('batch_log.costs.per_unit') }}</p>
                <p class="numeric mt-3 text-xl font-semibold text-[var(--color-ink-strong)]">{{ $formatMoney($productionBatch->cost_per_unit, $productionBatch->currency) }}</p>
            </article>
        </section>

        <form method="POST" action="{{ route('production-batches.update', $productionBatch) }}" class="space-y-6">
            @csrf
            @method('PATCH')
            <fieldset @disabled(auth()->user()->cannot('update', $productionBatch)) class="space-y-6">

            <section class="sk-card p-5">
                <p class="sk-eyebrow">{{ __('batch_log.fields.notes') }}</p>
                <div class="mt-4 grid gap-4 lg:grid-cols-[18rem_minmax(0,1fr)]">
                    <label class="block">
                        <span class="text-sm font-medium text-[var(--color-ink-strong)]">{{ __('batch_log.fields.batch_number') }}</span>
                        <input name="production_batch_number" value="{{ old('production_batch_number', $productionBatch->production_batch_number) }}" type="text" class="mt-2 w-full rounded-lg border border-[var(--color-line)] bg-[var(--color-field)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]" />
                        @error('production_batch_number')
                            <span class="mt-1 block text-xs font-medium text-[var(--color-danger-strong)]">{{ $message }}</span>
                        @enderror
                    </label>

                    <label class="block">
                        <span class="text-sm font-medium text-[var(--color-ink-strong)]">{{ __('batch_log.fields.notes') }}</span>
                        <textarea name="production_notes" rows="4" class="mt-2 w-full rounded-lg border border-[var(--color-line)] bg-[var(--color-field)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]">{{ old('production_notes', $productionBatch->production_notes) }}</textarea>
                        @error('production_notes')
                            <span class="mt-1 block text-xs font-medium text-[var(--color-danger-strong)]">{{ $message }}</span>
                        @enderror
                    </label>
                </div>
            </section>

            <section class="sk-card overflow-hidden">
                <div class="border-b border-[var(--color-line)] px-5 py-4">
                    <p class="sk-eyebrow">{{ __('batch_log.snapshot.ingredients') }}</p>
                    <h2 class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('batch_log.snapshot.ingredients_title') }}</h2>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead class="border-b border-[var(--color-line)] bg-[var(--color-field-muted)] text-xs font-semibold uppercase tracking-[0.12em] text-[var(--color-ink-soft)]">
                            <tr>
                                <th class="px-5 py-3">{{ __('batch_log.columns.phase') }}</th>
                                <th class="px-5 py-3">{{ __('batch_log.columns.ingredient') }}</th>
                                <th class="px-5 py-3">{{ __('batch_log.columns.quantity') }}</th>
                                <th class="px-5 py-3">{{ __('batch_log.columns.price_per_kg') }}</th>
                                <th class="px-5 py-3">{{ __('batch_log.columns.cost') }}</th>
                                <th class="px-5 py-3">{{ __('batch_log.columns.lot_number') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--color-line)]">
                            @foreach ($productionBatch->ingredients as $ingredient)
                                @php($lotKey = implode(':', [$ingredient->ingredient_id, $ingredient->phase_key, $ingredient->position]))
                                <tr>
                                    <td class="px-5 py-4 align-top text-[var(--color-ink-soft)]">{{ $ingredient->phase_name }}</td>
                                    <td class="px-5 py-4 align-top font-medium text-[var(--color-ink-strong)]"><span class="inline-flex items-center gap-1.5">{{ $ingredient->ingredient_name }} <x-ingredient-source-marker :is-user-owned="$ingredient->ingredient?->owner_type !== null" /></span></td>
                                    <td class="numeric px-5 py-4 align-top text-[var(--color-ink-strong)]">{{ $formatQuantity($ingredient->quantity, $ingredient->unit) }}</td>
                                    <td class="numeric px-5 py-4 align-top text-[var(--color-ink-soft)]">{{ $formatMoney($ingredient->price_per_kg, $productionBatch->currency) }}</td>
                                    <td class="numeric px-5 py-4 align-top font-medium text-[var(--color-ink-strong)]">{{ $formatMoney($ingredient->line_cost, $productionBatch->currency) }}</td>
                                    <td class="px-5 py-4 align-top">
                                        <input name="ingredient_lot_numbers[{{ $lotKey }}]" value="{{ old('ingredient_lot_numbers.'.$lotKey, $ingredient->ingredient_lot_number) }}" type="text" class="w-full min-w-40 rounded-lg border border-[var(--color-line)] bg-[var(--color-field)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]" />
                                        @error('ingredient_lot_numbers.'.$lotKey)
                                            <span class="mt-1 block text-xs font-medium text-[var(--color-danger-strong)]">{{ $message }}</span>
                                        @enderror
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <div class="border-t border-[var(--color-line)] px-5 py-2.5">
                        <x-ingredient-source-legend :show="$productionBatch->ingredients->contains(fn ($row): bool => $row->ingredient?->owner_type !== null)" />
                    </div>
                </div>
            </section>

            <section class="sk-card overflow-hidden">
                <div class="border-b border-[var(--color-line)] px-5 py-4">
                    <p class="sk-eyebrow">{{ __('batch_log.snapshot.packaging') }}</p>
                    <h2 class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('batch_log.snapshot.packaging_title') }}</h2>
                </div>

                @if ($productionBatch->packagingItems->isNotEmpty())
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-left text-sm">
                            <thead class="border-b border-[var(--color-line)] bg-[var(--color-field-muted)] text-xs font-semibold uppercase tracking-[0.12em] text-[var(--color-ink-soft)]">
                                <tr>
                                    <th class="px-5 py-3">{{ __('batch_log.columns.item') }}</th>
                                    <th class="px-5 py-3">{{ __('batch_log.columns.components_per_unit') }}</th>
                                    <th class="px-5 py-3">{{ __('batch_log.columns.unit_cost_currency', ['currency' => $productionBatch->currency]) }}</th>
                                    <th class="px-5 py-3">{{ __('batch_log.columns.batch_cost_currency', ['currency' => $productionBatch->currency]) }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[var(--color-line)]">
                                @foreach ($productionBatch->packagingItems as $packagingItem)
                                    <tr>
                                        <td class="px-5 py-4 font-medium text-[var(--color-ink-strong)]">{{ $packagingItem->name }}</td>
                                        <td class="numeric px-5 py-4 text-[var(--color-ink-soft)]">{{ $formatNumber($packagingItem->components_per_unit, 3) }}</td>
                                        <td class="numeric px-5 py-4 text-[var(--color-ink-soft)]">{{ $formatNumber($packagingItem->unit_cost, 2) }}</td>
                                        <td class="numeric px-5 py-4 font-medium text-[var(--color-ink-strong)]">{{ $formatNumber($packagingItem->line_cost, 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="px-5 py-6 text-sm text-[var(--color-ink-soft)]">
                        {{ __('batch_log.snapshot.no_packaging') }}
                    </div>
                @endif
            </section>

            @can('update', $productionBatch)
            <div class="flex justify-end">
                <button type="submit" class="rounded-full bg-[var(--color-ink-strong)] px-4 py-2 text-sm font-medium text-white transition hover:bg-[var(--color-accent-strong)]">
                    {{ __('batch_log.actions.save_notes') }}
                </button>
            </div>
            @endcan
            </fieldset>
        </form>
    </div>
@endsection
