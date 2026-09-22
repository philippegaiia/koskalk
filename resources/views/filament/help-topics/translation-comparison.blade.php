<div class="grid gap-6 lg:grid-cols-3">
    @foreach (['English source' => $source, 'Current draft' => $current, 'Translation candidate' => $candidate] as $label => $content)
        <section>
            <h3 class="mb-3 font-semibold">{{ $label }}</h3>
            @include('filament.help-topics.preview', ['content' => $content])
        </section>
    @endforeach
</div>
