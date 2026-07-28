<div class="mx-auto max-w-7xl space-y-8">
    <x-production-bench.navigation />
    <x-production-bench.purchasing-navigation />

    @if (! $isBenchActive && ! $isReadOnly)
        <section class="sk-card p-8 text-center">
            <h1 class="text-3xl font-semibold text-[var(--color-ink-strong)]">Activate the bench to manage suppliers.</h1>
            <a href="{{ route('production-bench.home') }}" wire:navigate class="mt-4 inline-block text-sm font-medium text-[var(--color-accent)]">Go to Production Bench home</a>
        </section>
    @else
        @if ($isReadOnly)
            <p role="status" class="rounded-xl bg-[var(--color-warning-soft)] px-4 py-3 text-sm text-[var(--color-warning-strong)]">Read-only: supplier records remain available. Resume Production Bench to make changes.</p>
        @endif

        <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="sk-eyebrow">Purchasing</p>
                <h1 class="mt-2 text-3xl font-semibold text-[var(--color-ink-strong)]">Suppliers</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-[var(--color-ink-soft)]">Keep supplier contacts and purchasing details ready before you create their listings.</p>
            </div>
            <a href="#add-supplier" class="rounded-full bg-[var(--color-accent)] px-5 py-2.5 text-center text-sm font-medium text-white transition hover:bg-[var(--color-accent-strong)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)] @if($isReadOnly) pointer-events-none opacity-40 @endif">Add supplier</a>
        </header>

        <section id="add-supplier" class="sk-card overflow-hidden">
            <div class="border-b border-[var(--color-line)] p-5">
                <p class="sk-eyebrow">Supplier record</p>
                <h2 class="mt-1 text-xl font-semibold text-[var(--color-ink-strong)]">Add supplier</h2>
            </div>
            <form wire:submit="saveSupplier" class="grid gap-4 p-5 md:grid-cols-2 xl:grid-cols-3">
                <label class="space-y-2 xl:col-span-2"><span class="text-sm font-medium">Supplier name</span><input wire:model="name" required @disabled($isReadOnly) class="sk-input w-full" autocomplete="organization">@error('name') <span class="text-xs text-[var(--color-danger-strong)]">{{ $message }}</span> @enderror</label>
                <label class="flex items-end gap-3"><input wire:model="isActive" type="checkbox" @disabled($isReadOnly) class="mb-1"><span><span class="block text-sm font-medium">Active supplier</span><span class="text-xs text-[var(--color-ink-soft)]">Available for new listings</span></span></label>
                <label class="space-y-2"><span class="text-sm font-medium">Main contact</span><input wire:model="contactName" @disabled($isReadOnly) class="sk-input w-full" autocomplete="name"></label>
                <label class="space-y-2"><span class="text-sm font-medium">Email</span><input wire:model="email" type="email" @disabled($isReadOnly) class="sk-input w-full" autocomplete="email">@error('email') <span class="text-xs text-[var(--color-danger-strong)]">{{ $message }}</span> @enderror</label>
                <label class="space-y-2"><span class="text-sm font-medium">Phone</span><input wire:model="phone" type="tel" @disabled($isReadOnly) class="sk-input w-full" autocomplete="tel"></label>
                <label class="space-y-2"><span class="text-sm font-medium">Address line 1</span><input wire:model="addressLine1" @disabled($isReadOnly) class="sk-input w-full" autocomplete="address-line1"></label>
                <label class="space-y-2"><span class="text-sm font-medium">Address line 2</span><input wire:model="addressLine2" @disabled($isReadOnly) class="sk-input w-full" autocomplete="address-line2"></label>
                <label class="space-y-2"><span class="text-sm font-medium">City</span><input wire:model="city" @disabled($isReadOnly) class="sk-input w-full" autocomplete="address-level2"></label>
                <label class="space-y-2"><span class="text-sm font-medium">Region</span><input wire:model="region" @disabled($isReadOnly) class="sk-input w-full" autocomplete="address-level1"></label>
                <label class="space-y-2"><span class="text-sm font-medium">Postal code</span><input wire:model="postalCode" @disabled($isReadOnly) class="sk-input w-full" autocomplete="postal-code"></label>
                <label class="space-y-2"><span class="text-sm font-medium">Country</span><input wire:model="countryCode" @disabled($isReadOnly) maxlength="2" placeholder="GB" class="sk-input w-full" autocomplete="country">@error('countryCode') <span class="text-xs text-[var(--color-danger-strong)]">{{ $message }}</span> @enderror</label>
                <label class="space-y-2"><span class="text-sm font-medium">Website</span><input wire:model="website" type="url" @disabled($isReadOnly) class="sk-input w-full" placeholder="https://">@error('website') <span class="text-xs text-[var(--color-danger-strong)]">{{ $message }}</span> @enderror</label>
                <label class="space-y-2"><span class="text-sm font-medium">Currency</span><input wire:model="defaultCurrency" required @disabled($isReadOnly) maxlength="3" class="sk-input w-full uppercase">@error('defaultCurrency') <span class="text-xs text-[var(--color-danger-strong)]">{{ $message }}</span> @enderror</label>
                <label class="space-y-2 md:col-span-2 xl:col-span-3"><span class="text-sm font-medium">Notes</span><textarea wire:model="notes" @disabled($isReadOnly) rows="2" class="sk-input w-full"></textarea></label>
                @error('production_bench') <p class="md:col-span-2 xl:col-span-3 text-sm text-[var(--color-danger-strong)]">{{ $message }}</p> @enderror
                <div class="md:col-span-2 xl:col-span-3"><button type="submit" @disabled($isReadOnly) class="rounded-full bg-[var(--color-accent)] px-5 py-2.5 text-sm font-medium text-white disabled:cursor-not-allowed disabled:opacity-40">Save supplier</button></div>
            </form>
        </section>

        <section class="overflow-hidden rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)]">
            <div class="flex flex-col gap-4 border-b border-[var(--color-line)] p-5 lg:flex-row lg:items-end lg:justify-between">
                <div><p class="sk-eyebrow">Supplier directory</p><h2 class="mt-1 text-xl font-semibold text-[var(--color-ink-strong)]">Find a supplier</h2></div>
                <div class="grid gap-2 sm:grid-cols-3">
                    <label class="space-y-1"><span class="text-sm font-medium">Search suppliers</span><input wire:model.live.debounce.300ms="search" type="search" placeholder="Name, contact, city…" class="sk-input w-full"></label>
                    <label class="space-y-1"><span class="text-sm font-medium">Supplier state</span><select wire:model.live="status" class="sk-input"><option value="active">Active</option><option value="all">All suppliers</option><option value="inactive">Inactive</option></select></label>
                    <label class="space-y-1"><span class="text-sm font-medium">Supplier sort</span><select wire:model.live="sort" class="sk-input"><option value="newest">Newest</option><option value="name">Name</option></select></label>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[760px] text-left text-sm">
                    <thead class="bg-[var(--color-panel-muted)] text-xs uppercase tracking-wide text-[var(--color-ink-soft)]"><tr><th class="px-5 py-3">Supplier</th><th class="px-4 py-3">Location</th><th class="px-4 py-3">Main contact</th><th class="px-4 py-3">State</th><th class="px-5 py-3 text-right">Listings</th></tr></thead>
                    <tbody class="divide-y divide-[var(--color-line)]">
                        @forelse ($suppliers as $supplier)
                            <tr wire:key="supplier-{{ $supplier->id }}" class="transition hover:bg-[var(--color-panel-strong)]"><td class="px-5 py-4"><a href="{{ route('production-bench.purchasing.supplier', $supplier) }}" wire:navigate class="font-medium text-[var(--color-ink-strong)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)]">{{ $supplier->name }}</a></td><td class="px-4 py-4 text-[var(--color-ink-soft)]">{{ collect([$supplier->city, $supplier->country_code])->filter()->join(', ') ?: 'Not recorded' }}</td><td class="px-4 py-4"><p>{{ $supplier->contact_name ?: 'Not recorded' }}</p><p class="text-xs text-[var(--color-ink-soft)]">{{ $supplier->email }}</p></td><td class="px-4 py-4"><span class="rounded-full px-2.5 py-1 text-xs font-medium {{ $supplier->is_active ? 'bg-[var(--color-success-soft)] text-[var(--color-success-strong)]' : 'bg-[var(--color-field-muted)] text-[var(--color-ink-soft)]' }}">{{ $supplier->is_active ? 'Active' : 'Inactive' }}</span></td><td class="numeric px-5 py-4 text-right">{{ $supplier->listings_count }}</td></tr>
                        @empty
                            <tr><td colspan="5" class="px-6 py-12 text-center text-sm text-[var(--color-ink-soft)]">No suppliers match this view. Add the supplier you buy from first.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <x-table-pagination :paginator="$suppliers" per-page-label="Suppliers per page" />
        </section>
    @endif
</div>
