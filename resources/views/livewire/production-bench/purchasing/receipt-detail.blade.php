<x-production-bench.page active="purchasing" subnavigation="receipts">
    @php($numberLocale = auth()->user()?->number_locale)
    @if ($isReadOnly)
        <p role="status" class="rounded-xl bg-[var(--color-warning-soft)] px-4 py-3 text-sm text-[var(--color-warning-strong)]">{{ __('production_bench.common.read_only') }}</p>
    @endif

    <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="sk-eyebrow">{{ $receipt->source->value === 'direct' ? __('production_bench.receipt.direct_source') : __('production_bench.receipt.order_source') }}</p>
            <h1 class="mt-2 text-3xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.receipt.singular') }}</h1>
            <p class="numeric mt-1 text-sm text-[var(--color-ink-soft)]">{{ $receipt->delivery_reference ?: __('production_bench.receipt.no_reference') }}</p>
        </div>
        <span @class(['rounded-full px-3 py-1 text-xs font-medium', 'bg-[var(--color-danger-soft)] text-[var(--color-danger-strong)]' => $receipt->status->value === 'reversed', 'bg-[var(--color-success-soft)] text-[var(--color-success-strong)]' => $receipt->status->value === 'posted'])>{{ $receipt->status->value === 'reversed' ? __('production_bench.receipt.status_reversed') : __('production_bench.receipt.status_posted') }}</span>
    </header>

    <dl class="grid gap-x-6 gap-y-4 border-y border-[var(--color-line)] py-5 sm:grid-cols-2 lg:grid-cols-4">
        <div><dt class="sk-eyebrow">{{ __('production_bench.receipt.received_on') }}</dt><dd class="numeric mt-1 text-sm text-[var(--color-ink-strong)]">{{ $receipt->received_at->format('Y-m-d') }}</dd></div>
        <div><dt class="sk-eyebrow">{{ __('production_bench.supplier.singular') }}</dt><dd class="mt-1 text-sm"><a href="{{ route('production-bench.purchasing.supplier', $receipt->supplier) }}" wire:navigate class="font-medium text-[var(--color-accent-strong)] hover:underline">{{ $receipt->supplier->name }}</a></dd></div>
        <div><dt class="sk-eyebrow">{{ __('production_bench.procurement.purchase_order') }}</dt><dd class="numeric mt-1 text-sm">@if($receipt->purchaseOrder)<a href="{{ route('production-bench.purchasing.procurement.show', $receipt->purchaseOrder) }}" wire:navigate class="font-medium text-[var(--color-accent-strong)] hover:underline">{{ $receipt->purchaseOrder->reference }}</a>@else<span class="text-[var(--color-ink-soft)]">{{ __('production_bench.receipt.not_applicable') }}</span>@endif</dd></div>
        <div><dt class="sk-eyebrow">{{ __('production_bench.receipt.lines') }}</dt><dd class="numeric mt-1 text-sm text-[var(--color-ink-strong)]">{{ $receipt->lines->count() }}</dd></div>
        @if ($receipt->notes)<div class="sm:col-span-2 lg:col-span-4"><dt class="sk-eyebrow">{{ __('production_bench.common.notes') }}</dt><dd class="mt-1 whitespace-pre-line text-sm text-[var(--color-ink-strong)]">{{ $receipt->notes }}</dd></div>@endif
    </dl>

    <section aria-labelledby="receipt-lines-heading" class="space-y-3">
        <h2 id="receipt-lines-heading" class="text-xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.receipt.received_items') }}</h2>
        <div class="overflow-x-auto" data-receipt-responsive-table>
            <div class="min-w-[920px] space-y-2">
                @foreach ($receipt->lines as $line)
                    <article wire:key="receipt-detail-line-{{ $line->id }}" data-receipt-detail-line="{{ $line->id }}" class="sk-card-elevation-subtle rounded-xl border border-[var(--color-line)] bg-[var(--color-panel)] p-5">
                        <div class="grid gap-4 lg:grid-cols-[minmax(13rem,1.2fr)_repeat(4,minmax(7rem,1fr))]">
                            <div>
                                <p class="font-medium text-[var(--color-ink-strong)]">{{ $line->stockLot->subjectName() }}</p>
                                <p class="mt-1 text-xs text-[var(--color-ink-soft)]">{{ $line->supplierListing->purchase_format }}</p>
                                <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs">
                                    <a href="{{ route('production-bench.purchasing.supplier', $receipt->supplier).'#listing-'.$line->supplierListing->public_id }}" wire:navigate class="inline-flex min-h-11 items-center font-medium text-[var(--color-accent-strong)] hover:underline">{{ __('production_bench.receipt.listing_link') }}</a>
                                    <a href="{{ route('production-bench.inventory', ['lot' => $line->stockLot->public_id]).'#lot-'.$line->stockLot->public_id }}" wire:navigate class="inline-flex min-h-11 items-center font-medium text-[var(--color-accent-strong)] hover:underline">{{ __('production_bench.receipt.inventory_link') }}</a>
                                </div>
                            </div>
                            @if ($line->purchaseOrderLine)
                                <div><p class="sk-eyebrow line-clamp-2 min-h-8 leading-4">{{ __('production_bench.receipt.ordered') }}</p><p class="numeric mt-2 text-sm">{{ $line->purchaseOrderLine->ordered_packs }}</p></div>
                            @endif
                            <div><p class="sk-eyebrow line-clamp-2 min-h-8 leading-4">{{ __('production_bench.receipt.formats') }}</p><p class="numeric mt-2 text-sm">{{ $line->packs_received }}</p></div>
                            <div><p class="sk-eyebrow line-clamp-2 min-h-8 leading-4">{{ __('production_bench.receipt.actual_quantity') }}</p><p class="numeric mt-2 text-sm">{{ \App\Support\NumberLocale::formatAdaptiveDecimal($line->original_quantity, 0, 3, $numberLocale) }} {{ $line->original_unit }}</p></div>
                            <div><p class="sk-eyebrow line-clamp-2 min-h-8 leading-4">{{ __('production_bench.receipt.price') }}</p><p class="numeric mt-2 text-sm">{{ \App\Support\NumberLocale::formatAdaptiveDecimal($line->receipt_price_amount, 2, 4, $numberLocale) }} {{ $line->currency }}@if($line->receipt_price_unit) / {{ $line->receipt_price_unit === 'count' ? __('production_bench.receipt.count') : $line->receipt_price_unit }}@endif</p><p class="mt-2 text-xs text-[var(--color-ink-soft)]">{{ $line->receipt_price_basis->value === 'per_unit' ? __('production_bench.receipt.per_unit') : __('production_bench.receipt.total_format') }}</p></div>
                            <div><p class="sk-eyebrow line-clamp-2 min-h-8 leading-4">{{ __('production_bench.receipt.historical_cost') }}</p><p class="numeric mt-2 text-sm">{{ \App\Support\NumberLocale::formatAdaptiveDecimal($line->historical_total_cost, 2, 3, $numberLocale) }} {{ $line->currency }}</p>@if($line->stockLot->unit_kind->value === 'count')<p class="numeric mt-2 text-xs text-[var(--color-ink-soft)]">{{ \App\Support\NumberLocale::formatAdaptiveDecimal($line->stockLot->historical_unit_cost, 2, 3, $numberLocale) }} / {{ __('production_bench.receipt.count') }}</p>@endif</div>
                            <div><p class="sk-eyebrow line-clamp-2 min-h-8 leading-4">{{ __('production_bench.receipt.inventory_cost') }}</p><p class="numeric mt-2 text-sm">{{ \App\Support\NumberLocale::formatAdaptiveDecimal($line->costing_total_cost ?? $line->historical_total_cost, 2, 3, $numberLocale) }} {{ $line->costing_currency ?? $line->currency }}</p>@if($line->stockLot->unit_kind->value === 'count')<p class="numeric mt-2 text-xs text-[var(--color-ink-soft)]">{{ \App\Support\NumberLocale::formatAdaptiveDecimal($line->stockLot->costing_unit_cost ?? $line->stockLot->historical_unit_cost, 2, 3, $numberLocale) }} / {{ __('production_bench.receipt.count') }}</p>@endif</div>
                        </div>
                        <dl class="mt-5 grid gap-x-5 gap-y-4 border-t border-[var(--color-line)] pt-5 sm:grid-cols-4">
                            <div><dt class="sk-eyebrow">{{ __('production_bench.receipt.internal_lot') }}</dt><dd class="numeric mt-2 text-xs">{{ $line->stockLot->internal_lot_code }}</dd></div>
                            <div><dt class="sk-eyebrow">{{ __('production_bench.receipt.supplier_batch') }}</dt><dd class="mt-2 text-xs">{{ $line->supplier_batch_number ?: __('production_bench.receipt.not_provided') }}</dd></div>
                            <div><dt class="sk-eyebrow">{{ __('production_bench.receipt.expiry') }}</dt><dd class="numeric mt-2 text-xs">{{ $line->expires_at?->format('Y-m-d') ?? __('production_bench.receipt.not_provided') }}</dd></div>
                            @if($line->exchange_rate && $line->currency !== ($line->costing_currency ?? $line->currency))<div><dt class="sk-eyebrow">{{ __('production_bench.receipt.exchange_rate') }}</dt><dd class="numeric mt-2 text-xs">1 {{ $line->currency }} = {{ \App\Support\NumberLocale::formatAdaptiveDecimal($line->exchange_rate, 4, 6, $numberLocale) }} {{ $line->costing_currency }} · {{ $line->exchange_rate_date?->format('Y-m-d') }}</dd></div>@endif
                            @if($line->notes)<div><dt class="sk-eyebrow">{{ __('production_bench.common.notes') }}</dt><dd class="mt-2 text-xs">{{ $line->notes }}</dd></div>@endif
                        </dl>
                    </article>
                @endforeach
            </div>
        </div>
    </section>

    <section aria-labelledby="receipt-documents-heading" class="space-y-4">
        <div>
            <h2 id="receipt-documents-heading" class="text-xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.receipt.documents') }}</h2>
            <p class="mt-1 text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.receipt.documents_help') }}</p>
        </div>

        @if (session('documentStatus'))
            <p role="status" class="rounded-xl bg-[var(--color-success-soft)] px-4 py-3 text-sm font-medium text-[var(--color-success-strong)]">{{ session('documentStatus') }}</p>
        @endif

        @if ($receipt->documents->isNotEmpty() || $receipt->lines->contains(fn ($line) => $line->stockLot->documents->isNotEmpty()))
            <ul class="divide-y divide-[var(--color-line)] rounded-xl border border-[var(--color-line)] bg-[var(--color-panel)] px-4">
                @foreach($receipt->documents as $document)
                    <li class="flex flex-col gap-2 py-3 text-sm sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            <a href="{{ route('media.download', $document->mediaAsset) }}" class="break-all font-medium text-[var(--color-accent-strong)] hover:underline">{{ $document->mediaAsset->original_filename }}</a>
                            @if($document->note)<p class="mt-1 text-xs text-[var(--color-ink-soft)]">{{ $document->note }}</p>@endif
                        </div>
                        <span class="text-xs text-[var(--color-ink-soft)]">{{ __('production_bench.receipt.document_types.'.$document->type->value) }} · {{ __('production_bench.receipt.document_receipt_target') }}</span>
                    </li>
                @endforeach
                @foreach($receipt->lines as $line)
                    @foreach($line->stockLot->documents as $document)
                        <li class="flex flex-col gap-2 py-3 text-sm sm:flex-row sm:items-center sm:justify-between">
                            <div class="min-w-0">
                                <a href="{{ route('media.download', $document->mediaAsset) }}" class="break-all font-medium text-[var(--color-accent-strong)] hover:underline">{{ $document->mediaAsset->original_filename }}</a>
                                @if($document->note)<p class="mt-1 text-xs text-[var(--color-ink-soft)]">{{ $document->note }}</p>@endif
                            </div>
                            <span class="text-xs text-[var(--color-ink-soft)]">{{ __('production_bench.receipt.document_types.'.$document->type->value) }} · {{ $line->stockLot->internal_lot_code }} · {{ $line->stockLot->subjectName() }}</span>
                        </li>
                    @endforeach
                @endforeach
            </ul>
        @else
            <p class="rounded-xl border border-dashed border-[var(--color-line-strong)] px-4 py-6 text-center text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.receipt.no_documents') }}</p>
        @endif

        @if ($canAttachDocuments)
            <form wire:submit="attachDocument" wire:loading.attr="aria-busy" wire:target="documentUpload,attachDocument" data-receipt-document-upload class="sk-card grid gap-5 p-5 sm:grid-cols-2">
                <label class="space-y-2 sm:col-span-2">
                    <span class="text-sm font-medium">{{ __('production_bench.receipt.document_file') }}</span>
                    <input wire:model="documentUpload" type="file" accept="application/pdf,image/*" required aria-invalid="{{ $errors->has('documentUpload') ? 'true' : 'false' }}" aria-describedby="receipt-document-upload-help{{ $errors->has('documentUpload') ? ' receipt-document-upload-error' : '' }}" class="sk-input w-full file:mr-4 file:rounded-lg file:border-0 file:bg-[var(--color-field-muted)] file:px-3 file:py-2 file:text-sm file:font-medium">
                    <span id="receipt-document-upload-help" class="block text-xs text-[var(--color-ink-muted)]">{{ __('production_bench.receipt.document_file_help') }}</span>
                    @error('documentUpload')<span id="receipt-document-upload-error" class="block text-xs text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror
                </label>

                <label class="space-y-2">
                    <span class="text-sm font-medium">{{ __('production_bench.receipt.document_type') }}</span>
                    <select wire:model.live="documentType" required aria-invalid="{{ $errors->has('documentType') ? 'true' : 'false' }}" @if($errors->has('documentType')) aria-describedby="receipt-document-type-error" @endif class="sk-input w-full">
                        <optgroup label="{{ __('production_bench.receipt.document_receipt_types') }}">
                            @foreach($receiptDocumentTypes as $type)<option value="{{ $type }}">{{ __('production_bench.receipt.document_types.'.$type) }}</option>@endforeach
                        </optgroup>
                        <optgroup label="{{ __('production_bench.receipt.document_lot_types') }}">
                            @foreach($lotDocumentTypes as $type)<option value="{{ $type }}">{{ __('production_bench.receipt.document_types.'.$type) }}</option>@endforeach
                        </optgroup>
                    </select>
                    @error('documentType')<span id="receipt-document-type-error" class="block text-xs text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror
                </label>

                <label class="space-y-2">
                    <span class="text-sm font-medium">{{ __('production_bench.common.notes') }} <span class="font-normal text-[var(--color-ink-muted)]">{{ __('production_bench.inventory.optional') }}</span></span>
                    <input wire:model="documentNote" maxlength="1000" aria-invalid="{{ $errors->has('documentNote') ? 'true' : 'false' }}" @if($errors->has('documentNote')) aria-describedby="receipt-document-note-error" @endif class="sk-input w-full">
                    @error('documentNote')<span id="receipt-document-note-error" class="block text-xs text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror
                </label>

                @if(in_array($documentType, $lotDocumentTypes, true))
                    <fieldset aria-describedby="receipt-document-lots-help{{ $errors->has('documentLotIds') ? ' receipt-document-lots-error' : '' }}" class="space-y-3 sm:col-span-2">
                        <legend class="text-sm font-medium">{{ __('production_bench.receipt.document_lot_targets') }}</legend>
                        <p id="receipt-document-lots-help" class="text-xs text-[var(--color-ink-muted)]">{{ __('production_bench.receipt.document_lot_targets_help') }}</p>
                        <div class="grid gap-2 sm:grid-cols-2">
                            @foreach($receipt->lines as $line)
                                <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-[var(--color-line)] bg-[var(--color-field-muted)] p-3">
                                    <input wire:model="documentLotIds" type="checkbox" value="{{ $line->stock_lot_id }}" class="mt-0.5 size-4 accent-[var(--color-accent)]">
                                    <span><span class="block text-sm font-medium">{{ $line->stockLot->subjectName() }}</span><span class="numeric mt-0.5 block text-xs text-[var(--color-ink-soft)]">{{ $line->stockLot->internal_lot_code }}</span></span>
                                </label>
                            @endforeach
                        </div>
                        @error('documentLotIds')<span id="receipt-document-lots-error" class="block text-xs text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror
                    </fieldset>
                @else
                    <p class="rounded-xl bg-[var(--color-field-muted)] px-4 py-3 text-sm text-[var(--color-ink-soft)] sm:col-span-2">{{ __('production_bench.receipt.document_receipt_target_help') }}</p>
                @endif

                <div class="sm:col-span-2">
                    <span wire:loading.delay wire:target="attachDocument" role="status" aria-live="polite" class="text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.receipt.attach_document') }}…</span>
                    <button type="submit" wire:loading.attr="disabled" wire:target="attachDocument" class="sk-btn sk-btn-primary min-h-11">{{ __('production_bench.receipt.attach_document') }}</button>
                </div>
            </form>
        @endif
    </section>

    @if ($receipt->status->value === 'reversed')
        <section role="status" class="rounded-xl bg-[var(--color-danger-soft)] p-4 text-sm text-[var(--color-danger-strong)]">
            <p class="font-semibold">{{ __('production_bench.receipt.reversal_reason') }}</p>
            <p class="mt-1">{{ $receipt->reversal_reason }}</p>
        </section>
    @endif

    @if ($canReverse)
        <div class="pb-24">
            <x-workflow-action-bar data-receipt-reversal-action-bar>
                <label class="min-w-0 flex-1 text-sm"><span class="sr-only">{{ __('production_bench.receipt.reversal_reason') }}</span><input wire:model="reversalReason" required placeholder="{{ __('production_bench.receipt.reversal_reason_placeholder') }}" aria-invalid="{{ $errors->has('reversalReason') ? 'true' : 'false' }}" @if($errors->has('reversalReason')) aria-describedby="reversal-reason-error" @endif class="sk-input w-full">@error('reversalReason')<span id="reversal-reason-error" class="mt-1 block text-xs text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror</label>
                <span wire:loading.delay wire:target="reverse" role="status" aria-live="polite" class="text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.receipt.reverse') }}…</span>
                <button type="button" wire:click="reverse" wire:confirm="{{ __('production_bench.receipt.reverse_confirm') }}" wire:loading.attr="disabled" wire:target="reverse" class="sk-btn sk-btn-danger min-h-11">{{ __('production_bench.receipt.reverse') }}</button>
            </x-workflow-action-bar>
        </div>
    @endif
</x-production-bench.page>
