@extends('layouts.app-shell')

@section('title', isset($ingredient) ? (($ingredient->display_name ?? __('ingredients.page.eyebrow')) . ' · '.config('app.name')) : __('ingredients.editor.create.page_title').' · '.config('app.name'))
@section('page_heading', isset($ingredient) ? ($ingredient->display_name ?? __('ingredients.page.eyebrow')) : __('ingredients.editor.create.page_title'))

@section('content')
    <livewire:dashboard.ingredient-editor :ingredient="$ingredient ?? null" />
@endsection
