@extends('layouts.public')

@section('title', 'Soapkraft — Soap & Cosmetics Formulation Workbench')

@section('content')
@php
    $oils = [
        ['name' => 'Olive oil virgin', 'pct' => '62.0%', 'g' => '620 g'],
        ['name' => 'Coconut oil', 'pct' => '20.0%', 'g' => '200 g'],
        ['name' => 'Shea butter', 'pct' => '10.0%', 'g' => '100 g'],
        ['name' => 'Laurel berry oil', 'pct' => '8.0%', 'g' => '80 g'],
    ];

    $qualities = [
        ['label' => 'Hardness', 'value' => 43, 'color' => 'bg-sage'],
        ['label' => 'Cleansing', 'value' => 17, 'color' => 'bg-sage'],
        ['label' => 'Conditioning', 'value' => 56, 'color' => 'bg-sage'],
        ['label' => 'Iodine', 'value' => 89, 'color' => 'bg-amber', 'valColor' => 'text-amber-light'],
        ['label' => 'INS', 'value' => 148, 'color' => 'bg-sage'],
        ['label' => 'Longevity', 'value' => 38, 'color' => 'bg-sage'],
    ];

    $features = [
        [
            'num' => '01',
            'title' => 'Trusted chemistry',
            'body' => 'SAP values, soap INCI, fatty-acid profiles, and allergen tracking stay separate and explicit — never buried in a generic ingredient row that hides the math.',
            'items' => ['KOH-first with derived NaOH', 'Traceable fatty-acid logic per oil', 'Allergen contribution per aromatic'],
        ],
        [
            'num' => '02',
            'title' => 'Faster drafting',
            'body' => 'Immediate recalculation on every change. Lye, water, INCI, allergens, and quality scores all stay live. No save-refresh-edit loops slowing you down.',
            'items' => ['No save-refresh-edit cycles', 'One working draft per recipe', 'Batch scaling without formula corruption'],
        ],
        [
            'num' => '03',
            'title' => 'Cleaner compliance handoff',
            'body' => 'Allergens, IFRA reference rates, and final INCI output belong in a deliberate closing pass. Compliance stays out of your drafting view until you need it.',
            'items' => ['EU allergen declaration thresholds', 'INCI normalized to cured-bar basis', 'IFRA data shown — you decide'],
        ],
    ];

    $soapScopeItems = ['NaOH · KOH · Dual lye', 'Superfat & water mode control', 'Dry soap basis output', 'Fatty-acid quality scores'];
    $cosmeticsScopeItems = ['Anhydrous & emulsion formulas', 'Rinse-off & leave-on products', 'EU allergen declarations', 'IFRA reference per aromatic'];

    $workflowSteps = [
        ['num' => '01', 'title' => 'Select the reaction core', 'body' => 'Pick carrier oils with chemistry fields visible — SAP values, fatty-acid contribution, and quality scores update as you build. No buried catalog rows.', 'tag' => 'Carrier oils · SAP values · Fatty acids'],
        ['num' => '02', 'title' => 'Tune the batch', 'body' => 'Adjust lye ratios, superfat percentage, and water mode while totals stay legible. Every change recalculates lye and water immediately.', 'tag' => 'Superfat · Lye water · Batch weight'],
        ['num' => '03', 'title' => 'Layer the aromatic phase', 'body' => 'Add essential oils, fragrance materials, and additives without corrupting the saponification core. IFRA usage rates shown as reference alongside each material.', 'tag' => 'Essential oils · Fragrance · Additives', 'note' => 'IFRA data is shown for reference — the decision is yours.'],
        ['num' => '04', 'title' => 'Close with compliance', 'body' => 'Review allergen thresholds and generate final INCI output when the formula is stable. Compliance is a deliberate closing step, not a constant interruption.', 'tag' => 'Allergens · INCI · allergen declarations · Label'],
    ];

    $stripItems = ['KOH + NaOH saponification', 'Fatty-acid profile tracking', 'INCI list generation', 'EU allergen declarations', 'IFRA reference data', 'Versioned recipes', 'Personal ingredient library', 'Soap & cosmetics'];
