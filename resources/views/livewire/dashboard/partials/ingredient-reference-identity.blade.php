@php
    $notAvailable = __('ingredients.editor.common.not_available');
    $identity = $referenceData['identity'] ?? [];
@endphp

<section class="sk-card p-5 sm:p-6" aria-labelledby="ingredient-reference-identity">
    <p class="sk-eyebrow">{{ __('ingredients.editor.reference.technical_eyebrow') }}</p>
    <h2 id="ingredient-reference-identity" class="mt-2 text-xl font-semibold text-[var(--color-ink-strong)]">
        {{ $referenceData['name'] ?: $notAvailable }}
    </h2>
    <dl class="mt-5 grid gap-4 sm:grid-cols-2">
        <div class="rounded-lg bg-[var(--color-field-muted)] px-4 py-3">
            <dt class="text-xs font-medium uppercase tracking-wide text-[var(--color-ink-soft)]">{{ __('ingredients.editor.reference.inci') }}</dt>
            <dd class="mt-1 text-sm font-medium text-[var(--color-ink-strong)]">{{ $identity['inci_name'] ?? $notAvailable }}</dd>
        </div>
        <div class="rounded-lg bg-[var(--color-field-muted)] px-4 py-3">
            <dt class="text-xs font-medium uppercase tracking-wide text-[var(--color-ink-soft)]">{{ __('ingredients.editor.reference.cas_number') }}</dt>
            <dd class="mt-1 text-sm font-medium text-[var(--color-ink-strong)]">{{ $identity['cas_number'] ?? $notAvailable }}</dd>
        </div>
        <div class="rounded-lg bg-[var(--color-field-muted)] px-4 py-3">
            <dt class="text-xs font-medium uppercase tracking-wide text-[var(--color-ink-soft)]">{{ __('ingredients.editor.reference.ec_number') }}</dt>
            <dd class="mt-1 text-sm font-medium text-[var(--color-ink-strong)]">{{ $identity['ec_number'] ?? $notAvailable }}</dd>
        </div>
        @if (filled($referenceData['notes'] ?? null))
            <div class="rounded-lg bg-[var(--color-field-muted)] px-4 py-3 sm:col-span-2">
                <dt class="text-xs font-medium uppercase tracking-wide text-[var(--color-ink-soft)]">{{ __('ingredients.editor.reference.notes') }}</dt>
                <dd class="mt-1 whitespace-pre-line text-sm text-[var(--color-ink-strong)]">{{ $referenceData['notes'] }}</dd>
            </div>
        @endif
    </dl>

    @if (count($referenceData['additional_identifiers'] ?? []) > 0)
        <div class="mt-5">
            <h3 class="text-sm font-semibold text-[var(--color-ink-strong)]">{{ __('ingredients.editor.reference.additional_identifiers') }}</h3>
            <ul class="mt-2 space-y-2 text-sm text-[var(--color-ink-soft)]">
                @foreach ($referenceData['additional_identifiers'] as $identifier)
                    <li>
                        <span class="font-medium text-[var(--color-ink-strong)]">{{ $identifier['label'] ?? $identifier['scheme'] }}</span>:
                        {{ $identifier['value'] ?: $notAvailable }}
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if (count($referenceData['aliases'] ?? []) > 0)
        <div class="mt-5">
            <h3 class="text-sm font-semibold text-[var(--color-ink-strong)]">{{ __('ingredients.editor.reference.aliases') }}</h3>
            <ul class="mt-2 flex flex-wrap gap-2">
                @foreach ($referenceData['aliases'] as $alias)
                    <li class="rounded-full border border-[var(--color-line)] bg-[var(--color-panel)] px-3 py-1.5 text-sm text-[var(--color-ink-strong)]">
                        {{ $alias['name'] }}
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</section>
