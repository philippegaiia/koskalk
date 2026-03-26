<div class="space-y-8">
    <section class="grid gap-4 xl:grid-cols-[minmax(0,1.3fr)_22rem]">
        <div class="rounded-[2rem] border border-[var(--color-line)] bg-white p-6">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div class="min-w-0">
                    <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Formulas</p>
                    <h3 class="mt-3 text-3xl font-semibold tracking-[-0.04em] text-[var(--color-ink-strong)]">Your saved drafts and versions live here.</h3>
                    <p class="mt-4 max-w-3xl text-sm leading-7 text-[var(--color-ink-soft)]">
                        Name the formula from the workbench header, save the draft, then reopen it here to continue editing or promote it into a new version.
                    </p>
                </div>

                <a href="{{ route('recipes.create') }}" wire:navigate class="inline-flex shrink-0 rounded-full bg-[var(--color-ink-strong)] px-5 py-2.5 text-sm font-medium text-white transition hover:bg-[var(--color-accent-strong)]">
                    Create soap formula
                </a>
            </div>
        </div>

        <div class="rounded-[2rem] border border-[var(--color-line-strong)] bg-[var(--color-panel-strong)] p-6">
            <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Saved work</p>
            <div class="mt-4 space-y-4">
                <div>
                    <p class="text-3xl font-semibold tracking-[-0.04em] text-[var(--color-ink-strong)]">{{ $recipeCount }}</p>
                    <p class="mt-1 text-sm text-[var(--color-ink-soft)]">
                        {{ $currentUser ? 'Recipes currently visible for your account.' : 'Sign in through the public app or admin panel to see your saved formulas.' }}
                    </p>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="rounded-[1.5rem] border border-[var(--color-line)] bg-white p-4">
                        <p class="text-xs font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase">Drafts</p>
                        <p class="mt-2 text-2xl font-semibold text-[var(--color-ink-strong)]">{{ $draftCount }}</p>
                    </div>
                    <div class="rounded-[1.5rem] border border-[var(--color-line)] bg-white p-4">
                        <p class="text-xs font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase">Published versions</p>
                        <p class="mt-2 text-2xl font-semibold text-[var(--color-ink-strong)]">{{ $publishedVersionCount }}</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="grid gap-4 lg:grid-cols-3">
        <div class="rounded-[2rem] border border-[var(--color-line)] bg-white p-5">
            <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Carrier oils</p>
            <p class="mt-4 text-4xl font-semibold tracking-[-0.04em] text-[var(--color-ink-strong)]">{{ $catalogStats['carrier_oils'] }}</p>
            <p class="mt-2 text-sm text-[var(--color-ink-soft)]">Only truly saponifiable carrier oils belong in the reaction-core picker.</p>
        </div>
        <div class="rounded-[2rem] border border-[var(--color-line)] bg-white p-5">
            <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Aromatic materials</p>
            <p class="mt-4 text-4xl font-semibold tracking-[-0.04em] text-[var(--color-ink-strong)]">{{ $catalogStats['aromatics'] }}</p>
            <p class="mt-2 text-sm text-[var(--color-ink-soft)]">Essential oils and aromatic extracts sit behind category filters and compliance context.</p>
        </div>
        <div class="rounded-[2rem] border border-[var(--color-line)] bg-white p-5">
            <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Additions</p>
            <p class="mt-4 text-4xl font-semibold tracking-[-0.04em] text-[var(--color-ink-strong)]">{{ $catalogStats['additives'] }}</p>
            <p class="mt-2 text-sm text-[var(--color-ink-soft)]">Colorants, preservatives, and other post-reaction additions stay separate from the soap core.</p>
        </div>
    </section>

    <section class="space-y-4">
        <div class="flex items-center justify-between gap-4">
            <div>
                <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Saved formulas</p>
                <h3 class="mt-1 text-xl font-semibold text-[var(--color-ink-strong)]">Drafts you can reopen and continue</h3>
            </div>
            @if ($currentUser)
                <span class="rounded-full border border-[var(--color-line)] bg-white px-4 py-2 text-sm text-[var(--color-ink-soft)]">{{ $recipeCount }} visible</span>
            @endif
        </div>

        @if (! $currentUser)
            <div class="rounded-[2rem] border border-[var(--color-line)] bg-white p-8 text-center">
                <h4 class="text-lg font-semibold text-[var(--color-ink-strong)]">No signed-in formula workspace yet</h4>
                <p class="mt-3 text-sm leading-7 text-[var(--color-ink-soft)]">Open the recipes workbench from the same account you use in the app or in the admin panel, then your saved drafts will appear here.</p>
            </div>
        @elseif ($recipes->isEmpty())
            <div class="rounded-[2rem] border border-[var(--color-line)] bg-white p-8 text-center">
                <h4 class="text-lg font-semibold text-[var(--color-ink-strong)]">No formulas saved yet</h4>
                <p class="mt-3 text-sm leading-7 text-[var(--color-ink-soft)]">Create the first soap formula, give it a name in the header, and save the draft to make it appear in this list.</p>
                <a href="{{ route('recipes.create') }}" wire:navigate class="mt-5 inline-flex rounded-full bg-[var(--color-ink-strong)] px-5 py-2.5 text-sm font-medium text-white transition hover:bg-[var(--color-accent-strong)]">Create soap formula</a>
            </div>
        @else
            <div class="grid gap-4 xl:grid-cols-2">
                @foreach ($recipes as $recipe)
                    <article class="rounded-[2rem] border border-[var(--color-line)] bg-white p-5">
                        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h4 class="truncate text-xl font-semibold text-[var(--color-ink-strong)]">{{ $recipe->name }}</h4>
                                    @if ($recipe->currentDraftVersion)
                                        <span class="rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-xs font-medium text-emerald-700">Draft</span>
                                    @endif
                                    @if ($recipe->published_versions_count > 0)
                                        <span class="rounded-full border border-[var(--color-line)] bg-[var(--color-panel)] px-3 py-1 text-xs font-medium text-[var(--color-ink-soft)]">
                                            {{ $recipe->published_versions_count }} {{ \Illuminate\Support\Str::plural('version', $recipe->published_versions_count) }}
                                        </span>
                                    @endif
                                </div>
                                <div class="mt-3 flex flex-wrap gap-2">
                                    <span class="rounded-full border border-[var(--color-line)] px-3 py-1 text-xs font-medium text-[var(--color-ink-soft)]">
                                        {{ $recipe->productFamily?->name ?? 'Formula' }}
                                    </span>
                                    @if ($recipe->currentDraftVersion)
                                        <span class="rounded-full border border-[var(--color-line)] px-3 py-1 text-xs font-medium text-[var(--color-ink-soft)]">
                                            Draft v{{ $recipe->currentDraftVersion->version_number }}
                                        </span>
                                    @endif
                                </div>
                            </div>

                            <a href="{{ route('recipes.edit', $recipe->id) }}" wire:navigate class="inline-flex shrink-0 rounded-full border border-[var(--color-line-strong)] px-4 py-2 text-sm font-medium text-[var(--color-ink-strong)] transition hover:bg-[var(--color-panel)]">
                                Open draft
                            </a>
                        </div>

                        <div class="mt-5 grid gap-3 sm:grid-cols-2">
                            <div class="rounded-[1.5rem] border border-[var(--color-line)] bg-[var(--color-panel)] px-4 py-3">
                                <p class="text-xs font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase">Last updated</p>
                                <p class="mt-2 text-sm font-medium text-[var(--color-ink-strong)]">{{ $recipe->updated_at?->diffForHumans() ?? 'Just now' }}</p>
                            </div>
                            <div class="rounded-[1.5rem] border border-[var(--color-line)] bg-[var(--color-panel)] px-4 py-3">
                                <p class="text-xs font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase">Naming</p>
                                <p class="mt-2 text-sm font-medium text-[var(--color-ink-strong)]">{{ $recipe->name }}</p>
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif

        <div class="grid gap-4 xl:grid-cols-[minmax(0,1.2fr)_minmax(0,0.9fr)]">
            <div class="overflow-hidden rounded-[2rem] border border-[var(--color-line)] bg-white">
                <div class="border-b border-[var(--color-line)] px-5 py-4">
                    <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Product families</p>
                    <h3 class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">Calculation basis is family-driven</h3>
                </div>

                <div class="grid gap-px bg-[var(--color-line)]">
                    @foreach ($productFamilies as $family)
                        <div class="bg-white px-5 py-4">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <p class="font-medium text-[var(--color-ink-strong)]">{{ $family['name'] }}</p>
                                    <p class="mt-2 text-sm leading-6 text-[var(--color-ink-soft)]">{{ $family['description'] }}</p>
                                </div>
                                <span class="shrink-0 rounded-full border border-[var(--color-line)] px-3 py-1 text-xs font-medium text-[var(--color-ink-soft)]">
                                    {{ $family['basis'] === 'initial_oils' ? 'Initial oils basis' : 'Total formula basis' }}
                                </span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="rounded-[2rem] border border-[var(--color-line)] bg-white p-5">
                <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">This slice delivers</p>
                <div class="mt-4 space-y-3">
                    @foreach ([
                        'Named drafts that now persist into a visible recipe list',
                        'A workbench that reopens a saved formula from the public shell',
                        'Category-filtered ingredient loading from the actual catalog',
                        'A clear split between reaction core and post-reaction phases',
                    ] as $point)
                        <div class="flex gap-3 rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] px-4 py-3 text-sm text-[var(--color-ink-soft)]">
                            <span class="mt-0.5 grid size-5 shrink-0 place-items-center rounded-full border border-[var(--color-line)] bg-white text-[11px] font-semibold text-[var(--color-ink-strong)]">•</span>
                            <span>{{ $point }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </section>
</div>
