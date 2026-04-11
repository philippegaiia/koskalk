@extends('layouts.app-shell')

@section('title', isset($ingredient) ? (($ingredient->display_name ?? 'Ingredient') . ' · '.config('app.name')) : 'New Ingredient · '.config('app.name'))
@section('page_heading', isset($ingredient) ? ($ingredient->display_name ?? 'Edit Ingredient') : 'New Ingredient')

@section('content')
    <livewire:dashboard.ingredient-editor :ingredient="$ingredient ?? null" />
@endsection
