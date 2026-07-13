@php
    $formatNumber = static fn (mixed $value, int $decimals = 2): string => rtrim(rtrim(number_format((float) $value, $decimals, '.', ''), '0'), '.');
    $formatSummaryNumber = static fn (mixed $value, mixed $unit): string => $unit === 'g'
        ? number_format((float) $value, 0, '.', '')
        : rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    $showDetails = $showDetails ?? false;
    $showSummary = $showSummary ?? true;
    $showIngredientLists = $showIngredientLists ?? $showDetails;
    $showLyeSection = $recipe->productFamily?->calculation_basis !== 'total_formula';
    $oilUnit = $snapshot['draft']['oilUnit'] ?? 'g';
@endphp

<div class="space-y-6">
    @if ($showSummary)
        <section class="grid gap-4 lg:grid-cols-4">
            @foreach ($summaryCards as $card)
                <article class="sk-card p-4">
                    <p class="sk-eyebrow">{{ $card['label'] }}</p>
                    <p class="numeric mt-2 text-2xl font-semibold text-[var(--color-ink-strong)]">
                        {{ $formatSummaryNumber($card['value'], $card['unit']) }}<span class="ml-1 text-sm font-medium text-[var(--color-ink-soft)]">{{ $card['unit'] }}</span>
                    </p>
                </article>
            @endforeach
        </section>
    @endif

    <section @class([
        'grid gap-4',
        'xl:grid-cols-[minmax(0,1.1fr)_minmax(0,0.9fr)]' => $showLyeSection,
    ])>
        <article class="sk-card p-5">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="sk-eyebrow">Recipe settings</p>
                    <h3 class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">How this recipe was calculated</h3>
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

        @if ($showLyeSection)
            <article class="sk-card p-5">
                <div>
                    <p class="sk-eyebrow">Lye and water</p>
                    <h3 class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">Amounts for this batch</h3>
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
                        Lye and water values are not available for this saved formula.
                    </div>
                @endif
            </article>
        @endif
    </section>

    <section class="space-y-4">
        @foreach ($phaseSections as $section)
            <article class="overflow-hidden sk-card">
                <div class="border-b border-[var(--color-line)] px-5 py-4">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p class="sk-eyebrow">{{ $section['label'] }}</p>
                            <p class="mt-1 text-sm text-[var(--color-ink-soft)]">Ingredient percentages come from the saved recipe. This sheet shows the actual weights for this batch size.</p>
                        </div>
                        <div class="flex flex-wrap gap-2 text-xs text-[var(--color-ink-soft)]">
                            <span class="numeric rounded-full border border-[var(--color-line)] bg-white px-3 py-1">{{ $formatNumber($section['total_percentage']) }}% {{ $section['basis_label'] ?? 'oils' }}</span>
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
                                <th class="px-5 py-3">% {{ $section['basis_label'] ?? 'oils' }}</th>
                                <th class="px-5 py-3">Weight ({{ $oilUnit }})</th>
                                <th class="px-5 py-3">Note</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--color-line)] bg-white">
                            @foreach ($section['rows'] as $row)
                                <tr>
                                    <td class="px-5 py-4 align-top">
                                        <p class="flex items-center gap-1.5 font-medium text-[var(--color-ink-strong)]">{{ $row['name'] }} <x-ingredient-source-marker :is-user-owned="$row['is_user_owned'] ?? false" /></p>
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

    <x-ingredient-source-legend :show="collect($phaseSections)->contains(fn (array $section): bool => collect($section['rows'] ?? [])->contains(fn (array $row): bool => (bool) ($row['is_user_owned'] ?? false)))" />

    @if ($recipe->description)
        <section class="sk-card p-5">
            <p class="sk-eyebrow">Product description</p>
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

    @if ($showIngredientLists)
        @php($printIngredientListText = $snapshot['labeling']['print_ingredient_list_text'] ?? ($snapshot['labeling']['final_label_text'] ?? ''))
        @php($printPlainIngredientListText = $snapshot['labeling']['print_plain_ingredient_list_text'] ?? ($snapshot['labeling']['plain_language_list']['final_label_text'] ?? ''))

        <section class="sk-card p-5">
            <p class="sk-eyebrow">Selected ingredients</p>
            <h3 class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">Final ingredient list</h3>
            <p class="mt-1 text-sm text-[var(--color-ink-soft)]">As it will appear on the label after processing.</p>

            <p class="mt-4 text-[0.98rem] leading-8 font-medium tracking-[0.01em] [font-stretch:88%] text-[var(--color-ink-strong)]">
                {{ $printIngredientListText ?: 'Save the recipe to generate an ingredient list.' }}
            </p>
            @if (($snapshot['labeling']['final_ingredient_list']['is_outdated'] ?? false) === true)
                <p class="mt-2 text-xs font-medium text-[var(--color-warning-strong)]">Ingredient list is out of date. Regenerate it from the current recipe.</p>
            @endif
        </section>

        <section class="sk-card p-5">
            <p class="sk-eyebrow">Selected ingredients</p>
            <h3 class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">Plain-language list</h3>

            <p class="mt-4 text-[0.98rem] leading-8 font-medium tracking-[0.01em] [font-stretch:88%] text-[var(--color-ink-strong)]">
                {{ $printPlainIngredientListText ?: 'Save the recipe to generate a plain-language list.' }}
            </p>
            @if (($snapshot['labeling']['plain_language_list']['is_outdated'] ?? false) === true)
                <p class="mt-2 text-xs font-medium text-[var(--color-warning-strong)]">Plain-language list is out of date. Regenerate it from the current recipe.</p>
            @endif
        </section>
    @endif
</div>
