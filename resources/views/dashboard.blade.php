@extends('layouts.app-shell')

@section('title', 'Dashboard · Koskalk')
@section('page_heading', 'Dashboard')

@section('content')
    <div class="mx-auto w-full max-w-7xl space-y-6 sm:space-y-8">
        <section class="rounded-[2rem] border border-[var(--color-line)] bg-white p-5 sm:p-6">
            <div class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
                <div class="min-w-0">
                    <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Workspace</p>
                    <h3 class="mt-3 max-w-4xl text-2xl font-semibold tracking-[-0.04em] text-[var(--color-ink-strong)] sm:text-3xl lg:text-4xl">Create formulas, reopen drafts, and keep your own ingredients in reach.</h3>
                    <p class="mt-4 max-w-3xl text-sm leading-7 text-[var(--color-ink-soft)] sm:text-[15px]">
                        The dashboard is the real home for the formulation app. It should show what matters immediately: what you can create, what is already saved, and what personal ingredients belong to you.
                    </p>
                </div>

                <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap">
                    <a href="{{ route('recipes.create') }}" wire:navigate class="inline-flex justify-center rounded-full bg-[var(--color-accent-strong)] px-5 py-2.5 text-sm font-medium text-white transition hover:bg-[var(--color-accent)]">
                        Create soap formula
                    </a>
                    <button type="button" disabled class="inline-flex cursor-not-allowed justify-center rounded-full border border-[var(--color-line)] bg-[var(--color-panel)] px-5 py-2.5 text-sm font-medium text-[var(--color-ink-soft)]">
                        Create formula
                    </button>
                </div>
            </div>
        </section>

        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <div class="rounded-[2rem] border border-[var(--color-line)] bg-white p-5">
                <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Saved recipes</p>
                <p class="mt-4 text-4xl font-semibold tracking-[-0.04em] text-[var(--color-ink-strong)]">{{ $recipeCount }}</p>
                <p class="mt-2 text-sm text-[var(--color-ink-soft)]">Every saved draft should come back here without needing to open a separate recipes page first.</p>
            </div>
            <div class="rounded-[2rem] border border-[var(--color-line)] bg-white p-5">
                <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Current drafts</p>
                <p class="mt-4 text-4xl font-semibold tracking-[-0.04em] text-[var(--color-ink-strong)]">{{ $draftCount }}</p>
                <p class="mt-2 text-sm text-[var(--color-ink-soft)]">Drafts are editable. Published versions remain history, while the working draft stays live.</p>
            </div>
            <div class="rounded-[2rem] border border-[var(--color-line)] bg-white p-5">
                <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Published versions</p>
                <p class="mt-4 text-4xl font-semibold tracking-[-0.04em] text-[var(--color-ink-strong)]">{{ $publishedVersionCount }}</p>
                <p class="mt-2 text-sm text-[var(--color-ink-soft)]">Versioning stays explicit, so history remains clean and meaningful.</p>
            </div>
        </section>

        <section class="grid gap-4 xl:grid-cols-[minmax(0,1.35fr)_24rem]">
            <div class="overflow-hidden rounded-[2rem] border border-[var(--color-line)] bg-white">
                <div class="flex items-center justify-between border-b border-[var(--color-line)] px-5 py-4">
                    <div>
                        <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Recipes</p>
                        <h3 class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">Your saved formulas and their statuses</h3>
                    </div>
                    <a href="{{ route('recipes.index') }}" wire:navigate class="rounded-full border border-[var(--color-line)] px-4 py-2 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel)]">
                        View all
                    </a>
                </div>

                @if (! $currentUser)
                    <div class="p-8 text-center">
                        <h4 class="text-lg font-semibold text-[var(--color-ink-strong)]">No connected workspace yet</h4>
                        <p class="mt-3 text-sm leading-7 text-[var(--color-ink-soft)]">Once you open the dashboard from your signed-in app or admin session, your saved formulas will appear here automatically.</p>
                    </div>
                @elseif ($recipes->isEmpty())
                    <div class="p-8 text-center">
                        <h4 class="text-lg font-semibold text-[var(--color-ink-strong)]">No saved formulas yet</h4>
                        <p class="mt-3 text-sm leading-7 text-[var(--color-ink-soft)]">Create the first soap formula, give it a name in the workbench header, and save the draft. It will then show up here with its current status.</p>
                    </div>
                @else
                    <div class="divide-y divide-[var(--color-line)]">
                        @foreach ($recipes as $recipe)
                            <article class="px-5 py-4">
                                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <h4 class="truncate text-lg font-semibold text-[var(--color-ink-strong)]">{{ $recipe->name }}</h4>
                                            @if ($recipe->currentDraftVersion && $recipe->published_versions_count > 0)
                                                <span class="rounded-full border border-[var(--color-success-soft)] bg-[var(--color-success-soft)] px-3 py-1 text-xs font-medium text-[var(--color-success-strong)]">Draft + versioned</span>
                                            @elseif ($recipe->currentDraftVersion)
                                                <span class="rounded-full border border-[var(--color-warning-soft)] bg-[var(--color-warning-soft)] px-3 py-1 text-xs font-medium text-[var(--color-warning-strong)]">Draft</span>
                                            @else
                                                <span class="rounded-full border border-[var(--color-line)] bg-[var(--color-panel)] px-3 py-1 text-xs font-medium text-[var(--color-ink-soft)]">Versioned</span>
                                            @endif
                                        </div>
                                        <div class="mt-3 flex flex-wrap gap-2">
                                            <span class="rounded-full border border-[var(--color-line)] px-3 py-1 text-xs font-medium text-[var(--color-ink-soft)]">
                                                {{ $recipe->productFamily?->name ?? 'Formula' }}
                                            </span>
                                            @if ($recipe->currentDraftVersion)
                                                <span class="rounded-full border border-[var(--color-line)] px-3 py-1 text-xs font-medium text-[var(--color-ink-soft)]">
                                                    Draft v{{ $recipe->currentDraftVersion->version_number }}
                                                </span>
                                            @endif
                                            @if ($recipe->published_versions_count > 0)
                                                <span class="rounded-full border border-[var(--color-line)] px-3 py-1 text-xs font-medium text-[var(--color-ink-soft)]">
                                                    {{ $recipe->published_versions_count }} {{ \Illuminate\Support\Str::plural('version', $recipe->published_versions_count) }}
                                                </span>
                                            @endif
                                        </div>
                                    </div>

                                    <div class="flex flex-wrap gap-2">
                                        <span class="inline-flex items-center rounded-full border border-[var(--color-line)] bg-[var(--color-panel)] px-4 py-2 text-sm text-[var(--color-ink-soft)]">
                                            Updated {{ $recipe->updated_at?->diffForHumans() ?? 'just now' }}
                                        </span>
                                        <a href="{{ route('recipes.edit', $recipe->id) }}" wire:navigate class="inline-flex rounded-full border border-[var(--color-line-strong)] px-4 py-2 text-sm font-medium text-[var(--color-ink-strong)] transition hover:bg-[var(--color-panel)]">
                                            Open draft
                                        </a>
                                    </div>
                                </div>
                            </article>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="rounded-[2rem] border border-[var(--color-line)] bg-white">
                <div class="border-b border-[var(--color-line)] px-5 py-4">
                    <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Personal ingredients</p>
                    <h3 class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">Your private ingredient library</h3>
                </div>

                <div class="space-y-4 p-5">
                    <div class="rounded-[1.5rem] border border-[var(--color-line)] bg-[var(--color-panel)] p-4">
                        <p class="text-xs font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase">Current count</p>
                        <p class="mt-2 text-2xl font-semibold text-[var(--color-ink-strong)]">{{ $personalIngredientCount }}</p>
                    </div>

                    @if (! $currentUser)
                        <div class="rounded-[1.5rem] border border-dashed border-[var(--color-line)] bg-white p-5">
                            <p class="font-medium text-[var(--color-ink-strong)]">Sign in to manage ingredients</p>
                            <p class="mt-2 text-sm leading-7 text-[var(--color-ink-soft)]">
                                Open the dashboard from your signed-in app or admin session to create private ingredients and reuse them in formulas.
                            </p>
                        </div>
                    @elseif ($personalIngredients->isEmpty())
                        <div class="rounded-[1.5rem] border border-dashed border-[var(--color-line)] bg-white p-5">
                            <p class="font-medium text-[var(--color-ink-strong)]">No personal ingredients yet</p>
                            <p class="mt-2 text-sm leading-7 text-[var(--color-ink-soft)]">
                                Create your own fragrance oils, CO2 extracts, additives, glycols, clays, or composite ingredients, then enrich them later with components or aromatic compliance data.
                            </p>
                        </div>
                    @else
                        <div class="space-y-3">
                            @foreach ($personalIngredients as $ingredient)
                                <article class="rounded-[1.5rem] border border-[var(--color-line)] bg-white p-4">
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="min-w-0">
                                            <p class="truncate font-medium text-[var(--color-ink-strong)]">{{ $ingredient->display_name ?? $ingredient->source_key }}</p>
                                            <p class="mt-1 text-xs text-[var(--color-ink-soft)]">{{ $ingredient->category?->getLabel() ?? 'Uncategorized' }}</p>
                                        </div>
                                        <a href="{{ route('ingredients.edit', $ingredient->id) }}" wire:navigate class="shrink-0 rounded-full border border-[var(--color-line)] px-3 py-1.5 text-xs font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel)]">
                                            Open
                                        </a>
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    @endif

                    <div class="grid gap-3 sm:grid-cols-2">
                        @if ($currentUser)
                            <a href="{{ route('ingredients.create') }}" wire:navigate class="inline-flex w-full justify-center rounded-full bg-[var(--color-accent-strong)] px-4 py-2.5 text-sm font-medium text-white transition hover:bg-[var(--color-accent)]">
                                Add ingredient
                            </a>
                        @endif
                        <a href="{{ route('ingredients.index') }}" wire:navigate class="inline-flex w-full justify-center rounded-full border border-[var(--color-line)] px-4 py-2.5 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel)] {{ $currentUser ? '' : 'sm:col-span-2' }}">
                            Browse my ingredients
                        </a>
                    </div>
                </div>
            </div>
        </section>
    </div>
@endsection
