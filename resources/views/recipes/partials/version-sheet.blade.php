@php
    $formatNumber = static fn (mixed $value, int $decimals = 2): string => rtrim(rtrim(number_format((float) $value, $decimals, '.', ''), '0'), '.');
    $showDetails = $showDetails ?? false;
    $oilUnit = $snapshot['draft']['oilUnit'] ?? 'g';
@endphp

<div class="space-y-6">
    <section class="grid gap-4 lg:grid-cols-4">
        @foreach ($summaryCards as $card)
            <article class="rounded-[1.75rem] border border-[var(--color-line)] bg-white p-5">
                <p class="text-xs font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase">{{ $card['label'] }}</p>
                <p class="numeric mt-3 text-3xl font-semibold text-[var(--color-ink-strong)]">
                    {{ $formatNumber($card['value']) }}<span class="ml-1 text-base font-medium text-[var(--color-ink-soft)]">{{ $card['unit'] }}</span>
                </p>
            </article>
        @endforeach
    </section>

    <section class="grid gap-4 xl:grid-cols-[minmax(0,1.1fr)_minmax(0,0.9fr)]">
        <article class="rounded-[2rem] border border-[var(--color-line)] bg-white p-5">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Formula context</p>
                    <h3 class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">Saved snapshot settings</h3>
                </div>
                <span class="rounded-full border border-[var(--color-line)] bg-[var(--color-panel)] px-3 py-1 text-xs font-medium text-[var(--color-ink-soft)]">Read-only</span>
            </div>

            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                @foreach ($contextRows as $row)
                    <div class="rounded-[1.5rem] border border-[var(--color-line)] bg-[var(--color-panel)] px-4 py-3">
                        <p class="text-xs font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase">{{ $row['label'] }}</p>
                        <p class="mt-2 text-sm font-medium text-[var(--color-ink-strong)]">{{ $row['value'] }}</p>
                    </div>
                @endforeach
            </div>
        </article>

        <article class="rounded-[2rem] border border-[var(--color-line)] bg-white p-5">
            <div>
                <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Lye and water</p>
                <h3 class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">Production-facing batch math</h3>
            </div>

            @if ($lyeRows !== [])
                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    @foreach ($lyeRows as $row)
                        <div class="rounded-[1.5rem] border border-[var(--color-line)] bg-[var(--color-panel)] px-4 py-3">
                            <p class="text-xs font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase">{{ $row['label'] }}</p>
                            <p class="numeric mt-2 text-sm font-medium text-[var(--color-ink-strong)]">
                                @if (is_numeric($row['value']))
                                    {{ $formatNumber($row['value']) }}@if ($row['unit']) <span class="text-[var(--color-ink-soft)]">{{ $row['unit'] }}</span>@endif
                                @else
                                    {{ $row['value'] }}
                                @endif
                            </p>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="mt-4 rounded-[1.5rem] border border-dashed border-[var(--color-line)] bg-[var(--color-panel)] px-4 py-6 text-sm text-[var(--color-ink-soft)]">
                    This saved formula is not using the soap calculation engine, so no lye or water sheet is shown here.
                </div>
            @endif
        </article>
    </section>

    <section class="space-y-4">
        @foreach ($phaseSections as $section)
            <article class="overflow-hidden rounded-[2rem] border border-[var(--color-line)] bg-white">
                <div class="border-b border-[var(--color-line)] px-5 py-4">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">{{ $section['label'] }}</p>
                            <p class="mt-1 text-sm text-[var(--color-ink-soft)]">Percentages stay locked to the current saved formula. Only the oil quantity basis changes.</p>
                        </div>
                        <div class="flex flex-wrap gap-2 text-xs text-[var(--color-ink-soft)]">
                            <span class="numeric rounded-full border border-[var(--color-line)] bg-[var(--color-panel)] px-3 py-1">{{ $formatNumber($section['total_percentage']) }}% oils</span>
                            <span class="numeric rounded-full border border-[var(--color-line)] bg-[var(--color-panel)] px-3 py-1">{{ $formatNumber($section['total_weight']) }} {{ $oilUnit }}</span>
                        </div>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-[var(--color-line)] text-sm">
                        <thead class="bg-[var(--color-panel)] text-left text-xs font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase">
                            <tr>
                                <th class="px-5 py-3">Ingredient</th>
                                <th class="px-5 py-3">INCI</th>
                                <th class="px-5 py-3">% oils</th>
                                <th class="px-5 py-3">Weight</th>
                                <th class="px-5 py-3">Note</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--color-line)] bg-white">
                            @foreach ($section['rows'] as $row)
                                <tr>
                                    <td class="px-5 py-4 align-top">
                                        <p class="font-medium text-[var(--color-ink-strong)]">{{ $row['name'] }}</p>
                                    </td>
                                    <td class="px-5 py-4 align-top text-[var(--color-ink-soft)]">{{ $row['inci_name'] ?: 'Not recorded yet' }}</td>
                                    <td class="numeric px-5 py-4 align-top font-medium text-[var(--color-ink-strong)]">{{ $formatNumber($row['percentage']) }}%</td>
                                    <td class="numeric px-5 py-4 align-top font-medium text-[var(--color-ink-strong)]">{{ $formatNumber($row['weight']) }} {{ $oilUnit }}</td>
                                    <td class="px-5 py-4 align-top text-[var(--color-ink-soft)]">{{ $row['note'] ?: '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </article>
        @endforeach
    </section>

    @if ($recipe->description)
        <section class="rounded-[2rem] border border-[var(--color-line)] bg-white p-5">
            <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Presentation</p>
            <div class="prose prose-stone mt-4 max-w-none text-[var(--color-ink-soft)]">
                {!! str($recipe->description)->sanitizeHtml() !!}
            </div>
        </section>
    @endif

    @if ($recipe->manufacturing_instructions)
        <section class="rounded-[2rem] border border-[var(--color-line)] bg-white p-5">
            <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Manufacturing instructions</p>
            <div class="prose prose-stone mt-4 max-w-none text-[var(--color-ink-soft)]">
                {!! str($recipe->manufacturing_instructions)->sanitizeHtml() !!}
            </div>
        </section>
    @endif

    @if ($showDetails)
        <section class="rounded-[2rem] border border-[var(--color-line)] bg-white p-5">
            @php($listVariants = $snapshot['labeling']['list_variants'] ?? [])
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Ingredient list preview</p>
                    <p class="mt-1 text-sm text-[var(--color-ink-soft)]">Generated from the current saved formula with the selected oil quantity basis.</p>
                </div>
                <span class="rounded-full border border-[var(--color-line)] bg-[var(--color-panel)] px-3 py-1 text-xs font-medium text-[var(--color-ink-soft)]">EU preview</span>
            </div>

            @if (($snapshot['labeling']['warnings'] ?? []) !== [])
                <div class="mt-4 space-y-2">
                    @foreach ($snapshot['labeling']['warnings'] as $warning)
                        <div class="rounded-[1.25rem] border border-[var(--color-warning-soft)] bg-[var(--color-warning-soft)] px-4 py-3 text-sm text-[var(--color-warning-strong)]">
                            {{ $warning }}
                        </div>
                    @endforeach
                </div>
            @endif

            @if ($listVariants !== [])
                <div class="mt-4 grid gap-4 xl:grid-cols-2">
                    @foreach ($listVariants as $variant)
                        <div class="rounded-[1.5rem] border border-[var(--color-line)] bg-[var(--color-panel)] px-5 py-4">
                            <p class="text-xs font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase">{{ $variant['label'] }}</p>
                            @if (filled($variant['note'] ?? null))
                                <p class="mt-2 text-xs leading-5 text-[var(--color-ink-soft)]">{{ $variant['note'] }}</p>
                            @endif
                            <p class="mt-3 text-[0.98rem] leading-8 font-medium tracking-[0.01em] [font-stretch:88%] text-[var(--color-ink-strong)]">
                                {{ $variant['final_label_text'] ?: 'No generated ingredient list yet.' }}
                            </p>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="mt-4 rounded-[1.5rem] border border-[var(--color-line)] bg-[var(--color-panel)] px-5 py-4 text-[0.98rem] leading-8 font-medium tracking-[0.01em] [font-stretch:88%] text-[var(--color-ink-strong)]">
                    {{ $snapshot['labeling']['final_label_text'] ?? 'No generated ingredient list yet.' }}
                </div>
            @endif
        </section>

        <section class="overflow-hidden rounded-[2rem] border border-[var(--color-line)] bg-white">
            <div class="border-b border-[var(--color-line)] px-5 py-4">
                <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Declaration details</p>
                <p class="mt-1 text-sm text-[var(--color-ink-soft)]">Threshold-based declarations are shown here with their current contribution to the selected batch basis for the default soap-style list.</p>
            </div>

            @if (($snapshot['labeling']['declaration_rows'] ?? []) !== [])
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-[var(--color-line)] text-sm">
                        <thead class="bg-[var(--color-panel)] text-left text-xs font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase">
                            <tr>
                                <th class="px-5 py-3">Label</th>
                                <th class="px-5 py-3">Sources</th>
                                <th class="px-5 py-3">% total formula</th>
                                <th class="px-5 py-3">Threshold</th>
                                <th class="px-5 py-3">Status</th>
                                <th class="px-5 py-3">Notes</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--color-line)] bg-white">
                            @foreach ($snapshot['labeling']['declaration_rows'] as $row)
                                <tr>
                                    <td class="px-5 py-4 align-top font-medium text-[var(--color-ink-strong)]">{{ $row['label'] }}</td>
                                    <td class="px-5 py-4 align-top text-[var(--color-ink-soft)]">{{ implode(', ', $row['source_ingredients']) }}</td>
                                    <td class="numeric px-5 py-4 align-top font-medium text-[var(--color-ink-strong)]">{{ $formatNumber($row['percent_of_formula'], 4) }}%</td>
                                    <td class="numeric px-5 py-4 align-top text-[var(--color-ink-soft)]">{{ $formatNumber($row['threshold_percent'], 3) }}%</td>
                                    <td class="px-5 py-4 align-top text-[var(--color-ink-strong)]">{{ $row['status_label'] }}</td>
                                    <td class="px-5 py-4 align-top text-[var(--color-ink-soft)]">{{ $row['notes'] ?: '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="px-5 py-6 text-sm text-[var(--color-ink-soft)]">
                    No declaration rows are available for this saved formula yet.
                </div>
            @endif
        </section>
    @endif
</div>
