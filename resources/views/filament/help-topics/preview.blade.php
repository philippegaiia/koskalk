<div class="max-w-md space-y-4">
    @if ($content)
        <h2 class="text-xl font-semibold">{{ $content['title'] }}</h2>
        <p>{{ $content['summary'] }}</p>
        @if ($content['body_html'])
            <div class="prose max-w-none">{!! $content['body_html'] !!}</div>
        @endif
    @else
        <p>No saved content to preview.</p>
    @endif
</div>
