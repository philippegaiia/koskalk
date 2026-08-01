<div class="mx-auto max-w-5xl space-y-8">
    <x-production-bench.navigation />
    <x-production-bench.purchasing-navigation />

    <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="sk-eyebrow">{{ $supplier->code }}</p>
            <h1 class="mt-2 text-3xl font-semibold text-[var(--color-ink-strong)]">Edit supplier</h1>
        </div>
        <a href="{{ route('production-bench.purchasing.supplier', $supplier) }}" wire:navigate class="text-sm font-medium text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)]">Cancel</a>
    </header>

    <form wire:submit="save" class="space-y-6">
        <section class="sk-card p-5">
            <h2 class="text-lg font-semibold text-[var(--color-ink-strong)]">Supplier</h2>
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <label class="space-y-2"><span class="text-sm font-medium">Code</span><input wire:model="code" required maxlength="16" autocomplete="off" class="sk-input w-full uppercase"><span class="block text-xs text-[var(--color-ink-soft)]">Up to 16 letters, numbers, hyphens, or underscores.</span>@error('code') <span class="block text-xs text-[var(--color-danger-strong)]">{{ $message }}</span> @enderror</label>
                <label class="space-y-2"><span class="text-sm font-medium">Name</span><input wire:model="name" required autocomplete="organization" class="sk-input w-full">@error('name') <span class="block text-xs text-[var(--color-danger-strong)]">{{ $message }}</span> @enderror</label>
                <label class="space-y-2"><span class="text-sm font-medium">Currency</span><input wire:model="defaultCurrency" required maxlength="3" class="sk-input w-full uppercase">@error('defaultCurrency') <span class="block text-xs text-[var(--color-danger-strong)]">{{ $message }}</span> @enderror</label>
                <label class="flex items-center gap-3 self-end rounded-xl border border-[var(--color-line)] px-4 py-3"><input wire:model="isActive" type="checkbox"><span class="text-sm font-medium">Active</span></label>
            </div>
        </section>

        <section class="sk-card p-5">
            <h2 class="text-lg font-semibold text-[var(--color-ink-strong)]">Main contact</h2>
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <label class="space-y-2"><span class="text-sm font-medium">Name</span><input wire:model="contactName" autocomplete="name" class="sk-input w-full">@error('contactName') <span class="block text-xs text-[var(--color-danger-strong)]">{{ $message }}</span> @enderror</label>
                <label class="space-y-2"><span class="text-sm font-medium">Email</span><input wire:model="email" type="email" autocomplete="email" class="sk-input w-full">@error('email') <span class="block text-xs text-[var(--color-danger-strong)]">{{ $message }}</span> @enderror</label>
                <label class="space-y-2"><span class="text-sm font-medium">Telephone</span><input wire:model="phone" type="tel" autocomplete="tel" class="sk-input w-full">@error('phone') <span class="block text-xs text-[var(--color-danger-strong)]">{{ $message }}</span> @enderror</label>
                <label class="space-y-2"><span class="text-sm font-medium">Website</span><input wire:model="website" type="url" autocomplete="url" placeholder="https://" class="sk-input w-full">@error('website') <span class="block text-xs text-[var(--color-danger-strong)]">{{ $message }}</span> @enderror</label>
            </div>
        </section>

        <section class="sk-card p-5">
            <h2 class="text-lg font-semibold text-[var(--color-ink-strong)]">Address</h2>
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <label class="space-y-2 md:col-span-2"><span class="text-sm font-medium">Address line 1</span><input wire:model="addressLine1" autocomplete="address-line1" class="sk-input w-full">@error('addressLine1') <span class="block text-xs text-[var(--color-danger-strong)]">{{ $message }}</span> @enderror</label>
                <label class="space-y-2 md:col-span-2"><span class="text-sm font-medium">Address line 2</span><input wire:model="addressLine2" autocomplete="address-line2" class="sk-input w-full">@error('addressLine2') <span class="block text-xs text-[var(--color-danger-strong)]">{{ $message }}</span> @enderror</label>
                <label class="space-y-2"><span class="text-sm font-medium">City</span><input wire:model="city" autocomplete="address-level2" class="sk-input w-full">@error('city') <span class="block text-xs text-[var(--color-danger-strong)]">{{ $message }}</span> @enderror</label>
                <label class="space-y-2"><span class="text-sm font-medium">Region</span><input wire:model="region" autocomplete="address-level1" class="sk-input w-full">@error('region') <span class="block text-xs text-[var(--color-danger-strong)]">{{ $message }}</span> @enderror</label>
                <label class="space-y-2"><span class="text-sm font-medium">Postal code</span><input wire:model="postalCode" autocomplete="postal-code" class="sk-input w-full">@error('postalCode') <span class="block text-xs text-[var(--color-danger-strong)]">{{ $message }}</span> @enderror</label>
                <label class="space-y-2"><span class="text-sm font-medium">Country code</span><input wire:model="countryCode" maxlength="2" autocomplete="country" class="sk-input w-full uppercase">@error('countryCode') <span class="block text-xs text-[var(--color-danger-strong)]">{{ $message }}</span> @enderror</label>
            </div>
        </section>

        <section class="sk-card p-5">
            <h2 class="text-lg font-semibold text-[var(--color-ink-strong)]">Notes</h2>
            <label class="mt-4 block"><span class="sr-only">Notes</span><textarea wire:model="notes" rows="4" class="sk-input w-full"></textarea>@error('notes') <span class="block text-xs text-[var(--color-danger-strong)]">{{ $message }}</span> @enderror</label>
        </section>

        @error('production_bench') <p class="text-sm text-[var(--color-danger-strong)]">{{ $message }}</p> @enderror
        <div class="flex flex-wrap items-center gap-4">
            <button type="submit" class="rounded-full bg-[var(--color-accent)] px-5 py-2.5 text-sm font-medium text-white transition hover:bg-[var(--color-accent-strong)] disabled:opacity-40" wire:loading.attr="disabled" wire:target="save">Save supplier</button>
            <a href="{{ route('production-bench.purchasing.supplier', $supplier) }}" wire:navigate class="text-sm font-medium text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)]">Cancel</a>
        </div>
    </form>
</div>
