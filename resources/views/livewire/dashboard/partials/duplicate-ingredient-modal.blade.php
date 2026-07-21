<div x-data="duplicateModal()" class="inline-flex">
    <button type="button" @click="open = true" class="inline-flex min-h-11 items-center justify-center whitespace-nowrap rounded-full border border-[var(--color-line)] px-5 py-2.5 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel)]">
        {{ __('ingredients.duplicate.button') }}
    </button>

    <template x-if="open">
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-[color:oklch(from_var(--color-surface-strong)_l_c_h_/_0.55)] px-4 py-6" @click.self="open = false" @keydown.escape.window="open = false">
            <div class="w-full max-w-lg sk-card p-6" @click.stop>
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="sk-eyebrow">{{ __('ingredients.duplicate.eyebrow') }}</p>
                        <h3 class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('ingredients.duplicate.heading') }}</h3>
                        <p class="mt-2 text-sm text-[var(--color-ink-soft)]">{{ __('ingredients.duplicate.description') }}</p>
                    </div>
                    <button type="button" @click="open = false" class="rounded-full border border-[var(--color-line)] px-3 py-1.5 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel)]">{{ __('ingredients.actions.cancel') }}</button>
                </div>

                <div class="mt-5">
                    <input
                        x-model="query"
                        @input.debounce.300ms="search()"
                        type="text"
                        placeholder="{{ __('ingredients.duplicate.search_placeholder') }}"
                        class="w-full rounded-lg border border-[var(--color-line)] bg-[var(--color-field)] px-4 py-3 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]"
                        x-ref="searchInput"
                    />
                </div>

                <div class="mt-4 max-h-64 overflow-y-auto divide-y divide-[var(--color-line)] rounded-lg border border-[var(--color-line)]">
                    <template x-if="loading">
                        <div class="px-4 py-6 text-center text-sm text-[var(--color-ink-soft)]">{{ __('ingredients.duplicate.searching') }}</div>
                    </template>

                    <template x-if="!loading && results.length === 0 && query.length >= 2">
                        <div class="px-4 py-6 text-center text-sm text-[var(--color-ink-soft)]">{{ __('ingredients.duplicate.no_matches') }}</div>
                    </template>

                    <template x-if="!loading && results.length === 0 && query.length < 2">
                        <div class="px-4 py-6 text-center text-sm text-[var(--color-ink-soft)]">{{ __('ingredients.duplicate.minimum_characters') }}</div>
                    </template>

                    <template x-for="item in results" :key="item.id">
                        <button type="button" @click="duplicate(item.id)" class="flex w-full items-center justify-between px-4 py-3 text-left transition hover:bg-[var(--color-panel)]">
                            <div>
                                <p class="text-sm font-medium text-[var(--color-ink-strong)]" x-text="item.name"></p>
                                <p class="mt-0.5 text-xs text-[var(--color-ink-soft)]" x-text="[item.inci_name, item.category].filter(Boolean).join(' · ')"></p>
                            </div>
                            <span class="shrink-0 text-xs font-medium text-[var(--color-accent)]">{{ __('ingredients.duplicate.action') }}</span>
                        </button>
                    </template>
                </div>
            </div>
        </div>
    </template>
</div>

<script>
function duplicateModal() {
    return {
        open: false,
        query: '',
        results: [],
        loading: false,

        async search() {
            if (this.query.length < 2) {
                this.results = [];
                return;
            }
            this.loading = true;
            const response = await fetch('{{ route("ingredients.search-platform") }}?q=' + encodeURIComponent(this.query), {
                headers: { 'Accept': 'application/json' }
            });
            this.results = await response.json();
            this.loading = false;
        },

        async duplicate(ingredientId) {
            const response = await fetch('{{ route("ingredients.duplicate") }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ ingredient_id: ingredientId }),
            });
            const data = await response.json();
            if (data.ok && data.redirect) {
                window.location.href = data.redirect;
            }
        }
    };
}
</script>
