@props(['topic', 'topics' => []])
@if (isset($topics[$topic]))
    <a role="button" tabindex="0" data-help-key="{{ $topic }}" aria-controls="contextual-help-panel" aria-label="{{ __('contextual_help.about', ['topic' => $topics[$topic]['title']]) }}" {{ $attributes->class('sk-help-trigger') }}>?</a>
@endif
