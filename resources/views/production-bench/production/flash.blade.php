@extends('layouts.app-shell')

@section('title', __('production_bench.flash.title').' · '.config('app.name'))
@section('page_heading', __('production_bench.title'))

@section('content')
    <livewire:production-bench.production.flash-planner />
@endsection
