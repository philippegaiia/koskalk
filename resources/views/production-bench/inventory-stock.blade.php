@extends('layouts.app-shell')

@section('title', __('production_bench.inventory.lot_register').' · '.config('app.name'))
@section('page_heading', __('production_bench.title'))

@section('content')
    <livewire:production-bench.inventory-index mode="stock" />
@endsection
