@extends('layouts.app-shell')

@php
 $workbenchFamilySlug = (isset($recipe) ? $recipe->productFamily?->slug : null) ?? (isset($productFamily) ? $productFamily->slug : null) ?? 'soap';
 $workbenchTypeSlug = (isset($recipe) ? $recipe->productType?->slug : null) ?? (isset($productType) ? $productType->slug : null);
 $newFormulaTitle = $workbenchFamilySlug === 'cosmetic' ? 'New Cosmetic Formula' : 'New Soap Formula';
@endphp

@section('title', isset($recipe) ? "{$recipe->name} · ".config('app.name') : $newFormulaTitle.' · '.config('app.name'))
@section('page_heading', 'Recipe workbench')

@section('content')
    <livewire:dashboard.recipe-workbench
        :recipe="$recipe ?? null"
        :product-family-slug="$workbenchFamilySlug"
        :product-type-slug="$workbenchTypeSlug"
    />
@endsection
