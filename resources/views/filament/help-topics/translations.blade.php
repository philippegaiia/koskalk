<div class="space-y-3" wire:poll.15s>
    <h2 class="font-semibold">Translation requests</h2>
    @forelse ($this->translationRequests() as $request)
        <div wire:key="translation-{{ $request->public_id }}" class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-200 p-3 dark:border-gray-700">
            <div>
                <p class="text-sm font-medium">{{ ucfirst($request->status->value) }} · {{ $request->created_at->format('Y-m-d H:i') }}</p>
                @if ($request->error_code)
                    <p class="text-sm text-gray-500">{{ $request->error_message }} Use “Generate translations” to request a new candidate.</p>
                @endif
            </div>
            <div class="flex gap-3">
                @if ($request->status === \App\Enums\HelpTranslationRequestStatus::Completed)
                    {{ ($this->acceptTranslationAction)(['request' => $request->public_id]) }}
                @endif
                @if (in_array($request->status, [\App\Enums\HelpTranslationRequestStatus::Completed, \App\Enums\HelpTranslationRequestStatus::Failed], true))
                    {{ ($this->dismissTranslationAction)(['request' => $request->public_id]) }}
                @endif
            </div>
        </div>
    @empty
        <p class="text-sm text-gray-500">Publish English first, then generate a candidate for this language. You can also write the translation directly in the editor.</p>
    @endforelse
</div>
