<div
    x-cloak
    x-show="pendingProductSizeRemoval"
    x-init="$watch('pendingProductSizeRemoval', (pending) => { if (pending) { $nextTick(() => $refs.cancelSizeRemoval?.focus()); } })"
    @click.self="cancelProductSizeRemoval()"
    class="fixed inset-0 z-[80] grid place-items-center bg-[color:oklch(from_var(--color-surface-strong)_l_c_h_/_0.55)] p-4"
    role="dialog"
    aria-modal="true"
    aria-labelledby="product-size-removal-heading"
    aria-describedby="product-size-removal-description"
    data-product-size-removal-dialog
>
    <div
        x-trap.inert.noscroll="Boolean(pendingProductSizeRemoval)"
        @keydown.escape="$event.preventDefault(); $event.stopPropagation(); cancelProductSizeRemoval()"
        class="w-full max-w-lg rounded-[1.25rem] border border-[var(--color-line)] bg-[var(--color-panel)] p-5 shadow-[0_18px_48px_rgba(60,50,30,0.18)] sm:p-6"
        @click.stop
    >
        <p class="sk-eyebrow" x-text="pendingProductSizeRemoval?.label ?? ''"></p>
        <h2
            id="product-size-removal-heading"
            class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]"
            x-text="pendingProductSizeRemoval?.inUse ? t('packaging.sizes.removal.retire_title') : t('packaging.sizes.removal.delete_title')"
        ></h2>
        <div id="product-size-removal-description" class="mt-2 space-y-3 text-sm leading-6 text-[var(--color-ink-soft)]">
            <p x-text="pendingProductSizeRemoval?.inUse
                ? t('packaging.sizes.removal.retire_description', { size: pendingProductSizeRemoval?.label ?? '' })
                : t('packaging.sizes.removal.delete_description', { size: pendingProductSizeRemoval?.label ?? '' })"></p>
            <template x-if="pendingProductSizeRemoval?.usageLines?.length > 0">
                <div>
                    <p class="font-medium text-[var(--color-ink-strong)]">{{ __('workbench.packaging.sizes.removal.used_by') }}</p>
                    <ul class="mt-1 list-disc pl-5">
                        <template x-for="line in pendingProductSizeRemoval.usageLines" :key="line">
                            <li x-text="line"></li>
                        </template>
                    </ul>
                </div>
            </template>
            <p x-show="(pendingProductSizeRemoval?.packagingCount ?? 0) > 0" x-text="t('packaging.sizes.removal.packaging', { count: pendingProductSizeRemoval?.packagingCount ?? 0 })"></p>
            <p>{{ __('workbench.packaging.sizes.removal.on_save') }}</p>
        </div>

        <div class="mt-6 flex flex-wrap justify-end gap-2 border-t border-[var(--color-line)] pt-4">
            <button
                x-ref="cancelSizeRemoval"
                type="button"
                @click="cancelProductSizeRemoval()"
                class="sk-btn sk-btn-ghost min-h-11 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)]"
            >
                {{ __('workbench.packaging.sizes.removal.cancel') }}
            </button>
            <button
                type="button"
                @click="confirmProductSizeRemoval()"
                class="sk-btn min-h-11 bg-[var(--color-danger-strong)] text-[var(--color-inverse)] hover:bg-[var(--color-danger)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-danger-strong)]"
                x-text="pendingProductSizeRemoval?.inUse ? t('packaging.sizes.retire') : t('packaging.sizes.delete')"
            ></button>
        </div>
    </div>
</div>
