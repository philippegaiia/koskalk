@extends('layouts.app-shell')

@section('title', 'My Ingredients · '.config('app.name'))
@section('page_heading', 'Ingredients')

@section('content')
    <livewire:dashboard.ingredients-index />
@endsection
