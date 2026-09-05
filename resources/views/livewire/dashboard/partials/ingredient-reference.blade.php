@php
    $notAvailable = __('ingredients.editor.common.not_available');
    $formatReferenceNumber = static function (mixed $value): string {
        if (! is_numeric($value)) {
            return '';
        }

        return rtrim(rtrim(number_format((float) $value, 5, '.', ''), '0'), '.');
    };
    $formatReferencePercentage = static function (mixed $value) use ($formatReferenceNumber): string {
        $formatted = $formatReferenceNumber($value);

        return $formatted === '' ? '' : $formatted.'%';
    };
    $classification = $referenceData['classification'] ?? [];
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

@if (filled($referenceData['material_code'] ?? null))
    <section class="sk-card p-5 sm:p-6" aria-labelledby="ingredient-reference-material-code">
        <p class="sk-eyebrow">{{ __('ingredients.editor.material_code.workspace_eyebrow') }}</p>
        <h2 id="ingredient-reference-material-code" class="mt-2 text-lg font-semibold text-[var(--color-ink-strong)]">
            {{ __('ingredients.editor.material_code.workspace_heading') }}
        </h2>
        @if (filled($workspaceName ?? null))
            <p class="mt-2 text-xs leading-5 text-[var(--color-ink-soft)]">
                {{ __('ingredients.editor.workspace_scope', ['workspace' => $workspaceName]) }}
            </p>
        @endif
        <dl class="mt-5 max-w-xl rounded-lg bg-[var(--color-field-muted)] px-4 py-3">
            <dt class="text-xs font-medium uppercase tracking-wide text-[var(--color-ink-soft)]">{{ __('ingredients.editor.material_code.label') }}</dt>
            <dd class="mt-1 text-sm font-medium text-[var(--color-ink-strong)]">{{ $referenceData['material_code'] }}</dd>
        </dl>
        <p class="mt-3 max-w-xl text-xs leading-5 text-[var(--color-ink-soft)]">{{ __('ingredients.editor.material_code.workspace_read_only') }}</p>
    </section>
@endif