@endphp

{{-- ============================================================
     HERO
     ============================================================ --}}
<section class="relative min-h-screen bg-forest-deep grid lg:grid-cols-[54%_46%] items-center pt-[58px] overflow-hidden">
    {{-- Grain texture --}}
    <div class="absolute inset-0 pointer-events-none" style="background-image: url(&quot;data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='4' height='4'%3E%3Crect width='1' height='1' fill='rgba(122,158,126,0.055)'/%3E%3C/svg%3E&quot;)"></div>
    {{-- Glow --}}
    <div class="absolute w-[700px] h-[700px] rounded-full top-1/2 left-[35%] -translate-x-1/2 -translate-y-1/2 pointer-events-none" style="background: radial-gradient(circle, rgba(122,158,126,0.06) 0%, transparent 70%)"></div>

    {{-- Left: copy --}}
    <div class="py-16 px-5 lg:py-20 lg:pl-20 lg:pr-14 relative z-10">
        <p class="font-mono text-[11px] tracking-[0.1em] uppercase text-sage/85 mb-[22px]">Soap & cosmetics formulation workbench</p>

        <h1 class="font-serif text-[clamp(36px,4vw,54px)] leading-[1.1] text-cream font-medium mb-[22px]">
            Fast drafting, clean chemistry, and a <em class="italic text-sage-light">compliance-ready</em> handoff.
        </h1>

        <p class="text-[15px] text-cream/52 leading-[1.75] max-w-[440px] mb-7">
            Built for soap and cosmetic formulation when carrier oils, SAP values, fatty-acid profiles, and version history need to stay legible under pressure.
        </p>

        <div class="flex flex-col gap-[11px] mb-8">
            <div class="flex items-start gap-2.5 text-sm text-cream/52 leading-[1.5]">
                <span class="w-[5px] h-[5px] rounded-full bg-sage mt-[7px] shrink-0"></span>
                <span><span class="text-cream/92 font-medium">Trusted chemistry.</span> KOH-first SAP data with derived NaOH, fatty-acid profiles, and allergen tracking — separate and auditable.</span>
            </div>
            <div class="flex items-start gap-2.5 text-sm text-cream/52 leading-[1.5]">
                <span class="w-[5px] h-[5px] rounded-full bg-sage mt-[7px] shrink-0"></span>
                <span><span class="text-cream/92 font-medium">Faster drafting.</span> Change an oil and everything recalculates instantly — lye, water, INCI, qualities. No save-refresh loops.</span>
            </div>
            <div class="flex items-start gap-2.5 text-sm text-cream/52 leading-[1.5]">
                <span class="w-[5px] h-[5px] rounded-full bg-sage mt-[7px] shrink-0"></span>
                <span><span class="text-cream/92 font-medium">Cleaner handoff.</span> Allergens, IFRA reference, and final INCI output in a deliberate closing pass — not leaking into your working draft.</span>
            </div>
            <div class="flex items-start gap-2.5 text-sm text-cream/52 leading-[1.5]">
                <span class="w-[5px] h-[5px] rounded-full bg-sage mt-[7px] shrink-0"></span>
                <span><span class="text-cream/92 font-medium">Your ingredient library.</span> Private ingredients with full chemistry — not a platform catalog you can't edit or correct.</span>
            </div>
        </div>

        <div class="flex gap-3 flex-wrap mb-9">
            <a href="{{ route('dashboard') }}" class="text-sm px-[26px] py-3 rounded-lg bg-sage text-forest-deep font-medium no-underline transition hover:bg-sage-light hover:-translate-y-px">Open workspace</a>
            <a href="#features" class="text-sm px-[26px] py-3 rounded-lg bg-transparent text-cream border border-cream/20 transition hover:border-cream/40 hover:bg-cream/[0.04]">See how it works</a>
        </div>

        <p class="text-xs text-cream/26 font-mono tracking-[0.03em] border-t border-cream/[0.08] pt-5">
            Built by a soap & cosmetics formulator — <span class="text-sage/80">16 years, 1M+ bars, India & France.</span>
        </p>
    </div>

    {{-- Right: mockup --}}
    <div class="py-16 pr-5 pl-5 lg:py-20 lg:pr-[60px] lg:pl-5 hidden lg:flex items-center justify-center relative z-10">
        <div class="relative w-full max-w-[390px]">
            {{-- Floating iodine badge --}}
            <div class="absolute -top-2.5 -right-2.5 bg-forest-light border border-sage/25 rounded-[9px] px-3.5 py-2.5 shadow-[0_10px_28px_rgba(0,0,0,0.4)] z-10">
                <p class="text-[8px] tracking-[0.08em] uppercase text-sage font-mono mb-[5px]">Iodine value</p>
                <p class="text-xl font-medium font-mono text-cream">88.7</p>
                <p class="text-[9px] text-cream/52 font-mono mt-0.5">↑ increase hard oils</p>
            </div>

            {{-- Mockup frame --}}
            <div class="w-full rounded-[14px] bg-forest-mid border border-sage/18 shadow-[0_40px_80px_rgba(0,0,0,0.5)] overflow-hidden">
                {{-- Title bar --}}
                <div class="px-3.5 py-2.5 bg-forest-light border-b border-sage/12 flex items-center gap-2.5">
                    <div class="flex gap-1">
                        <span class="w-[7px] h-[7px] rounded-full bg-[#e05252]"></span>
                        <span class="w-[7px] h-[7px] rounded-full bg-[#e0a852]"></span>
                        <span class="w-[7px] h-[7px] rounded-full bg-[#52a852]"></span>
                    </div>
                    <p class="flex-1 text-center text-[11px] text-cream/52 font-mono">Olive + Laurel Bar</p>
                    <span class="text-[9px] px-[7px] py-0.5 rounded-full bg-sage/18 text-sage-light font-mono">Draft</span>
                </div>

                {{-- Body: main + sidebar --}}
                <div class="grid grid-cols-[1fr_144px]">
                    {{-- Main content --}}
                    <div class="p-3.5">
                        <p class="text-[9px] tracking-[0.1em] uppercase text-cream/26 font-mono mb-2">Reaction core</p>

                        @foreach ($oils as $oil)
                            <div class="flex items-center gap-1.5 px-2 py-[5px] rounded-[5px] mb-[3px] bg-white/[0.025] border border-white/[0.04]">
                                <span class="text-[10px] text-cream/92 flex-1">{{ $oil['name'] }}</span>
                                <span class="text-[10px] font-mono text-sage-light w-[34px] text-right">{{ $oil['pct'] }}</span>
                                <span class="text-[10px] font-mono text-cream/52 w-[40px] text-right">{{ $oil['g'] }}</span>
                            </div>
                        @endforeach

                        {{-- Lye / Water --}}
                        <div class="grid grid-cols-2 gap-[5px] mt-2.5">
                            <div class="bg-sage/7 rounded-[5px] px-2 py-[7px] border border-sage/12">
                                <p class="text-xs font-medium font-mono text-cream">137.8 g</p>
                                <p class="text-[8px] text-cream/26 font-mono mt-px">NaOH</p>
                            </div>
                            <div class="bg-sage/7 rounded-[5px] px-2 py-[7px] border border-sage/12">
                                <p class="text-xs font-medium font-mono text-cream">310 g</p>
                                <p class="text-[8px] text-cream/26 font-mono mt-px">Water</p>
                            </div>
                        </div>

                        {{-- INCI preview --}}
                        <div class="mt-2.5 p-2 bg-black/20 rounded-[5px] border border-sage/8">
                            <p class="text-[8px] tracking-[0.08em] uppercase text-cream/26 font-mono mb-[3px]">INCI preview</p>
                            <p class="text-[7.5px] font-mono text-cream/52 leading-[1.5]">SODIUM OLIVATE, SODIUM COCOATE, BUTYROSPERMUM PARKII BUTTER, LAURUS NOBILIS FRUIT OIL, AQUA, GLYCERIN...</p>
                        </div>
                    </div>

                    {{-- Sidebar: qualities --}}
                    <div class="bg-black/18 border-l border-sage/8 px-3 py-3.5">
                        <p class="text-[9px] tracking-[0.1em] uppercase text-cream/26 font-mono mb-2">Qualities</p>

                        @foreach ($qualities as $q)
                            @php $width = $q['label'] === 'INS' ? round($q['value'] / 290 * 100) : $q['value'] @endphp
                            <div class="mb-[9px]">
                                <p class="text-[8px] text-cream/26 font-mono mb-[3px]">{{ $q['label'] }}</p>
                                <div class="h-[3px] bg-white/6 rounded-sm overflow-hidden">
                                    <div class="h-full rounded-sm {{ $q['color'] }}" style="width: {{ $width }}%"></div>
                                </div>
                                <p class="text-[8px] font-mono {{ $q['valColor'] ?? 'text-cream/52' }} text-right mt-[2px]">{{ $q['value'] }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

{{-- ============================================================
     TRUST STRIP
     ============================================================ --}}
<div class="bg-forest py-[18px] px-5 lg:px-20 flex items-center gap-10 overflow-x-auto border-y border-sage/10">
    @foreach ($stripItems as $item)
        <div class="flex items-center gap-[7px] whitespace-nowrap shrink-0">
            <span class="w-1 h-1 rounded-full bg-sage/60"></span>
            <span class="text-[11px] text-cream/52 font-mono tracking-[0.04em]">{{ $item }}</span>
        </div>
    @endforeach
</div>

{{-- ============================================================
     FEATURES
     ============================================================ --}}
<section id="features" class="py-24 px-5 lg:px-20 bg-cream scroll-mt-[58px]">
    <p class="font-mono text-[11px] tracking-[0.12em] uppercase text-sage mb-4 flex items-center gap-2.5">
        <span class="block w-5 h-[0.5px] bg-sage"></span>
        What makes it different
    </p>
    <h2 class="font-serif text-[clamp(26px,3vw,38px)] text-forest-deep leading-[1.2] font-medium max-w-[520px] mb-14">Built around the chemistry, not the UI.</h2>

    <div class="grid lg:grid-cols-3 gap-[2px] bg-cream-dark">
        @foreach ($features as $feature)
            <div class="bg-cream p-7 lg:p-9 transition hover:bg-cream-warm">
                <p class="font-mono text-[11px] text-cream-dark tracking-[0.06em] mb-[18px]">{{ $feature['num'] }}</p>

                <div class="w-10 h-10 rounded-[10px] bg-forest-deep flex items-center justify-center mb-[18px]">
                    @if ($feature['num'] === '01')
                        <svg class="w-5 h-5 stroke-sage-light fill-none stroke-[1.5]" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18"/></svg>
                    @elseif ($feature['num'] === '02')
                        <svg class="w-5 h-5 stroke-sage-light fill-none stroke-[1.5]" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    @else
                        <svg class="w-5 h-5 stroke-sage-light fill-none stroke-[1.5]" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    @endif
                </div>

                <h3 class="font-serif text-xl text-forest-deep mb-2.5 font-medium leading-[1.25]">{{ $feature['title'] }}</h3>
                <p class="text-sm text-[#4a5c4e] leading-[1.75]">{{ $feature['body'] }}</p>

                <div class="mt-4 pt-4 border-t border-cream-dark">
                    @foreach ($feature['items'] as $item)
                        <p class="text-xs text-[#6a8070] py-[3px] font-mono flex items-center gap-1.5">
                            <span class="text-sage shrink-0">→</span>
                            {{ $item }}
                        </p>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
</section>

{{-- ============================================================
     SCOPE — SOAP + COSMETICS
     ============================================================ --}}
<section id="scope" class="py-24 px-5 lg:px-20 bg-cream-warm scroll-mt-[58px]">
    <p class="font-mono text-[11px] tracking-[0.12em] uppercase text-sage mb-4 flex items-center gap-2.5">
        <span class="block w-5 h-[0.5px] bg-sage"></span>
        Scope
    </p>
    <h2 class="font-serif text-[clamp(26px,3vw,38px)] text-forest-deep leading-[1.2] font-medium max-w-[520px] mb-14">
        Soap is where most of us start.<br>The bench always grows.
    </h2>

    <div class="grid lg:grid-cols-2 gap-10 lg:gap-[60px] items-start">
        {{-- Soap --}}
        <div>
            <h3 class="font-serif text-[22px] text-forest-deep mb-3 font-medium">Soap formulation</h3>
            <p class="text-sm text-[#4a5c4e] leading-[1.75] mb-4">Cold process, hot process, liquid soap, syndet bars — the full saponification workflow with lye calculation, superfat control, and cured-bar INCI output.</p>
            <div class="flex flex-col gap-1.5">
                @foreach ($soapScopeItems as $item)
                    <p class="text-[13px] text-[#5a7060] py-1.5 px-2.5 rounded-md bg-cream border border-cream-dark font-mono">{{ $item }}</p>
                @endforeach
            </div>
        </div>

        {{-- Cosmetics --}}
        <div>
            <h3 class="font-serif text-[22px] text-forest-deep mb-3 font-medium">Cosmetics formulation</h3>
            <p class="text-sm text-[#4a5c4e] leading-[1.75] mb-4">Balms, butters, rinse-off treatments, emulsions — the same ingredient discipline and INCI rigour applied to leave-on and rinse-off cosmetics.</p>
            <div class="flex flex-col gap-1.5">
                @foreach ($cosmeticsScopeItems as $item)
                    <p class="text-[13px] text-[#5a7060] py-1.5 px-2.5 rounded-md bg-cream border border-cream-dark font-mono">{{ $item }}</p>
                @endforeach
            </div>
        </div>

        {{-- Callout --}}
        <div class="col-span-full p-6 lg:p-7 rounded-xl bg-forest-deep flex flex-col lg:flex-row items-center justify-between gap-6">
            <div>
                <p class="font-serif text-[17px] text-cream italic leading-[1.5]">"The name says soap. The workbench handles everything on your bench."</p>
                <p class="text-[13px] text-cream/52 mt-1.5">A cosmetic formulator landing on Soapkraft won't feel out of place — they'll feel at home.</p>
            </div>
            <a href="{{ route('dashboard') }}" class="text-sm px-[26px] py-3 rounded-lg bg-sage text-forest-deep font-medium shrink-0 whitespace-nowrap no-underline transition hover:bg-sage-light">Open workspace</a>
        </div>
    </div>
</section>

{{-- ============================================================
     WORKFLOW
     ============================================================ --}}
<section id="workflow" class="py-24 px-5 lg:px-20 bg-forest-deep relative overflow-hidden scroll-mt-[58px]">
    {{-- Background glow --}}
    <div class="absolute inset-0 pointer-events-none" style="background: radial-gradient(ellipse at 80% 50%, rgba(122,158,126,0.05) 0%, transparent 60%)"></div>

    <div class="relative z-10">
        <p class="font-mono text-[11px] tracking-[0.12em] uppercase text-sage mb-4 flex items-center gap-2.5">
            <span class="block w-5 h-[0.5px] bg-sage"></span>
            How it works
        </p>
        <h2 class="font-serif text-[clamp(26px,3vw,38px)] text-cream leading-[1.2] font-medium max-w-[480px] mb-14">A calmer route from raw material to finished label.</h2>

        <div class="grid lg:grid-cols-2 gap-[2px] bg-sage/8">
            @foreach ($workflowSteps as $step)
                <div class="bg-forest-deep p-7 lg:p-9 transition hover:bg-forest-mid">
                    <p class="font-serif text-4xl text-sage/12 font-medium mb-3.5 leading-none">{{ $step['num'] }}</p>
                    <h3 class="font-serif text-xl text-cream mb-2.5 font-medium">{{ $step['title'] }}</h3>
                    <p class="text-sm text-cream/52 leading-[1.7]">{{ $step['body'] }}</p>
                    <span class="inline-block mt-3.5 text-[10px] px-2.5 py-[3px] rounded-full bg-sage/10 text-sage-light font-mono tracking-[0.04em]">{{ $step['tag'] }}</span>
                    @isset($step['note'])
                        <p class="mt-2 text-[11px] text-cream/26 italic">{{ $step['note'] }}</p>
                    @endisset
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ============================================================
     FOUNDER BAND
     ============================================================ --}}
