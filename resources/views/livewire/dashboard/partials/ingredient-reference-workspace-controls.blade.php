@php
    $displayWorkspaceGuidance = $effectiveWorkspaceGuidance;

    if ($displayWorkspaceGuidance === null && ! $workspaceGuidanceOverride?->is_active) {
        $displayWorkspaceGuidance = data_get($referenceData, 'guidance.html');
    }
@endphp

<section class="sk-card p-5 sm:p-6" aria-labelledby="workspace-guidance-heading">
    <div class="flex flex-col gap-1">
        <p class="sk-eyebrow">{{ __('ingredients.editor.workspace_guidance.eyebrow') }}</p>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h3 id="workspace-guidance-heading" class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('ingredients.editor.workspace_guidance.heading') }}</h3>
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

    @if (! $isEditingWorkspaceGuidance || ! $canEditWorkspaceGuidance)
        <div data-ingredient-guidance-preview class="sk-rich-content mt-5 max-w-none">
            @if (filled($displayWorkspaceGuidance))
                {!! $displayWorkspaceGuidance !!}
            @else
                <p class="text-sm text-[var(--color-ink-soft)]">{{ __('ingredients.editor.workspace_guidance.empty') }}</p>
            @endif
        </div>
    @endif

    @if ($isEditingWorkspaceGuidance && $canEditWorkspaceGuidance)
        <form wire:submit="saveWorkspaceGuidance" data-ingredient-scope="guidance" class="mt-5 max-w-2xl space-y-3">
            {{ $workspaceGuidanceForm }}
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
        <h3 id="platform-material-code-heading" class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('ingredients.editor.material_code.workspace_heading') }}</h3>
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
