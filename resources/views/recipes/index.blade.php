@extends('layouts.app-shell')

@section('title', 'Recipes · '.config('app.name'))
@section('page_heading', 'Recipes')

@section('content')
    <livewire:dashboard.recipes-index />
@endsection
