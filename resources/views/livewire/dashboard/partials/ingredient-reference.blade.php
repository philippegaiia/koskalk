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
    $displayReferenceNumber = static function (mixed $value) use ($formatReferenceNumber, $notAvailable): string {
        $formatted = $formatReferenceNumber($value);

        return $formatted === '' ? $notAvailable : $formatted;
    };
    $displayReferencePercentage = static function (mixed $value) use ($formatReferencePercentage, $notAvailable): string {
        $formatted = $formatReferencePercentage($value);

        return $formatted === '' ? $notAvailable : $formatted;
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

@if ($isPlatformIngredient)
    <section class="sk-card p-5 sm:p-6" aria-labelledby="workspace-guidance-heading">
        <div class="flex flex-col gap-1">
            <p class="sk-eyebrow">{{ __('ingredients.editor.workspace_guidance.eyebrow') }}</p>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 id="workspace-guidance-heading" class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('ingredients.editor.workspace_guidance.heading') }}</h2>
                <span class="rounded-full border border-[var(--color-line)] bg-[var(--color-field-muted)] px-3 py-1 text-xs font-medium text-[var(--color-ink-soft)]">
                    {{ $workspaceGuidanceOverride?->is_active ? __('ingredients.editor.workspace_guidance.workspace_badge') : __('ingredients.editor.workspace_guidance.platform_badge') }}
                </span>
            </div>
            <p class="text-sm leading-6 text-[var(--color-ink-soft)]">
                {{ $canEditWorkspaceGuidance ? __('ingredients.editor.workspace_guidance.helper', ['max' => \App\Services\WorkspaceIngredientGuidanceService::MAX_LENGTH]) : __('ingredients.editor.workspace_guidance.read_only') }}
            </p>
            @if (filled($workspaceName))
                <p class="text-xs leading-5 text-[var(--color-ink-soft)]">
                    {{ __('ingredients.editor.workspace_scope', ['workspace' => $workspaceName]) }}
                </p>
            @endif
        </div>

        @if (! $isEditingWorkspaceGuidance)
            <div data-ingredient-guidance-preview class="sk-rich-content mt-5 max-w-none">
                @if (filled($effectiveWorkspaceGuidance))
                    {!! $effectiveWorkspaceGuidance !!}
                @else
                    <p class="text-sm text-[var(--color-ink-soft)]">{{ __('ingredients.editor.common.not_available') }}</p>
                @endif
            </div>
        @endif

        @if ($isEditingWorkspaceGuidance && $canEditWorkspaceGuidance)
            <form wire:submit="saveWorkspaceGuidance" data-ingredient-scope="guidance" class="mt-5 max-w-2xl space-y-3">
                {{ $this->workspaceGuidanceForm }}
                <div class="flex flex-wrap gap-3">
                    <p data-ingredient-editor-status="guidance" class="mr-auto self-center text-sm text-[var(--color-ink-soft)]" role="status" aria-live="polite" aria-atomic="true" x-text="statusText('guidance')"></p>
                    <button type="submit" wire:loading.attr="disabled" wire:target="saveWorkspaceGuidance" class="sk-btn sk-btn-primary">
                        {{ __('ingredients.editor.workspace_guidance.save') }}
                    </button>
                    <button type="button" wire:click="cancelWorkspaceGuidanceCustomization" data-ingredient-editor-local-cancel="guidance" wire:loading.attr="disabled" wire:target="cancelWorkspaceGuidanceCustomization" class="sk-btn sk-btn-ghost">
                        {{ __('ingredients.editor.workspace_guidance.cancel') }}
                    </button>
                </div>
            </form>
        @elseif ($canEditWorkspaceGuidance)
            <div class="mt-5 flex flex-wrap gap-3">
                @if ($workspaceGuidanceOverride?->is_active)
                    <button type="button" wire:click="startWorkspaceGuidanceCustomization" wire:loading.attr="disabled" wire:target="startWorkspaceGuidanceCustomization" class="sk-btn sk-btn-secondary">
                        {{ __('ingredients.editor.workspace_guidance.edit') }}
                    </button>
                    <button type="button" wire:click="usePlatformGuidance" data-ingredient-guidance-replace="usePlatformGuidance" data-ingredient-guidance-confirm="{{ __('ingredients.editor.workspace_guidance.platform_confirm') }}" wire:confirm="{{ __('ingredients.editor.workspace_guidance.platform_confirm') }}" wire:loading.attr="disabled" wire:target="usePlatformGuidance" class="sk-btn sk-btn-ghost">
                        {{ __('ingredients.editor.workspace_guidance.use_platform') }}
                    </button>
                @elseif ($workspaceGuidanceOverride)
                    <button type="button" wire:click="startWorkspaceGuidanceCustomization" wire:loading.attr="disabled" wire:target="startWorkspaceGuidanceCustomization" class="sk-btn sk-btn-secondary">
                        {{ __('ingredients.editor.workspace_guidance.edit') }}
                    </button>
                    <button type="button" wire:click="useWorkspaceGuidance" data-ingredient-guidance-replace="useWorkspaceGuidance" wire:loading.attr="disabled" wire:target="useWorkspaceGuidance" class="sk-btn sk-btn-primary">
                        {{ __('ingredients.editor.workspace_guidance.use_workspace') }}
                    </button>
                @else
                    <button type="button" wire:click="startWorkspaceGuidanceCustomization" wire:loading.attr="disabled" wire:target="startWorkspaceGuidanceCustomization" class="sk-btn sk-btn-primary">
                        {{ __('ingredients.editor.workspace_guidance.customize') }}
                    </button>
                @endif
            </div>
        @endif
    </section>

    <section class="sk-card p-5 sm:p-6" aria-labelledby="platform-material-code-heading">
        <div class="flex flex-col gap-1">
            <p class="sk-eyebrow">{{ __('ingredients.editor.material_code.workspace_eyebrow') }}</p>
            <h2 id="platform-material-code-heading" class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('ingredients.editor.material_code.workspace_heading') }}</h2>
            <p class="text-sm leading-6 text-[var(--color-ink-soft)]">{{ __('ingredients.editor.material_code.workspace_helper') }}</p>
        </div>
        @if ($canEditWorkspaceMaterialCode)
            <form wire:submit="saveWorkspaceMaterialCode" data-ingredient-scope="material-code" class="mt-5 max-w-xl space-y-3">
                <label for="workspace-material-code" class="block text-sm font-medium text-[var(--color-ink-strong)]">{{ __('ingredients.editor.material_code.label') }}</label>
                <input
                    id="workspace-material-code"
                    type="text"
                    wire:model="workspaceMaterialCode"
                    maxlength="64"
                    placeholder="{{ __('ingredients.editor.material_code.placeholder') }}"
                    class="sk-field-control w-full"
                    aria-describedby="workspace-material-code-help"
                    aria-invalid="{{ $errors->has('workspaceMaterialCode') ? 'true' : 'false' }}"
                />
                <p id="workspace-material-code-help" class="text-xs leading-5 text-[var(--color-ink-soft)]">{{ __('ingredients.editor.material_code.helper') }}</p>
                @error('workspaceMaterialCode')
                    <p class="text-sm text-[var(--color-danger-strong)]" role="alert">{{ $message }}</p>
                @enderror
                <div class="flex flex-wrap items-center gap-3">
                    <p data-ingredient-editor-status="material-code" class="mr-auto text-sm text-[var(--color-ink-soft)]" role="status" aria-live="polite" aria-atomic="true" x-text="statusText('material-code')"></p>
                    <button type="submit" wire:loading.attr="disabled" wire:target="saveWorkspaceMaterialCode" class="sk-btn sk-btn-primary">
                        {{ __('ingredients.editor.material_code.save') }}
                    </button>
                </div>
            </form>
        @else
            <dl class="mt-5 max-w-xl rounded-lg bg-[var(--color-field-muted)] px-4 py-3">
                <dt class="text-xs font-medium uppercase tracking-wide text-[var(--color-ink-soft)]">{{ __('ingredients.editor.material_code.label') }}</dt>
                <dd class="mt-1 text-sm font-medium text-[var(--color-ink-strong)]">{{ $workspaceMaterialCode ?: __('ingredients.editor.common.not_available') }}</dd>
            </dl>
            <p class="mt-3 max-w-xl text-xs leading-5 text-[var(--color-ink-soft)]">{{ __('ingredients.editor.material_code.workspace_read_only') }}</p>
        @endif
    </section>
