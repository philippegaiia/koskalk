@extends('layouts.app-shell')

@section('title', __('locations.title').' · '.config('app.name'))
@section('page_heading', __('production_bench.title'))

@section('content')
    <livewire:production-bench.production.planning-preferences />
@endsection
