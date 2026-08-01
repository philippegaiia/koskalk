@extends('layouts.app-shell')

@section('title', 'New supplier · Production Bench · '.config('app.name'))
@section('page_heading', 'Production Bench')

@section('content')
    <livewire:production-bench.purchasing.supplier-create />
@endsection
