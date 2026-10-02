@props(['allocations' => false])
<div class="space-y-2" data-production-editing-status>
    <div class="flex flex-wrap items-center gap-3">
        <div role="status" aria-live="polite" class="flex min-w-0 flex-1 flex-wrap items-center gap-2">
            <span data-production-editing-badge class="inline-flex max-w-full items-center rounded-full border px-3 py-1 text-sm font-medium" :class="owns ? 'border-[var(--color-success-soft)] bg-[var(--color-success-soft)] text-[var(--color-success-strong)]' : state.status === 'blocked' ? 'border-[var(--color-warning-soft)] bg-[var(--color-warning-soft)] text-[var(--color-warning-strong)]' : 'border-[var(--color-line)] bg-[var(--color-panel)] text-[var(--color-ink-soft)]'">
                <span x-show="!owns && state.status !== 'blocked'">{{ __('production_bench.editing.viewing') }}</span>
                <span x-cloak x-show="owns">{{ __('production_bench.editing.active') }}</span>
                <span x-cloak x-show="!owns && state.status === 'blocked'" x-text="@js(__('production_bench.editing.holder')).replace(':name', Object.values(state.productions || {}).find(row => row.status === 'blocked')?.holder_name || '')"></span>
            </span>
            <span x-cloak x-show="dirty && owns" class="inline-flex items-center rounded-full border border-[var(--color-warning-soft)] bg-[var(--color-warning-soft)] px-3 py-1 text-sm font-medium text-[var(--color-warning-strong)]">{{ __('production_bench.editing.pending') }}</span>
        </div>
        <button type="button" @click="begin()" x-show="state.can_edit && !owns && !stale && !unavailable && !reloadUnconfirmed && state.status !== 'blocked'" :disabled="busy" class="sk-btn sk-btn-outline"><span x-text="waiting ? @js(__('production_bench.editing.resume')) : @js(__($allocations ? 'production_bench.editing.begin_allocations' : 'production_bench.editing.begin'))">{{ __($allocations ? 'production_bench.editing.begin_allocations' : 'production_bench.editing.begin') }}</span></button>
        <button type="button" data-production-finish-editing @click="finish()" x-cloak x-show="owns" :disabled="busy || uploading" class="sk-btn sk-btn-outline">{{ __('production_bench.editing.finish') }}</button>
        <button type="button" @click="reload()" x-cloak x-show="stale || unavailable || presentationFailed || reloadUnconfirmed" :disabled="busy" class="sk-btn sk-btn-outline">{{ __('production_bench.editing.reload') }}</button>
        <button type="button" @click="takeoverOpen = true" x-cloak x-show="state.can_edit && state.can_take_over && state.status === 'blocked' && !stale" :disabled="busy" class="sk-btn sk-btn-outline">{{ __('production_bench.editing.takeover') }}</button>
    </div>
    <div data-production-editing-notice role="status" aria-live="polite" x-cloak x-show="message || (dirty && !canWrite)" class="space-y-1 text-sm text-[var(--color-ink-soft)]">
        <p x-show="message" x-text="message"></p>
        <p x-cloak x-show="dirty && !canWrite">{{ __('production_bench.editing.unsaved') }}</p>
        <template x-if="Object.keys(state.productions || {}).length > 1 && ['blocked', 'stale'].includes(state.status)">
            <ul class="mt-2 space-y-1"><template x-for="row in Object.values(state.productions)" :key="row.public_id"><li><span x-text="row.reference || row.label"></span><span x-show="row.holder_name" x-text="' · ' + row.holder_name"></span></li></template></ul>
        </template>
    </div>
    <div x-cloak x-show="takeoverOpen" @keydown.escape.window="takeoverOpen = false" @click.self="takeoverOpen = false" role="dialog" aria-modal="true" aria-labelledby="production-takeover-heading" class="fixed inset-0 z-[80] grid place-items-center bg-[color:oklch(from_var(--color-surface-strong)_l_c_h_/_0.55)] p-4">
        <form @submit.prevent="takeover()" x-trap.inert.noscroll="takeoverOpen" class="sk-card w-full max-w-lg space-y-4 p-6">
            <h2 id="production-takeover-heading" class="text-lg font-semibold">{{ __('production_bench.editing.takeover') }}</h2>
            <label class="block text-sm">{{ __('production_bench.editing.takeover_reason') }}<textarea x-model="takeoverReason" required maxlength="1000" class="sk-input mt-2 w-full" rows="3"></textarea></label>
            <p role="status" x-text="message" class="text-sm text-[var(--color-danger-strong)]"></p>
            <div class="flex justify-end gap-2"><button type="button" @click="takeoverOpen = false" class="sk-btn sk-btn-ghost">{{ __('production_bench.common.cancel') }}</button><button type="submit" :disabled="busy || !takeoverReason.trim()" class="sk-btn sk-btn-primary">{{ __('production_bench.editing.takeover') }}</button></div>
        </form>
    </div>
    <div x-cloak x-show="discardOpen" @keydown.escape.window="discardOpen = false" @click.self="discardOpen = false" role="dialog" aria-modal="true" aria-labelledby="production-discard-heading" class="fixed inset-0 z-[80] grid place-items-center bg-[color:oklch(from_var(--color-surface-strong)_l_c_h_/_0.55)] p-4">
        <div x-trap.inert.noscroll="discardOpen" class="sk-card w-full max-w-lg space-y-4 p-6">
            <h2 id="production-discard-heading" class="text-lg font-semibold">{{ __('production_bench.editing.discard_confirmation') }}</h2>
            <div class="flex justify-end gap-2"><button type="button" @click="discardOpen = false" class="sk-btn sk-btn-ghost">{{ __('production_bench.common.cancel') }}</button><button type="button" @click="discardAndContinue()" :disabled="busy || uploading" class="sk-btn sk-btn-danger">{{ __('production_bench.editing.discard') }}</button></div>
        </div>
    </div>
</div>
