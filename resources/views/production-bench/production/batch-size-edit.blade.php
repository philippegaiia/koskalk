@extends('layouts.app-shell')

@section('title', __('production_bench.settings.edit_batch_size').' · '.config('app.name'))
@section('page_heading', __('production_bench.title'))

@section('content')
    <livewire:production-bench.production.batch-size-form :preset="$preset" />
@endsection
