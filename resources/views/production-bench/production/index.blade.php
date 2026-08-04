@extends('layouts.app-shell')

@section('title', __('production_bench.navigation.production_workflow').' · '.config('app.name'))
@section('page_heading', __('production_bench.title'))

@section('content')
    <livewire:production-bench.production.production-index />
@endsection
