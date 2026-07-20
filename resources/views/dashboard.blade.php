@extends('layouts.app-shell')

@section('title', __('dashboard.title').' · '.config('app.name'))
@section('page_heading', __('dashboard.title'))

@section('content')
<div class="mx-auto w-full max-w-7xl space-y-8">
	<section class="sk-card p-6">
		<div class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
			<div class="min-w-0">
				<h3 class="font-display max-w-4xl text-xl text-[var(--color-ink-strong)] sm:text-2xl">{{ __('dashboard.create.heading') }}</h3>
			</div>

			<div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap">
				<a href="{{ route('recipes.create') }}" wire:navigate class="sk-btn sk-btn-primary">
					{{ __('dashboard.create.soap') }}
				</a>
				<a href="{{ route('recipes.create', ['family' => 'cosmetic']) }}" wire:navigate class="sk-btn sk-btn-outline">
					{{ __('dashboard.create.cosmetic') }}
				</a>
			</div>
		</div>
	</section>

	<section aria-labelledby="product-library-heading" class="space-y-4">
		<h3 id="product-library-heading" class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('dashboard.library.heading') }}</h3>
		<div class="grid gap-4 sm:grid-cols-3">
			<a href="{{ route('recipes.index') }}" wire:navigate class="sk-card p-5 transition hover:shadow-lg">
				<p class="sk-eyebrow">{{ __('dashboard.library.products') }}</p>
				<p class="mt-4 font-mono text-4xl text-[var(--color-ink-strong)]">{{ $recipeCount }}</p>
			</a>
			<a href="{{ route('ingredients.index') }}" wire:navigate class="sk-card p-5 transition hover:shadow-lg">
				<p class="sk-eyebrow">{{ __('dashboard.library.ingredients') }}</p>
				<p class="mt-4 font-mono text-4xl text-[var(--color-ink-strong)]">{{ $ingredientCount }}</p>
			</a>
			<div class="sk-card p-5">
				<p class="sk-eyebrow">{{ __('dashboard.library.locked_products') }}</p>
				<p class="mt-4 font-mono text-4xl text-[var(--color-ink-strong)]">{{ $lockedFormulaCount }}</p>
			</div>
		</div>
	</section>
</div>
@endsection
