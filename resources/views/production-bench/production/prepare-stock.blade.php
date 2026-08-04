@extends('layouts.app-shell')

@section('title', __('production_bench.production.prepare_stock_title').' · '.config('app.name'))
@section('page_heading', __('production_bench.title'))

@section('content')
    <livewire:production-bench.production.stock-preparation :production-run="$productionRun ?? null" />
@endsection
