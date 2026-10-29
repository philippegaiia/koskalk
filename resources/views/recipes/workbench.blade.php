@extends('layouts.app-shell')

@php
 $workbenchFamilySlug = (isset($recipe) ? $recipe->productFamily?->slug : null) ?? (isset($productFamily) ? $productFamily->slug : null) ?? 'soap';
 $workbenchTypeSlug = (isset($recipe) ? $recipe->productType?->slug : null) ?? (isset($productType) ? $productType->slug : null);
 $workbenchFamily = (isset($recipe) ? $recipe->productFamily : null) ?? ($productFamily ?? null);
 $newFormulaTitle = match (true) {
     $workbenchFamilySlug === 'cosmetic' => __('workbench.page.new_cosmetic_title'),
     $workbenchFamily?->usesLiquidSoapPasteBench() ?? false => __('workbench.page.new_liquid_soap_title'),
     default => __('workbench.page.new_soap_title'),
 };
@endphp

@section('title', isset($recipe) ? "{$recipe->name} · ".config('app.name') : $newFormulaTitle.' · '.config('app.name'))
@section('page_heading', __('workbench.page.heading'))

@section('content')
    <livewire:dashboard.recipe-workbench
        :recipe="$recipe ?? null"
        :product-family-slug="$workbenchFamilySlug"
        :product-type-slug="$workbenchTypeSlug"
    />
@endsection
