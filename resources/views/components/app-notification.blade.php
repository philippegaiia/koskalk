@props([
    'message' => null,
    'type' => 'success',
])

<div
    {{ $attributes->class([
        'pointer-events-none fixed inset-x-4 top-[max(1rem,env(safe-area-inset-top))] z-[100] flex justify-center sm:left-auto sm:right-6 sm:w-full sm:max-w-sm',
    ]) }}
    data-app-notification
    x-data="appNotification({
        message: @js($message),
        type: @js($type),
    })"
    x-on:app-notification.window="show($event.detail)"
>
    <div
        x-cloak
        x-show="visible"
        x-transition:enter="motion-safe:transition motion-safe:duration-200 motion-safe:ease-out"
        x-transition:enter-start="motion-safe:-translate-y-2 motion-safe:opacity-0"
        x-transition:enter-end="motion-safe:translate-y-0 motion-safe:opacity-100"
        x-transition:leave="motion-safe:transition motion-safe:duration-150 motion-safe:ease-in"
        x-transition:leave-start="motion-safe:translate-y-0 motion-safe:opacity-100"
        x-transition:leave-end="motion-safe:-translate-y-2 motion-safe:opacity-0"
        :role="type === 'error' ? 'alert' : 'status'"
        :aria-live="type === 'error' ? 'assertive' : 'polite'"
        aria-atomic="true"
        :class="type === 'error'
            ? 'border-[var(--color-danger-soft)] bg-[var(--color-panel)] text-[var(--color-danger-strong)]'
            : 'border-[var(--color-success-soft)] bg-[var(--color-panel)] text-[var(--color-success-strong)]'"
        class="pointer-events-auto flex w-full items-start gap-3 rounded-xl border px-4 py-3 shadow-[0_12px_28px_rgba(60,50,30,0.16)]"
    >
        <span
            aria-hidden="true"
            :class="type === 'error'
                ? 'bg-[var(--color-danger-soft)] text-[var(--color-danger-strong)]'
                : 'bg-[var(--color-success-soft)] text-[var(--color-success-strong)]'"
            class="mt-0.5 grid size-6 shrink-0 place-items-center rounded-full"
        >
            <svg x-show="type === 'success'" xmlns="http://www.w3.org/2000/svg" class="size-3.5" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M16.704 5.293a1 1 0 0 1 .003 1.414l-7.25 7.28a1 1 0 0 1-1.414.003l-3.75-3.72a1 1 0 1 1 1.414-1.42l3.041 3.017 6.542-6.57a1 1 0 0 1 1.414-.004Z" clip-rule="evenodd" />
            </svg>
            <svg x-show="type === 'error'" xmlns="http://www.w3.org/2000/svg" class="size-3.5" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm-1-5a1 1 0 1 0 2 0 1 1 0 0 0-2 0Zm1-7a1 1 0 0 0-1 1v3a1 1 0 1 0 2 0V7a1 1 0 0 0-1-1Z" clip-rule="evenodd" />
            </svg>
        </span>

        <p x-text="message" class="min-w-0 flex-1 text-sm font-medium leading-6"></p>

        <button
            type="button"
            x-on:click="dismiss"
            class="grid size-8 shrink-0 place-items-center rounded-lg text-[var(--color-ink-soft)] transition hover:bg-[var(--color-field-muted)] hover:text-[var(--color-ink-strong)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)]"
        >
            <span class="sr-only">{{ __('navigation.actions.dismiss_notification') }}</span>
            <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M6 18 18 6M6 6l12 12" />
            </svg>
        </button>
    </div>
</div>
