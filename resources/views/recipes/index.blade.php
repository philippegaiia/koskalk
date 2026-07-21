@extends('layouts.app-shell')

@section('title', __('products.page.title').' · '.config('app.name'))
@section('page_heading', __('products.page.title'))

@section('content')
    <livewire:dashboard.recipes-index />
@endsection