<section class="py-14 px-5 lg:px-20 bg-forest-mid border-y border-sage/12">
    <div class="max-w-[900px] mx-auto grid lg:grid-cols-[1fr_auto] items-center gap-10 lg:gap-[60px]">
        <div>
            <p class="font-mono text-[10px] tracking-[0.1em] uppercase text-sage/80 mb-3">Built from the bench</p>
            <p class="font-serif text-lg text-cream leading-[1.55] italic mb-2.5">"I built the tool I needed on the manufacturing floor and never had."</p>
            <p class="text-[13px] text-cream/52 leading-[1.6]">
                Built by a French soap & cosmetics formulator — not a developer who soaps on weekends. The chemistry in Soapkraft comes from real production floors across India and France.
                <a href="/about" class="text-sage-light no-underline border-b border-sage-light/40">Read the full story →</a>
            </p>
        </div>
        <div class="flex gap-8 shrink-0">
            <div class="text-center">
                <p class="font-serif text-[32px] text-cream font-medium leading-none">1M+</p>
                <p class="text-[11px] text-cream/52 font-mono mt-1">bars made</p>
            </div>
            <div class="text-center">
                <p class="font-serif text-[32px] text-cream font-medium leading-none">16</p>
                <p class="text-[11px] text-cream/52 font-mono mt-1">years formulating</p>
            </div>
            <div class="text-center">
                <p class="font-serif text-[32px] text-cream font-medium leading-none">2</p>
                <p class="text-[11px] text-cream/52 font-mono mt-1">countries</p>
            </div>
        </div>
    </div>
