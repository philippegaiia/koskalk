@php
 $isCreate = $ingredientId === null;
 $isPlatformIngredient = $ingredient !== null
     && $ingredient->owner_type === null
     && $ingredient->owner_id === null
     && $ingredient->workspace_id === null;
 $isReadOnlyIngredient = ! $isCreate && ! $isPlatformIngredient && ! $canEditIngredientData;
 $ingredientContext = $ingredient?->localizedDisplayName() ?: __('ingredients.editor.common.new_ingredient');
 $isCarrierOil = \App\Enums\IngredientCategory::tryFrom((string) ($data['category'] ?? '')) === \App\Enums\IngredientCategory::Lipids;
 $destinationWorkspaceName = filled($workspaceName)
     ? $workspaceName
     : __('ingredients.editor.composition.workspace_fallback');
@endphp

<div>
<div
 x-data="ingredientEditor({
     element: $el,
     isCreate: @js($isCreate),
     labels: @js([
         'saved' => __('ingredients.editor.status.all_saved'),
         'notCreated' => __('ingredients.editor.status.not_created'),
         'dirty' => __('ingredients.editor.status.unsaved'),
         'saving' => __('ingredients.editor.status.saving'),
         'failed' => __('ingredients.editor.status.save_failed'),
         'leaveWarning' => __('ingredients.editor.status.leave_warning'),
         'replaceGuidance' => __('ingredients.editor.status.replace_guidance'),
         'cancelGuidance' => __('ingredients.editor.status.cancel_guidance'),
     ]),
     baselines: @js([
         'ingredient' => $data,
         'guidance' => $workspaceGuidance,
         'material-code' => $workspaceMaterialCode,
     ]),
     editable: @js([
         'ingredient' => $canEditIngredientData,
         'guidance' => $canEditWorkspaceGuidance,
         'material-code' => $canEditWorkspaceMaterialCode,
     ]),
     read: (scope) => $wire.get(scope === 'ingredient' ? 'data' : (scope === 'guidance' ? 'workspaceGuidance' : 'workspaceMaterialCode')),
     watch: (path, callback) => $wire.$watch(path, callback),
     on: (event, callback) => $wire.$on(event, callback),
     hook: (event, callback) => $wire.$hook(event, callback),
     interceptRequest: (method, callback) => $wire.$interceptRequest(method, callback),
     invoke: (method) => $wire[method](),
 })"
 x-init="init()"
 wire:ignore.self
 data-ingredient-editor
 class="mx-auto w-full max-w-app space-y-6">
 <section aria-labelledby="ingredient-editor-title">
 <nav aria-label="{{ __('ingredients.editor.common.breadcrumb') }}" class="flex min-h-10 flex-wrap items-center gap-2 text-sm font-medium text-[var(--color-ink-soft)]">
 <a href="{{ route('ingredients.index') }}" wire:navigate class="inline-flex min-h-10 items-center rounded-md text-[var(--color-accent-strong)] transition hover:text-[var(--color-accent-hover)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)]">
 {{ __('ingredients.page.eyebrow') }}
 </a>
 <span aria-hidden="true" class="text-[var(--color-line-strong)]">/</span>
 <span aria-current="page" class="min-w-0 truncate">{{ $ingredientContext }}</span>
 </nav>

 <div class="mt-3 max-w-3xl">
 <h1 id="ingredient-editor-title" class="text-3xl font-semibold tracking-tight text-[var(--color-ink-strong)]">
 {{ $isPlatformIngredient
     ? __('ingredients.editor.reference.heading')
     : ($isReadOnlyIngredient
         ? __('ingredients.editor.read_only.heading')
         : ($isCreate
             ? __('ingredients.editor.create.heading')
             : __('ingredients.editor.edit.heading', ['ingredient' => $ingredientContext]))) }}
 </h1>
 <p class="mt-2 max-w-[70ch] text-sm leading-6 text-[var(--color-ink-soft)]">
 @if ($isPlatformIngredient)
 {{ __('ingredients.editor.reference.intro') }}
 @elseif ($isReadOnlyIngredient)
 {{ __('ingredients.editor.read_only_description') }}
 @elseif (! $isCreate)
 {{ __('ingredients.editor.edit.intro') }}
 @else
 {{ __('ingredients.editor.create.intro') }}
 @endif
 </p>
 @if ((! $isPlatformIngredient && ! $isReadOnlyIngredient) || (! $isCreate && $ingredient?->workspace_id !== null && filled($workspaceName)))
 <p class="mt-2 max-w-[70ch] text-xs leading-5 text-[var(--color-ink-soft)]">
 {{ __('ingredients.editor.workspace_scope', ['workspace' => $destinationWorkspaceName]) }}
 </p>
 @endif

 @if (! $isPlatformIngredient && $isCarrierOil && ! $hasSoapChemistry)
 <aside data-ingredient-carrier-oil-warning class="mt-4 rounded-lg border border-[var(--color-warning-soft)] bg-[var(--color-warning-soft)] px-4 py-3 text-sm leading-6 text-[var(--color-warning-strong)]" aria-labelledby="carrier-oil-guidance-title">
 <p id="carrier-oil-guidance-title" class="font-medium text-[var(--color-ink-strong)]">{{ __('ingredients.editor.carrier_oil_warning.heading') }}</p>
 <p class="mt-1">
 {{ __('ingredients.editor.carrier_oil_warning.description') }}
 <a data-ingredient-carrier-oil-duplication-link href="{{ route('ingredients.index') }}" wire:navigate class="font-medium text-[var(--color-accent-strong)] underline decoration-[var(--color-accent)] underline-offset-2 hover:text-[var(--color-accent-hover)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)]">{{ __('ingredients.duplicate.button') }}</a>
 </p>
 </aside>
 @endif
 </div>
 </section>

