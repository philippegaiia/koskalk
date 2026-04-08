@extends('layouts.app-shell')

@section('title', $recipe->name.' · Saved Formula · Koskalk')
@section('page_heading', 'Saved Formula')

@section('content')
    <div class="mx-auto max-w-[90rem] space-y-6">
        @php
            /** @var array<string, string>|null $draftReplaceConfirmation */
            $draftReplaceConfirmation = session('draftReplaceConfirmation');
        @endphp

        @if (is_array($draftReplaceConfirmation))
            <section class="rounded-[2rem] border border-[var(--color-warning-soft)] bg-[var(--color-warning-soft)]/35 p-6">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div class="min-w-0">
                        <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Draft confirmation</p>
                        <h2 class="mt-2 text-xl font-semibold tracking-[-0.03em] text-[var(--color-ink-strong)]">
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

        <section class="rounded-[2rem] border border-[var(--color-line)] bg-white p-6">
            <div class="flex flex-col gap-6 xl:flex-row xl:items-start xl:justify-between">
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="rounded-full border border-[var(--color-line)] bg-[var(--color-panel)] px-3 py-1 text-xs font-medium text-[var(--color-ink-soft)]">Saved formula</span>
                    </div>
                    <h1 class="mt-4 text-3xl font-semibold tracking-[-0.04em] text-[var(--color-ink-strong)]">{{ $version->name }}</h1>
                    <p class="mt-3 max-w-3xl text-sm leading-7 text-[var(--color-ink-soft)]">
                        This view keeps the current saved formula locked. Only the oil quantity basis is adjustable so you can scale the sheet without changing the saved state itself.
                    </p>

                    <div class="mt-5 flex flex-wrap gap-2">
                        <form method="POST" action="{{ route('recipes.saved.edit-in-draft', $recipe->id) }}">
                            @csrf
                            <button type="submit" class="inline-flex rounded-full border border-[var(--color-line-strong)] px-4 py-2 text-sm font-medium text-[var(--color-ink-strong)] transition hover:bg-[var(--color-panel)]">
                                Edit in draft
                            </button>
                        </form>
                        <form method="POST" action="{{ route('recipes.duplicate', $recipe->id) }}">
                            @csrf
                            <button type="submit" class="inline-flex rounded-full border border-[var(--color-line)] px-4 py-2 text-sm font-medium text-[var(--color-ink-strong)] transition hover:bg-[var(--color-panel)]">
                                Duplicate
                            </button>
                        </form>
                        <a href="{{ route('recipes.print.recipe', ['recipe' => $recipe->id, 'oil_weight' => $selectedOilWeight]) }}" class="inline-flex rounded-full border border-[var(--color-line)] px-4 py-2 text-sm font-medium text-[var(--color-ink-strong)] transition hover:bg-[var(--color-panel)]">
                            Print recipe
                        </a>
                        <a href="{{ route('recipes.print.details', ['recipe' => $recipe->id, 'oil_weight' => $selectedOilWeight]) }}" class="inline-flex rounded-full bg-[var(--color-accent-strong)] px-4 py-2 text-sm font-medium text-white transition hover:bg-[var(--color-accent)]">
                            Print full details
                        </a>
                    </div>
                </div>

                <div class="grid gap-4 lg:grid-cols-[minmax(0,18rem)]">
                    <form method="GET" action="{{ route('recipes.saved', ['recipe' => $recipe->id]) }}" class="rounded-[1.75rem] border border-[var(--color-line)] bg-[var(--color-panel)] p-4">
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
                            <a href="{{ route('recipes.saved', ['recipe' => $recipe->id]) }}" class="inline-flex rounded-full border border-[var(--color-line)] px-4 py-2 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-white">
                                Reset
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </section>

        <section class="rounded-[2rem] border border-[var(--color-line)] bg-white p-6">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Recovery snapshots</p>
                    <p class="mt-2 max-w-3xl text-sm leading-7 text-[var(--color-ink-soft)]">
                        The current saved formula stays at the top. Older saved states are kept as short-term recovery points, and you can either restore one as the current saved formula or load it into the draft for editing.
                    </p>
                </div>
                <span class="rounded-full border border-[var(--color-line)] bg-[var(--color-panel)] px-3 py-1 text-xs font-medium text-[var(--color-ink-soft)]">
                    Current + last {{ max(count($recoverySnapshots) - 1, 0) }} saves
                </span>
            </div>

            <div class="mt-5 space-y-3">
                @foreach ($recoverySnapshots as $snapshotVersion)
                    <div class="flex flex-col gap-3 rounded-[1.5rem] border border-[var(--color-line)] bg-[var(--color-panel)] px-4 py-4 lg:flex-row lg:items-center lg:justify-between">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="text-sm font-medium text-[var(--color-ink-strong)]">{{ $snapshotVersion['name'] }}</p>
                                <span class="rounded-full border border-[var(--color-line)] bg-white px-3 py-1 text-[11px] font-medium text-[var(--color-ink-soft)]">
                                    v{{ $snapshotVersion['version_number'] }}
                                </span>
                                @if ($snapshotVersion['is_current'])
                                    <span class="rounded-full border border-[var(--color-success-soft)] bg-[var(--color-success-soft)] px-3 py-1 text-[11px] font-medium text-[var(--color-success-strong)]">Current</span>
                                @else
                                    <span class="rounded-full border border-[var(--color-line)] bg-white px-3 py-1 text-[11px] font-medium text-[var(--color-ink-soft)]">Recovery</span>
                                @endif
                            </div>
                            <p class="mt-2 text-xs text-[var(--color-ink-soft)]">
                                Saved {{ \Illuminate\Support\Carbon::parse($snapshotVersion['saved_at'])->format('Y-m-d H:i') }}
                            </p>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            @if ($snapshotVersion['is_current'])
                                <form method="POST" action="{{ route('recipes.saved.edit-in-draft', $recipe->id) }}">
                                    @csrf
                                    <button type="submit" class="inline-flex rounded-full border border-[var(--color-line-strong)] px-4 py-2 text-sm font-medium text-[var(--color-ink-strong)] transition hover:bg-white">
                                        Edit in draft
                                    </button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('recipes.use-version-as-draft', ['recipe' => $recipe->id, 'version' => $snapshotVersion['id']]) }}">
                                    @csrf
                                    <button type="submit" class="inline-flex rounded-full border border-[var(--color-line)] px-4 py-2 text-sm font-medium text-[var(--color-ink-strong)] transition hover:bg-white">
                                        Load into draft
                                    </button>
                                </form>
                                <form method="POST" action="{{ route('recipes.saved.restore', ['recipe' => $recipe->id, 'version' => $snapshotVersion['id']]) }}">
                                    @csrf
                                    <button type="submit" class="inline-flex rounded-full bg-[var(--color-accent-strong)] px-4 py-2 text-sm font-medium text-white transition hover:bg-[var(--color-accent)]">
                                        Restore current
                                    </button>
                                </form>
                            @endif
                        </div>
                    </div>
                @endforeach
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
