@extends('layouts.app-shell')

@section('title', isset($recipe) ? "{$recipe->name} · Koskalk" : 'New Soap Formula · Koskalk')
@section('page_heading', isset($recipe) ? $recipe->name : 'New Soap Formula')

@section('content')
    <livewire:dashboard.recipe-workbench :recipe="$recipe ?? null" />
@endsection
