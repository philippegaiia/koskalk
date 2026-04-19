@extends('layouts.app-shell')

@section('title', 'Dashboard · '.config('app.name'))
@section('page_heading', 'Dashboard')

@section('content')
 <div class="mx-auto w-full max-w-7xl space-y-6 sm:space-y-8">
 <section class="sk-card p-5 sm:p-6">
 <div class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
 <div class="min-w-0">
 <p class="sk-eyebrow">Workspace</p>
 <h3 class="mt-3 max-w-4xl text-xl font-semibold text-[var(--color-ink-strong)] sm:text-2xl">Create formulas, keep one working draft, and save one official recipe without extra version clutter.</h3>
 <p class="mt-4 max-w-3xl text-sm leading-7 text-[var(--color-ink-soft)] sm:text-[15px]">
 The dashboard is the real home for the formulation app. It should show what matters immediately: what you can create, what is already saved, and what personal ingredients belong to you.
 </p>
 </div>

 <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap">
 <a href="{{ route('recipes.create') }}" wire:navigate class="inline-flex justify-center rounded-lg bg-[var(--color-accent)] px-5 py-2.5 text-sm font-medium text-white transition hover:bg-[var(--color-accent-hover)]">
 Create soap formula
 </a>
 <button type="button" disabled class="inline-flex cursor-not-allowed justify-center sk-inset px-5 py-2.5 text-sm font-medium text-[var(--color-ink-soft)]">
 Create formula
 </button>
 </div>
 </div>
 </section>

 <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
 <div class="sk-card p-5">
 <p class="sk-eyebrow">Saved recipes</p>
 <p class="mt-4 font-mono text-4xl text-[var(--color-ink-strong)]">{{ $recipeCount }}</p>
 <p class="mt-2 text-sm text-[var(--color-ink-soft)]">Every saved draft should come back here without needing to open a separate recipes page first.</p>
 </div>
 <div class="sk-card p-5">
 <p class="sk-eyebrow">Current drafts</p>
 <p class="mt-4 font-mono text-4xl text-[var(--color-ink-strong)]">{{ $draftCount }}</p>
 <p class="mt-2 text-sm text-[var(--color-ink-soft)]">One working draft stays editable so you can experiment before replacing the official recipe.</p>
 </div>
 <div class="sk-card p-5">
 <p class="sk-eyebrow">Official recipes</p>
 <p class="mt-4 font-mono text-4xl text-[var(--color-ink-strong)]">{{ $savedFormulaCount }}</p>
 <p class="mt-2 text-sm text-[var(--color-ink-soft)]">Each recipe can keep one official recipe, while hidden recovery snapshots stay out of the way.</p>
 </div>
 </section>

 <section class="grid gap-4 xl:grid-cols-[minmax(0,1.35fr)_24rem]">
 <div class="overflow-hidden sk-card">
 <div class="flex items-center justify-between px-5 py-4">
 <div>
 <p class="sk-eyebrow">Recipes</p>
 <h3 class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">Your official recipes and draft states</h3>
 </div>
 <a href="{{ route('recipes.index') }}" wire:navigate class="rounded-lg px-4 py-2 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel-strong)]">
 View all
 </a>
 </div>

 @if (! $currentUser)
 <div class="p-8 text-center">
 <h4 class="text-lg font-semibold text-[var(--color-ink-strong)]">No connected workspace yet</h4>
 <p class="mt-3 text-sm leading-7 text-[var(--color-ink-soft)]">Once you open the dashboard from your signed-in app or admin session, your official recipes will appear here automatically.</p>
 </div>
 @elseif ($recipes->isEmpty())
 <div class="p-8 text-center">
 <h4 class="text-lg font-semibold text-[var(--color-ink-strong)]">No official recipes yet</h4>
 <p class="mt-3 text-sm leading-7 text-[var(--color-ink-soft)]">Create the first soap formula, give it a name in the workbench header, and save the draft. It will then show up here with its current status.</p>
 </div>
 @else
 <div class="space-y-2 px-3 pb-3">
 @foreach ($recipes as $recipe)
 <article class="sk-inset px-4 py-3">
 <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
 <div class="min-w-0">
 <div class="flex flex-wrap items-center gap-2">
 <h4 class="truncate text-lg font-semibold text-[var(--color-ink-strong)]">{{ $recipe->name }}</h4>
 @if ($recipe->currentDraftVersion)
 <span class="rounded-full bg-[var(--color-warning-soft)] px-3 py-1 text-xs font-medium text-[var(--color-warning-strong)]">Draft</span>
 @endif
 @if ($recipe->currentSavedVersion)
 <span class="rounded-full bg-[var(--color-panel)] px-3 py-1 text-xs font-medium text-[var(--color-ink-soft)]">Saved</span>
 @endif
 </div>
 <div class="mt-3 flex flex-wrap gap-2">
 <span class="rounded-full bg-[var(--color-panel)] px-3 py-1 text-xs font-medium text-[var(--color-ink-soft)]">
 {{ $recipe->productFamily?->name ?? 'Formula' }}
 </span>
 </div>
 <p class="mt-3 text-xs text-[var(--color-ink-soft)]">
 Last updated {{ $recipe->updated_at?->diffForHumans() ?? 'just now' }}
 </p>
 </div>

 <div class="flex flex-wrap gap-2">
 <a href="{{ route('recipes.edit', $recipe->id) }}" wire:navigate class="inline-flex rounded-lg bg-[var(--color-accent)] px-4 py-2 text-sm font-medium text-white transition hover:bg-[var(--color-accent-hover)]">
 Open draft
 </a>
 @if ($recipe->currentSavedVersion)
 <a href="{{ route('recipes.saved', $recipe->id) }}" wire:navigate class="inline-flex rounded-lg px-4 py-2 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel)]">
 Open recipe
 </a>
 @endif
 <form method="POST" action="{{ route('recipes.duplicate', $recipe->id) }}">
 @csrf
 <button type="submit" class="inline-flex rounded-lg px-4 py-2 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel)]">
 Duplicate
 </button>
 </form>
 </div>
 </div>
 </article>
 @endforeach
 </div>
 @endif
 </div>

 <div class="sk-card">
 <div class="px-5 py-4">
 <p class="sk-eyebrow">Personal ingredients</p>
 <h3 class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">Your private ingredient library</h3>
 </div>

 <div class="space-y-4 p-5">
 <div class="sk-inset p-4">
 <p class="sk-eyebrow">Current count</p>
 <p class="mt-2 font-mono text-2xl text-[var(--color-ink-strong)]">{{ $personalIngredientCount }}</p>
 </div>

 @if (! $currentUser)
 <div class="sk-inset p-5">
 <p class="font-medium text-[var(--color-ink-strong)]">Sign in to manage ingredients</p>
 <p class="mt-2 text-sm leading-7 text-[var(--color-ink-soft)]">
 Open the dashboard from your signed-in app or admin session to create private ingredients and reuse them in formulas.
 </p>
 </div>
 @elseif ($personalIngredients->isEmpty())
 <div class="sk-inset p-5">
 <p class="font-medium text-[var(--color-ink-strong)]">No personal ingredients yet</p>
 <p class="mt-2 text-sm leading-7 text-[var(--color-ink-soft)]">
 Create your own fragrance oils, CO2 extracts, additives, glycols, clays, or composite ingredients, then enrich them later with components or aromatic compliance data.
 </p>
 </div>
 @else
 <div class="space-y-3">
 @foreach ($personalIngredients as $ingredient)
 <article class="sk-inset p-4">
 <div class="flex items-start justify-between gap-3">
 <div class="min-w-0">
 <p class="truncate font-medium text-[var(--color-ink-strong)]">{{ $ingredient->display_name ?? $ingredient->source_key }}</p>
 <p class="mt-1 text-xs text-[var(--color-ink-soft)]">{{ $ingredient->category?->getLabel() ?? 'Uncategorized' }}</p>
 </div>
 <a href="{{ route('ingredients.edit', $ingredient->id) }}" wire:navigate class="shrink-0 rounded-lg px-3 py-1.5 text-xs font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel)]">
 Open
 </a>
 </div>
 </article>
 @endforeach
 </div>
 @endif

 <div class="grid gap-3 sm:grid-cols-2">
 @if ($currentUser)
 <a href="{{ route('ingredients.create') }}" wire:navigate class="inline-flex w-full justify-center rounded-lg bg-[var(--color-accent)] px-4 py-2.5 text-sm font-medium text-white transition hover:bg-[var(--color-accent-hover)]">
 Add ingredient
 </a>
 @endif
 <a href="{{ route('ingredients.index') }}" wire:navigate class="inline-flex w-full justify-center rounded-lg px-4 py-2.5 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel-strong)] {{ $currentUser ? '' : 'sm:col-span-2' }}">
 Browse my ingredients
 </a>
 </div>
 </div>
 </div>
 </section>
 </div>
@endsection
