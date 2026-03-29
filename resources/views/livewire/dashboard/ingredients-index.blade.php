<div class="mx-auto w-full max-w-7xl space-y-6">
    <section class="rounded-[2rem] border border-[var(--color-line)] bg-white p-5 sm:p-6">
        <div class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
            <div class="min-w-0">
                <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Personal library</p>
                <h3 class="mt-3 max-w-4xl text-2xl font-semibold tracking-[-0.04em] text-[var(--color-ink-strong)] sm:text-3xl">Create private ingredients, enrich them later, and reuse them in your formulas.</h3>
                <p class="mt-3 max-w-3xl text-sm leading-7 text-[var(--color-ink-soft)]">
                    Use the same ingredient model as the platform catalog, but keep your own materials private by default. Aromatic compliance and composite breakdowns can be added whenever you are ready.
                </p>
            </div>

            <div class="flex flex-col gap-3 sm:flex-row">
                @if ($currentUser)
                    <a href="{{ route('ingredients.create') }}" wire:navigate class="inline-flex justify-center rounded-full bg-[var(--color-accent-strong)] px-5 py-2.5 text-sm font-medium text-white transition hover:bg-[var(--color-accent)]">
                        Add ingredient
                    </a>
                @endif
                <a href="{{ route('dashboard') }}" wire:navigate class="inline-flex justify-center rounded-full border border-[var(--color-line)] px-5 py-2.5 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel)]">
                    Back to dashboard
                </a>
            </div>
        </div>
    </section>

    <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-[18rem_minmax(0,1fr)]">
        <div class="rounded-[2rem] border border-[var(--color-line)] bg-white p-5">
            <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Personal ingredients</p>
            <p class="mt-4 text-4xl font-semibold tracking-[-0.04em] text-[var(--color-ink-strong)]">{{ $ingredientCount }}</p>
            <p class="mt-2 text-sm text-[var(--color-ink-soft)]">Only your own private ingredient library is listed here.</p>
        </div>

        <div class="overflow-hidden rounded-[2rem] border border-[var(--color-line)] bg-white">
            <div class="border-b border-[var(--color-line)] px-5 py-4">
                <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">My ingredients</p>
                <h3 class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">Private catalog records</h3>
            </div>

            @if (! $currentUser)
                <div class="p-8 text-center">
                    <h4 class="text-lg font-semibold text-[var(--color-ink-strong)]">Sign in to manage ingredients</h4>
                    <p class="mt-3 text-sm leading-7 text-[var(--color-ink-soft)]">Open the dashboard from your signed-in app or admin session to create private ingredients.</p>
                </div>
            @elseif ($ingredients->isEmpty())
                <div class="p-8 text-center">
                    <h4 class="text-lg font-semibold text-[var(--color-ink-strong)]">No personal ingredients yet</h4>
                    <p class="mt-3 text-sm leading-7 text-[var(--color-ink-soft)]">Create your first ingredient with a name, category, and optional INCI. You can add components or aromatic compliance data afterwards.</p>
                </div>
            @else
                <div class="divide-y divide-[var(--color-line)]">
                    @foreach ($ingredients as $ingredient)
                        <article class="px-5 py-4">
                            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h4 class="truncate text-lg font-semibold text-[var(--color-ink-strong)]">{{ $ingredient->currentVersion?->display_name ?? $ingredient->source_key }}</h4>
                                        <span class="rounded-full border border-[var(--color-line)] px-3 py-1 text-xs font-medium text-[var(--color-ink-soft)]">
                                            {{ $ingredient->category?->getLabel() ?? 'Uncategorized' }}
                                        </span>
                                        @if ($ingredient->components_count > 0)
                                            <span class="rounded-full border border-[var(--color-line)] bg-[var(--color-panel)] px-3 py-1 text-xs font-medium text-[var(--color-ink-soft)]">Composite</span>
                                        @endif
                                    </div>
                                    @if (filled($ingredient->currentVersion?->inci_name))
                                        <p class="mt-2 truncate text-sm text-[var(--color-ink-soft)]">{{ $ingredient->currentVersion?->inci_name }}</p>
                                    @endif
                                </div>

                                <div class="flex flex-wrap gap-2">
                                    <a href="{{ route('ingredients.edit', $ingredient->id) }}" wire:navigate class="inline-flex rounded-full border border-[var(--color-line-strong)] px-4 py-2 text-sm font-medium text-[var(--color-ink-strong)] transition hover:bg-[var(--color-panel)]">
                                        Open ingredient
                                    </a>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </div>
    </section>
</div>
