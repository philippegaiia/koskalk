@extends('layouts.app-shell')

@section('title', 'Purchasing · Production Bench · '.config('app.name'))
@section('page_heading', 'Production Bench')

@section('content')
    <livewire:production-bench.purchasing-index />
@endsection
