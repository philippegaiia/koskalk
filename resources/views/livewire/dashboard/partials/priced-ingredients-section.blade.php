@if($pricedIngredients->isNotEmpty())
<section class="overflow-hidden rounded-xl bg-[var(--color-panel)] shadow-[0_2px_4px_rgba(60,50,30,0.04),0_12px_24px_rgba(60,50,30,0.08)]">
    <div class="border-b border-[var(--color-line)] px-5 py-4">
        <p class="text-[0.6875rem] font-medium tracking-[0.05em] text-[var(--color-ink-soft)] uppercase">Priced ingredients</p>
        <p class="mt-1 text-sm text-[var(--color-ink-soft)]">Platform ingredients you have priced in recipe costing. Edit the price here and it will be prefilled next time.</p>
    </div>

    <div class="divide-y divide-[var(--color-line)]">
        @foreach($pricedIngredients as $priced)
            @php $ingredient = $priced->ingredient; @endphp
            <div class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between"
                 x-data="{ price: '{{ number_format((float) $priced->price_per_kg, 4, '.', '') }}' }">
                <div class="min-w-0">
                    <p class="font-medium text-[var(--color-ink-strong)]">{{ $ingredient->display_name }}</p>
                    <p class="mt-0.5 text-xs text-[var(--color-ink-soft)]">
                        @if($ingredient->inci_name) {{ $ingredient->inci_name }} &middot; @endif
                        {{ $ingredient->category?->getLabel() }}
                    </p>
                </div>

                <div class="flex items-center gap-2">
                    <span class="text-xs text-[var(--color-ink-soft)]">EUR/kg</span>
                    <input
                        x-model="price"
                        @blur="
                            fetch('{{ route('ingredients.update-price') }}', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                                body: JSON.stringify({ ingredient_id: {{ $ingredient->id }}, price_per_kg: price })
                            })
                        "
                        type="text"
                        inputmode="decimal"
                        class="numeric w-24 rounded-lg border border-[var(--color-line)] bg-[var(--color-field)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]"
                    />
                </div>
            </div>
        @endforeach
    </div>
</section>
@endif
