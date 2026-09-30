<div class="mx-auto max-w-app space-y-6">
    <section class="sk-card space-y-4 p-5">
        <h1 class="text-xl font-semibold">{{ __('sharing.share') }} · {{ $productName }}</h1>
        <p class="text-sm text-[var(--color-ink-soft)]">{{ __('sharing.saved_only') }}</p>
        <form wire:submit="preview" class="space-y-4">
            {{ $this->form }}
            <div class="flex flex-wrap gap-3"><button type="button" wire:click="resolveRecipient" class="sk-btn sk-btn-outline">{{ __('sharing.resolve') }}</button><button type="submit" class="sk-btn sk-btn-primary" wire:loading.attr="disabled">{{ __('sharing.preview') }}</button></div>
        </form>
        @if ($recipientName)<p>{{ __('sharing.recipient') }}: <strong>{{ $recipientName }}</strong></p>@endif
        @foreach ($errors->all() as $message)<p role="alert" class="text-sm text-[var(--color-danger)]">{{ $message }}</p>@endforeach
    </section>
    @if ($display !== [])
        @include('formula-shares.partials.disclosure')
        <section class="sk-card space-y-4 p-5">
            <p class="font-semibold">{{ __('sharing.consequence') }}</p>
            <label class="flex items-start gap-3 text-sm"><input type="checkbox" wire:model="confirmed" class="mt-1 rounded">{{ __('sharing.confirm') }}</label>
            <button type="button" wire:click="send" wire:loading.attr="disabled" class="sk-btn sk-btn-primary">{{ __('sharing.send') }}</button>
        </section>
    @endif
    <x-filament-actions::modals />
</div>
