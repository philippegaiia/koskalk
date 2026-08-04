<x-production-bench.page compact>
    @if (! $isBenchActive && ! $isReadOnly)
        <section class="sk-card p-8 text-center">
            <h1 class="text-3xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.common.inactive') }}</h1>
            <a href="{{ route('production-bench.home') }}" wire:navigate class="mt-4 inline-block text-sm font-medium text-[var(--color-accent)]">{{ __('production_bench.title') }}</a>
        </section>
    @else
        <header class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <a href="{{ route('production-bench.production.index') }}" wire:navigate class="text-sm font-medium text-[var(--color-accent-strong)] hover:underline">← {{ __('production_bench.production.back_to_list') }}</a>
                <p class="mt-4 sk-eyebrow">{{ __('production_bench.production.prepare_stock') }}</p>
                <h1 class="mt-2 text-3xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.production.prepare_stock_title') }}</h1>
                <p class="mt-2 max-w-3xl text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.production.prepare_stock_help') }}</p>
            </div>
            @if ($isReadOnly)
                <span role="status" class="rounded-full bg-[var(--color-warning-soft)] px-3 py-1.5 text-sm font-medium text-[var(--color-warning-strong)]">{{ __('production_bench.common.read_only') }}</span>
            @endif
        </header>

        @error('productions')
            <p role="alert" class="rounded-xl bg-[var(--color-danger-soft)] px-4 py-3 text-sm text-[var(--color-danger-strong)]">{{ $message }}</p>
        @enderror
        @error('allocations')
            <p role="alert" class="rounded-xl bg-[var(--color-danger-soft)] px-4 py-3 text-sm text-[var(--color-danger-strong)]">{{ $message }}</p>
        @enderror
        @error('idempotencyKey')
            <p role="alert" class="rounded-xl bg-[var(--color-danger-soft)] px-4 py-3 text-sm text-[var(--color-danger-strong)]">{{ $message }}</p>
        @enderror

        <div class="space-y-6">
            @foreach ($proposals as $productionProposal)
                @php($production = $productionProposal['production'])
                <section aria-labelledby="prepare-production-{{ $production->id }}" class="sk-card overflow-hidden">
                    <div class="border-b border-[var(--color-line)] bg-[var(--color-panel-muted)] p-5 sm:p-6">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <p class="font-mono text-xs text-[var(--color-ink-soft)]">{{ $production->public_id }}</p>
                                <h2 id="prepare-production-{{ $production->id }}" class="mt-1 text-xl font-semibold text-[var(--color-ink-strong)]">{{ $production->recipe?->name ?? __('production_bench.production.unknown_product') }}</h2>
                            </div>
                            <div class="text-left text-sm sm:text-right">
                                <p class="font-medium text-[var(--color-ink-strong)]">{{ $production->planned_for?->format('Y-m-d') ?? '—' }}</p>
                                <p class="text-[var(--color-ink-soft)]">{{ $production->status->label() }}</p>
                            </div>
                        </div>
                    </div>

                    <div class="divide-y divide-[var(--color-line)]">
                        @foreach ($productionProposal['requirements'] as $requirementProposal)
                            @php($requirement = $requirementProposal['requirement'])
                            <article class="space-y-4 p-5 sm:p-6">
                                <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                    <div>
                                        <h3 class="font-semibold text-[var(--color-ink-strong)]">{{ $requirement->subject_name_snapshot }}</h3>
                                        <p class="mt-1 text-sm text-[var(--color-ink-soft)]">
                                            {{ $requirement->ingredient_id !== null ? $requirementProposal['required'].' g' : $requirementProposal['required'].' '.__('production_bench.inventory.units') }}
                                            · {{ __('production_bench.production.remaining_to_prepare') }} {{ $requirementProposal['remaining'] }}
                                        </p>
                                    </div>
                                    <dl class="grid grid-cols-3 gap-4 text-right text-sm">
                                        <div><dt class="text-xs uppercase tracking-wide text-[var(--color-ink-muted)]">{{ __('production_bench.production.proposed') }}</dt><dd class="mt-1 font-mono tabular-nums text-[var(--color-ink-strong)]">{{ $requirementProposal['proposed'] }}</dd></div>
                                        <div><dt class="text-xs uppercase tracking-wide text-[var(--color-ink-muted)]">{{ __('production_bench.production.missing') }}</dt><dd class="mt-1 font-mono tabular-nums {{ bccomp($requirementProposal['missing'], '0', 9) > 0 ? 'text-[var(--color-danger-strong)]' : 'text-[var(--color-success-strong)]' }}">{{ $requirementProposal['missing'] }}</dd></div>
                                        <div><dt class="text-xs uppercase tracking-wide text-[var(--color-ink-muted)]">{{ __('production_bench.production.lots') }}</dt><dd class="mt-1 font-mono tabular-nums text-[var(--color-ink-strong)]">{{ count($requirementProposal['allocations']) }}</dd></div>
                                    </dl>
                                </div>

                                @error('requirements.'.$requirement->id)
                                    <p role="alert" class="rounded-lg bg-[var(--color-danger-soft)] px-3 py-2 text-sm text-[var(--color-danger-strong)]">{{ $message }}</p>
                                @enderror

                                @if ($requirementProposal['allocations'] !== [])
                                    <div class="rounded-xl border border-[var(--color-line)] bg-[var(--color-panel-muted)] p-4">
                                        <p class="text-sm font-medium text-[var(--color-ink-strong)]">{{ __('production_bench.production.automatic_lots') }}</p>
                                        <div class="mt-3 space-y-2">
                                            @foreach ($requirementProposal['allocations'] as $allocation)
                                                <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                                                    <span class="font-mono text-[var(--color-ink-soft)]">{{ $allocation['lot']->internal_lot_code }} · {{ $allocation['lot']->expires_at?->format('Y-m-d') ?? __('production_bench.production.no_expiry') }}</span>
                                                    <span class="font-mono tabular-nums text-[var(--color-ink-strong)]">{{ $allocation['quantity'] }}</span>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif

                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <button type="button" wire:click="toggleManual({{ $requirement->id }})" class="text-sm font-medium text-[var(--color-accent-strong)] hover:underline" @disabled($isReadOnly)>
                                        {{ ($manualMode[(string) $requirement->id] ?? false) ? __('production_bench.production.use_automatic_lots') : __('production_bench.production.choose_lots_manually') }}
                                    </button>
                                    <span class="text-xs text-[var(--color-ink-soft)]">{{ __('production_bench.production.preview_only') }}</span>
                                </div>

                                @if ($manualMode[(string) $requirement->id] ?? false)
                                    <div class="rounded-xl border border-[var(--color-accent)] bg-[var(--color-panel-muted)] p-4">
                                        <p class="text-sm font-medium text-[var(--color-ink-strong)]">{{ __('production_bench.production.manual_lots') }}</p>
                                        <div class="mt-3 space-y-3">
                                            @forelse ($requirementProposal['eligible_lots'] as $lotRow)
                                                <label class="grid gap-2 sm:grid-cols-[1fr_10rem] sm:items-center">
                                                    <span class="text-sm text-[var(--color-ink-soft)]">{{ $lotRow['lot']->internal_lot_code }} · {{ __('production_bench.production.available_quantity') }} {{ $lotRow['available'] }} · {{ $lotRow['lot']->expires_at?->format('Y-m-d') ?? __('production_bench.production.no_expiry') }}</span>
                                                    <input type="text" inputmode="decimal" wire:model.live.debounce.300ms="manualQuantities.{{ $requirement->id }}.{{ $lotRow['lot']->id }}" class="sk-input w-full" placeholder="0">
                                                </label>
                                            @empty
                                                <p class="text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.production.no_eligible_lots') }}</p>
                                            @endforelse
                                        </div>
                                    </div>
                                @endif
                            </article>
                        @endforeach
                    </div>
                </section>
            @endforeach
        </div>

        <form wire:submit="confirm" class="sk-card flex flex-col gap-4 p-5 sm:flex-row sm:items-center sm:justify-between sm:p-6">
            <div>
                <p class="font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.production.confirm_prepare_stock') }}</p>
                <p class="mt-1 text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.production.confirm_prepare_stock_help') }}</p>
            </div>
            <button type="submit" wire:loading.attr="disabled" @disabled($isReadOnly || ! $isBenchActive) class="sk-btn sk-btn-primary shrink-0">{{ __('production_bench.production.prepare_stock') }}</button>
        </form>
    @endif
</x-production-bench.page>
