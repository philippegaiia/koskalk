@extends('layouts.app-shell')

@section('title', isset($ingredient) ? (($ingredient->currentVersion?->display_name ?? 'Ingredient') . ' · Koskalk') : 'New Ingredient · Koskalk')
@section('page_heading', isset($ingredient) ? ($ingredient->currentVersion?->display_name ?? 'Edit Ingredient') : 'New Ingredient')

@section('content')
    <livewire:dashboard.ingredient-editor :ingredient="$ingredient ?? null" />
@endsection
