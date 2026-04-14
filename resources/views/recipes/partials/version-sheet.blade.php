@php
    $formatNumber = static fn (mixed $value, int $decimals = 2): string => rtrim(rtrim(number_format((float) $value, $decimals, '.', ''), '0'), '.');
    $showDetails = $showDetails ?? false;
    $oilUnit = $snapshot['draft']['oilUnit'] ?? 'g';
@endphp

<div class="space-y-6">
    <section class="grid gap-4 lg:grid-cols-4">
        @foreach ($summaryCards as $card)
            <article class="sk-card p-4">
                <p class="sk-eyebrow">{{ $card['label'] }}</p>
                <p class="numeric mt-2 text-2xl font-semibold text-[var(--color-ink-strong)]">
                    {{ $formatNumber($card['value']) }}<span class="ml-1 text-sm font-medium text-[var(--color-ink-soft)]">{{ $card['unit'] }}</span>
                </p>
            </article>
        @endforeach
    </section>

    <section class="grid gap-4 xl:grid-cols-[minmax(0,1.1fr)_minmax(0,0.9fr)]">
        <article class="sk-card p-5">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="sk-eyebrow">Formula context</p>
                    <h3 class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">Snapshot settings</h3>
                </div>
                <span class="rounded-full border border-[var(--color-line)] bg-white px-3 py-1 text-xs font-medium text-[var(--color-ink-soft)]">Read-only</span>
            </div>

            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                @foreach ($contextRows as $row)
                    <div class="sk-inset px-4 py-3">
                        <p class="sk-eyebrow">{{ $row['label'] }}</p>
                        <p class="mt-1 text-sm font-medium text-[var(--color-ink-strong)]">{{ $row['value'] }}</p>
                    </div>
                @endforeach
            </div>
        </article>

        <article class="sk-card p-5">
            <div>
                <p class="sk-eyebrow">Lye and water</p>
                <h3 class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">Batch math</h3>
            </div>

            @if ($lyeRows !== [])
                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    @foreach ($lyeRows as $row)
                        <div class="sk-inset px-4 py-3">
                            <p class="sk-eyebrow">{{ $row['label'] }}</p>
                            <p class="numeric mt-1 text-sm font-medium text-[var(--color-ink-strong)]">
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
                <div class="mt-4 rounded-lg border border-dashed border-[var(--color-line)] bg-[var(--color-panel-strong)] px-4 py-5 text-sm text-[var(--color-ink-soft)]">
                    This formula is not using the soap calculation engine, so no lye or water data is shown.
                </div>
            @endif
        </article>
    </section>

    <section class="space-y-4">
        @foreach ($phaseSections as $section)
            <article class="overflow-hidden sk-card">
                <div class="border-b border-[var(--color-line)] px-5 py-4">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p class="sk-eyebrow">{{ $section['label'] }}</p>
                            <p class="mt-1 text-sm text-[var(--color-ink-soft)]">Percentages come from the saved recipe. Only the oil quantity basis changes here.</p>
                        </div>
                        <div class="flex flex-wrap gap-2 text-xs text-[var(--color-ink-soft)]">
                            <span class="numeric rounded-full border border-[var(--color-line)] bg-white px-3 py-1">{{ $formatNumber($section['total_percentage']) }}% oils</span>
                            <span class="numeric rounded-full border border-[var(--color-line)] bg-white px-3 py-1">{{ $formatNumber($section['total_weight']) }} {{ $oilUnit }}</span>
                        </div>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-[var(--color-line)] text-sm">
                        <thead class="bg-[var(--color-panel-strong)] text-left text-xs font-semibold tracking-[0.14em] text-[var(--color-ink-soft)] uppercase">
                            <tr>
                                <th class="px-5 py-3">Ingredient</th>
                                <th class="px-5 py-3">INCI</th>
                                <th class="px-5 py-3">% oils</th>
                                <th class="px-5 py-3">Weight ({{ $oilUnit }})</th>
                                <th class="px-5 py-3">Note</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--color-line)] bg-white">
                            @foreach ($section['rows'] as $row)
                                <tr>
                                    <td class="px-5 py-4 align-top">
                                        <p class="font-medium text-[var(--color-ink-strong)]">{{ $row['name'] }}</p>
                                    </td>
                                    <td class="px-5 py-4 align-top text-[var(--color-ink-soft)]">{{ $row['inci_name'] ?: '—' }}</td>
                                    <td class="numeric px-5 py-4 align-top font-medium text-[var(--color-ink-strong)]">{{ $formatNumber($row['percentage']) }}%</td>
                                    <td class="numeric px-5 py-4 align-top font-medium text-[var(--color-ink-strong)]">{{ $formatNumber($row['weight']) }}</td>
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
        <section class="sk-card p-5">
            <p class="sk-eyebrow">Presentation</p>
            <div class="prose prose-stone mt-4 max-w-none text-[var(--color-ink-soft)]">
                {!! str($recipe->description)->sanitizeHtml() !!}
            </div>
        </section>
    @endif

    @if ($recipe->manufacturing_instructions)
        <section class="sk-card p-5">
            <p class="sk-eyebrow">Manufacturing instructions</p>
            <div class="prose prose-stone mt-4 max-w-none text-[var(--color-ink-soft)]">
                {!! str($recipe->manufacturing_instructions)->sanitizeHtml() !!}
            </div>
        </section>
    @endif

    @if ($showDetails)
        <section class="sk-card p-5">
            @php($listVariants = $snapshot['labeling']['list_variants'] ?? [])
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="sk-eyebrow">Ingredient list preview</p>
                    <p class="mt-1 text-sm text-[var(--color-ink-soft)]">Generated from the current saved recipe with the selected oil quantity basis.</p>
                </div>
                <span class="rounded-full border border-[var(--color-line)] bg-white px-3 py-1 text-xs font-medium text-[var(--color-ink-soft)]">EU preview</span>
            </div>

            @if (($snapshot['labeling']['warnings'] ?? []) !== [])
                <div class="mt-4 space-y-2">
                    @foreach ($snapshot['labeling']['warnings'] as $warning)
                        <div class="rounded-lg border border-[var(--color-warning-soft)] bg-[var(--color-warning-soft)] px-4 py-3 text-sm text-[var(--color-warning-strong)]">
                            {{ $warning }}
                        </div>
                    @endforeach
                </div>
            @endif

            @if ($listVariants !== [])
                <div class="mt-4 grid gap-4 xl:grid-cols-2">
                    @foreach ($listVariants as $variant)
                        <div class="sk-inset px-4 py-3">
                            <p class="sk-eyebrow">{{ $variant['label'] }}</p>
                            @if (filled($variant['note'] ?? null))
                                <p class="mt-1 text-xs text-[var(--color-ink-soft)]">{{ $variant['note'] }}</p>
                            @endif
                            <p class="mt-2 text-[0.98rem] leading-8 font-medium tracking-[0.01em] [font-stretch:88%] text-[var(--color-ink-strong)]">
                                {{ $variant['final_label_text'] ?: 'No generated ingredient list yet.' }}
                            </p>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="mt-4 sk-inset px-4 py-3 text-[0.98rem] leading-8 font-medium tracking-[0.01em] [font-stretch:88%] text-[var(--color-ink-strong)]">
                    {{ $snapshot['labeling']['final_label_text'] ?? 'No generated ingredient list yet.' }}
                </div>
            @endif
        </section>

        <section class="overflow-hidden sk-card">
            <div class="border-b border-[var(--color-line)] px-5 py-4">
                <p class="sk-eyebrow">Declaration details</p>
                <p class="mt-1 text-sm text-[var(--color-ink-soft)]">Threshold-based declarations with their current contribution to the selected batch basis.</p>
            </div>

            @if (($snapshot['labeling']['declaration_rows'] ?? []) !== [])
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-[var(--color-line)] text-sm">
                        <thead class="bg-[var(--color-panel-strong)] text-left text-xs font-semibold tracking-[0.14em] text-[var(--color-ink-soft)] uppercase">
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
                    No declaration rows are available for this saved recipe yet.
                </div>
            @endif
        </section>
    @endif
</div>