<section class="sk-card p-5 sm:p-6" aria-labelledby="ingredient-reference-classification">
    <p class="sk-eyebrow">{{ __('ingredients.editor.reference.section') }}</p>
    <h2 id="ingredient-reference-classification" class="mt-2 text-lg font-semibold text-[var(--color-ink-strong)]">
        {{ __('ingredients.editor.reference.classification') }}
    </h2>
    <dl class="mt-5 grid gap-4 sm:grid-cols-2">
        <div>
            <dt class="text-xs font-medium uppercase tracking-wide text-[var(--color-ink-soft)]">{{ __('ingredients.editor.reference.category') }}</dt>
            <dd class="mt-1 text-sm text-[var(--color-ink-strong)]">{{ data_get($classification, 'category.label') ?: $notAvailable }}</dd>
        </div>
        <div>
            <dt class="text-xs font-medium uppercase tracking-wide text-[var(--color-ink-soft)]">{{ __('ingredients.editor.reference.subcategory') }}</dt>
            <dd class="mt-1 text-sm text-[var(--color-ink-strong)]">{{ data_get($classification, 'subcategory.label') ?: $notAvailable }}</dd>
        </div>
        <div>
            <dt class="text-xs font-medium uppercase tracking-wide text-[var(--color-ink-soft)]">{{ __('ingredients.editor.reference.aromatic_compliance') }}</dt>
            <dd class="mt-1 text-sm text-[var(--color-ink-strong)]">{{ data_get($classification, 'requires_aromatic_compliance') ? __('ingredients.editor.reference.required') : __('ingredients.editor.reference.not_required') }}</dd>
        </div>
    </dl>

    @if (count($referenceData['functions'] ?? []) > 0)
        <div class="mt-5">
            <h3 class="text-sm font-semibold text-[var(--color-ink-strong)]">{{ __('ingredients.editor.reference.functions') }}</h3>
            <ul class="mt-2 space-y-2 text-sm text-[var(--color-ink-soft)]">
                @foreach ($referenceData['functions'] as $function)
                    <li>
                        <span class="font-medium text-[var(--color-ink-strong)]">{{ $function['name'] }}</span>
                        @if (filled($function['description'] ?? null))
                            <span> — {{ $function['description'] }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</section>

@if (($referenceData['ingredient_structure'] ?? 'ingredient') === 'blend')
    <section class="sk-card p-5 sm:p-6" aria-labelledby="ingredient-reference-composition">
        <p class="sk-eyebrow">{{ __('ingredients.editor.reference.technical_eyebrow') }}</p>
        <h2 id="ingredient-reference-composition" class="mt-2 text-lg font-semibold text-[var(--color-ink-strong)]">
            {{ __('ingredients.editor.reference.composition') }}
        </h2>
        @if (count($referenceData['components'] ?? []) > 0)
            <div class="mt-5 overflow-x-auto">
                <table class="w-full min-w-[32rem] text-left text-sm">
                    <caption class="sr-only">{{ __('ingredients.editor.reference.composition') }}</caption>
                    <thead class="border-b border-[var(--color-line)] text-xs uppercase tracking-wide text-[var(--color-ink-soft)]">
                        <tr>
                            <th scope="col" class="pb-3 pr-4 font-medium">{{ __('ingredients.editor.reference.component') }}</th>
                            <th scope="col" class="pb-3 pr-4 font-medium">{{ __('ingredients.editor.reference.percentage') }}</th>
                            <th scope="col" class="pb-3 font-medium">{{ __('ingredients.editor.reference.source_notes') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--color-line)]">
                        @foreach ($referenceData['components'] as $component)
                            <tr>
                                <th scope="row" class="py-3 pr-4 font-medium text-[var(--color-ink-strong)]">
                                    {{ $component['name'] ?: $notAvailable }}
                                    @if (filled($component['inci_name'] ?? null))
                                        <span class="block text-xs font-normal text-[var(--color-ink-soft)]">{{ $component['inci_name'] }}</span>
                                    @endif
                                </th>
                                <td class="py-3 pr-4 tabular-nums text-[var(--color-ink-strong)]">{{ $formatReferencePercentage($component['percentage'] ?? null) ?: $notAvailable }}</td>
                                <td class="py-3 text-[var(--color-ink-soft)]">{{ $component['source_notes'] ?: $notAvailable }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="mt-4 text-sm text-[var(--color-ink-soft)]">{{ $notAvailable }}</p>
        @endif
        @if (filled($referenceData['composition_source_notes'] ?? null))
            <p class="mt-5 whitespace-pre-line text-sm text-[var(--color-ink-soft)]">
                <span class="font-medium text-[var(--color-ink-strong)]">{{ __('ingredients.editor.reference.composition_source') }}:</span>
                {{ $referenceData['composition_source_notes'] }}
            </p>
        @endif
    </section>
@endif

@if (filled(data_get($referenceData, 'guidance.html')))
    <section class="sk-card p-5 sm:p-6" aria-labelledby="ingredient-reference-guidance">
        <p class="sk-eyebrow">{{ __('ingredients.editor.reference.technical_eyebrow') }}</p>
        <h2 id="ingredient-reference-guidance" class="mt-2 text-lg font-semibold text-[var(--color-ink-strong)]">
            {{ __('ingredients.editor.reference.guidance') }}
        </h2>
        <div class="sk-rich-content mt-5 max-w-none">
            {!! data_get($referenceData, 'guidance.html') !!}
        </div>
    </section>
@endif

@if (count($referenceData['documents'] ?? []) > 0)
    <section class="sk-card p-5 sm:p-6" aria-labelledby="ingredient-reference-documents">
        <p class="sk-eyebrow">{{ __('ingredients.editor.reference.technical_eyebrow') }}</p>
        <h2 id="ingredient-reference-documents" class="mt-2 text-lg font-semibold text-[var(--color-ink-strong)]">
            {{ __('ingredients.editor.reference.documents') }}
        </h2>
        <ul class="mt-5 space-y-3 text-sm">
            @foreach ($referenceData['documents'] as $document)
                <li>
                    <a href="{{ $document['download_url'] }}" class="font-medium text-[var(--color-accent-strong)] underline decoration-[var(--color-accent-soft)] underline-offset-2 hover:text-[var(--color-accent-hover)]">
                        {{ $document['name'] ?: $notAvailable }}
                    </a>
                </li>
            @endforeach
        </ul>
    </section>
@endif

@if (is_array($referenceData['soap'] ?? null))
    <section class="sk-card p-5 sm:p-6" aria-labelledby="ingredient-reference-soap">
        <p class="sk-eyebrow">{{ __('ingredients.editor.reference.technical_eyebrow') }}</p>
        <h2 id="ingredient-reference-soap" class="mt-2 text-lg font-semibold text-[var(--color-ink-strong)]">
            {{ __('ingredients.editor.reference.soap_chemistry') }}
        </h2>
        <dl class="mt-5 grid gap-4 sm:grid-cols-2">
            <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-[var(--color-ink-soft)]">{{ __('ingredients.editor.reference.koh_sap') }}</dt>
                <dd class="mt-1 tabular-nums text-sm text-[var(--color-ink-strong)]">{{ $formatReferenceNumber($referenceData['soap']['koh_sap_value'] ?? null) ?: $notAvailable }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-[var(--color-ink-soft)]">{{ __('ingredients.editor.reference.naoh_sap') }}</dt>
                <dd class="mt-1 tabular-nums text-sm text-[var(--color-ink-strong)]">{{ $formatReferenceNumber($referenceData['soap']['naoh_sap_value'] ?? null) ?: $notAvailable }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-[var(--color-ink-soft)]">{{ __('ingredients.editor.reference.iodine') }}</dt>
                <dd class="mt-1 tabular-nums text-sm text-[var(--color-ink-strong)]">{{ $formatReferenceNumber($referenceData['soap']['iodine_value'] ?? null) ?: $notAvailable }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-[var(--color-ink-soft)]">{{ __('ingredients.editor.reference.ins') }}</dt>
                <dd class="mt-1 tabular-nums text-sm text-[var(--color-ink-strong)]">{{ $formatReferenceNumber($referenceData['soap']['ins_value'] ?? null) ?: $notAvailable }}</dd>
            </div>
        </dl>
        @if (filled($referenceData['soap']['source_notes'] ?? null))
            <p class="mt-5 whitespace-pre-line text-sm text-[var(--color-ink-soft)]">
                <span class="font-medium text-[var(--color-ink-strong)]">{{ __('ingredients.editor.reference.source_notes') }}:</span>
                {{ $referenceData['soap']['source_notes'] }}
            </p>
        @endif
        @if (count($referenceData['soap']['fatty_acids'] ?? []) > 0)
            <div class="mt-6">
                <h3 class="text-sm font-semibold text-[var(--color-ink-strong)]">{{ __('ingredients.editor.reference.fatty_acids') }}</h3>
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full min-w-[28rem] text-left text-sm">
                        <caption class="sr-only">{{ __('ingredients.editor.reference.fatty_acids') }}</caption>
                        <thead class="border-b border-[var(--color-line)] text-xs uppercase tracking-wide text-[var(--color-ink-soft)]">
                            <tr>
                                <th scope="col" class="pb-3 pr-4 font-medium">{{ __('ingredients.editor.reference.fatty_acid') }}</th>
                                <th scope="col" class="pb-3 pr-4 font-medium">{{ __('ingredients.editor.reference.percentage') }}</th>
                                <th scope="col" class="pb-3 font-medium">{{ __('ingredients.editor.reference.source_notes') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--color-line)]">
                            @foreach ($referenceData['soap']['fatty_acids'] as $fattyAcid)
                                <tr>
                                    <th scope="row" class="py-3 pr-4 font-medium text-[var(--color-ink-strong)]">{{ $fattyAcid['name'] }}</th>
                                    <td class="py-3 pr-4 tabular-nums text-[var(--color-ink-strong)]">{{ $formatReferencePercentage($fattyAcid['percentage'] ?? null) ?: $notAvailable }}</td>
                                    <td class="py-3 text-[var(--color-ink-soft)]">{{ $fattyAcid['source_notes'] ?: $notAvailable }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </section>
@endif

@if (count($referenceData['allergens'] ?? []) > 0)
    <section class="sk-card p-5 sm:p-6" aria-labelledby="ingredient-reference-allergens">
        <p class="sk-eyebrow">{{ __('ingredients.editor.reference.technical_eyebrow') }}</p>
        <h2 id="ingredient-reference-allergens" class="mt-2 text-lg font-semibold text-[var(--color-ink-strong)]">
            {{ __('ingredients.editor.reference.allergens') }}
        </h2>
        <ul class="mt-5 divide-y divide-[var(--color-line)] text-sm">
            @foreach ($referenceData['allergens'] as $allergen)
                <li class="flex flex-wrap items-baseline justify-between gap-3 py-3 first:pt-0 last:pb-0">
                    <span class="font-medium text-[var(--color-ink-strong)]">{{ $allergen['name'] }}</span>
                    <span class="tabular-nums text-[var(--color-ink-soft)]">{{ $formatReferencePercentage($allergen['concentration'] ?? null) ?: $notAvailable }}</span>
                    @if (filled($allergen['source_notes'] ?? null))
                        <span class="basis-full text-[var(--color-ink-soft)]">{{ $allergen['source_notes'] }}</span>
                    @endif
                </li>
            @endforeach
        </ul>
        @if (filled($referenceData['allergen_source_notes'] ?? null))
            <p class="mt-5 whitespace-pre-line text-sm text-[var(--color-ink-soft)]">
                <span class="font-medium text-[var(--color-ink-strong)]">{{ __('ingredients.editor.reference.source_notes') }}:</span>
                {{ $referenceData['allergen_source_notes'] }}
            </p>
        @endif
    </section>
@endif

@if (count($referenceData['substances'] ?? []) > 0)
    <section class="sk-card p-5 sm:p-6" aria-labelledby="ingredient-reference-substances">
        <p class="sk-eyebrow">{{ __('ingredients.editor.reference.technical_eyebrow') }}</p>
        <h2 id="ingredient-reference-substances" class="mt-2 text-lg font-semibold text-[var(--color-ink-strong)]">
            {{ __('ingredients.editor.reference.substances') }}
        </h2>
        <ul class="mt-5 divide-y divide-[var(--color-line)] text-sm">
            @foreach ($referenceData['substances'] as $substance)
                <li class="flex flex-wrap items-baseline justify-between gap-3 py-3 first:pt-0 last:pb-0">
                    <span class="font-medium text-[var(--color-ink-strong)]">{{ $substance['name'] }}</span>
                    <span class="tabular-nums text-[var(--color-ink-soft)]">{{ $formatReferencePercentage($substance['concentration'] ?? null) ?: $notAvailable }}</span>
                    @if (filled($substance['source_notes'] ?? null))
                        <span class="basis-full text-[var(--color-ink-soft)]">{{ $substance['source_notes'] }}</span>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>
@endif

@if (is_array($referenceData['ifra'] ?? null))
    <section class="sk-card p-5 sm:p-6" aria-labelledby="ingredient-reference-ifra">
        <p class="sk-eyebrow">{{ __('ingredients.editor.reference.technical_eyebrow') }}</p>
        <h2 id="ingredient-reference-ifra" class="mt-2 text-lg font-semibold text-[var(--color-ink-strong)]">
            {{ __('ingredients.editor.reference.ifra') }}
        </h2>
        <dl class="mt-5 grid gap-4 sm:grid-cols-2">
            <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-[var(--color-ink-soft)]">{{ __('ingredients.editor.reference.reference_label') }}</dt>
                <dd class="mt-1 text-sm text-[var(--color-ink-strong)]">{{ $referenceData['ifra']['reference_label'] ?: $notAvailable }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-[var(--color-ink-soft)]">{{ __('ingredients.editor.reference.amendment') }}</dt>
                <dd class="mt-1 text-sm text-[var(--color-ink-strong)]">{{ $referenceData['ifra']['source_amendment_label'] ?: ($referenceData['ifra']['amendment'] ?: $notAvailable) }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-[var(--color-ink-soft)]">{{ __('ingredients.editor.reference.peroxide') }}</dt>
                <dd class="mt-1 tabular-nums text-sm text-[var(--color-ink-strong)]">{{ $formatReferenceNumber($referenceData['ifra']['peroxide_value'] ?? null) ?: $notAvailable }}</dd>
            </div>
        </dl>
        @if (filled($referenceData['ifra']['source_notes'] ?? null))
            <p class="mt-5 whitespace-pre-line text-sm text-[var(--color-ink-soft)]">
                <span class="font-medium text-[var(--color-ink-strong)]">{{ __('ingredients.editor.reference.source_notes') }}:</span>
                {{ $referenceData['ifra']['source_notes'] }}
            </p>
        @endif
        @if (count($referenceData['ifra']['limits'] ?? []) > 0)
            <div class="mt-6 overflow-x-auto">
                <table class="w-full min-w-[28rem] text-left text-sm">
                    <caption class="sr-only">{{ __('ingredients.editor.reference.ifra_limits') }}</caption>
                    <thead class="border-b border-[var(--color-line)] text-xs uppercase tracking-wide text-[var(--color-ink-soft)]">
                        <tr>
                            <th scope="col" class="pb-3 pr-4 font-medium">{{ __('ingredients.editor.reference.ifra_category') }}</th>
                            <th scope="col" class="pb-3 pr-4 font-medium">{{ __('ingredients.editor.reference.maximum') }}</th>
                            <th scope="col" class="pb-3 font-medium">{{ __('ingredients.editor.reference.restriction_note') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--color-line)]">
                        @foreach ($referenceData['ifra']['limits'] as $limit)
                            <tr>
                                <th scope="row" class="py-3 pr-4 font-medium text-[var(--color-ink-strong)]">{{ $limit['category'] ?: ($limit['code'] ?: $notAvailable) }}</th>
                                <td class="py-3 pr-4 tabular-nums text-[var(--color-ink-strong)]">{{ $formatReferencePercentage($limit['max_percentage'] ?? null) ?: $notAvailable }}</td>
                                <td class="py-3 text-[var(--color-ink-soft)]">{{ $limit['restriction_note'] ?: $notAvailable }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endif
