<div class="mx-auto max-w-[90rem] space-y-8">
    <section class="grid gap-4 xl:grid-cols-[minmax(0,1.3fr)_22rem]">
        <div class="rounded-[2rem] border border-[var(--color-line)] bg-white p-6">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div class="min-w-0">
                    <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Reusable catalog</p>
                    <h3 class="mt-3 text-3xl font-semibold tracking-[-0.04em] text-[var(--color-ink-strong)]">Reusable packaging catalog for bottles, tubes, labels, and other repeatable items.</h3>
                    <p class="mt-4 max-w-3xl text-sm leading-7 text-[var(--color-ink-soft)]">
                        Keep the reusable packaging items you reach for most often in one place so costing can stay consistent and easy to maintain.
                    </p>
                </div>

                <a href="{{ route('dashboard') }}" wire:navigate class="inline-flex shrink-0 rounded-full bg-[var(--color-accent-strong)] px-5 py-2.5 text-sm font-medium text-white transition hover:bg-[var(--color-accent)]">
                    Back to dashboard
                </a>
            </div>
        </div>

        <div class="rounded-[2rem] border border-[var(--color-line-strong)] bg-[var(--color-panel-strong)] p-6">
            <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Saved packaging</p>
            <div class="mt-4 space-y-4">
                <div>
                    <p class="text-3xl font-semibold tracking-[-0.04em] text-[var(--color-ink-strong)]">{{ $packagingItemCount }}</p>
                    <p class="mt-1 text-sm text-[var(--color-ink-soft)]">
                        {{ $currentUser ? 'Packaging items available in your reusable catalog.' : 'Sign in through the app or admin panel to manage packaging items.' }}
                    </p>
                </div>
            </div>
        </div>
    </section>

    <section class="space-y-4">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Packaging items</p>
                <h3 class="mt-1 text-xl font-semibold text-[var(--color-ink-strong)]">Reusable packaging records</h3>
            </div>
            @if ($currentUser)
                <span class="rounded-full border border-[var(--color-line)] bg-white px-4 py-2 text-sm text-[var(--color-ink-soft)]">
                    {{ $packagingItemCount }} {{ $packagingItemCount === 1 ? 'item' : 'items' }}
                </span>
            @endif
        </div>

        @if (! $currentUser)
            <div class="rounded-[2rem] border border-[var(--color-line)] bg-white p-8 text-center">
                <h4 class="text-lg font-semibold text-[var(--color-ink-strong)]">Sign in to manage packaging items</h4>
                <p class="mt-3 text-sm leading-7 text-[var(--color-ink-soft)]">
                    Open the dashboard from your signed-in app or admin session to review and maintain reusable packaging items.
                </p>
            </div>
        @elseif ($packagingItems->isEmpty())
            <div class="rounded-[2rem] border border-[var(--color-line)] bg-white p-8 text-center">
                <h4 class="text-lg font-semibold text-[var(--color-ink-strong)]">No reusable packaging items yet</h4>
                <p class="mt-3 text-sm leading-7 text-[var(--color-ink-soft)]">
                    Add bottle, tube, label, or container records here when you are ready to keep costing inputs reusable and consistent.
                </p>
            </div>
        @else
            <div class="overflow-hidden rounded-[2rem] border border-[var(--color-line)] bg-white">
                <div class="divide-y divide-[var(--color-line)]">
                    @foreach ($packagingItems as $packagingItem)
                        <article class="px-5 py-4">
                            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h4 class="truncate text-lg font-semibold text-[var(--color-ink-strong)]">{{ $packagingItem->name }}</h4>
                                        <span class="rounded-full border border-[var(--color-line)] px-3 py-1 text-xs font-medium text-[var(--color-ink-soft)]">
                                            {{ $packagingItem->currency }} {{ number_format((float) $packagingItem->unit_cost, 4, '.', '') }}
                                        </span>
                                    </div>
                                    @if (filled($packagingItem->notes))
                                        <p class="mt-2 text-sm leading-7 text-[var(--color-ink-soft)]">{{ $packagingItem->notes }}</p>
                                    @endif
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
            </div>
        @endif
    </section>
</div>
