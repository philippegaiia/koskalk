@extends('layouts.app-shell')

@section('title', 'Production Bench · '.config('app.name'))
@section('page_heading', 'Production Bench')

@section('content')
    <livewire:production-bench.home-index />
@endsection
