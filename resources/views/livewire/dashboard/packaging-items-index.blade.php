<div class="mx-auto max-w-[90rem] space-y-6">
    <section class="rounded-[2rem] border border-[var(--color-line)] bg-white p-6">
        <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Packaging items</p>
        <h3 class="mt-3 text-3xl font-semibold tracking-[-0.04em] text-[var(--color-ink-strong)]">Create reusable packaging items here, then reuse them in recipe costing.</h3>
        <p class="mt-4 max-w-3xl text-sm leading-7 text-[var(--color-ink-soft)]">
            This page manages your reusable catalog. Recipe costing decides how many of each packaging item one finished unit uses.
        </p>
    </section>

    @if (! $currentUser)
        <section class="rounded-[2rem] border border-[var(--color-line)] bg-white p-8 text-center">
            <h4 class="text-lg font-semibold text-[var(--color-ink-strong)]">Sign in to manage packaging items</h4>
            <p class="mt-3 text-sm leading-7 text-[var(--color-ink-soft)]">
                Open the dashboard from your signed-in app or admin session to create and reuse packaging items.
            </p>
        </section>
    @else
        <section class="rounded-[2rem] border border-[var(--color-line)] bg-white p-6">
            <div class="flex flex-col gap-6 xl:flex-row xl:items-start xl:justify-between">
                <div class="xl:max-w-xl">
                    <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">New packaging item</p>
                    <h4 class="mt-2 text-xl font-semibold text-[var(--color-ink-strong)]">Add a reusable packaging record</h4>
                    <p class="mt-2 text-sm leading-7 text-[var(--color-ink-soft)]">
                        Save boxes, labels, stickers, wraps, and inserts here so they are ready inside recipe costing.
                    </p>
                </div>

                <div class="rounded-[1.5rem] border border-[var(--color-line)] bg-[var(--color-panel)] px-4 py-3 text-sm text-[var(--color-ink-soft)]">
                    <span class="font-medium text-[var(--color-ink-strong)]">{{ $packagingItemCount }}</span>
                    {{ $packagingItemCount === 1 ? 'saved item' : 'saved items' }}
                </div>
            </div>

            <form wire:submit="save" class="mt-6 grid gap-4 xl:grid-cols-[minmax(0,1.8fr)_14rem]">
                <label class="rounded-[1.5rem] border border-[var(--color-line)] bg-[var(--color-panel)] p-4">
                    <span class="text-xs font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase">Name</span>
                    <input wire:model.blur="form.name" type="text" class="mt-3 w-full rounded-2xl border border-[var(--color-line)] bg-white px-3 py-2.5 text-sm text-[var(--color-ink-strong)] outline-none" placeholder="Box soap rectangle 100g" />
                    @error('form.name')
                        <p class="mt-2 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </label>

                <label class="rounded-[1.5rem] border border-[var(--color-line)] bg-[var(--color-panel)] p-4">
                    <span class="text-xs font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase">Effective unit price</span>
                    <input wire:model.blur="form.unit_cost" type="number" min="0" step="0.0001" class="mt-3 w-full rounded-2xl border border-[var(--color-line)] bg-white px-3 py-2.5 text-sm text-[var(--color-ink-strong)] outline-none" placeholder="0.4200" />
                    @error('form.unit_cost')
                        <p class="mt-2 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </label>

                <label class="rounded-[1.5rem] border border-[var(--color-line)] bg-[var(--color-panel)] p-4 xl:col-span-2">
                    <span class="text-xs font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase">Notes</span>
                    <textarea wire:model.blur="form.notes" rows="3" class="mt-3 w-full rounded-2xl border border-[var(--color-line)] bg-white px-3 py-2.5 text-sm text-[var(--color-ink-strong)] outline-none" placeholder="Optional context for size, finish, or pack variant"></textarea>
                    @error('form.notes')
                        <p class="mt-2 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </label>

                <div class="xl:col-span-2 flex items-center justify-between gap-3">
                    <p class="text-sm text-[var(--color-ink-soft)]">
                        {{ $saveMessage ?? 'Saved items here become available inside recipe costing.' }}
                    </p>

                    <button type="submit" class="rounded-full bg-[var(--color-accent-strong)] px-5 py-2.5 text-sm font-medium text-white transition hover:bg-[var(--color-accent)]">
                        Save packaging item
                    </button>
                </div>
            </form>
        </section>

        <section class="overflow-hidden rounded-[2rem] border border-[var(--color-line)] bg-white">
            <div class="border-b border-[var(--color-line)] px-5 py-4">
                <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Saved packaging</p>
                <h4 class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">Reusable packaging items</h4>
            </div>

            @if ($packagingItems->isEmpty())
                <div class="px-5 py-8 text-sm text-[var(--color-ink-soft)]">
                    No packaging items yet. Create your first packaging item above.
                </div>
            @else
                <div class="divide-y divide-[var(--color-line)]">
                    @foreach ($packagingItems as $packagingItem)
                        <article class="px-5 py-4">
                            <div class="flex flex-col gap-2 md:flex-row md:items-start md:justify-between">
                                <div class="min-w-0">
                                    <h5 class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ $packagingItem->name }}</h5>
                                    @if (filled($packagingItem->notes))
                                        <p class="mt-2 text-sm leading-7 text-[var(--color-ink-soft)]">{{ $packagingItem->notes }}</p>
                                    @endif
                                </div>

                                <div class="rounded-full border border-[var(--color-line)] bg-[var(--color-panel)] px-4 py-2 text-sm font-medium text-[var(--color-ink-strong)]">
                                    {{ $packagingItem->currency }} {{ number_format((float) $packagingItem->unit_cost, 4, '.', '') }}
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>
    @endif
</div>
