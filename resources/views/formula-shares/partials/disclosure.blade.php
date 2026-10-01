<section class="sk-card space-y-5 p-5">
    @php($numbers = app(\App\Services\FormulaShareDisplayNumbers::class))
    <h2 class="text-lg font-semibold">{{ __('sharing.disclosure') }}</h2>
    <p class="text-sm text-[var(--color-ink-soft)]">{{ __('sharing.private_disclosure') }}</p>
    <p class="text-sm text-[var(--color-ink-soft)]">{{ __('sharing.excluded') }}</p>
    @foreach ($display['warnings'] ?? [] as $warning)
        <p role="status" class="text-sm">{{ \Illuminate\Support\Facades\Lang::has('sharing.warnings.'.$warning) ? __('sharing.warnings.'.$warning) : __('sharing.review_required') }}</p>
    @endforeach
    <h3 class="font-semibold">{{ $display['product_name'] }}</h3>
    <dl class="grid gap-3 text-sm md:grid-cols-3">
        @foreach ($display['settings'] ?? [] as $key => $value)
            <div><dt class="font-semibold">{{ __('sharing.settings.'.$key) }}</dt><dd>{{ \Illuminate\Support\Facades\Lang::has('sharing.values.'.$value) ? __('sharing.values.'.$value) : $numbers->format($value, $numberLocale) }}</dd></div>
        @endforeach
        <div><dt class="font-semibold">{{ __('sharing.ifra_category') }}</dt><dd>{{ $display['ifra']['category_code'] ?? '—' }}</dd></div>
        <div><dt class="font-semibold">{{ __('sharing.ifra_amendment') }}</dt><dd>{{ $display['ifra']['amendment_code'] ?? '—' }}</dd></div>
    </dl>
    @foreach (['description', 'procedure'] as $text)
        @if (filled($display[$text] ?? null))
            <div><h4 class="font-semibold">{{ __('sharing.'.$text) }}</h4><p class="whitespace-pre-wrap text-sm">{{ $display[$text] }}</p></div>
        @endif
    @endforeach
    @php($ingredientNames = collect($display['ingredients'])->mapWithKeys(fn ($row) => [$row['key'] => ($row['mode'] ?? null) === 'substitute' && filled($row['local_name'] ?? null) ? $row['name'].' → '.$row['local_name'] : $row['name']]))
    @foreach ($display['phases'] as $phase)
        <div class="overflow-x-auto">
            <h4 class="mb-2 font-semibold">{{ $phase['name'] }}</h4>
            <table class="w-full text-left text-sm">
                <thead><tr><th class="p-2">{{ __('sharing.ingredient') }}</th><th class="p-2">{{ __('sharing.percentage') }}</th><th class="p-2">{{ __('sharing.weight') }}</th><th class="p-2">{{ __('sharing.note') }}</th></tr></thead>
                <tbody>@foreach ($phase['items'] as $item)<tr class="border-t border-[var(--color-line)]"><td class="p-2">{{ $ingredientNames[$item['ingredient_key']] ?? '' }}</td><td class="p-2 tabular-nums">{{ $numbers->format($item['percentage'], $numberLocale, 2) }}%</td><td class="p-2 tabular-nums">{{ $numbers->format($item['weight'], $numberLocale, 2) }} {{ $display['settings']['batch_unit'] ?? '' }}</td><td class="p-2 whitespace-pre-wrap">{{ $item['note'] }}</td></tr>@endforeach</tbody>
            </table>
        </div>
    @endforeach
    <h3 class="font-semibold">{{ __('sharing.materials') }}</h3>
    @foreach ($display['ingredients'] as $ingredient)
        <article class="rounded-lg border border-[var(--color-line)] p-4" wire:key="material-{{ $ingredient['key'] }}">
            <div class="flex flex-wrap items-center gap-2"><h4 class="font-semibold">{{ $ingredient['name'] }}</h4><span class="text-xs text-[var(--color-ink-soft)]">{{ __('sharing.'.$ingredient['kind']) }}</span></div>
            @if (isset($ingredient['mode']))<p class="mt-2 text-sm">{{ __('sharing.choice_modes.'.$ingredient['mode']) }} @if ($ingredient['local_name'] ?? null) · {{ $ingredient['local_name'] }} @endif</p>@endif
            @if ($ingredient['warning'] ?? null)<p class="mt-2 text-sm">{{ __('sharing.warnings.'.$ingredient['warning']) }}</p>@endif
            <details class="mt-3"><summary class="cursor-pointer text-sm font-medium">{{ __('sharing.technical_data') }}</summary>
                @if (isset($ingredient['identity']))
                    <div class="mt-3">@include('formula-shares.partials.technical-value', ['value' => $ingredient['identity']])</div>
                @endif
                <dl class="mt-3 grid gap-3 md:grid-cols-2">
                    @foreach ($ingredient['technical'] as $key => $value)
                        <div><dt class="text-sm font-semibold">{{ \Illuminate\Support\Facades\Lang::has('sharing.technical.'.$key) ? __('sharing.technical.'.$key) : \Illuminate\Support\Str::headline($key) }}</dt>
                            <dd class="mt-1 text-sm">
                                @if ($key === 'components')
                                    @foreach ($value as $component)<p>{{ $component['name'] }} · {{ $numbers->format($component['percentage_in_parent'], $numberLocale) }}%</p>@endforeach
                                @else
                                    @include('formula-shares.partials.technical-value', ['value' => $value])
                                @endif
                            </dd>
                        </div>
                    @endforeach
                </dl>
            </details>
            @if (($ingredient['differences'] ?? []) !== [])
                <details class="mt-3"><summary class="cursor-pointer text-sm font-medium">{{ __('sharing.differences') }}</summary>
                    @foreach ($ingredient['differences'] as $key)
                        <div class="mt-3 grid gap-3 md:grid-cols-2">
                            <div><h5 class="text-sm font-semibold">{{ __('sharing.incoming') }} · {{ \Illuminate\Support\Str::headline($key) }}</h5>@include('formula-shares.partials.technical-value', ['value' => $ingredient['technical'][$key]])</div>
                            <div><h5 class="text-sm font-semibold">{{ __('sharing.local') }}</h5>@include('formula-shares.partials.technical-value', ['value' => $ingredient['local_technical'][$key] ?? null])</div>
                        </div>
                    @endforeach
                </details>
            @endif
            @foreach ($ingredient['candidates'] ?? [] as $candidate)
                <p class="mt-2 text-sm">{{ $candidate['name'] }} · {{ implode(', ', array_map(fn ($key) => \Illuminate\Support\Str::headline($key), $candidate['differences'])) ?: __('sharing.no_differences') }}</p>
            @endforeach
        </article>
    @endforeach
</section>
