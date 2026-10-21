<div class="mx-auto max-w-5xl space-y-6">
    <p class="text-sm leading-6 text-[var(--color-ink-soft)]">{{ __('dossiers.raw_materials.intro') }}</p>

    <section class="sk-card overflow-hidden" aria-label="{{ __('dossiers.raw_materials.title') }}">
        @if ($ingredients->isEmpty())
            <p class="p-5 text-sm text-[var(--color-ink-soft)]">{{ __('dossiers.raw_materials.empty') }}</p>
        @else
            <table class="w-full text-sm">
                <thead class="text-left text-xs text-[var(--color-ink-soft)]">
                    <tr>
                        <th scope="col" class="px-4 py-3">{{ __('dossiers.raw_materials.ingredient') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('dossiers.raw_materials.inci') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('dossiers.raw_materials.validated_suppliers') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('dossiers.raw_materials.coverage') }}</th>
                        <th scope="col" class="px-4 py-3"><span class="sr-only">{{ __('dossiers.raw_materials.open') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($ingredients as $ingredient)
                        <tr class="border-t border-[var(--color-line)]">
                            <td class="px-4 py-3 font-medium text-[var(--color-ink-strong)]">{{ $ingredient->display_name }}</td>
                            <td class="px-4 py-3 text-[var(--color-ink-soft)]">{{ $ingredient->inci_name }}</td>
                            <td class="px-4 py-3">{{ $ingredient->validated_suppliers_count }}</td>
                            <td class="px-4 py-3">{{ __('dossiers.raw_materials.coverage_states.'.($coverageStates[$ingredient->id] ?? 'not_reviewed')) }}</td>
                            <td class="px-4 py-3 text-right">
                                <a href="{{ route('dossiers.raw-material', $ingredient) }}" wire:navigate class="sk-btn sk-btn-outline min-h-11 gap-2 whitespace-nowrap">{{ __('dossiers.raw_materials.open') }}</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="p-4">
                <x-table-pagination :paginator="$ingredients" :show-per-page="false" />
            </div>
        @endif
    </section>
</div>
