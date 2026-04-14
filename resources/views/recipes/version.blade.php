@extends('layouts.app-shell')

@section('title', $recipe->name.' · Saved Recipe · '.config('app.name'))
@section('page_heading', 'Saved Recipe')

@section('content')
    <div class="mx-auto max-w-[90rem] space-y-6">
        @php
            /** @var array<string, string>|null $draftReplaceConfirmation */
            $draftReplaceConfirmation = session('draftReplaceConfirmation');
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
                        <p class="sk-eyebrow">Saved recipe</p>
                        <span class="rounded-full border border-[var(--color-success-soft)] bg-[var(--color-success-soft)] px-3 py-1 text-xs font-medium text-[var(--color-success-strong)]">Saved recipe</span>
                    </div>
                    <h1 class="mt-2 text-2xl font-semibold text-[var(--color-ink-strong)]">{{ $version->name }}</h1>
                    <p class="mt-2 max-w-3xl text-sm text-[var(--color-ink-soft)]">
                        This is the saved recipe. To change the formula, open the editable draft and save the recipe again. The oil quantity here is only for scaling and printing.
                    </p>

                    <div class="mt-4 flex flex-wrap gap-2">
                        <form method="POST" action="{{ route('recipes.saved.edit-in-draft', $recipe->id) }}">
                            @csrf
                            <button type="submit" class="inline-flex rounded-full border border-[var(--color-line-strong)] bg-white px-4 py-2 text-sm font-medium text-[var(--color-ink-strong)] transition hover:bg-[var(--color-panel)]">
                                Open editable draft
                            </button>
                        </form>
                        <form method="POST" action="{{ route('recipes.duplicate', $recipe->id) }}">
                            @csrf
                            <button type="submit" class="inline-flex rounded-full border border-[var(--color-line)] px-4 py-2 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel)]">
                                Duplicate
                            </button>
                        </form>
                        <a href="{{ route('recipes.print.recipe', ['recipe' => $recipe->id, 'oil_weight' => $selectedOilWeight]) }}" class="inline-flex rounded-full border border-[var(--color-line)] px-4 py-2 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel)]">
                            Print recipe
                        </a>
                        <a href="{{ route('recipes.print.details', ['recipe' => $recipe->id, 'oil_weight' => $selectedOilWeight]) }}" class="inline-flex rounded-full bg-[var(--color-accent-strong)] px-4 py-2 text-sm font-medium text-white transition hover:bg-[var(--color-accent)]">
                            Print full details
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
        <section class="sk-card overflow-hidden">
            <div class="border-b border-[var(--color-line)] px-5 py-4">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <p class="sk-eyebrow">Recovery snapshots</p>
                        <p class="mt-1 text-sm text-[var(--color-ink-soft)]">Older saved states. Restore one as current, or load it into the draft for editing.</p>
                    </div>
                    <span class="rounded-full border border-[var(--color-line)] bg-white px-3 py-1 text-xs font-medium text-[var(--color-ink-soft)]">
                        {{ count($recoverySnapshots) - 1 }} previous saves
                    </span>
                </div>
            </div>

            <div class="divide-y divide-[var(--color-line)]">
                @foreach ($recoverySnapshots as $snapshotVersion)
                    @if (! $snapshotVersion['is_current'])
                    <div class="flex flex-col gap-3 px-5 py-3 lg:flex-row lg:items-center lg:justify-between">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="text-sm font-medium text-[var(--color-ink-strong)]">{{ $snapshotVersion['name'] }}</p>
                                <span class="rounded-full border border-[var(--color-line)] bg-[var(--color-panel)] px-2.5 py-0.5 text-[11px] font-medium text-[var(--color-ink-soft)]">v{{ $snapshotVersion['version_number'] }}</span>
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
                                    Restore current
                                </button>
                            </form>
                        </div>
                    </div>
                    @endif
                @endforeach
            </div>
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
