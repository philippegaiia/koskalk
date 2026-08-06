@extends('layouts.app-shell')

@section('title', __('production_bench.settings.edit_task_set').' · '.config('app.name'))
@section('page_heading', __('production_bench.title'))

@section('content')
    <livewire:production-bench.production.task-set-form :task-set="$taskSet" />
@endsection
