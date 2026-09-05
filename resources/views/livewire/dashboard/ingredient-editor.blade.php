@php
 $isCreate = $ingredientId === null;
 $isPlatformIngredient = $ingredient !== null
     && $ingredient->owner_type === null
     && $ingredient->owner_id === null
     && $ingredient->workspace_id === null;
 $isReadOnlyIngredient = ! $isCreate && ! $isPlatformIngredient && ! $canEditIngredientData;
 $ingredientContext = $ingredient?->localizedDisplayName() ?: __('ingredients.editor.common.new_ingredient');
 $isCarrierOil = \App\Enums\IngredientCategory::tryFrom((string) ($data['category'] ?? '')) === \App\Enums\IngredientCategory::Lipids;
@endphp

<div
 x-data="ingredientEditor({
     element: $el,
     isCreate: @js($isCreate),
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
         : ($isCreate ? __('ingredients.editor.create.heading') : __('ingredients.editor.edit.heading'))) }}
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
 @if (! $isCreate && $ingredient?->workspace_id !== null && filled($workspaceName))
 <p class="mt-2 max-w-[70ch] text-xs leading-5 text-[var(--color-ink-soft)]">
 {{ __('ingredients.editor.workspace_scope', ['workspace' => $workspaceName]) }}
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
 @include('livewire.dashboard.partials.ingredient-reference', ['referenceData' => $referenceData, 'workspaceName' => $workspaceName])
 @endif

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

 <div class="sk-rich-content mt-5 max-w-none">
 @if (filled($effectiveWorkspaceGuidance))
 {!! $effectiveWorkspaceGuidance !!}
 @else
 <p class="text-sm text-[var(--color-ink-soft)]">{{ __('ingredients.editor.common.not_available') }}</p>
 @endif
 </div>

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
