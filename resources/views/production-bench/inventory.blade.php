@extends('layouts.app-shell')

@section('title', __('production_bench.inventory.stock_by_material').' · '.config('app.name'))
@section('page_heading', __('production_bench.title'))

@section('content')
    <livewire:production-bench.inventory-index />
@endsection