@if ($isReferenceView)
    @include('livewire.dashboard.partials.ingredient-reference-identity', [
        'referenceData' => $referenceData,
    ])

    @if ($isPlatformIngredient && filled($workspaceName))
        <section class="space-y-4" aria-labelledby="ingredient-workspace-context">
            <div>
                <p class="sk-eyebrow">{{ __('ingredients.editor.workspace_context.eyebrow') }}</p>
                <h2 id="ingredient-workspace-context" class="mt-2 text-xl font-semibold text-[var(--color-ink-strong)]">
                    {{ __('ingredients.editor.workspace_context.heading', ['workspace' => $workspaceName]) }}
                </h2>
                <p class="mt-2 max-w-[70ch] text-sm leading-6 text-[var(--color-ink-soft)]">
                    {{ __('ingredients.editor.workspace_context.description') }}
                </p>
            </div>

            @include('livewire.dashboard.partials.ingredient-reference-workspace-controls', [
                'referenceData' => $referenceData,
                'effectiveWorkspaceGuidance' => $effectiveWorkspaceGuidance,
                'workspaceGuidanceOverride' => $workspaceGuidanceOverride,
                'canEditWorkspaceGuidance' => $canEditWorkspaceGuidance,
                'workspaceName' => $workspaceName,
                'isEditingWorkspaceGuidance' => $isEditingWorkspaceGuidance,
                'workspaceGuidanceForm' => $this->workspaceGuidanceForm,
                'canEditWorkspaceMaterialCode' => $canEditWorkspaceMaterialCode,
                'workspaceMaterialCode' => $workspaceMaterialCode,
                'errors' => $errors,
            ])
        </section>
    @endif

    @include('livewire.dashboard.partials.ingredient-reference', [
        'referenceData' => $referenceData,
        'workspaceName' => $workspaceName,
        'showGuidance' => ! $isPlatformIngredient || ! filled($workspaceName),
    ])
@endif

 @if (! $isReferenceView)
 <form wire:submit="save" data-ingredient-scope="ingredient" class="space-y-4 pb-24">
 {{ $this->form }}

 @error('data.plan')
 <div data-ingredient-plan-error role="alert" class="rounded-lg border border-[var(--color-danger-soft)] bg-[var(--color-danger-soft)] px-4 py-3 text-sm leading-6 text-[var(--color-danger-strong)]">
 <p>{{ $message }}</p>
 <a data-ingredient-plan-recovery-link href="{{ route('ingredients.index') }}" wire:navigate class="mt-2 inline-flex font-medium text-[var(--color-accent-strong)] underline decoration-[var(--color-accent)] underline-offset-2 hover:text-[var(--color-accent-hover)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)]">
 {{ __('ingredients.editor.quota.review_private_ingredients') }}
 </a>
 </div>
 @enderror

 @if ($canEditIngredientData)
 <x-workflow-action-bar data-ingredient-save-bar>
 <x-slot:leading>
 <p data-ingredient-editor-status="ingredient" class="text-sm text-[var(--color-ink-soft)]" role="status" aria-live="polite" aria-atomic="true" x-text="statusText('ingredient')"></p>
 </x-slot:leading>
 <a href="{{ route('ingredients.index') }}" wire:navigate class="sk-btn sk-btn-ghost">
 {{ __('ingredients.actions.cancel') }}
 </a>
 <button
 type="submit"
 wire:loading.attr="disabled"
 wire:target="save"
 class="sk-btn sk-btn-primary"
 >
 {{ $ingredient ? __('ingredients.editor.actions.save') : __('ingredients.editor.actions.create') }}
 </button>
 </x-workflow-action-bar>
 @endif
 </form>
 @endif

 <x-filament-actions::modals />
</div>
</div>
