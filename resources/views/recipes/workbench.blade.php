@extends('layouts.app-shell')

@php
 $workbenchFamilySlug = (isset($recipe) ? $recipe->productFamily?->slug : null) ?? (isset($productFamily) ? $productFamily->slug : null) ?? 'soap';
 $workbenchTypeSlug = (isset($recipe) ? $recipe->productType?->slug : null) ?? (isset($productType) ? $productType->slug : null);
 $newFormulaTitle = $workbenchFamilySlug === 'cosmetic' ? 'New Cosmetic Formula' : 'New Soap Formula';
@endphp

@section('title', isset($recipe) ? "{$recipe->name} · ".config('app.name') : $newFormulaTitle.' · '.config('app.name'))
@section('page_heading', isset($recipe) ? $recipe->name : $newFormulaTitle)

@section('content')
    @if (isset($productType) && $productType)
        <div class="mx-auto mb-4 max-w-[90rem]">
            <span class="sk-badge sk-badge-neutral">{{ $productType->name }}</span>
        </div>
    @endif

    <livewire:dashboard.recipe-workbench
        :recipe="$recipe ?? null"
        :product-family-slug="$workbenchFamilySlug"
        :product-type-slug="$workbenchTypeSlug"
    />
@endsection
