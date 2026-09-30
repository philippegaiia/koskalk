<div class="mx-auto max-w-app space-y-6">
    <section class="sk-card space-y-3 p-5" x-data="{ copied: false }">
        <h1 class="text-xl font-semibold">{{ __('sharing.title') }}</h1>
        <label for="sharing-workspace-address" class="block text-sm font-medium">{{ __('sharing.workspace_address') }}</label>
        <div class="flex flex-wrap items-center gap-3"><input id="sharing-workspace-address" readonly value="{{ $workspaceAddress }}" class="w-full max-w-md rounded-lg border border-[var(--color-line)] bg-[var(--color-field-muted)] px-3 py-2 font-mono text-sm"><button type="button" @click="navigator.clipboard.writeText(@js($workspaceAddress)).then(() => copied = true)" class="sk-btn sk-btn-outline">{{ __('sharing.copy_address') }}</button></div>
    </section>
    <section class="sk-card overflow-hidden">
        <nav class="flex gap-3 p-5" aria-label="{{ __('sharing.title') }}">@foreach (['inbox', 'outbox'] as $tab)<button type="button" wire:click="$set('direction', '{{ $tab }}')" class="{{ $direction === $tab ? 'sk-btn sk-btn-primary' : 'sk-btn sk-btn-outline' }}" @if ($direction === $tab) aria-current="page" @endif>{{ __('sharing.'.$tab) }}</button>@endforeach</nav>
        <div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr><th class="px-5 py-3">{{ __('sharing.product') }}</th><th class="px-5 py-3">{{ __('sharing.sender') }}</th><th class="px-5 py-3">{{ __('sharing.recipient') }}</th><th class="px-5 py-3">{{ __('sharing.state') }}</th><th class="px-5 py-3">{{ __('sharing.expires') }}</th><th class="px-5 py-3"><span class="sr-only">{{ __('sharing.open') }}</span></th></tr></thead><tbody>
            @forelse ($offers as $offer)<tr class="border-t border-[var(--color-line)]" wire:key="offer-{{ $offer['public_id'] }}"><td class="px-5 py-3">{{ $offer['product_name'] }}</td><td class="px-5 py-3">{{ $offer['sender'] }}</td><td class="px-5 py-3">{{ $offer['recipient'] }}</td><td class="px-5 py-3">{{ __('sharing.states.'.$offer['status']) }}</td><td class="px-5 py-3">{{ $offer['expires_at'] }}</td><td class="px-5 py-3"><a href="{{ route('formula-shares.show', $offer['public_id']) }}" class="underline">{{ __('sharing.open') }}</a></td></tr>@empty<tr><td colspan="6" class="p-5">{{ __('sharing.empty') }}</td></tr>@endforelse
        </tbody></table></div>
        <x-table-pagination :paginator="$offers" />
    </section>
</div>
