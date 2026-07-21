@props(['document'])

<section {{ $attributes->merge(['class' => 'border-y border-[var(--color-line)] py-4']) }}>
    <h2 class="text-xs font-semibold tracking-[0.08em] text-[var(--color-ink-soft)] uppercase">
        {{ __('formula_documents.sections.results') }}
    </h2>
    <dl class="mt-3 grid gap-x-6 gap-y-3 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($document['results'] as $result)
            <div class="flex items-baseline justify-between gap-3 border-b border-[var(--color-line)] pb-2">
                <dt class="text-xs text-[var(--color-ink-soft)]">{{ $result['label'] }}</dt>
                <dd class="numeric text-sm font-semibold text-[var(--color-ink-strong)]">
                    {{ number_format($result['value'], 2) }} {{ $result['unit'] }}
                </dd>
            </div>
        @endforeach
    </dl>
</section>
