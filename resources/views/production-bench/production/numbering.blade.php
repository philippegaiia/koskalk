@extends('layouts.app-shell')

@section('title', __('production_bench.settings.numbering').' · '.config('app.name'))
@section('page_heading', __('production_bench.title'))

@section('content')
    <livewire:production-bench.production.numbering-settings />
@endsection
