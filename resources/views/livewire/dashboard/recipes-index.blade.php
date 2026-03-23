<div class="space-y-8">
    <section class="grid gap-4 xl:grid-cols-[minmax(0,1.35fr)_22rem]">
        <div class="rounded-[2rem] border border-[var(--color-line)] bg-white p-6">
            <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Recipe strategy</p>
            <h3 class="mt-3 text-3xl font-semibold tracking-[-0.04em] text-[var(--color-ink-strong)]">Soap starts with the reaction core, then the later phases.</h3>
            <p class="mt-4 max-w-3xl text-sm leading-7 text-[var(--color-ink-soft)]">
                The workbench separates the soap calculation itself from the additions that come afterward. Oils and lye water stay central, while additives and aromatics stay visible as their own later phases.
            </p>

            <div class="mt-6 flex flex-wrap gap-3">
                <a href="{{ route('recipes.create') }}" wire:navigate class="inline-flex rounded-full bg-[var(--color-ink-strong)] px-5 py-2.5 text-sm font-medium text-white transition hover:bg-[var(--color-accent-strong)]">Create soap formula</a>
                <span class="inline-flex rounded-full border border-[var(--color-line)] px-4 py-2 text-sm text-[var(--color-ink-soft)]">Persistence comes after public auth</span>
            </div>
        </div>

        <div class="rounded-[2rem] border border-[var(--color-line-strong)] bg-[var(--color-panel-strong)] p-6">
            <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Current availability</p>
            <div class="mt-4 space-y-4">
                <div>
                    <p class="text-3xl font-semibold tracking-[-0.04em] text-[var(--color-ink-strong)]">{{ $recipeCount ?? 'Auth pending' }}</p>
                    <p class="mt-1 text-sm text-[var(--color-ink-soft)]">Recipe listing is ready to become real as soon as the public auth slice is in place.</p>
                </div>
                <div class="rounded-[1.5rem] border border-[var(--color-line)] bg-white p-4">
                    <p class="text-xs font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase">Current basis rule</p>
                    <p class="mt-2 text-sm leading-6 text-[var(--color-ink-soft)]">Soap edits on oil-based percentages first, while non-soap formulas will normalize on total percentage only.</p>
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

    <section class="grid gap-4 xl:grid-cols-[minmax(0,1.2fr)_minmax(0,0.9fr)]">
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
                    'A real recipes area in the public shell',
                    'A Livewire + Alpine soap workbench preview',
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
    </section>
</div>
