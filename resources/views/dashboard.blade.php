@extends('layouts.app-shell')

@section('title', 'Dashboard · '.config('app.name'))
@section('page_heading', 'Dashboard')

@section('content')
<div class="mx-auto w-full max-w-7xl space-y-8">
	<section class="sk-card p-6">
		<div class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
			<div class="min-w-0">
				<p class="sk-eyebrow">Workspace</p>
				<h3 class="mt-3 max-w-4xl text-xl font-semibold text-[var(--color-ink-strong)] sm:text-2xl">Welcome to your formulation workspace.</h3>
				<p class="mt-4 max-w-3xl text-sm leading-7 text-[var(--color-ink-soft)]">Create and manage soap and cosmetic formulas. More modules coming soon.</p>
			</div>

			<div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap">
				<a href="{{ route('recipes.create') }}" wire:navigate class="sk-btn sk-btn-primary">
					Create soap formula
				</a>
				<a href="{{ route('recipes.create', ['family' => 'cosmetic']) }}" wire:navigate class="sk-btn sk-btn-outline">
					Create cosmetic formula
				</a>
			</div>
		</div>
	</section>

	<section class="grid gap-4 sm:grid-cols-3">
		<a href="{{ route('recipes.index') }}" wire:navigate class="sk-card p-5 transition hover:shadow-lg">
			<p class="sk-eyebrow">Recipes</p>
			<p class="mt-4 font-mono text-4xl text-[var(--color-ink-strong)]">{{ $recipeCount }}</p>
		</a>
		<a href="{{ route('ingredients.index') }}" wire:navigate class="sk-card p-5 transition hover:shadow-lg">
			<p class="sk-eyebrow">Ingredients</p>
			<p class="mt-4 font-mono text-4xl text-[var(--color-ink-strong)]">{{ $ingredientCount }}</p>
		</a>
		<div class="sk-card p-5">
			<p class="sk-eyebrow">Drafts</p>
			<p class="mt-4 font-mono text-4xl text-[var(--color-ink-strong)]">{{ $draftCount }}</p>
		</div>
	</section>
</div>
@endsection
