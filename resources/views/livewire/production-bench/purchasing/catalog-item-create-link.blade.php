@php
    $materialType = $this->data['material_type'] ?? 'ingredient';
    $supplierPublicId = $this->catalogCreationSupplierPublicId();
@endphp

<p class="text-sm text-[var(--color-ink-soft)]">
    @if ($materialType === 'packaging')
        Packaging item not in the catalogue?
        <a
            href="{{ route('packaging-items.create', array_filter(['return_to' => 'supplier_listing', 'supplier' => $supplierPublicId])) }}"
            wire:navigate
            class="font-medium text-[var(--color-accent-strong)] underline decoration-[var(--color-line-strong)] underline-offset-4 hover:text-[var(--color-accent-hover)]"
        >Create packaging item</a>
    @else
        Ingredient not in the catalogue?
        <a
            href="{{ route('ingredients.create', array_filter(['return_to' => 'supplier_listing', 'supplier' => $supplierPublicId])) }}"
            wire:navigate
            class="font-medium text-[var(--color-accent-strong)] underline decoration-[var(--color-line-strong)] underline-offset-4 hover:text-[var(--color-accent-hover)]"
        >Create ingredient</a>
    @endif
</p>
