@php
    $state = $getState();
    $rows = is_array($state['rows'] ?? null) ? $state['rows'] : [];
    $sources = is_array($state['sources'] ?? null) ? $state['sources'] : [];
    $displayValue = static function (mixed $value): string {
        if ($value === null || $value === '') {
            return __('ingredient_enrichment_admin.review.no_value');
        }

        if (is_bool($value)) {
            return $value ? __('Yes') : __('No');
        }

        return is_scalar($value)
            ? (string) $value
            : json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    };
    $confidenceColor = static fn (?string $confidence): string => match ($confidence) {
        'verified' => 'success',
        'supported' => 'info',
        'conflicting' => 'danger',
        'unresolved' => 'warning',
        default => 'gray',
    };
@endphp

<x-dynamic-component :component="$getEntryWrapperView()" :entry="$entry">
    <div class="space-y-4">
        @forelse ($rows as $row)
            <section class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                <div class="flex flex-wrap items-center gap-2">
                    <h3 class="mr-auto text-sm font-semibold text-gray-950 dark:text-white">{{ $row['label'] }}</h3>
                    <x-filament::badge :color="$confidenceColor($row['confidence'])">
                        {{ $row['confidence'] ? __('ingredient_enrichment.confidence.'.$row['confidence']) : __('ingredient_enrichment_admin.review.no_confidence') }}
                    </x-filament::badge>
                    <x-filament::badge color="gray">{{ __('ingredient_enrichment_admin.decisions.'.$row['decision']) }}</x-filament::badge>
                </div>

                <div class="mt-3 grid gap-3 lg:grid-cols-2">
                    <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/5">
                        <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('ingredient_enrichment_admin.review.current') }}</div>
                        <pre class="mt-2 max-w-full whitespace-pre-wrap break-words font-sans text-sm text-gray-700 dark:text-gray-200">{{ $displayValue($row['current']) }}</pre>
                    </div>
                    <div class="rounded-lg bg-primary-50 p-3 dark:bg-primary-950/20">
                        <div class="text-xs font-semibold uppercase tracking-wide text-primary-700 dark:text-primary-300">{{ __('ingredient_enrichment_admin.review.proposed') }}</div>
                        <pre class="mt-2 max-w-full whitespace-pre-wrap break-words font-sans text-sm text-gray-950 dark:text-white">{{ $displayValue($row['proposed']) }}</pre>
                    </div>
                </div>

                @if ($row['conflict_explanation'])
                    <p class="mt-3 rounded-lg bg-warning-50 px-3 py-2 text-sm text-warning-800 dark:bg-warning-950/30 dark:text-warning-200">
                        {{ $row['conflict_explanation'] }}
                    </p>
                @endif

                @if ($row['evidence'] !== [])
                    <div class="mt-3 flex flex-wrap gap-x-4 gap-y-2 text-sm">
                        @foreach ($row['evidence'] as $evidence)
                            <a class="font-medium text-primary-700 underline decoration-primary-300 underline-offset-4 hover:text-primary-900 dark:text-primary-300"
                               href="{{ $evidence['url'] }}" target="_blank" rel="noopener noreferrer">
                                {{ $evidence['title'] }}
                            </a>
                            <span class="text-gray-500">
                                {{ $evidence['source_tier'] ? __('ingredient_enrichment.source_tiers.'.$evidence['source_tier']) : '' }}
                                @if ($evidence['version']) · {{ $evidence['version'] }} @endif
                                @if ($evidence['retrieved_at']) · {{ __('ingredient_enrichment_admin.review.retrieved', ['date' => $evidence['retrieved_at']]) }} @endif
                            </span>
                        @endforeach
                    </div>
                @endif
            </section>
        @empty
            <p class="text-sm text-gray-500">{{ __('ingredient_enrichment_admin.review.no_rows') }}</p>
        @endforelse

        @if ($sources !== [])
            <details class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-sm dark:border-white/10 dark:bg-white/5">
                <summary class="cursor-pointer font-medium text-gray-700 dark:text-gray-200">{{ __('ingredient_enrichment_admin.review.diagnostics') }}</summary>
                <pre class="mt-3 max-h-80 overflow-auto whitespace-pre-wrap break-words text-xs text-gray-600 dark:text-gray-300">{{ json_encode($sources, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
            </details>
        @endif
    </div>
</x-dynamic-component>
