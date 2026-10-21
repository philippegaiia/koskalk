<div class="mx-auto max-w-5xl space-y-6">
    <a href="{{ route('dossiers.raw-materials') }}" wire:navigate class="text-sm text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)]">← {{ __('dossiers.raw_materials.back') }}</a>

    <div>
        <h2 class="text-xl font-semibold text-[var(--color-ink-strong)]">{{ $ingredient->display_name }}</h2>
        <p class="text-sm text-[var(--color-ink-soft)]">{{ $ingredient->inci_name }}</p>
    </div>

    @if ($status)
        <p role="status" class="rounded-lg bg-[var(--color-success-soft)] px-4 py-3 text-sm text-[var(--color-success-strong)]">{{ $status }}</p>
    @endif

    <section class="sk-card space-y-4 p-5" aria-labelledby="qualified-suppliers-heading">
        <h3 id="qualified-suppliers-heading" class="text-base font-semibold text-[var(--color-ink-strong)]">{{ __('dossiers.raw_materials.suppliers_heading') }}</h3>

        @foreach ($qualifications as $qualification)
            <article class="sk-inset space-y-3 p-4" aria-label="{{ $qualification->supplier->name }}">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="font-medium text-[var(--color-ink-strong)]">{{ $qualification->supplier->name }}</p>
                        <p class="text-xs text-[var(--color-ink-soft)]">{{ $qualification->supplier_reference ?: '—' }} · {{ __('dossiers.raw_materials.statuses.'.$qualification->status->value) }}</p>
                    </div>
                    <button type="button" wire:click="startUpload({{ $qualification->id }})" class="sk-btn sk-btn-outline min-h-11 gap-2 whitespace-nowrap">{{ __('dossiers.raw_materials.upload') }}</button>
                </div>

                @if ($qualification->evidenceItems->isEmpty())
                    <p class="text-sm text-[var(--color-ink-soft)]">{{ __('dossiers.raw_materials.no_documents') }}</p>
                @else
                    <ul class="divide-y divide-[var(--color-line)] text-sm">
                        @foreach ($qualification->evidenceItems as $item)
                            <li class="flex flex-wrap items-center justify-between gap-3 py-2">
                                <span>
                                    <span class="font-medium">{{ __('dossiers.evidence_types.'.$item->type->value) }}</span>
                                    — {{ $item->file->original_filename }}
                                    @if ($item->issued_on) · {{ $item->issued_on->toDateString() }} @endif
                                    <span class="font-mono text-xs text-[var(--color-ink-soft)]" title="{{ __('dossiers.raw_materials.fingerprint') }}">{{ substr($item->file->sha256, 0, 12) }}</span>
                                </span>
                                <span class="flex flex-wrap gap-2">
                                    <a href="{{ route('dossiers.evidence.download', $item) }}" class="sk-btn sk-btn-outline min-h-11 gap-2 whitespace-nowrap"><x-action-icon name="download" />{{ __('dossiers.raw_materials.download') }}</a>
                                    @if ($item->type->constituentKinds() !== [] || $item->type->useLimitBasis() !== null || $item->type->factKeys() !== [])
                                        <button type="button" wire:click="startEditData({{ $item->id }})" class="sk-btn sk-btn-outline min-h-11 gap-2 whitespace-nowrap">{{ __('dossiers.evidence_data.edit') }}</button>
                                    @endif
                                    <button type="button" wire:click="startReplace({{ $item->id }})" class="sk-btn sk-btn-outline min-h-11 gap-2 whitespace-nowrap">{{ __('dossiers.raw_materials.replace') }}</button>
                                    <button type="button" wire:click="startWithdraw({{ $item->id }})" class="sk-btn sk-btn-outline min-h-11 gap-2 whitespace-nowrap">{{ __('dossiers.raw_materials.withdraw') }}</button>
                                </span>
                                <p class="w-full text-xs text-[var(--color-ink-soft)]">
                                    @forelse ($item->constituents as $constituent)
                                        {{ $constituent->allergen?->inci_name ?? $constituent->substance?->name }} ≤ {{ rtrim(rtrim($constituent->max_value, '0'), '.') }} {{ $constituent->unit === 'ppm' ? 'ppm' : '%' }}@if (! $loop->last) · @endif
                                    @empty
                                        @if ($item->constituents_complete) {{ __('dossiers.evidence_data.complete_without_contents') }}
                                        @elseif ($item->useLimits->isEmpty() && blank($item->facts)) {{ __('dossiers.evidence_data.none') }} @endif
                                    @endforelse
                                    @foreach ($item->useLimits as $limit)
                                        · {{ $limit->ifraProductCategory?->code ?? $limit->product_class_label }} ≤ {{ rtrim(rtrim($limit->max_percent, '0'), '.') }} %
                                    @endforeach
                                </p>
                                @if ($dataItemId === $item->id)
                                    <form wire:submit="saveEvidenceData" class="w-full space-y-3 border-t border-[var(--color-line)] pt-3">
                                        {{ $this->evidenceDataForm }}
                                        <div class="flex justify-end">
                                            <button type="submit" class="sk-btn sk-btn-primary min-h-11 py-2.5">{{ __('dossiers.evidence_data.save') }}</button>
                                        </div>
                                    </form>
                                @endif
                                @if ($withdrawItemId === $item->id)
                                    <div class="w-full space-y-2 border-t border-[var(--color-line)] pt-3">
                                        <label class="block text-sm font-medium text-[var(--color-ink-strong)]">
                                            {{ __('dossiers.raw_materials.withdraw_reason') }}
                                            <textarea wire:model="withdrawReason" rows="2" aria-label="{{ __('dossiers.raw_materials.withdraw_reason') }}" class="mt-2 w-full rounded-lg bg-[var(--color-field)] px-3 py-2.5 text-sm outline outline-1 outline-[var(--color-field-outline)] focus:outline-2 focus:outline-[var(--color-accent)]"></textarea>
                                        </label>
                                        @error('withdrawn_reason')
                                            <p role="alert" class="text-xs text-[var(--color-danger-strong)]">{{ $message }}</p>
                                        @enderror
                                        <div class="flex justify-end">
                                            <button type="button" wire:click="withdrawEvidence" class="sk-btn sk-btn-outline min-h-11 py-2.5">{{ __('dossiers.raw_materials.withdraw_confirm') }}</button>
                                        </div>
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($uploadQualificationId === $qualification->id || ($replaceItemId !== null && $qualification->evidenceItems->contains('id', $replaceItemId)))
                    <form wire:submit="uploadEvidence" class="space-y-4 border-t border-[var(--color-line)] pt-4">
                        {{ $this->evidenceForm }}
                        <div class="flex justify-end">
                            <button type="submit" class="sk-btn sk-btn-primary min-h-11 py-2.5">{{ __('dossiers.raw_materials.save_document') }}</button>
                        </div>
                    </form>
                @endif
            </article>
        @endforeach

        <form wire:submit="saveQualification" class="space-y-4 border-t border-[var(--color-line)] pt-4" aria-label="{{ __('dossiers.raw_materials.add_qualification') }}">
            <h4 class="text-sm font-semibold text-[var(--color-ink-strong)]">{{ __('dossiers.raw_materials.add_qualification') }}</h4>
            {{ $this->qualificationForm }}
            <div class="flex justify-end">
                <button type="submit" class="sk-btn sk-btn-primary min-h-11 py-2.5">{{ __('dossiers.raw_materials.save_qualification') }}</button>
            </div>
        </form>
    </section>

    <livewire:dossiers.quick-supplier-create :key="'quick-supplier-'.$workspaceId" />
</div>
