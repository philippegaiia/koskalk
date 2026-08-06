<x-production-bench.page productionSetup compact>
    <header class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="sk-eyebrow">{{ __('production_bench.navigation.production') }}</p>
            <h1 class="mt-2 text-3xl font-semibold text-[var(--color-ink-strong)]">{{ $editing ? __('production_bench.settings.edit_task_set') : __('production_bench.settings.new_task_set') }}</h1>
            <p class="mt-2 max-w-2xl text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.settings.task_sets_help') }}</p>
        </div>
        <a href="{{ route('production-bench.production.settings.task-sets') }}" wire:navigate class="sk-btn sk-btn-ghost">{{ __('production_bench.common.cancel') }}</a>
    </header>

    @if ($isReadOnly)
        <p role="status" class="rounded-xl bg-[var(--color-warning-soft)] px-4 py-3 text-sm text-[var(--color-warning-strong)]">{{ __('production_bench.common.read_only') }}</p>
    @endif

    <form wire:submit="save" class="space-y-6">
        <section class="sk-card space-y-5 p-5">
            <div class="grid gap-4 md:grid-cols-3">
                <label class="text-sm md:col-span-2">
                    <span class="font-medium">{{ __('production_bench.settings.task_set_name') }}</span>
                    <input wire:model="name" class="sk-input mt-1 w-full" placeholder="Soap workflow" @disabled(! $isBenchActive || $isReadOnly)>
                    @error('name')<span class="mt-1 block text-xs text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror
                </label>
                <label class="flex items-start gap-3 pt-6 text-sm">
                    <input wire:model="isActive" type="checkbox" class="mt-0.5 size-4 rounded border-[var(--color-line-strong)]" style="accent-color: var(--color-accent);" @disabled(! $isBenchActive || $isReadOnly)>
                    <span><span class="font-medium text-[var(--color-ink-strong)]">{{ $isActive ? __('production_bench.common.active') : __('production_bench.common.inactive') }}</span><span class="mt-1 block text-[var(--color-ink-soft)]">{{ __('production_bench.settings.task_set_active_help') }}</span></span>
                </label>
            </div>
        </section>

        <section class="sk-card space-y-4 p-5" aria-labelledby="task-set-tasks-heading">
            <div>
                <h2 id="task-set-tasks-heading" class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.settings.task_set_tasks') }}</h2>
                <p class="mt-1 text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.settings.task_set_tasks_help') }}</p>
            </div>
            <div class="hidden gap-2 text-xs font-semibold uppercase tracking-wide text-[var(--color-ink-soft)] sm:grid sm:grid-cols-[minmax(0,1fr)_12rem_12rem_auto]">
                <span>{{ __('production_bench.settings.task_name') }}</span>
                <span>{{ __('production_bench.settings.days_relative_to_production') }}</span>
                <span>{{ __('production_bench.settings.duration_override') }}</span>
                <span class="sr-only">{{ __('production_bench.common.actions') }}</span>
            </div>
            <div class="space-y-3">
                @foreach ($taskSetItems as $index => $item)
                    <div wire:key="task-set-item-{{ $index }}" class="grid gap-2 rounded-xl border border-[var(--color-line)] p-3 sm:grid-cols-[minmax(0,1fr)_12rem_12rem_auto] sm:border-0 sm:p-0">
                        <label class="text-sm">
                            <span class="font-medium sm:sr-only">{{ __('production_bench.settings.task_name') }}</span>
                            <select wire:model="taskSetItems.{{ $index }}.task_type_id" class="sk-input mt-1 w-full" @disabled(! $isBenchActive || $isReadOnly)>
                                <option value="">{{ __('production_bench.settings.choose_task') }}</option>
                                @foreach ($taskTypes as $taskType)
                                    <option value="{{ $taskType->id }}">{{ $taskType->name }}{{ $taskType->is_active ? '' : ' · '.__('production_bench.common.inactive') }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="text-sm">
                            <span class="font-medium sm:sr-only">{{ __('production_bench.settings.days_relative_to_production') }}</span>
                            <input wire:model="taskSetItems.{{ $index }}.days_after_production" type="number" step="1" class="sk-input mt-1 w-full" placeholder="0" @disabled(! $isBenchActive || $isReadOnly)>
                        </label>
                        <label class="text-sm">
                            <span class="font-medium sm:sr-only">{{ __('production_bench.settings.duration_override') }}</span>
                            <input wire:model="taskSetItems.{{ $index }}.duration_minutes" type="number" min="0" step="1" inputmode="numeric" class="sk-input mt-1 w-full" placeholder="{{ __('production_bench.settings.default') }}" @disabled(! $isBenchActive || $isReadOnly)>
                        </label>
                        <button type="button" wire:click="removeTaskSetItem({{ $index }})" class="sk-btn sk-btn-ghost sm:mt-1" @disabled(! $isBenchActive || $isReadOnly || count($taskSetItems) <= 1) aria-label="{{ __('production_bench.settings.remove_task') }}">×</button>
                        @error("taskSetItems.{$index}.task_type_id")<span class="text-xs text-[var(--color-danger-strong)] sm:col-span-2">{{ $message }}</span>@enderror
                        @error("taskSetItems.{$index}.days_after_production")<span class="text-xs text-[var(--color-danger-strong)] sm:col-span-2">{{ $message }}</span>@enderror
                        @error("taskSetItems.{$index}.duration_minutes")<span class="text-xs text-[var(--color-danger-strong)] sm:col-span-2">{{ $message }}</span>@enderror
                    </div>
                @endforeach
            </div>
            <p class="text-xs text-[var(--color-ink-soft)]">{{ __('production_bench.settings.task_offset_help') }} {{ __('production_bench.settings.task_duration_help') }}</p>
            @error('taskSetItems')<span class="block text-xs text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror
            <button type="button" wire:click="addTaskSetItem" class="text-sm font-medium text-[var(--color-accent-strong)] hover:underline" @disabled(! $isBenchActive || $isReadOnly)>+ {{ __('production_bench.settings.add_task') }}</button>
        </section>

        <section class="sk-card space-y-4 p-5" aria-labelledby="applicable-products-heading">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h2 id="applicable-products-heading" class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.settings.applicable_products') }}</h2>
                    <p class="mt-1 text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.settings.applicable_products_help') }}</p>
                </div>
                <label class="w-full text-sm sm:max-w-xs">
                    <span class="font-medium">{{ __('production_bench.common.search') }}</span>
                    <input wire:model.live.debounce.300ms="productSearch" type="search" class="sk-input mt-1 w-full" placeholder="{{ __('production_bench.settings.search_products') }}">
                </label>
            </div>
            <p class="text-xs text-[var(--color-ink-soft)]">{{ trans_choice('production_bench.settings.products_selected', count($selectedRecipeIds), ['count' => count($selectedRecipeIds)]) }}</p>
            <div class="max-h-[28rem] overflow-y-auto rounded-xl border border-[var(--color-line)]">
                <table class="w-full min-w-[36rem] text-left text-sm">
                    <thead class="sticky top-0 z-10 bg-[var(--color-panel-muted)] text-xs uppercase tracking-wide text-[var(--color-ink-soft)]"><tr><th class="px-4 py-3">{{ __('production_bench.settings.product') }}</th><th class="px-4 py-3 text-center">{{ __('production_bench.settings.applicable') }}</th><th class="px-4 py-3 text-center">{{ __('production_bench.settings.default') }}</th></tr></thead>
                    <tbody class="divide-y divide-[var(--color-line)]">
                        @forelse ($recipes as $recipe)
                            <tr wire:key="task-set-product-{{ $recipe->id }}">
                                <td class="px-4 py-3 font-medium text-[var(--color-ink-strong)]">{{ $recipe->name }}</td>
                                <td class="px-4 py-3 text-center"><input wire:model.live="selectedRecipeIds" type="checkbox" value="{{ $recipe->id }}" class="size-4 rounded border-[var(--color-line-strong)]" style="accent-color: var(--color-accent);" @disabled(! $isBenchActive || $isReadOnly) aria-label="{{ __('production_bench.settings.applicable_product', ['product' => $recipe->name]) }}"></td>
                                <td class="px-4 py-3 text-center"><input wire:model.live="defaultRecipeIds" type="checkbox" value="{{ $recipe->id }}" class="size-4 rounded border-[var(--color-line-strong)]" style="accent-color: var(--color-accent);" @disabled(! $isBenchActive || $isReadOnly) aria-label="{{ __('production_bench.settings.default_product', ['product' => $recipe->name]) }}"></td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="px-4 py-8 text-center text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.settings.no_matching_products') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <x-table-pagination :paginator="$recipes" />
            @error('selectedRecipeIds')<span class="block text-xs text-[var(--color-danger-strong)]">{{ $message }}</span>@enderror
        </section>

        <div class="flex flex-wrap justify-end gap-2">
            <a href="{{ route('production-bench.production.settings.task-sets') }}" wire:navigate class="sk-btn sk-btn-ghost">{{ __('production_bench.common.cancel') }}</a>
            <button type="submit" class="sk-btn sk-btn-primary" @disabled(! $isBenchActive || $isReadOnly)>{{ $editing ? __('production_bench.common.save_changes') : __('production_bench.settings.add_task_set') }}</button>
        </div>
    </form>
</x-production-bench.page>
