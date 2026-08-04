@extends('layouts.app-shell')

@section('title', __('production_bench.production.detail_title').' · '.config('app.name'))
@section('page_heading', __('production_bench.title'))

@section('content')
    <livewire:production-bench.production.production-detail :production-id="$productionRun" />
@endsection
