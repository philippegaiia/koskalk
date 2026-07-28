@extends('layouts.app-shell')

@section('title', 'Inventory · Production Bench · '.config('app.name'))
@section('page_heading', 'Production Bench')

@section('content')
    <livewire:production-bench.inventory-index />
@endsection
