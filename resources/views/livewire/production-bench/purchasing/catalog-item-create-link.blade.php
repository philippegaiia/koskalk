@php
    $materialType = $this->data['material_type'] ?? 'ingredient';
    $supplierPublicId = $this->catalogCreationSupplierPublicId();
@endphp

<p class="text-sm text-[var(--color-ink-soft)]">
    @if ($materialType === 'packaging')
        {{ __('production_bench.listing.packaging_missing') }}
        <a
            href="{{ route('packaging-items.create', array_filter(['return_to' => 'supplier_listing', 'supplier' => $supplierPublicId])) }}"
            wire:navigate
            class="font-medium text-[var(--color-accent-strong)] underline decoration-[var(--color-line-strong)] underline-offset-4 hover:text-[var(--color-accent-hover)]"
        >{{ __('production_bench.listing.create_packaging') }}</a>
    @else
        {{ __('production_bench.listing.ingredient_missing') }}
        <a
            href="{{ route('ingredients.create', array_filter(['return_to' => 'supplier_listing', 'supplier' => $supplierPublicId])) }}"
            wire:navigate
            class="font-medium text-[var(--color-accent-strong)] underline decoration-[var(--color-line-strong)] underline-offset-4 hover:text-[var(--color-accent-hover)]"
        >{{ __('production_bench.listing.create_ingredient') }}</a>
    @endif
</p>