@endif

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
                                <td class="py-3 pr-4 tabular-nums text-[var(--color-ink-strong)]">{{ $displayReferencePercentage($component['percentage'] ?? null) }}</td>
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

@if (! $isPlatformIngredient && filled(data_get($referenceData, 'guidance.html')))
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
                <dd class="mt-1 tabular-nums text-sm text-[var(--color-ink-strong)]">{{ $displayReferenceNumber($referenceData['soap']['koh_sap_value'] ?? null) }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-[var(--color-ink-soft)]">{{ __('ingredients.editor.reference.naoh_sap') }}</dt>
                <dd class="mt-1 tabular-nums text-sm text-[var(--color-ink-strong)]">{{ $displayReferenceNumber($referenceData['soap']['naoh_sap_value'] ?? null) }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-[var(--color-ink-soft)]">{{ __('ingredients.editor.reference.iodine') }}</dt>
                <dd class="mt-1 tabular-nums text-sm text-[var(--color-ink-strong)]">{{ $displayReferenceNumber($referenceData['soap']['iodine_value'] ?? null) }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-[var(--color-ink-soft)]">{{ __('ingredients.editor.reference.ins') }}</dt>
                <dd class="mt-1 tabular-nums text-sm text-[var(--color-ink-strong)]">{{ $displayReferenceNumber($referenceData['soap']['ins_value'] ?? null) }}</dd>
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
                                    <td class="py-3 pr-4 tabular-nums text-[var(--color-ink-strong)]">{{ $displayReferencePercentage($fattyAcid['percentage'] ?? null) }}</td>
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
                    <span class="tabular-nums text-[var(--color-ink-soft)]">{{ $displayReferencePercentage($allergen['concentration'] ?? null) }}</span>
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
                    <span class="tabular-nums text-[var(--color-ink-soft)]">{{ $displayReferencePercentage($substance['concentration'] ?? null) }}</span>
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
                <dd class="mt-1 tabular-nums text-sm text-[var(--color-ink-strong)]">{{ $displayReferenceNumber($referenceData['ifra']['peroxide_value'] ?? null) }}</dd>
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
                                <td class="py-3 pr-4 tabular-nums text-[var(--color-ink-strong)]">{{ $displayReferencePercentage($limit['max_percentage'] ?? null) }}</td>
                                <td class="py-3 text-[var(--color-ink-soft)]">{{ $limit['restriction_note'] ?: $notAvailable }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endif
