@extends('layouts.app-shell')

@section('title', __('production_bench.settings.task_sets').' · '.config('app.name'))
@section('page_heading', __('production_bench.title'))

@section('content')
    <livewire:production-bench.production.task-set-index />
@endsection
