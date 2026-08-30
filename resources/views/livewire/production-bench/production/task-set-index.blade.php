<x-production-bench.page active="production-setup" subnavigation="task-sets">
    @if (! $isBenchActive && ! $isReadOnly)
        <section class="sk-card p-8 text-center">
            <h1 class="text-3xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.common.inactive') }}</h1>
        </section>
    @else
        <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="sk-eyebrow">{{ __('production_bench.navigation.settings') }}</p>
                <h1 id="task-set-heading" class="mt-2 text-3xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.settings.task_sets') }}</h1>
                <p class="mt-2 max-w-2xl text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.settings.task_sets_help') }}</p>
            </div>
            @if ($isBenchActive && ! $isReadOnly)
                <a href="{{ route('production-bench.production.settings.task-sets.create') }}" wire:navigate class="sk-btn sk-btn-primary">{{ __('production_bench.settings.new_task_set') }}</a>
            @endif
        </header>

        @if ($isReadOnly)
            <p role="status" class="rounded-xl bg-[var(--color-warning-soft)] px-4 py-3 text-sm text-[var(--color-warning-strong)]">{{ __('production_bench.common.read_only') }}</p>
        @endif

        <section class="overflow-hidden sk-card">
            <div class="grid gap-3 border-b border-[var(--color-line)] bg-[var(--color-panel-muted)] p-4 sm:grid-cols-[minmax(0,1fr)_12rem]">
                <label class="text-sm">
                    <span class="font-medium">{{ __('production_bench.common.search') }}</span>
                    <input wire:model.live.debounce.300ms="search" type="search" class="sk-input mt-1 w-full" placeholder="{{ __('production_bench.settings.search_task_sets') }}">
                </label>
                <label class="text-sm">
                    <span class="font-medium">{{ __('production_bench.common.status') }}</span>
                    <select wire:model.live="status" class="sk-input mt-1 w-full">
                        <option value="active">{{ __('production_bench.common.active') }}</option>
                        <option value="all">{{ __('production_bench.common.all') }}</option>
                        <option value="inactive">{{ __('production_bench.common.inactive') }}</option>
                    </select>
                </label>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[900px] text-left text-sm">
                    <thead class="bg-[var(--color-panel-muted)] text-xs uppercase tracking-wide text-[var(--color-ink-soft)]"><tr><th class="px-5 py-3">{{ __('production_bench.settings.task_set_name') }}</th><th class="px-4 py-3">{{ __('production_bench.settings.task_set_tasks') }}</th><th class="px-4 py-3">{{ __('production_bench.settings.applicable_products') }}</th><th class="px-4 py-3">{{ __('production_bench.common.status') }}</th><th class="px-5 py-3 text-right">{{ __('production_bench.common.actions') }}</th></tr></thead>
                    <tbody class="divide-y divide-[var(--color-line)]">
                        @forelse ($taskSets as $taskSet)
                            <tr wire:key="task-set-{{ $taskSet->id }}" class="align-top transition hover:bg-[var(--color-panel-strong)]">
                                <td class="px-5 py-4 font-medium text-[var(--color-ink-strong)]">{{ $taskSet->name }}</td>
                                <td class="px-4 py-4">
                                    <details class="max-w-sm">
                                        <summary class="cursor-pointer font-medium text-[var(--color-accent-strong)]">{{ trans_choice('production_bench.settings.tasks_selected', $taskSet->items_count, ['count' => $taskSet->items_count]) }}</summary>
                                        <ol class="mt-2 max-h-40 space-y-1 overflow-y-auto rounded-lg bg-[var(--color-panel-muted)] p-3 text-xs text-[var(--color-ink-soft)]">
                                            @foreach ($taskSet->items as $item)
                                                <li>{{ $item->taskType?->name ?? '—' }} · {{ $item->days_after_production > 0 ? '+' : '' }}{{ $item->days_after_production }}</li>
                                            @endforeach
                                        </ol>
                                    </details>
                                </td>
                                <td class="px-4 py-4">
                                    @if ($taskSet->recipes_count > 0)
                                        <details class="max-w-xs">
                                            <summary class="cursor-pointer font-medium text-[var(--color-accent-strong)]">{{ __('production_bench.settings.view_products') }} · {{ trans_choice('production_bench.settings.products_selected', $taskSet->recipes_count, ['count' => $taskSet->recipes_count]) }}</summary>
                                            <ul class="mt-2 max-h-40 space-y-1 overflow-y-auto rounded-lg bg-[var(--color-panel-muted)] p-3 text-xs text-[var(--color-ink-soft)]">
                                                @foreach ($taskSet->recipes as $recipe)
                                                    <li>{{ $recipe->name }}@if ($recipe->pivot->is_default) <span class="text-[var(--color-accent-strong)]">· {{ __('production_bench.settings.default') }}</span>@endif</li>
                                                @endforeach
                                            </ul>
                                        </details>
                                    @else
                                        <span class="text-[var(--color-ink-soft)]">{{ __('production_bench.settings.no_applicable_products') }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-4"><span class="rounded-full px-2.5 py-1 text-xs font-medium {{ $taskSet->is_active ? 'bg-[var(--color-success-soft)] text-[var(--color-success-strong)]' : 'bg-[var(--color-field-muted)] text-[var(--color-ink-soft)]' }}">{{ $taskSet->is_active ? __('production_bench.common.active') : __('production_bench.common.inactive') }}</span></td>
                                <td class="px-5 py-4 text-right"><span class="inline-flex items-center gap-3">@if ($isBenchActive && ! $isReadOnly)<a href="{{ route('production-bench.production.settings.task-sets.edit', $taskSet) }}" wire:navigate class="font-medium text-[var(--color-accent-strong)] hover:underline">{{ __('production_bench.common.edit') }}</a><button type="button" wire:click="delete({{ $taskSet->id }})" wire:confirm="{{ __('production_bench.settings.delete_task_set_confirm') }}" class="font-medium text-[var(--color-danger-strong)] hover:underline">{{ __('production_bench.common.delete') }}</button>@else — @endif</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-6 py-12 text-center text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.settings.no_task_sets') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <x-table-pagination :paginator="$taskSets" />
        </section>
    @endif
</x-production-bench.page>
