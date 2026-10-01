<div class="mx-auto max-w-app space-y-6">
    <script type="application/json" data-contextual-help-scope>{!! \Illuminate\Support\Js::encode($contextualHelp) !!}</script>
    <x-contextual-help.index-button :help="$contextualHelp" tab="page" />
    <section class="sk-card space-y-3 p-5">
        <a href="{{ route('formula-shares.index') }}" class="text-sm underline">{{ __('sharing.back') }}</a>
        <h1 data-contextual-help-heading class="text-xl font-semibold">{{ $summary['product_name'] }}</h1>
        <p>{{ __('sharing.sender') }}: {{ $summary['sender'] }} · {{ __('sharing.recipient') }}: {{ $summary['recipient'] }}</p>
        <p>{{ __('sharing.states.'.$summary['status']) }} · {{ __('sharing.expires') }}: {{ $summary['expires_at'] }}</p>
        @foreach ($errors->all() as $message)<p role="alert" class="text-sm text-[var(--color-danger)]">{{ $message }}</p>@endforeach
        @if (! $summary['pending'])<p>{{ __('sharing.'.($summary['status'] === 'accepted' ? 'accepted' : 'unavailable')) }}</p>@endif
        @if ($summary['accepted_product_public_id'])<a href="{{ route('recipes.saved', $summary['accepted_product_public_id']) }}" class="sk-btn sk-btn-primary">{{ __('sharing.open_product') }}</a>@endif
    </section>
    @if ($display !== [])
        @include('formula-shares.partials.disclosure')
    @endif
    @if ($summary['pending'])
        <section class="sk-card space-y-4 p-5">
            @if ($summary['incoming'])
                <p>{{ __('sharing.quota', ['count' => $display['import_count'] ?? 0, 'used' => $display['private_ingredient_quota']['used'] ?? 0, 'limit' => $display['private_ingredient_quota']['limit'] ?? __('sharing.unlimited')]) }}</p>
                <h2 class="text-lg font-semibold">{{ __('sharing.choose_ingredients') }}</h2>
                <p class="text-sm text-[var(--color-ink-soft)]">{{ __('sharing.choice_intro') }}</p>
                <form wire:submit="refreshPreview" class="space-y-4">{{ $this->form }}<button type="submit" class="sk-btn sk-btn-outline" wire:loading.attr="disabled">{{ __('sharing.refresh') }}</button></form>
                @if (($display['remaining_keys'] ?? []) !== [])<p>{{ __('sharing.remaining') }}</p>@endif
                @if ($expectedHash === null)<p role="status" class="text-sm">{{ __('sharing.preview_needed') }}</p>@endif
                <p class="text-sm">{{ __('sharing.review_required') }}</p>
                <label class="flex items-start gap-3 text-sm"><input type="checkbox" wire:model="confirmed" class="mt-1 rounded">{{ __('sharing.confirm_accept') }}</label>
                <button type="button" wire:click="accept" wire:loading.attr="disabled" @disabled($expectedHash === null || ($display['remaining_keys'] ?? []) !== []) class="sk-btn sk-btn-primary">{{ __('sharing.accept') }}</button>
            @else
                <p>{{ __('sharing.consequence') }}</p>
            @endif
            <button type="button" wire:click="close" wire:loading.attr="disabled" class="sk-btn sk-btn-outline">{{ __('sharing.'.($summary['incoming'] ? 'decline' : 'revoke')) }}</button>
        </section>
    @endif
    <x-filament-actions::modals />
</div>
