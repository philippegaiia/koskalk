<div class="space-y-6">
    @forelse ($revisions as $revision)
        <article>
            <h3 class="font-semibold">#{{ $revision->revision_number }} · {{ $revision->title }}</h3>
            <p class="text-sm">{{ $revision->created_at->format('Y-m-d H:i') }} · {{ $revision->origin->value }}</p>
            @include('filament.help-topics.preview', ['content' => app(\App\Services\ContextualHelp\HelpContentRenderer::class)->render($revision)])
        </article>
    @empty
        <p>No saved revisions yet.</p>
    @endforelse
</div>