</section>

{{-- ============================================================
     CTA
     ============================================================ --}}
<section id="cta" class="py-24 lg:py-[120px] px-5 lg:px-20 bg-forest-deep text-center relative overflow-hidden scroll-mt-[58px]">
    {{-- Watermark --}}
    <div class="absolute font-serif text-[clamp(60px,12vw,150px)] text-sage/[0.03] font-medium whitespace-nowrap -bottom-2.5 left-1/2 -translate-x-1/2 pointer-events-none tracking-[-0.02em]">SOAPKRAFT</div>

    <div class="max-w-[600px] mx-auto relative z-10">
        <p class="font-mono text-[11px] tracking-[0.12em] uppercase text-sage mb-6 flex items-center justify-center gap-2.5">
            <span class="block w-5 h-[0.5px] bg-sage"></span>
            Now open
            <span class="block w-5 h-[0.5px] bg-sage"></span>
        </p>

        <h2 class="font-serif text-[clamp(30px,4vw,50px)] text-cream leading-[1.15] mb-4 font-medium">
            See if it works<br>for <em class="italic text-sage-light">your</em> bench.
        </h2>

        <p class="text-[15px] text-cream/52 leading-[1.75] mb-9 max-w-[480px] mx-auto">
            Open the workspace, build a formula, and push the chemistry. No commitment — just find out if it fits how you actually work.
        </p>

        <div class="flex gap-3 justify-center flex-wrap">
            <a href="{{ route('dashboard') }}" class="text-sm px-[26px] py-3 rounded-lg bg-sage text-forest-deep font-medium no-underline transition hover:bg-sage-light hover:-translate-y-px">Open workspace</a>
            <a href="{{ route('recipes.create') }}" class="text-sm px-[26px] py-3 rounded-lg bg-transparent text-cream border border-cream/20 no-underline transition hover:border-cream/40 hover:bg-cream/[0.04]">Start a soap formula</a>
        </div>

        <p class="mt-[18px] text-xs text-cream/26 font-mono tracking-[0.04em]">Free to start · No account required to explore</p>
    </div>
</section>
@endsection
