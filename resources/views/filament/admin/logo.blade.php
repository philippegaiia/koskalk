<div class="flex h-full items-center gap-3">
    <img
        src="{{ asset('images/app/brand/soapkraftlogo-beige.png') }}"
        alt=""
        class="h-full w-auto rounded-lg object-contain dark:hidden"
    >
    <img
        src="{{ asset('images/app/brand/soapcraft-logo-green-light.png') }}"
        alt=""
        class="hidden h-full w-auto rounded-lg object-contain dark:block"
    >
    <span class="text-base font-semibold text-[var(--color-ink-sidebar)] dark:text-[var(--color-inverse)]">
        {{ config('app.name', 'Soapkraft') }}
    </span>
</div>
