@extends('layouts.app-shell')

@section('title', $recipe->name.' · v'.$version->version_number.' · Koskalk')
@section('page_heading', 'Recipe Version')

@section('content')
    <div class="mx-auto max-w-[90rem] space-y-6">
        <section class="rounded-[2rem] border border-[var(--color-line)] bg-white p-6">
            <div class="flex flex-col gap-6 xl:flex-row xl:items-start xl:justify-between">
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="rounded-full border border-[var(--color-line)] bg-[var(--color-panel)] px-3 py-1 text-xs font-medium text-[var(--color-ink-soft)]">Saved version</span>
                        <span class="rounded-full border border-[var(--color-line)] bg-[var(--color-panel)] px-3 py-1 text-xs font-medium text-[var(--color-ink-soft)]">v{{ $version->version_number }}</span>
                    </div>
                    <h1 class="mt-4 text-3xl font-semibold tracking-[-0.04em] text-[var(--color-ink-strong)]">{{ $version->name }}</h1>
                    <p class="mt-3 max-w-3xl text-sm leading-7 text-[var(--color-ink-soft)]">
                        This view keeps the saved formula locked. Only the oil quantity basis is adjustable so you can scale the sheet without changing the version itself.
                    </p>

                    <div class="mt-5 flex flex-wrap gap-2">
                        <a href="{{ route('recipes.edit', $recipe->id) }}" wire:navigate class="inline-flex rounded-full border border-[var(--color-line-strong)] px-4 py-2 text-sm font-medium text-[var(--color-ink-strong)] transition hover:bg-[var(--color-panel)]">
                            Back to draft
                        </a>
                        <form method="POST" action="{{ route('recipes.use-version-as-draft', ['recipe' => $recipe->id, 'version' => $version->id]) }}">
                            @csrf
                            <button type="submit" class="inline-flex rounded-full border border-[var(--color-line)] bg-white px-4 py-2 text-sm font-medium text-[var(--color-ink-strong)] transition hover:bg-[var(--color-panel)]">
                                Use this version as draft
                            </button>
                        </form>
                        <a href="{{ route('recipes.print.recipe', ['recipe' => $recipe->id, 'version' => $version->id, 'oil_weight' => $selectedOilWeight]) }}" class="inline-flex rounded-full border border-[var(--color-line)] px-4 py-2 text-sm font-medium text-[var(--color-ink-strong)] transition hover:bg-[var(--color-panel)]">
                            Print recipe
                        </a>
                        <a href="{{ route('recipes.print.details', ['recipe' => $recipe->id, 'version' => $version->id, 'oil_weight' => $selectedOilWeight]) }}" class="inline-flex rounded-full bg-[var(--color-accent-strong)] px-4 py-2 text-sm font-medium text-white transition hover:bg-[var(--color-accent)]">
                            Print full details
                        </a>
                    </div>
                </div>

                <div class="grid gap-4 lg:grid-cols-[minmax(0,18rem)_minmax(0,18rem)]">
                    <form method="GET" action="{{ route('recipes.version', ['recipe' => $recipe->id, 'version' => $version->id]) }}" class="rounded-[1.75rem] border border-[var(--color-line)] bg-[var(--color-panel)] p-4">
                        <p class="text-xs font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase">Scale quantity</p>
                        <label class="mt-3 block text-sm font-medium text-[var(--color-ink-strong)]" for="oil_weight">Oil quantity</label>
                        <div class="mt-2 flex items-center gap-2">
                            <input id="oil_weight" name="oil_weight" type="number" min="0.01" step="0.01" value="{{ rtrim(rtrim(number_format($selectedOilWeight, 2, '.', ''), '0'), '.') }}" class="w-full rounded-[1.25rem] border border-[var(--color-line)] bg-white px-4 py-3 text-sm text-[var(--color-ink-strong)] outline-none transition focus:border-[var(--color-line-strong)]" />
                            <span class="rounded-full border border-[var(--color-line)] bg-white px-3 py-2 text-xs font-medium text-[var(--color-ink-soft)]">{{ $snapshot['draft']['oilUnit'] ?? 'g' }}</span>
                        </div>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <button type="submit" class="inline-flex rounded-full bg-[var(--color-ink-strong)] px-4 py-2 text-sm font-medium text-white transition hover:bg-[var(--color-accent-strong)]">
                                Recalculate
                            </button>
                            <a href="{{ route('recipes.version', ['recipe' => $recipe->id, 'version' => $version->id]) }}" class="inline-flex rounded-full border border-[var(--color-line)] px-4 py-2 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-white">
                                Reset
                            </a>
                        </div>
                    </form>

                    <div class="rounded-[1.75rem] border border-[var(--color-line)] bg-[var(--color-panel)] p-4">
                        <p class="text-xs font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase">Other saved versions</p>
                        <div class="mt-3 space-y-2">
                            @foreach ($versionOptions as $option)
                                <a href="{{ route('recipes.version', ['recipe' => $recipe->id, 'version' => $option['id']]) }}" class="flex items-center justify-between rounded-[1.25rem] border px-3 py-2 text-sm transition {{ $option['id'] === $version->id ? 'border-[var(--color-line-strong)] bg-white text-[var(--color-ink-strong)]' : 'border-[var(--color-line)] bg-white/70 text-[var(--color-ink-soft)] hover:bg-white' }}">
                                    <span>{{ $option['label'] }}</span>
                                    <span class="text-xs font-medium">{{ $option['id'] === $version->id ? 'Open' : 'View' }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </section>

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
