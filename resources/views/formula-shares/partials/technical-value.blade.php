@if (is_array($value))
    <dl class="space-y-1 text-sm">
        @foreach ($value as $field => $entry)
            <div>@if (! is_int($field))<dt class="inline font-medium">{{ \Illuminate\Support\Str::headline($field) }}: </dt>@endif<dd class="inline">@include('formula-shares.partials.technical-value', ['value' => $entry, 'numericField' => is_string($field) ? $field : ($numericField ?? null)])</dd></div>
        @endforeach
    </dl>
@elseif (is_bool($value))
    {{ $value ? __('sharing.yes') : __('sharing.no') }}
@else
    {{ app(\App\Services\FormulaShareDisplayNumbers::class)->technical($value, $numberLocale, $numericField ?? null) ?? '—' }}
@endif
