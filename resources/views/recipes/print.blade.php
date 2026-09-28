@extends('layouts.print')

@section('title', $formulaDocument['identity']['name'].' · '.__('formula_documents.print.title').' · '.config('app.name'))
@section('print_document_class', 'formula-sheet-document')

@section('content')
    @php
        $identity = $formulaDocument['identity'];
        $description = (string) ($identity['description'] ?? '');
        $procedure = (string) ($identity['manufacturing_procedure'] ?? '');
        $descriptionText = html_entity_decode(strip_tags($description));
        $procedureText = html_entity_decode(strip_tags($procedure));
        $hasDescription = filled(preg_replace('/\s+/u', '', $descriptionText));
        $hasProcedure = filled(preg_replace('/\s+/u', '', $procedureText))
            || preg_match('/<img\b/i', $procedure) === 1;
        $analysis = $formulaDocument['soap_analysis'] ?? null;
        $hasAnalysis = is_array($analysis)
            && (filled($analysis['qualities'] ?? []) || filled($analysis['fatty_acids'] ?? []));
        $selectedSections = $selectedSections ?? ['formula', 'procedure', 'label', 'notes'];
        $availableSections = $availableSections ?? $selectedSections;
        $metadata = array_merge([
            'version_number' => null,
            'generated_at' => now()->format('Y-m-d H:i'),
        ], $formulaDocument['metadata'] ?? []);
        $numberLocale = \App\Support\NumberLocale::resolve($numberLocale ?? auth()->user()?->number_locale);
        $recipe = $recipe ?? null;
        $returnUrl = $returnUrl ?? ($recipe instanceof \App\Models\Recipe
            ? route('recipes.saved', ['recipe' => $recipe])
            : null);
    @endphp

    <div class="print-hidden mb-4 flex flex-wrap items-center justify-between gap-3 border border-slate-300 bg-white p-3">
        @if (filled($returnUrl))
            <a href="{{ $returnUrl }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium">{{ __('formula_documents.actions.back') }}</a>
        @else
            <span></span>
        @endif

        <x-formula-document.print-options
            :available-sections="$availableSections"
            :selected-sections="$selectedSections"
        />
    </div>

    <article class="document-sheet mx-auto max-w-5xl bg-white p-6 text-slate-900 print:mx-0 print:max-w-none print:p-0">
        <header class="border-b border-slate-400 pb-4">
            <p class="text-xs font-semibold tracking-[0.12em] text-slate-500 uppercase">{{ __('formula_documents.print.title') }}</p>
            <h1 class="mt-1 text-2xl font-semibold">{{ $identity['name'] }}</h1>

            <dl class="mt-3 grid grid-cols-2 gap-x-5 gap-y-2 text-xs sm:grid-cols-4">
                <div>
                    <dt class="font-semibold text-slate-600">{{ __('formula_documents.print.source') }}</dt>
                    <dd class="mt-0.5">{{ __('formula_documents.states.'.($identity['state'] ?? 'current')) }}</dd>
                </div>
                <div>
                    <dt class="font-semibold text-slate-600">{{ __('formula_documents.print.version') }}</dt>
                    <dd class="mt-0.5">{{ $metadata['version_number'] === null ? '—' : $metadata['version_number'] }}</dd>
                </div>
                <div>
                    <dt class="font-semibold text-slate-600">{{ __('formula_documents.print.'.($formulaDocument['percentage_basis'] === 'oils' ? 'oil_basis_quantity' : 'formula_basis_quantity')) }}</dt>
                    <dd class="numeric mt-0.5">{{ \App\Support\NumberLocale::formatAdaptiveDecimal($formulaDocument['basis_weight'], 2, 4, $numberLocale) }} {{ $formulaDocument['unit'] }}</dd>
                </div>
                <div>
                    <dt class="font-semibold text-slate-600">{{ __('formula_documents.print.generated_at') }}</dt>
                    <dd class="mt-0.5">{{ $metadata['generated_at'] }}</dd>
                </div>
            </dl>
        </header>

        @if ($hasDescription)
            <section class="mt-4 border-b border-slate-300 pb-4">
                <h2 class="text-xs font-semibold uppercase">{{ __('formula_documents.sections.description') }}</h2>
                <div class="prose prose-sm mt-2 max-w-none text-sm leading-6">{!! str($description)->sanitizeHtml() !!}</div>
            </section>
        @endif

        <section data-formula-section="formula" @if (! in_array('formula', $selectedSections, true)) hidden @endif class="mt-4">
            <x-formula-document.settings :document="$formulaDocument" />
            <x-formula-document.table :document="$formulaDocument" :number-locale="$numberLocale" class="mt-3" />
            <x-formula-document.results :document="$formulaDocument" class="mt-3" />
            <x-ingredient-source-legend :show="collect($formulaDocument['sections'])->contains(fn (array $section): bool => collect($section['rows'] ?? [])->contains(fn (array $row): bool => (bool) ($row['is_user_owned'] ?? false)))" />
        </section>

        @if ($hasProcedure)
            <x-formula-document.procedure
                :procedure="$procedure"
                :is-selected="in_array('procedure', $selectedSections, true)"
            />
        @endif

        @if (filled($formulaDocument['label_text'] ?? null))
            <x-formula-document.label-text
                :label-text="$formulaDocument['label_text']"
                :is-selected="in_array('label', $selectedSections, true)"
            />
        @endif

        @if ($hasAnalysis)
            <x-formula-document.soap-analysis
                :analysis="$analysis"
                :number-locale="$numberLocale"
                :is-selected="in_array('analysis', $selectedSections, true)"
            />
        @endif

        <x-formula-document.trial-notes :is-selected="in_array('notes', $selectedSections, true)" />
    </article>
@endsection
