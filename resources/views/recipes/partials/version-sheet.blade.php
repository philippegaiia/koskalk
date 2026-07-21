<div class="space-y-5">
    <section class="rounded-xl border border-[var(--color-line)] bg-[var(--color-panel)] px-4 py-3">
        <h2 class="sr-only">{{ __('formula_documents.sections.settings') }}</h2>
        <dl class="flex flex-wrap gap-x-6 gap-y-2 text-xs">
            @foreach ($formulaDocument['settings'] as $setting)
                <div class="flex gap-1.5">
                    <dt class="text-[var(--color-ink-soft)]">{{ $setting['label'] }}:</dt>
                    <dd class="font-medium text-[var(--color-ink-strong)]">{{ $setting['value'] }}</dd>
                </div>
            @endforeach
        </dl>
    </section>

    <x-formula-document.table :document="$formulaDocument" />
    <x-formula-document.results :document="$formulaDocument" />

    <x-ingredient-source-legend :show="collect($formulaDocument['sections'])->contains(fn (array $section): bool => collect($section['rows'] ?? [])->contains(fn (array $row): bool => (bool) ($row['is_user_owned'] ?? false)))" />

    @if (filled($formulaDocument['identity']['description'] ?? null))
        <section>
            <h2 class="text-sm font-semibold">{{ __('formula_documents.sections.description') }}</h2>
            <div class="prose prose-stone mt-2 max-w-none text-sm">{!! str($formulaDocument['identity']['description'])->sanitizeHtml() !!}</div>
        </section>
    @endif

    @if (filled($formulaDocument['identity']['manufacturing_procedure'] ?? null))
        <section>
            <h2 class="text-sm font-semibold">{{ __('formula_documents.sections.manufacturing_procedure') }}</h2>
            <div class="prose prose-stone mt-2 max-w-none text-sm">{!! str($formulaDocument['identity']['manufacturing_procedure'])->sanitizeHtml() !!}</div>
        </section>
    @endif

    @if (filled($formulaDocument['label_text']))
        <section>
            <h2 class="text-sm font-semibold">{{ __('formula_documents.sections.label') }}</h2>
            <p class="mt-2 text-sm leading-6 text-[var(--color-ink-strong)]">{{ $formulaDocument['label_text'] }}</p>
        </section>
    @endif
</div>
