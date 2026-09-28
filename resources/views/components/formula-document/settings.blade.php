@props(['document'])

<section data-formula-settings class="rounded-lg border border-slate-300 bg-slate-50 px-4 py-3">
    <h2 class="text-xs font-semibold tracking-[0.08em] text-slate-600 uppercase">{{ __('formula_documents.sections.settings') }}</h2>
    <dl class="mt-2 flex flex-wrap gap-x-5 gap-y-1.5 text-xs">
        @foreach ($document['settings'] as $setting)
            <div class="flex gap-1.5">
                <dt class="text-slate-600">{{ $setting['label'] }}:</dt>
                <dd class="font-medium text-slate-900">{{ $setting['value'] }}</dd>
            </div>
        @endforeach
    </dl>
</section>
