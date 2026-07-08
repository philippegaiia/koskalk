<aside aria-label="Calculator sidebar" class="order-2 lg:order-1 lg:sticky lg:top-[74px]">
    <div class="space-y-4">
        @guest
            <section class="rounded-xl border border-[var(--color-line)] bg-[var(--color-panel)] p-4 shadow-[0_1px_2px_rgba(60,50,30,0.04)]">
                <p class="sk-eyebrow">Save later</p>
                <h2 class="mt-2 text-base font-semibold text-[var(--color-ink-strong)]">Create free account</h2>
                <p class="mt-2 text-sm leading-6 text-[var(--color-ink-soft)]">Use Save this formula so your current draft follows you into registration.</p>
                <button type="submit" form="public-calculator-save-form" class="mt-4 inline-flex min-h-10 w-full items-center justify-center rounded-lg bg-[var(--color-accent)] px-4 py-2 text-sm font-medium text-white transition hover:bg-[var(--color-accent-hover)]">Save from current formula</button>
                <a href="{{ route('login') }}" class="mt-2 inline-flex min-h-10 w-full items-center justify-center rounded-lg px-4 py-2 text-sm font-medium text-[var(--color-ink-soft)] no-underline transition hover:bg-[var(--color-field-muted)] hover:text-[var(--color-ink-strong)]">Sign in</a>
            </section>
        @endguest

        @auth
            @php($calculatorUser = auth()->user())

            <section aria-label="Signed in user" class="px-1 py-1">
                <p class="sk-eyebrow">Signed in</p>
                <p class="mt-2 truncate text-sm font-semibold text-[var(--color-ink-strong)]">{{ $calculatorUser->name }}</p>
                <p class="mt-0.5 truncate text-xs text-[var(--color-ink-soft)]">{{ $calculatorUser->email }}</p>
                <div class="mt-3 flex flex-wrap gap-2">
                    <span class="rounded-full border border-[var(--color-line)] bg-[var(--color-field-muted)] px-2.5 py-1 text-xs font-medium text-[var(--color-ink-soft)]">Free account</span>
                    @if ($calculatorUser->is_admin)
                        <span class="rounded-full border border-[var(--color-line)] bg-[var(--color-accent-soft)] px-2.5 py-1 text-xs font-medium text-[var(--color-accent-strong)]">Admin</span>
                    @endif
                </div>

                <div class="mt-4 grid gap-2">
                    <a href="{{ route('dashboard') }}" class="inline-flex min-h-10 items-center justify-center rounded-lg bg-[var(--color-field-muted)] px-4 py-2 text-sm font-medium text-[var(--color-ink-strong)] no-underline transition hover:bg-[var(--color-accent-soft)]">Dashboard</a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="inline-flex min-h-10 w-full items-center justify-center rounded-lg px-4 py-2 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-field-muted)] hover:text-[var(--color-ink-strong)]">Sign out</button>
                    </form>
                </div>
            </section>
        @endauth

        <section aria-labelledby="calculator-advertising-heading" class="rounded-xl border border-dashed border-[var(--color-line)] bg-[var(--color-panel)] p-4">
            <div class="flex items-center justify-between gap-3">
                <p id="calculator-advertising-heading" class="sk-eyebrow">Advertisement</p>
                <span class="rounded-full border border-[var(--color-line)] px-2 py-0.5 text-[0.65rem] font-medium text-[var(--color-ink-soft)]">Free version</span>
            </div>
            <div class="mt-4 grid min-h-[14rem] place-items-center rounded-lg bg-[var(--color-field-muted)] px-4 text-center">
                <div>
                    <p class="text-sm font-medium text-[var(--color-ink-strong)]">Partner space</p>
                    <p class="mt-1 text-xs leading-5 text-[var(--color-ink-soft)]">Reserved for relevant soapmaking suppliers.</p>
                </div>
            </div>
        </section>

        <section aria-label="Secondary advertisement" class="hidden rounded-xl border border-dashed border-[var(--color-line)] bg-[var(--color-panel)] p-4 lg:block">
            <p class="sk-eyebrow">Advertisement</p>
            <div class="mt-4 grid min-h-[18rem] place-items-center rounded-lg bg-[var(--color-field-muted)] px-4 text-center">
                <p class="text-xs leading-5 text-[var(--color-ink-soft)]">Reserved placement</p>
            </div>
        </section>
    </div>
</aside>
