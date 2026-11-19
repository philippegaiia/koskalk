@extends('layouts.app-shell')

@section('title', __('production_bench.inventory.finished_products').' · '.config('app.name'))
@section('page_heading', __('production_bench.title'))

@section('content')
    <livewire:production-bench.inventory-index mode="finished-products" />
@endsection
